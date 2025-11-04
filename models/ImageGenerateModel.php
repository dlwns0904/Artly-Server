<?php
namespace Models;

class ImageGenerateModel
{
    /** @var string */
    private $openaiKey;
    /** @var string */
    private $pixabayKey;

    public function __construct($openaiKey, $pixabayKey)
    {
        $this->openaiKey  = $openaiKey;
        $this->pixabayKey = $pixabayKey;
    }

    /** 키워드 추출 */
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
           
        ];

        $resp = $this->postJson('https://api.openai.com/v1/chat/completions', $payload, [
            "Authorization: Bearer {$this->openaiKey}",
            "Content-Type: application/json"
        ]);

        $content = isset($resp['choices'][0]['message']['content']) ? $resp['choices'][0]['message']['content'] : '';
        if (!$content) return [];

        $json = trim(preg_replace('/```json|```/i', '', $content));
        $obj = json_decode($json, true);
        $arr = isset($obj['keywords']) ? $obj['keywords'] : [];

        $out = [];
        foreach ((array)$arr as $kw) {
            $kw = strtolower(trim((string)$kw));
            if ($kw !== '' && preg_match('/^[a-z-]+$/', $kw)) $out[] = $kw;
        }
        return array_slice(array_values(array_unique($out)), 0, 2);
    }

    public function fallbackKeywords(string $text): array
    {
        $words = preg_split('/\s+/', mb_strtolower($text));
        $words = array_filter($words, function ($w) { return preg_match('/^[a-z가-힣]+$/u', $w); });
        return array_slice(array_values(array_unique($words)), 0, 2);
    }

    public function fetchPixabayImages(array $keywords, int $n=2): array
    {
        $query = implode(' ', $keywords);
        $url = "https://pixabay.com/api/?key={$this->pixabayKey}&q=".urlencode($query)."&per_page=20&orientation=vertical&safesearch=true";
        $json = $this->getJson($url);
        $hits = isset($json['hits']) ? $json['hits'] : [];
        if (!$hits) return [];
        shuffle($hits);
        $sel = array_slice($hits, 0, $n);
        return array_values(array_filter(array_map(
            function ($h) { return isset($h['largeImageURL']) ? $h['largeImageURL'] : (isset($h['webformatURL']) ? $h['webformatURL'] : null); },
            $sel
        )));
    }

    public function downloadTempFiles(array $urls): array
    {
        $files = [];
        foreach ($urls as $u) {
            $data = @file_get_contents($u);
            if ($data === false) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'px_');
            file_put_contents($tmp, $data);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_buffer($finfo, $data) : 'image/jpeg';
            if ($finfo) finfo_close($finfo);
            $ext   = ($mime === 'image/png') ? '.png' : '.jpg';
            $new   = $tmp.$ext;
            rename($tmp, $new);
            $files[] = $new;
        }
        return $files;
    }


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

    // 필수 필드
    $add('model', $model);
    $add('prompt', $prompt);
    $add('n', '1');
    $add('size', '1024x1536'); // 세로. 가로는 1536x1024, 정사각형은 1024x1024
    // ❌ response_format 제거 (gpt-image-1은 미지원)

    // 여러 장이면 반드시 image[]
    foreach ($imagePaths as $p) {
        if (!is_file($p)) continue;
        $bin  = file_get_contents($p);
        $name = basename($p);
        $type = (substr($name, -4) === '.png') ? 'image/png' : 'image/jpeg';
        $add('image[]', $bin, $name, $type);
    }

    $body .= "--{$boundary}--\r\n";

    $res  = $this->rawPost('https://api.openai.com/v1/images/edits', $body, $headers);
    $code = $res['code'];
    $txt  = $res['body'];

    if ($code >= 400) {
        throw new \RuntimeException("OpenAI error {$code}: ".$txt);
    }

    $json = json_decode($txt, true);

    // ✅ 두 형태 모두 지원 (url 우선, 없으면 b64_json)
    $data = $json['data'][0] ?? null;
    $url  = $data['url'] ?? null;
    $b64  = $data['b64_json'] ?? null;

    if ($url) return $url;
    if ($b64) return 'data:image/png;base64,'.$b64;

    // 예외 케이스 디버깅을 위해 본문 일부 남김 (운영에서는 로그로)
    return null;
}



    public function buildImagePrompt(string $userText): string
    {
        return <<<PROMPT
이미지 생성 지침:
1) 전시회 포스터에 적합한 "배경 이미지"를 생성합니다.
2) 사용자의 스타일 요구를 정확히 반영하십시오.
3) 전문적인 디자인 관점에서 조화와 균형을 유지하십시오.
4) 텍스트나 과도한 요소는 제외하십시오.

요청사항: {$userText}
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
        return json_decode($res, true) ?: [];
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
        return json_decode($res, true) ?: [];
    }
}
