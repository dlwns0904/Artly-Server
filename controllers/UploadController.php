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
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         type="object",
     *         @OA\Property(
     *           property="image", type="string", format="binary",
     *           description="업로드할 이미지 파일 (jpg/png/webp/gif)"
     *         ),
     *         @OA\Property(
     *           property="category", type="string",
     *           description="저장 카테고리 (기본: image, 'artCatalog'면 media/artCatalog에 저장)",
     *           example="artCatalog"
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
    // 인증 X면 삭제
    $this->auth->authenticate();

    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === false) {
        http_response_code(400);
        echo json_encode(['message' => 'multipart/form-data 로 업로드해주세요.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (empty($_FILES['image'])) {
        http_response_code(400);
        echo json_encode(['message' => 'image 파일이 필요합니다. (form field name: image 또는 image[])'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ✅ category 파라미터 처리 (기본: image, artCatalog면 artCatalog)
    $category = $_POST['category'] ?? null;
    $subdir   = ($category === 'artCatalog') ? 'artCatalog' : 'image';

    try {
        $fileField = $_FILES['image'];

        // 1) 여러 장 업로드: image[] 로 들어온 경우 (name이 배열)
        if (is_array($fileField['name'])) {
            $urls = [];

            $count = count($fileField['name']);
            for ($i = 0; $i < $count; $i++) {
                // 각 파일을 saveUploadedImage 형식에 맞게 재구성
                $file = [
                    'name'     => $fileField['name'][$i],
                    'type'     => $fileField['type'][$i],
                    'tmp_name' => $fileField['tmp_name'][$i],
                    'error'    => $fileField['error'][$i],
                    'size'     => $fileField['size'][$i],
                ];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    // 하나라도 실패하면 건너뛰거나, 여기서 에러 리턴할지 정책 선택 가능
                    // 일단은 건너뛰는 예시:
                    continue;
                }

                $relative = $this->saveUploadedImage($file, $subdir);
                $absolute = $this->toAbsoluteUrl($relative);
                $urls[]   = $absolute;
            }

            if (empty($urls)) {
                http_response_code(500);
                echo json_encode(['message' => '업로드에 성공한 이미지가 없습니다.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // ✅ 여러 장일 때 응답: urls 배열
            echo json_encode(['urls' => $urls], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2) 단일 업로드: 기존 방식 (image 하나)
        $relative = $this->saveUploadedImage($fileField, $subdir);
        $absolute = $this->toAbsoluteUrl($relative);

        // 단일일 때는 기존과 호환되게 url만 반환
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
