<?php
namespace Controllers;

use Middlewares\AuthMiddleware;
use OpenApi\Annotations as OA;
use Models\LeafletModel;

class UploadController {
    private $auth;
    private $leafletModel;

    public function __construct() {
        $this->auth = new AuthMiddleware();
        $this->leafletModel = new LeafletModel();
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
    public function uploadImage() {
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

    /**
     * @OA\Post(
     * path="/api/leaflet",
     * summary="리플렛 생성 (이미지 업로드 포함)",
     * tags={"Leaflet"},
     * security={{"bearerAuth":{}}},
     * @OA\RequestBody(
     * required=true,
     * @OA\MediaType(
     * mediaType="multipart/form-data",
     * @OA\Schema(
     * type="object",
     * @OA\Property(
     * property="image[]", type="array",
     * @OA\Items(type="string", format="binary"),
     * description="업로드할 이미지 파일들 (다중 선택 가능)"
     * ),
     * @OA\Property(
     * property="title", type="string", description="리플렛 제목"
     * ),
     * @OA\Property(
     * property="category", type="string",
     * description="카테고리 (기본: image)", example="artCatalog"
     * )
     * )
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="리플렛 생성 성공",
     * @OA\JsonContent(
     * @OA\Property(property="id", type="integer", example=1),
     * @OA\Property(property="title", type="string", example="전시회 리플렛"),
     * @OA\Property(property="image_urls", type="array", @OA\Items(type="string")),
     * @OA\Property(property="create_user_id", type="integer", example=10)
     * )
     * ),
     * @OA\Response(response=400, description="요청 오류"),
     * @OA\Response(response=401, description="인증 필요"),
     * @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function createLeaflet() {
        // 1. 인증 및 사용자 ID 확보
        $this->auth->requireAdmin();
        $userId = $this->auth->getUserId(); 

        header('Content-Type: application/json; charset=utf-8');

        // 2. 요청 유효성 검사
        if (empty($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === false) {
            http_response_code(400);
            echo json_encode(['message' => 'multipart/form-data 로 업로드해주세요.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($_FILES['image'])) {
            http_response_code(400);
            echo json_encode(['message' => 'image 파일이 필요합니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 3. 파라미터 받기
        $title    = $_POST['title'] ?? null;
        $category = $_POST['category'] ?? 'image';
        $subdir   = ($category === 'artCatalog') ? 'artCatalog' : 'image';

        $uploadedUrls = [];

        try {
            $fileField = $_FILES['image'];

            // 4. 이미지 업로드 처리
            if (is_array($fileField['name'])) {
                $count = count($fileField['name']);
                for ($i = 0; $i < $count; $i++) {
                    $file = [
                        'name'     => $fileField['name'][$i],
                        'type'     => $fileField['type'][$i],
                        'tmp_name' => $fileField['tmp_name'][$i],
                        'error'    => $fileField['error'][$i],
                        'size'     => $fileField['size'][$i],
                    ];

                    if ($file['error'] !== UPLOAD_ERR_OK) continue;

                    $relative = $this->saveUploadedImage($file, $subdir);
                    $uploadedUrls[] = $this->toAbsoluteUrl($relative);
                }
            } else {
                $relative = $this->saveUploadedImage($fileField, $subdir);
                $uploadedUrls[] = $this->toAbsoluteUrl($relative);
            }

            if (empty($uploadedUrls)) {
                throw new \RuntimeException('업로드된 파일이 없습니다.');
            }

            // 5. 모델 호출 데이터 준비
            $jsonUrls = json_encode($uploadedUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $leafletData = [
                'user_id'    => $userId,
                'title'      => $title,
                'image_urls' => $jsonUrls,
                'category'   => $category
            ];

            // 6. DB 저장 (★ 수정됨: $this->leafletModel 사용)
            $newLeaflet = $this->leafletModel->create($leafletData);

            // 7. 최종 응답
            echo json_encode($newLeaflet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (\InvalidArgumentException $e) {
            http_response_code(415);
            echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => '서버 오류가 발생했습니다: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Patch(
     * path="/api/leaflet/{id}",
     * summary="리플렛 정보 부분 수정 (PATCH)",
     * description="보낸 필드만 수정됩니다. (예: 제목만 보내면 제목만 수정됨) 아무것도 안 보내도 되긴 합니다. 중단조건 설정 되어 있삼",
     * tags={"Leaflet"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(
     * name="id",
     * in="path",
     * required=true,
     * description="수정할 리플렛 ID",
     * @OA\Schema(type="integer")
     * ),
     * @OA\RequestBody(
     * required=true,
     * description="수정할 필드만 JSON으로 전송",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="title", type="string", description="변경할 제목 (선택)", example="수정된 제목"),
     * @OA\Property(property="category", type="string", description="변경할 카테고리 (선택)", example="artCatalog"),
     * @OA\Property(
     * property="image_urls",
     * type="array",
     * description="이미지 순서 변경 시 URL 전체 배열 재전송 (선택)",
     * @OA\Items(type="string"),
     * example={"http://host/media/img1.jpg", "http://host/media/img2.jpg"}
     * )
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="수정 성공 (수정된 리플렛 정보 반환)",
     * @OA\JsonContent(
     * @OA\Property(property="id", type="integer"),
     * @OA\Property(property="title", type="string"),
     * @OA\Property(property="image_urls", type="array", @OA\Items(type="string")),
     * @OA\Property(property="category", type="string"),
     * @OA\Property(property="update_dtm", type="string", format="date-time")
     * )
     * ),
     * @OA\Response(response=400, description="잘못된 요청"),
     * @OA\Response(response=401, description="인증 필요"),
     * @OA\Response(response=403, description="권한 없음"),
     * @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function updateLeaflet($id) {
        // 1. 인증 확인
        $this->auth->authenticate();
        // $currentUserId = $this->auth->getUserId(); // 권한 체크용

        header('Content-Type: application/json; charset=utf-8');

        // 2. 요청 Body (JSON) 읽기
        $inputData = json_decode(file_get_contents('php://input'), true);
        
        // JSON 파싱 에러 체크
        if (json_last_error() !== JSON_ERROR_NONE && !empty(file_get_contents('php://input'))) {
            http_response_code(400);
            echo json_encode(['message' => '잘못된 JSON 형식입니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // (권한 체크 로직 위치)

            // 3. 모델에 넘길 데이터 준비
            $updateData = [];

            // isset() 대신 array_key_exists() 사용
            if (array_key_exists('title', $inputData) && trim($inputData['title']) !== '') {
                // null이든 빈 문자열("")이든 그대로 업데이트 데이터에 포함
                $updateData['title'] = $inputData['title'];
            }

            if (array_key_exists('category', $inputData) && trim($inputData['category']) !== '') {
                $updateData['category'] = $inputData['category'];
            }

            // 이미지 URL 처리
            if (array_key_exists('image_urls', $inputData) && !empty($inputData['image_urls'])) {
                $imgUrls = $inputData['image_urls'];

                if (is_array($imgUrls)) {
                    // 빈 배열 []이 들어오면 "[]" 문자열로 변환됨 (DB에 JSON형태로 저장 시 적절)
                    $updateData['image_urls'] = json_encode(
                        $imgUrls, 
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                } else {
                    // null이나 다른 값이 들어올 경우
                    $updateData['image_urls'] = $imgUrls;
                }
            }

            // 수정할 내용이 없으면 에러 혹은 현재 상태 반환
            if (empty($updateData)) {
                // PATCH에서는 body가 비어도 에러보다는 '변경 없음'으로 200 OK를 주는 경우도 있지만,
                // 명시적으로 무엇을 바꿀지 요청하지 않았으므로 400을 주는 것도 일반적입니다.
                http_response_code(400);
                echo json_encode(['message' => '수정할 데이터가 없습니다.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 4. 모델 업데이트 호출
            $updatedLeaflet = $this->leafletModel->update($id, $updateData);

            if (!$updatedLeaflet) {
                http_response_code(404);
                echo json_encode(['message' => '업데이트 실패 (존재하지 않는 ID)'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 5. 결과 반환
            echo json_encode($updatedLeaflet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => '서버 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Get(
     * path="/api/leaflet/{id}",
     * summary="리플렛 상세 조회 (ID 기준)",
     * tags={"Leaflet"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(
     * name="id",
     * in="path",
     * required=true,
     * description="조회할 리플렛 ID",
     * @OA\Schema(type="integer")
     * ),
     * @OA\Response(
     * response=200,
     * description="성공",
     * @OA\JsonContent(
     * @OA\Property(property="id", type="integer", example=1),
     * @OA\Property(property="title", type="string", example="전시회 리플렛"),
     * @OA\Property(property="image_urls", type="array", @OA\Items(type="string")),
     * @OA\Property(property="category", type="string", example="artCatalog"),
     * @OA\Property(property="create_user_id", type="integer", example=10),
     * @OA\Property(property="create_dtm", type="string", format="date-time"),
     * @OA\Property(property="update_dtm", type="string", format="date-time")
     * )
     * ),
     * @OA\Response(response=404, description="리플렛을 찾을 수 없음"),
     * @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function getLeafletById($id) {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $leaflet = $this->leafletModel->getById($id);

            if (!$leaflet) {
                http_response_code(404);
                echo json_encode(['message' => '해당 리플렛을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // DB에 저장된 JSON 문자열을 PHP 배열로 변환
            if (isset($leaflet['image_urls']) && is_string($leaflet['image_urls'])) {
                $leaflet['image_urls'] = json_decode($leaflet['image_urls'], true);
            }

            echo json_encode($leaflet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => '서버 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Get(
     * path="/api/leaflet/create_user/{userId}",
     * summary="특정 사용자가 업로드한 리플렛 목록 조회",
     * tags={"Leaflet"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(
     * name="userId",
     * in="path",
     * required=true,
     * description="작성자(User) ID",
     * @OA\Schema(type="integer")
     * ),
     * @OA\Response(
     * response=200,
     * description="성공 (배열 반환)",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(
     * @OA\Property(property="id", type="integer", example=1),
     * @OA\Property(property="title", type="string", example="전시회 리플렛"),
     * @OA\Property(property="image_urls", type="array", @OA\Items(type="string")),
     * @OA\Property(property="category", type="string"),
     * @OA\Property(property="create_dtm", type="string", format="date-time")
     * )
     * )
     * ),
     * @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function getLeafletsByUserId($userId) {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $leaflets = $this->leafletModel->getByCreateUserId($userId);

            // 목록이 비어있어도 빈 배열 [] 리턴 (404 아님)
            if (empty($leaflets)) {
                echo json_encode([], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 목록 전체를 순회하며 image_urls JSON 디코딩 처리
            foreach ($leaflets as &$item) {
                if (isset($item['image_urls']) && is_string($item['image_urls'])) {
                    $item['image_urls'] = json_decode($item['image_urls'], true);
                }
            }
            // 참조 해제 (foreach 참조 사용 시 권장)
            unset($item);

            echo json_encode($leaflets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => '서버 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }


    /**
     * @OA\Delete(
     * path="/api/leaflet/{id}",
     * summary="리플렛 삭제",
     * description="ID를 기준으로 특정 리플렛 정보를 삭제합니다.",
     * tags={"Leaflet"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(
     * name="id",
     * in="path",
     * required=true,
     * description="삭제할 리플렛 ID",
     * @OA\Schema(type="integer")
     * ),
     * @OA\Response(
     * response=200,
     * description="삭제 성공",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="message", type="string", example="리플렛이 성공적으로 삭제되었습니다."),
     * @OA\Property(property="id", type="integer", example=15)
     * )
     * ),
     * @OA\Response(
     * response=404,
     * description="삭제 실패 (존재하지 않는 ID)",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="message", type="string", example="삭제 실패: 존재하지 않는 리플렛 ID입니다.")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="서버 오류",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="message", type="string", example="서버 오류: ...")
     * )
     * )
     * )
     */
    public function deleteLeaflet($id) {
        // 1. CORS 및 Method 확인 (라우터에서 처리 안 했을 경우 안전장치)
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405); // Method Not Allowed
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['message' => '허용되지 않은 메서드입니다. (DELETE만 가능)'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. 인증 확인
        try {
            $this->auth->authenticate();
        } catch (\Exception $e) {
            // auth 클래스 내부에서 에러를 던진다면 여기서 잡아서 처리
            http_response_code(401);
            echo json_encode(['message' => '인증 실패'], JSON_UNESCAPED_UNICODE);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');

        // ID 유효성 검사 (빈 값 체크)
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['message' => '삭제할 ID가 전달되지 않았습니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // 3. 모델 삭제 호출 (앞서 만든 delete 함수 사용)
            $isDeleted = $this->leafletModel->delete($id);

            if ($isDeleted) {
                // 삭제 성공
                http_response_code(200); 
                echo json_encode(['message' => '리플렛이 성공적으로 삭제되었습니다.', 'id' => $id], JSON_UNESCAPED_UNICODE);
            } else {
                // 삭제 실패 (ID가 없거나 이미 삭제됨)
                http_response_code(404);
                echo json_encode(['message' => '삭제 실패: 존재하지 않는 리플렛 ID입니다.'], JSON_UNESCAPED_UNICODE);
            }

        } catch (\Throwable $e) {
            // 서버 내부 오류
            http_response_code(500);
            echo json_encode(['message' => '서버 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
