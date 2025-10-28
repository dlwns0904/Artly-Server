<?php
namespace Models;

class ImageService
{
    public function __construct(
        private string $openaiKey,
        private string $pixabayKey
    ) {}

    /** 키워드 추출: Chat Completions */
    public function extractKeywords(string $userText, string $model='gpt-5-mini'): array
    {
        $system = '사용자 이미지 요구에서 포스터 "배경" 검색용 키워드만 JSON으로 추출하세요.';
        $prompt = <<<TXT
지침:
- 영어 단어 1~2개, 소문자, 단어 하나로만 구성 (예: texture, color)
- "artist/exhibition" 등 배경 탐색에 부적절한 단어 제외
형식:
{"keywords": ["키워드1","키워드2(선택)"]}

요청사항: "{$userText}"
TXT;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>$system],
                ['role'=>'user','content'=>$prompt],
            ],
            'temperature' => 0.2
        ];

        $resp = $this->postJson('https://api.openai.com/v1/chat/completions', $payload, [
            "Authorization: Bearer {$this->openaiKey}",
            "Content-Type: application/json"
        ]);

        $content = $resp['choices'][0]['message']['content'] ?? '';
        if (!$content) return [];

        $json = trim(preg_replace('/```json|```/i', '', $content));
        $obj = json_decode($json, true);
        $arr = $obj['keywords'] ?? [];

        $out = [];
        foreach ((array)$arr as $kw) {
            $kw = strtolower(trim((string)$kw));
            if ($kw !== '' && preg_match('/^[a-z-]+$/', $kw)) $out[] = $kw;
        }
        return array_slice(array_values(array_unique($out)), 0, 2);
    }

    /** 추출 실패 시 간이 대체 */
    public function fallbackKeywords(string $text): array
    {
        $words = preg_split('/\s+/', mb_strtolower($text));
        $words = array_filter($words, fn($w)=>preg_match('/^[a-z가-힣]+$/u', $w));
        return array_slice(array_values(array_unique($words)), 0, 2);
    }

    /** Pixabay 검색 → 무작위 N개 URL */
    public function fetchPixabayImages(array $keywords, int $n=2): array
    {
        $query = implode(' ', $keywords);
        $url = "https://pixabay.com/api/?key={$this->pixabayKey}&q=".urlencode($query)."&per_page=20&orientation=vertical&safesearch=true";
        $json = $this->getJson($url);
        $hits = $json['hits'] ?? [];
        if (!$hits) return [];
        shuffle($hits);
        $sel = array_slice($hits, 0, $n);
        return array_values(array_filter(array_map(
            fn($h) => $h['largeImageURL'] ?? $h['webformatURL'] ?? null, $sel
        )));
    }

    /** URL → 임시파일 */
    public function downloadTempFiles(array $urls): array
    {
        $files = [];
        foreach ($urls as $u) {
            $data = @file_get_contents($u);
            if ($data === false) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'px_');
            file_put_contents($tmp, $data);
            // 간단 확장자 보정
            $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $data) ?: 'image/jpeg';
            $ext  = ($mime === 'image/png') ? '.png' : '.jpg';
            $new  = $tmp.$ext;
            rename($tmp, $new);
            $files[] = $new;
        }
        return $files;
    }

    /** OpenAI Images Edits → data:image/png;base64,... */
    public function callImagesEdits(string $model, string $prompt, array $imagePaths): ?string
    {
        $boundary = uniqid('frm_');
        $headers = [
            "Authorization: Bearer {$this->openaiKey}",
            "Content-Type: multipart/form-data; boundary={$boundary}"
        ];

        $body = '';
        $add = function($name, $value, $filename=null, $type=null) use (&$body, $boundary) {
            $body .= "--{$boundary}\r\n";
            if ($filename) {
                $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
                if ($type) $body .= "Content-Type: {$type}\r\n";
                $body .= "\r\n{$value}\r\n";
            } else {
                $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
            }
        };

        $add('model', $model);
        $add('prompt', $prompt);
        $add('n', '1');
        $add('size', '1024x1536');
        $add('response_format', 'b64_json');

        foreach ($imagePaths as $p) {
            if (!is_file($p)) continue;
            $bin = file_get_contents($p);
            $name= basename($p);
            $type= str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg';
            // 동일 필드명 반복 첨부
            $add('image', $bin, $name, $type);
        }
        $body .= "--{$boundary}--\r\n";

        $res = $this->rawPost('https://api.openai.com/v1/images/edits', $body, $headers);
        $code = $res['code'];
        $txt  = $res['body'];

        if ($code >= 400) throw new \RuntimeException("OpenAI error {$code}: ".$txt);

        $json = json_decode($txt, true);
        $b64  = $json['data'][0]['b64_json'] ?? null;
        return $b64 ? 'data:image/png;base64,'.$b64 : null;
    }

    /** 이미지 생성 지침 + 사용자 요구사항 */
    public function buildImagePrompt(string $userText): string
    {
        return <<<PROMPT
이미지 생성 지침:
1) 사용자의 요구를 반영해 전시회 포스터에 적합한 "배경 이미지"를 생성합니다.
2) 사용자가 제공한 스타일 요청을 정확히 반영하십시오.
3) 분위기는 요구의 방향성에 맞춰 일관되게 표현합니다.
4) 전문적인 포스터 디자인 관점에서 시각적 균형/조화를 유지하십시오.
5) 요구하지 않은 요소가 과도하게 두드러지지 않도록 합니다.
6) 배경 용도이므로 요소 과밀을 피합니다.
7) 텍스트(제목/문구) 삽입 금지.

요구사항: {$userText}
PROMPT;
    }

    public function cleanup(array $paths): void
    {
        foreach ($paths as $p) if (is_file($p)) @unlink($p);
    }

    /* ------------ HTTP 유틸 ------------ */
    private function postJson(string $url, array $payload, array $headers=[]): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP POST 실패: {$err}");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) throw new \RuntimeException("HTTP {$code}: {$res}");
        return json_decode($res, true) ?? [];
    }

    private function rawPost(string $url, string $body, array $headers=[]): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 120
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP POST 실패: {$err}");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code'=>$code, 'body'=>$res];
    }

    private function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60]);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP GET 실패: {$err}");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) throw new \RuntimeException("HTTP {$code}: {$res}");
        return json_decode($res, true) ?? [];
    }
}
