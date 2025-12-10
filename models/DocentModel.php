<?php
namespace Models;

use PDO;

class DocentModel
{
     /** @var PDO */
    private $pdo;

    /** @var string */
    private $mediaBaseDir;

    /** @var string|null */
    private $googleApiKey;

    /** @var string|null */
    private $hedraApiKey;

    private const HEDRA_BASE_URL = 'https://api.hedra.com/web-app/public';
    private const HEDRA_MODEL_ID = 'd1dd37a3-e39a-4854-a298-6510289f9cf2';

    public function __construct()
    {
        $config = require __DIR__ . '/../config/config.php';

        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $base = realpath(__DIR__ . '/../media');
        $this->mediaBaseDir = $base ?: (__DIR__ . '/../media');

        $this->googleApiKey = getenv('GOOGLE_API_KEY') ?: null;
        $this->hedraApiKey  = getenv('HEDRA_API_KEY') ?: null;
    }

    /**
     * 도슨트 생성 (audio | video)
     *
     * @param int         $artId
     * @param string      $type            'audio' | 'video'
     * @param string|null $script          도슨트 텍스트 (없으면 DB 내용 사용)
     * @param string|null $avatarImageUrl  아바타 이미지 URL (video용 옵션)
     * @param string|null $artName         파일명에 넣을 작품명 (없으면 art_title 사용)
     * @return array{mode:string,audio_path:?string,video_path:?string}
     * @throws \Exception
     */
    public function generateDocent(
        int $artId,
        string $type,
        ?string $script,
        ?string $avatarImageUrl,
        ?string $artName = null
    ): array {
        $art = $this->findArtById($artId);
        if (!$art) {
            throw new \Exception('Artwork not found', 404);
        }

        // script 없으면: curator가 따로 적어둔 art_docent → 없으면 art_description 사용
        if (!$script) {
            $script = $art['art_docent']
                ?? $art['art_description']
                ?? null;

            if (!$script) {
                throw new \Exception('Script text is required to generate docent.');
            }
        }

        // 파일명에 넣을 작품명 (없으면 art_title 기준)
        if (!$artName) {
            $artName = $art['art_title'] ?? '';
        }

        $type = ($type === 'video') ? 'video' : 'audio';

        // ---------- 1) audio 모드: Google TTS만 ---------- //
        if ($type === 'audio') {
            $audioRelative = $this->generateTtsMp3($artId, $script, $artName);

            // audio path + script 저장
            $this->updateArtDocentPaths($artId, $audioRelative, null, $script);

            return [
                'mode'       => 'audio',
                'audio_path' => $audioRelative,
                'video_path' => null,
            ];
        }

        // ---------- 2) video 모드: Hedra + TTS ---------- //
        if (!$this->hedraApiKey) {
            throw new \Exception('Hedra API key is not configured.');
        }

        // (1) 사용할 이미지 파일 경로
        $imageFilePath = $this->getArtImageFilePath($art, $avatarImageUrl);
        if (!is_file($imageFilePath)) {
            throw new \Exception('Artwork image file not found: ' . $imageFilePath);
        }

        // (2) TTS mp3 생성 (video에서도 audio 같이 저장)
        $audioRelative = $this->generateTtsMp3($artId, $script, $artName);
        $audioFilePath = $this->mediaBaseDir . '/' . $audioRelative;
        if (!is_file($audioFilePath)) {
            throw new \Exception('Generated audio file not found: ' . $audioFilePath);
        }

        // (3) Hedra에 이미지/오디오 asset 업로드 → id 얻기
        $imageAssetId = $this->hedraUploadAsset($imageFilePath, basename($imageFilePath), 'image');
        $audioAssetId = $this->hedraUploadAsset($audioFilePath, basename($audioFilePath), 'audio');

        // (4) 영상 생성 generation 생성
        $generationId = $this->hedraCreateVideoGeneration(
            $imageAssetId,
            $audioAssetId,
            $script
        );

        // (5) /generations/{id}/status 폴링 → 최종 video url
        $videoUrl = $this->hedraWaitForVideoUrl($generationId);

        // (6) 우리 서버 media/docent/video 에 mp4 저장
        $dir = $this->mediaBaseDir . '/docent/video';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $slug = $this->slugify($artName);
        $fileName = $artId
            . ($slug ? '_' . $slug : '')
            . '_' . time()
            . '.mp4';

        $fullPath      = $dir . '/' . $fileName;
        $videoRelative = 'docent/video/' . $fileName;

        $this->downloadFile($videoUrl, $fullPath);

        // (7) DB에 audio + video + script 저장
        $this->updateArtDocentPaths($artId, $audioRelative, $videoRelative, $script);

        return [
            'mode'       => 'video',
            'audio_path' => $audioRelative,
            'video_path' => $videoRelative,
        ];
    }

    /** GET /api/docents/{id} 용 */
    public function getDocent(int $artId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT docent_audio_path, docent_video_path
            FROM APIServer_art
            WHERE id = :id
        ");
        $stmt->execute([':id' => $artId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ================= 내부 유틸 ================= //

    private function findArtById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_art WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * docent_audio_path / docent_video_path / art_docent / update_dtm 갱신
     */
    private function updateArtDocentPaths(
        int $id,
        ?string $audioPath,
        ?string $videoPath,
        ?string $script = null
    ): void {
        $sql    = "UPDATE APIServer_art SET update_dtm = NOW()";
        $params = [':id' => $id];

        if ($audioPath !== null) {
            $sql .= ", docent_audio_path = :audioPath";
            $params[':audioPath'] = $audioPath;
        }
        if ($videoPath !== null) {
            $sql .= ", docent_video_path = :videoPath";
            $params[':videoPath'] = $videoPath;
        }
        if ($script !== null) {
            $sql .= ", art_docent = :docent";
            $params[':docent'] = $script;
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * 사용할 이미지 파일 경로
     * - avatarImageUrl 이 http(s)면 media/docent/tmp 에 다운로드
     * - /media/... 형식이면 mediaBaseDir 기준 경로로 해석
     * - 없으면 APIServer_art.art_image 사용
     */
    private function getArtImageFilePath(array $art, ?string $avatarImageUrl): string
    {
        // 1) 클라이언트가 절대 URL로 아바타 이미지를 넘긴 경우
        if ($avatarImageUrl && preg_match('#^https?://#', $avatarImageUrl)) {
            $tmpDir = $this->mediaBaseDir . '/docent/tmp';
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0775, true);
            }
            $path     = parse_url($avatarImageUrl, PHP_URL_PATH) ?? '';
            $ext      = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = 'avatar_' . md5($avatarImageUrl) . '.' . $ext;
            $fullPath = $tmpDir . '/' . $fileName;

            if (!is_file($fullPath)) {
                $this->downloadFile($avatarImageUrl, $fullPath);
            }
            return $fullPath;
        }

        // 2) /media/... 혹은 상대 경로가 들어온 경우
        if ($avatarImageUrl && !preg_match('#^https?://#', $avatarImageUrl)) {
            $relative = preg_replace('#^/media/#', '', $avatarImageUrl);
            $fullPath = $this->mediaBaseDir . '/' . ltrim($relative, '/');
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }

        // 3) 기본: 작품 대표 이미지 art_image 사용
        if (!empty($art['art_image'])) {
            $relative = preg_replace('#^/media/#', '', $art['art_image']); // 혹시 /media/ 로 시작하면 제거
            $fullPath = $this->mediaBaseDir . '/' . ltrim($relative, '/');
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }

        throw new \Exception('No valid image found for docent video.');
    }

    /**
     * 파일명에 들어가기 적당한 슬러그 생성
     */
    private function slugify(?string $text): string
    {
        if (!$text) return '';

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/', '_', $text);
        $text = preg_replace('/[^0-9a-zA-Z가-힣_\-]/u', '', $text);

        if (mb_strlen($text, 'UTF-8') > 40) {
            $text = mb_substr($text, 0, 40, 'UTF-8');
        }

        return $text;
    }

    /**
     * Google TTS 로 mp3 생성 후 media/docent/mp3 에 저장
     * @return string media 기준 상대경로 (예: docent/mp3/1_작품명_타임스탬프.mp3)
     */
    private function generateTtsMp3(int $artId, string $script, ?string $artName): string
    {
        if (!$this->googleApiKey) {
            throw new \Exception('Google TTS API key is not configured.');
        }

        $audioContentBase64 = $this->callGoogleTts($script);

        $dir = $this->mediaBaseDir . '/docent/mp3';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $slug = $this->slugify($artName);
        $fileName = $artId
            . ($slug ? '_' . $slug : '')
            . '_' . time()
            . '.mp3';

        $fullPath = $dir . '/' . $fileName;
        file_put_contents($fullPath, base64_decode($audioContentBase64));

        return 'docent/mp3/' . $fileName;
    }

    // ---------- Google TTS 호출 ---------- //

    private function callGoogleTts(string $script): string
    {
        $url = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . $this->googleApiKey;

        $body = [
            'input' => [ 'text' => $script ],
            'voice' => [
                'languageCode' => 'ko-KR',
                'name'         => 'ko-KR-Wavenet-C',
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Google TTS request failed: ' . $err);
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!isset($data['audioContent'])) {
            throw new \Exception('Google TTS response error: ' . $response);
        }

        return $data['audioContent']; // base64
    }

    // ---------- Hedra: Asset 업로드 + 영상 생성 ---------- //

    /**
     * Hedra에 파일을 asset 로 업로드하고 asset id 반환
     * $type: image | audio
     */
    private function hedraUploadAsset(string $filePath, string $name, string $type): string
    {
        if (!$this->hedraApiKey) {
            throw new \Exception('Hedra API key is not configured.');
        }
        if (!is_file($filePath)) {
            throw new \Exception('File not found for Hedra upload: ' . $filePath);
        }

        // 1) Create Asset
        $createUrl = self::HEDRA_BASE_URL . '/assets';
        $body = [
            'name' => $name,
            'type' => $type, // image or audio
        ];

        $ch = curl_init($createUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->hedraApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Hedra Create Asset failed: ' . $err);
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (empty($data['id'])) {
            throw new \Exception('Hedra Create Asset response error: ' . $response);
        }
        $assetId = $data['id'];

        // 2) Upload Asset
        $uploadUrl = self::HEDRA_BASE_URL . '/assets/' . $assetId . '/upload';

        $mime = function_exists('mime_content_type')
            ? (mime_content_type($filePath) ?: 'application/octet-stream')
            : 'application/octet-stream';

        $cfile = curl_file_create($filePath, $mime, $name);

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->hedraApiKey,
                // Content-Type는 multipart/form-data 를 curl 이 자동 설정
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => ['file' => $cfile],
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Hedra Upload Asset failed: ' . $err);
        }
        curl_close($ch);

        // 필요하면 응답 내용 검사
        return $assetId;
    }

    /**
     * Hedra /generations 로 영상 생성 job 생성 → generation_id 반환
     */
    private function hedraCreateVideoGeneration(
        string $imageAssetId,
        string $audioAssetId,
        ?string $script
    ): string {
        $url = self::HEDRA_BASE_URL . '/generations';

        $body = [
            'type'            => 'video',
            'ai_model_id'     => self::HEDRA_MODEL_ID,
            'start_keyframe_id' => $imageAssetId,
            'audio_id'        => $audioAssetId,
            'generated_video_inputs' => [
                'text_prompt' =>
                    $script
                        ? 'A person friendly speaking to the audience. Korean narration: '
                          . mb_substr($script, 0, 80, 'UTF-8')
                        : 'A person friendly speaking to the audience.',
                'resolution'   => '720p',
                'aspect_ratio' => '16:9',
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->hedraApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Hedra Generate Asset failed: ' . $err);
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (empty($data['id'])) {
            throw new \Exception('Hedra Generate Asset response error: ' . $response);
        }

        return $data['id']; // generation_id
    }

    /**
     * Hedra /generations/{id}/status 폴링해서 최종 영상 URL 반환
     */
    private function hedraWaitForVideoUrl(
        string $generationId,
        int $maxTries = 20,
        int $intervalSec = 5
    ): string {
        $url = self::HEDRA_BASE_URL . '/generations/' . urlencode($generationId) . '/status';

        for ($i = 0; $i < $maxTries; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => [
                    'X-API-Key: ' . $this->hedraApiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new \Exception('Hedra Get Status failed: ' . $err);
            }
            curl_close($ch);

            $data   = json_decode($response, true);
            $status = $data['status'] ?? null;

            if ($status === 'complete') {
                $videoUrl = $data['url'] ?? $data['download_url'] ?? null;
                if (!$videoUrl) {
                    throw new \Exception('Hedra status complete but video url is empty: ' . $response);
                }
                return $videoUrl;
            }

            if ($status === 'error') {
                $msg = $data['error_message'] ?? 'unknown error';
                throw new \Exception('Hedra video generation error: ' . $msg);
            }

            // processing / queued 등 → 잠깐 대기 후 재시도
            sleep($intervalSec);
        }

        throw new \Exception('Hedra video generation did not complete in time.');
    }

    private function downloadFile(string $url, string $fullPath): void
    {
        $fp = fopen($fullPath, 'w+');
        if ($fp === false) {
            throw new \Exception('Cannot open file for writing: ' . $fullPath);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $success = curl_exec($ch);
        if ($success === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            throw new \Exception('Download failed: ' . $err);
        }

        curl_close($ch);
        fclose($fp);
    }
}