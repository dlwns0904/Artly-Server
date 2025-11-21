<?php
namespace Controllers;

use Models\ExhibitionModel;
use Models\UserModel;
use Models\GalleryModel;
use Middlewares\AuthMiddleware;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Exhibition",
 *     description="전시회 관련 API"
 * )
 */
class ExhibitionController {
    private $model;
    private $userModel;
    private $galleryModel;
    private $auth;

    public function __construct() {
        $this->model = new ExhibitionModel();
        $this->userModel = new UserModel();
        $this->galleryModel = new GalleryModel();
        $this->auth = new AuthMiddleware();
    }

    /** 외부 URL 여부 */
    private function isExternalUrl(?string $val): bool {
        return is_string($val) && preg_match('#^https?://#i', $val);
    }

    /** 상대경로 -> 절대 URL 변환 */
    private function toAbsoluteUrl(?string $path): ?string {
        if (!$path) return null;
        if ($this->isExternalUrl($path)) return $path;
        $clean  = ltrim($path, '/'); // media/... or /media/...
        $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
            ? $_SERVER['HTTP_X_FORWARDED_PROTO']
            : (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http');
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/' . $clean;
    }

    /** 이미지 저장 (media/exhibition/YYYY/MM) 후 DB용 상대경로 반환 */
    private function saveUploadedImage(array $file, string $subdir = 'exhibition'): string {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('포스터 업로드 실패');
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
        $base = __DIR__ . '/../media/' . $subdir . '/' . $ym; // 서버 실제 경로
        $rel  = 'media/' . $subdir . '/' . $ym;               // DB 저장 상대경로

        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('이미지 디렉토리 생성 실패');
        }

        $ext  = $allowed[$mime];
        $name = bin2hex(random_bytes(8)) . '.' . $ext;

        $dest = $base . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('이미지 저장 실패');
        }
        @chmod($dest, 0644);
        return $rel . '/' . $name; // 예: media/exhibition/2025/11/abcd1234.png
    }

    /**
     * @OA\Get(
     *     path="/api/exhibitions",
     *     summary="전시회 목록 조회",
     *     tags={"Exhibition"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", description="scheduled/exhibited/ended", @OA\Schema(type="string")),
     *     @OA\Parameter(name="category", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="region", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort", in="query", description="latest/ending/popular", @OA\Schema(type="string")),
     *     @OA\Parameter(name="liked_only", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="gallery_name", in="query", description="갤러리명 검색", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="전시회 목록 조회 성공")
     * )
     */
    public function getExhibitionList() {
        $auth = new AuthMiddleware();
        $decoded = $auth->decodeToken();
        $user_id = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;
        $likedOnly = $_GET['liked_only'] ?? null;
        $likedOnlyBool = filter_var($likedOnly, FILTER_VALIDATE_BOOLEAN);

        if ($likedOnlyBool && !$user_id) {
            http_response_code(401);
            echo json_encode(['message' => 'Authentication required for liked_only filter.']);
            return;
        }

        $filters = [
            'status'     => $_GET['status'] ?? null,
            'category'   => $_GET['category'] ?? null,
            'region'     => $_GET['region'] ?? null,
            'sort'       => $_GET['sort'] ?? null,
            'liked_only' => $likedOnly,
            'user_id'    => $user_id,
            'search'     => $_GET['search'] ?? null
        ];

        if (!empty($_GET['gallery_name'])) {
            $galleryList = $this->galleryModel->getGalleries(['search' => $_GET['gallery_name']]);
            if (!empty($galleryList)) {
                $filters['gallery_id'] = $galleryList[0]['id'];
            } else {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['message' => '해당 이름의 갤러리를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return;
            }
        }

        $exhibitions = $this->model->getExhibitions($filters);

        // ✅ 포스터/조직 이미지 절대 URL 변환
        foreach ($exhibitions as &$e) {
            if (isset($e['exhibition_poster'])) {
                $e['exhibition_poster'] = $this->toAbsoluteUrl($e['exhibition_poster']);
            }
            if (isset($e['exhibition_organization']['image'])) {
                $e['exhibition_organization']['image'] = $this->toAbsoluteUrl($e['exhibition_organization']['image']);
            }
        }
        unset($e);

        header('Content-Type: application/json');
        echo json_encode($exhibitions, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Get(
     *     path="/api/exhibitions/{id}",
     *     summary="전시회 상세 조회",
     *     tags={"Exhibition"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="전시회 상세 조회 성공"),
     *     @OA\Response(response=404, description="전시회 없음")
     * )
     */
    public function getExhibitionById($id) {
    $user_id = AuthMiddleware::getUserId();
    $exhibition = $this->model->getExhibitionDetailById($id, $user_id);

    if (!$exhibition) {
        http_response_code(404);
        echo json_encode(['message' => 'Exhibition not found']);
        return;
    }

    //갤러리 상세 붙이기
    if (!empty($exhibition['gallery_id'])) {
        // 사용자 ID 없이 호출 (동일 동작 유지)
        $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
        // 갤러리 이미지도 절대 URL로 변환
        if (is_array($gallery) && isset($gallery['gallery_image'])) {
            $gallery['gallery_image'] = $this->toAbsoluteUrl($gallery['gallery_image']);
        }
        $exhibition['gallery'] = $gallery;
    } else {
        $exhibition['gallery'] = null;
    }

    // 전시 포스터 절대 URL 변환
    if (isset($exhibition['exhibition_poster'])) {
        $exhibition['exhibition_poster'] = $this->toAbsoluteUrl($exhibition['exhibition_poster']);
    }

    header('Content-Type: application/json');
    echo json_encode($exhibition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

    /**
     * @OA\Post(
     *   path="/api/exhibitions",
     *   summary="전시회 등록 (multipart 또는 JSON)",
     *   tags={"Exhibition"},
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         type="object",
     *         @OA\Property(property="gallery_id", type="integer", example=123),
     *         @OA\Property(property="exhibition_title", type="string"),
     *         @OA\Property(property="exhibition_category", type="string"),
     *         @OA\Property(property="exhibition_start_date", type="string", format="date"),
     *         @OA\Property(property="exhibition_end_date", type="string", format="date"),
     *         @OA\Property(property="exhibition_start_time", type="string", format="time"),
     *         @OA\Property(property="exhibition_end_time", type="string", format="time"),
     *         @OA\Property(property="exhibition_location", type="string"),
     *         @OA\Property(property="exhibition_price", type="integer"),
     *         @OA\Property(property="exhibition_tag", type="string"),
     *         @OA\Property(property="exhibition_status", type="string", enum={"scheduled","exhibited","ended"}),
     *         @OA\Property(property="exhibition_phone", type="string"),
     *         @OA\Property(property="exhibition_homepage", type="string"),
     *         @OA\Property(property="exhibition_poster_file", type="string", format="binary"),
     *         @OA\Property(property="exhibition_poster_url", type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=201, description="등록 성공")
     * )
     */
    public function createExhibition() {
    $user = $this->auth->authenticate(); // JWT 검사만 유지

    // 요청 형태 판단
    $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;

    // 공통 데이터 파싱
    if ($isMultipart) {
        // ✅ gallery_id 프론트에서 받기 (필수)
        $gallery_id = isset($_POST['gallery_id']) ? (int)$_POST['gallery_id'] : 0;
        if ($gallery_id <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'gallery_id는 필수입니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $data = [
            'exhibition_title'      => $_POST['exhibition_title']      ?? null,
            'exhibition_category'   => $_POST['exhibition_category']   ?? null,
            'exhibition_start_date' => $_POST['exhibition_start_date'] ?? null,
            'exhibition_end_date'   => $_POST['exhibition_end_date']   ?? null,
            'exhibition_start_time' => $_POST['exhibition_start_time'] ?? null,
            'exhibition_end_time'   => $_POST['exhibition_end_time']   ?? null,
            'exhibition_location'   => $_POST['exhibition_location']   ?? null,
            'exhibition_price'      => $_POST['exhibition_price']      ?? null,
            'exhibition_tag'        => $_POST['exhibition_tag']        ?? null,
            'exhibition_status'     => $_POST['exhibition_status']     ?? null,
            'exhibition_phone'      => $_POST['exhibition_phone']      ?? null,
            'exhibition_homepage'   => $_POST['exhibition_homepage']   ?? null,
        ];

        // 파일 우선 저장, 없으면 URL
        if (!empty($_FILES['exhibition_poster_file']) && $_FILES['exhibition_poster_file']['error'] === UPLOAD_ERR_OK) {
            $relPath = $this->saveUploadedImage($_FILES['exhibition_poster_file'], 'exhibition');
            $data['exhibition_poster'] = $relPath; // DB엔 상대경로 저장
        } else {
            $url = $_POST['exhibition_poster_url'] ?? null;
            $data['exhibition_poster'] = $url ?: null;
        }
    } else {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        // ✅ gallery_id 프론트에서 받기 (필수)
        $gallery_id = isset($raw['gallery_id']) ? (int)$raw['gallery_id'] : 0;
        if ($gallery_id <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'gallery_id는 필수입니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $data = $raw;
    }

    // 생성
    $createdExhibition = $this->model->create($data, $gallery_id);
    if ($createdExhibition) {
        // 응답 시 포스터 절대 URL 변환
        $createdExhibition['exhibition_poster'] = $this->toAbsoluteUrl($createdExhibition['exhibition_poster'] ?? null);
        http_response_code(201);
        echo json_encode(['message' => 'Exhibition created successfully', 'data' => $createdExhibition], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to create exhibition']);
    }
}


    /**
     * @OA\Post(
     * path="/api/exhibitions/{id}",
     * summary="[PATCH with method spoofing] 전시회 일부 수정 (multipart 또는 JSON)",
     * tags={"Exhibition"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     * description="[매우중요] multipart/form-data로 파일과 함께 요청 시, 실제로는 POST 메서드를 사용하고 본문에 `_method=PATCH` 필드를 포함해야 합니다. (Method Spoofing)",
     * @OA\RequestBody(
     * required=true,
     * description="수정할 필드만 포함하여 전송합니다. (부분 업데이트)",
     * @OA\MediaType(
     * mediaType="multipart/form-data",
     * @OA\Schema(
     * type="object",
     * required={"_method"},
     * @OA\Property(
     * property="_method", 
     * type="string", 
     * enum={"PATCH"}, 
     * example="PATCH", 
     * description="Method Spoofing을 위해 'PATCH' 값을 전송해야 합니다."
     * ),
     * @OA\Property(property="exhibition_title", type="string", nullable=true),
     * @OA\Property(property="exhibition_category", type="string", nullable=true),
     * @OA\Property(property="exhibition_start_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_end_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_start_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_end_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_location", type="string", nullable=true),
     * @OA\Property(property="exhibition_price", type="integer", nullable=true),
     * @OA\Property(property="exhibition_tag", type="string", nullable=true),
     * @OA\Property(property="exhibition_status", type="string", enum={"scheduled","exhibited","ended"}, nullable=true),
     * @OA\Property(property="exhibition_phone", type="string", nullable=true),
     * @OA\Property(property="exhibition_homepage", type="string", nullable=true),
     * @OA\Property(property="exhibition_poster_file", type="string", format="binary", description="새로 업로드할 포스터 파일", nullable=true),
     * @OA\Property(property="exhibition_poster_url", type="string", description="포스터 URL을 직접 지정할 때", nullable=true)
     * )
     * ),
     * @OA\MediaType(
     * mediaType="application/json",
     * @OA\Schema(
     * type="object",
     * @OA\Property(property="exhibition_title", type="string", nullable=true),
     * @OA\Property(property="exhibition_category", type="string", nullable=true),
     * @OA\Property(property="exhibition_start_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_end_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_start_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_end_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_location", type="string", nullable=true),
     * @OA\Property(property="exhibition_price", type="integer", nullable=true),
     * @OA\Property(property="exhibition_tag", type="string", nullable=true),
     * @OA\Property(property="exhibition_status", type="string", enum={"scheduled","exhibited","ended"}, nullable=true),
     * @OA\Property(property="exhibition_phone", type="string", nullable=true),
     * @OA\Property(property="exhibition_homepage", type="string", nullable=true),
     * @OA\Property(property="exhibition_poster_url", type="string", description="포스터 URL을 직접 지정할 때", nullable=true)
     * )
     * )
     * ),
     * @OA\Response(response=200, description="수정 성공"),
     * @OA\Response(response=403, description="권한 없음"),
     * @OA\Response(response=404, description="전시회 없음")
     * )
     */
    public function updateExhibition($id) {
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        $userData   = $this->userModel->getById($userId);
        $exhibition = $this->model->getById($id);

        if (!$exhibition) {
            http_response_code(404);
            echo json_encode(['message' => 'Exhibition not found']);
            exit;
        }

        // [버그] 지금 APIServer_user에 gallery_id 필드가 없는데 조건문이 이렇게 작성되어 있었음!!
        //
        // if ($userData['gallery_id'] != $exhibition['gallery_id']) {
        //     http_response_code(403);
        //     echo json_encode(['message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
        //     exit;
        // }

        $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
        
        $data = []; 

        if ($isMultipart) {
            if (isset($_POST['exhibition_title']) && $_POST['exhibition_title'] !== '') {
                $data['exhibition_title'] = $_POST['exhibition_title'];
            }
            if (isset($_POST['exhibition_category']) && $_POST['exhibition_category'] !== '') {
                $data['exhibition_category'] = $_POST['exhibition_category'];
            }
            if (isset($_POST['exhibition_start_date']) && $_POST['exhibition_start_date'] !== '') {
                $data['exhibition_start_date'] = $_POST['exhibition_start_date'];
            }
            if (isset($_POST['exhibition_end_date']) && $_POST['exhibition_end_date'] !== '') {
                $data['exhibition_end_date'] = $_POST['exhibition_end_date'];
            }
            if (isset($_POST['exhibition_start_time']) && $_POST['exhibition_start_time'] !== '') {
                $data['exhibition_start_time'] = $_POST['exhibition_start_time'];
            }
            if (isset($_POST['exhibition_end_time']) && $_POST['exhibition_end_time'] !== '') {
                $data['exhibition_end_time'] = $_POST['exhibition_end_time'];
            }
            if (isset($_POST['exhibition_location']) && $_POST['exhibition_location'] !== '') {
                $data['exhibition_location'] = $_POST['exhibition_location'];
            }
            if (isset($_POST['exhibition_price']) && $_POST['exhibition_price'] !== '') {
                $data['exhibition_price'] = $_POST['exhibition_price'];
            }
            if (isset($_POST['exhibition_tag']) && $_POST['exhibition_tag'] !== '') {
                $data['exhibition_tag'] = $_POST['exhibition_tag'];
            }
            if (isset($_POST['exhibition_status']) && $_POST['exhibition_status'] !== '') {
                $data['exhibition_status'] = $_POST['exhibition_status'];
            }
            if (isset($_POST['exhibition_phone']) && $_POST['exhibition_phone'] !== '') {
                $data['exhibition_phone'] = $_POST['exhibition_phone'];
            }
            if (isset($_POST['exhibition_homepage']) && $_POST['exhibition_homepage'] !== '') {
                $data['exhibition_homepage'] = $_POST['exhibition_homepage'];
            }

            // 파일 처리 로직 (URL은 빈 문자열을 null로 처리)
            if (!empty($_FILES['exhibition_poster_file']) && $_FILES['exhibition_poster_file']['error'] === UPLOAD_ERR_OK) {
                $relPath = $this->saveUploadedImage($_FILES['exhibition_poster_file'], 'exhibition');
                $data['exhibition_poster'] = $relPath;
            } elseif (isset($_POST['exhibition_poster_url'])) { // URL은 빈 문자열을 'null'로 처리
                $data['exhibition_poster'] = $_POST['exhibition_poster_url'] ?: null;
            }
        } else {
            // JSON 방식 (수정 불필요)
            $jsonData = json_decode(file_get_contents('php://input'), true) ?? [];
            $data = array_merge($data, $jsonData);
        }
        
        // $data가 비어있으면 업데이트할 필요가 없음
        if (empty($data)) {
            http_response_code(200); 
            echo json_encode(['message' => 'No fields to update', 'data' => $exhibition], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $gallery_id = $exhibition['gallery_id'];
        $success = $this->model->update($id, $data, $gallery_id);

        if ($success) {
            $updatedExhibition = $this->model->getById($id);
            if ($updatedExhibition) {
                $updatedExhibition['exhibition_poster'] = $this->toAbsoluteUrl($updatedExhibition['exhibition_poster'] ?? null);
            }
            echo json_encode(['message' => 'Exhibition updated successfully', 'data' => $updatedExhibition], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Exhibition update failed']);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/exhibitions/{id}",
     *     summary="전시회 삭제",
     *     tags={"Exhibition"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="삭제 성공"),
     *     @OA\Response(response=404, description="전시회 없음 또는 삭제 실패")
     * )
     */
    public function deleteExhibition($id) {
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        $userData = $this->userModel->getById($userId);
        $exhibition = $this->model->getById($id);

        if ($userData['gallery_id'] != $exhibition['gallery_id']) {
            http_response_code(403);
            echo json_encode(['message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $success = $this->model->delete($id);
        if ($success) {
            echo json_encode(['message' => 'Exhibition deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Exhibition not found or delete failed']);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/exhibitions/{id}/arts",
     *   summary="전시회 작품 등록",
     *   tags={"Exhibition"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="전시회 ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"art_id","display_order"},
     *       @OA\Property(property="art_id", type="integer"),
     *       @OA\Property(property="display_order", type="integer")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="등록 성공",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Artworks registered successfully"),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="exhibition_id", type="integer"),
     *         @OA\Property(property="art_id", type="integer"),
     *         @OA\Property(property="display_order", type="integer"),
     *         @OA\Property(property="create_dtm", type="string", format="date-time"),
     *         @OA\Property(property="update_dtm", type="string", format="date-time")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthorized"),
     *   @OA\Response(response=403, description="권한이 없습니다."),
     *   @OA\Response(response=500, description="등록 실패")
     * )
     */
    public function registerArts($id) {
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        $userData = $this->userModel->getById($userId);
        $exhibition = $this->model->getById($id);

        if ($userData['gallery_id'] != $exhibition['gallery_id']) {
            http_response_code(403);
            echo json_encode(['message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $registeredArt = $this->model->registerArt($id, $data);

        if ($registeredArt) {
            http_response_code(201);
            echo json_encode(['message' => 'Artworks registered successfully', 'data' => $registeredArt], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to register Artworks']);
        }
    }

        /**
     * @OA\Post(
     *   path="/api/exhibitions/{id}/artworks",
     *   summary="전시회 작가 등록",
     *   tags={"Exhibition"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="전시회 ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"artist_id","role"},
     *       @OA\Property(property="artist_id", type="integer"),
     *       @OA\Property(property="role", type="string")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="등록 성공",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Artist registered successfully"),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="exhibition_id", type="integer"),
     *         @OA\Property(property="artist_id", type="integer"),
     *         @OA\Property(property="role", type="string"),
     *         @OA\Property(property="create_dtm", type="string", format="date-time"),
     *         @OA\Property(property="update_dtm", type="string", format="date-time")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthorized"),
     *   @OA\Response(response=403, description="권한이 없습니다."),
     *   @OA\Response(response=500, description="등록 실패")
     * )
     */
    public function registerArtists($id) {
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        $userData = $this->userModel->getById($userId);
        $exhibition = $this->model->getById($id);

        if ($userData['gallery_id'] != $exhibition['gallery_id']) {
            http_response_code(403);
            echo json_encode(['message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $registeredArtist = $this->model->registerArtist($id, $data);

        if ($registeredArtist) {
            http_response_code(201);
            echo json_encode(['message' => 'Artist registered successfully', 'data' => $registeredArtist], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to register Artist']);
        }
    }
}
