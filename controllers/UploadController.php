<?php
namespace Controllers;

use Middlewares\AuthMiddleware;
use OpenApi\Annotations as OA;

class UploadController
{
    private $auth;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
    }

    /** 절대 URL 빌더 */
    private function toAbsoluteUrl(string $relative): string
    {
        $clean  = ltrim($relative, '/'); // "media/..." or "/media/..." 모두 허용
        $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
            ? $_SERVER['HTTP_X_FORWARDED_PROTO']
            : (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http');
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/' . $clean;
    }

    /** 이미지 저장 (subdir 기본: image) → DB용 상대경로 반환 */
    private function saveUploadedImage(array $file, string $subdir = 'image'): string
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('업로드 실패');
        }

        // (선택) 용량 제한 10MB
        $maxBytes = 10 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('파일 용량이 제한(10MB)을 초과했습니다.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            throw new \InvalidArgumentException('허용되지 않는 이미지 형식입니다 (jpg/png/webp/gif)');
        }

        $ym   = date('Y/m');
        $base = __DIR__ . '/../media/' . $subdir . '/' . $ym; // 실제 서버 경로
        $rel  = 'media/' . $subdir . '/' . $ym;               // 상대 경로

        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('이미지 디렉토리 생성 실패');
        }

        $name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        $dest = $base . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('이미지 저장 실패');
        }
        @chmod($dest, 0644);

        return $rel . '/' . $name; // 예: media/image/2025/11/abcd1234.png
    }

    /**
     * @OA\Post(
     *   path="/api/upload/image",
     *   summary="Tiptap 에디터용 단일 이미지 업로드",
     *   tags={"Upload"},
     *   security={{"bearerAuth":{}}},  // 공개 업로드면 이 줄 삭제
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         type="object",
     *         @OA\Property(
     *           property="image", type="string", format="binary",
     *           description="업로드할 이미지 파일 (jpg/png/webp/gif)"
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="성공",
     *     @OA\JsonContent(
     *       @OA\Property(property="url", type="string", example="https://example.com/media/image/2025/11/xxx.png")
     *     )
     *   ),
     *   @OA\Response(response=400, description="요청 오류"),
     *   @OA\Response(response=401, description="인증 필요"),
     *   @OA\Response(response=415, description="허용되지 않는 형식"),
     *   @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function uploadImage()
    {
        // 인증을 걸고 싶지 않다면 다음 1줄을 삭제
        $this->auth->authenticate();

        header('Content-Type: application/json; charset=utf-8');

        if (empty($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === false) {
            http_response_code(400);
            echo json_encode(['message' => 'multipart/form-data 로 업로드해주세요.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($_FILES['image'])) {
            http_response_code(400);
            echo json_encode(['message' => 'image 파일이 필요합니다. (form field name: image)'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // media/image 하위에 저장
            $relative = $this->saveUploadedImage($_FILES['image'], 'image');
            $absolute = $this->toAbsoluteUrl($relative);

            // 요구사항: 업로드된 이미지 URL만 JSON으로 반환
            echo json_encode(['url' => $absolute], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(415);
            echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => '알 수 없는 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
        }
    }
}
