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

    /**
     * @OA\Get(
     *     path="/api/exhibitions",
     *     summary="전시회 목록 조회",
     *     tags={"Exhibition"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="전시회 상태 (scheduled: 예정, exhibited: 진행중, ended: 종료)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="category",
     *         in="query",
     *         description="전시회 카테고리 (회화, 미디어, 디자인, 사진, 키즈아트, 특별전시, 조각, 설치미술, 공예, 소장품전, 테마전, 기획전)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="region",
     *         in="query",
     *         description="전시회 지역 (서울, 경기, 인천, 대구, 경북, 부산, 울산, 경남, 광주, 전라, 대전, 충청, 세종, 제주, 강원)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         description="정렬 순서 (latest: 최신순, ending: 종료임박순, popular: 좋아요순)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="liked_only",
     *         in="query",
     *         description="좋아요한 전시만 필터 (true일 경우 내가 좋아요한 전시만 표시)",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *        name="search",
     *        in="query",
     *        description="검색어",
     *        required=false,
     *        @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *        name="gallery_name",
     *        in="query",
     *        description="갤러리명 기준 검색어",
     *        required=false,
     *        @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="전시회 목록 조회 성공",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="exhibition_title", type="string"),
     *             @OA\Property(property="exhibition_poster", type="string"),
     *             @OA\Property(property="exhibition_category", type="string"),
     *             @OA\Property(property="exhibition_start_date", type="string", format="date"),
     *             @OA\Property(property="exhibition_end_date", type="string", format="date"),
     *             @OA\Property(property="exhibition_start_time", type="string", format="date-time"),
     *             @OA\Property(property="exhibition_end_time", type="string", format="date-time"),
     *             @OA\Property(property="exhibition_location", type="string"),
     *             @OA\Property(property="exhibition_price", type="integer"),
     *             @OA\Property(property="gallery_id", type="integer"),
     *             @OA\Property(property="exhibition_tag", type="string"),
     *             @OA\Property(property="exhibition_status", type="string"),
     *             @OA\Property(property="create_dtm", type="string", format="date-time"),
     *             @OA\Property(property="update_dtm", type="string", format="date-time"),
     *             @OA\Property(property="like_count", type="integer"),
     *             @OA\Property(property="is_liked", type="boolean")
     *         ))
     *     )
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
            'status' => $_GET['status'] ?? null,
            'category' => $_GET['category'] ?? null,
            'region' => $_GET['region'] ?? null,
            'sort' => $_GET['sort'] ?? null,
            'liked_only' => $likedOnly,
            'user_id' => $user_id,
            'search' => $_GET['search'] ?? null
        ];

        // 파라미터로 특정 gallery_name만 검색하고자 하면 검색 결과를 제한해야 하므로,        
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
        header('Content-Type: application/json');
        echo json_encode($exhibitions, JSON_UNESCAPED_UNICODE);
    }



    /**
     * @OA\Get(
     *     path="/api/exhibitions/{id}",
     *     summary="전시회 상세 조회",
     *     tags={"Exhibition"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="전시회 상세 조회 성공",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="exhibition_title", type="string"),
     *             @OA\Property(property="exhibition_poster", type="string"),
     *             @OA\Property(property="exhibition_category", type="string"),
     *             @OA\Property(property="exhibition_start_date", type="string", format="date"),
     *             @OA\Property(property="exhibition_end_date", type="string", format="date"),
     *             @OA\Property(property="exhibition_start_time", type="string", format="date-time"),
     *             @OA\Property(property="exhibition_end_time", type="string", format="date-time"),
     *             @OA\Property(property="exhibition_location", type="string"),
     *             @OA\Property(property="exhibition_price", type="integer"),
     *             @OA\Property(property="gallery_id", type="integer"),
     *             @OA\Property(property="exhibition_tag", type="string"),
     *             @OA\Property(property="exhibition_status", type="string"),
     *             @OA\Property(property="create_dtm", type="string", format="date-time"),
     *             @OA\Property(property="update_dtm", type="string", format="date-time"),
     *             @OA\Property(property="like_count", type="integer"),
     *             @OA\Property(property="is_liked", type="boolean")
     *         )
     *     )
     * )
     */
    public function getExhibitionById($id) {
        $user_id = AuthMiddleware::getUserId();
        $exhibition = $this->model->getExhibitionDetailById($id, $user_id);
        if ($exhibition) {
            $gallery = null;

            if (!empty($exhibition['gallery_id'])) {
                $gallery = $this->galleryModel->getGalleryById($exhibition['gallery_id']);
                $exhibition['gallery'] = $gallery;
            }
            else {
                $exhibition['gallery'] = null;
            }

            header('Content-Type: application/json');
            echo json_encode($exhibition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Exhibition not found']);
        }
    }

    /**
 * @OA\Post(
 *     path="/api/exhibitions",
 *     summary="전시회 등록",
 *     tags={"Exhibition"},
 *     security={{"bearerAuth":{}}},  
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="exhibition_title", type="string", example="빛의 정원"),
 *             @OA\Property(property="exhibition_poster", type="string", example="example.com"),
 *             @OA\Property(property="exhibition_category", type="string", example="회화"),
 *             @OA\Property(property="exhibition_start_date", type="string", format="date", example="2025-07-01"),
 *             @OA\Property(property="exhibition_end_date", type="string", format="date", example="2025-08-01"),
 *             @OA\Property(property="exhibition_start_time", type="string", format="time", example="10:00:00"),
 *             @OA\Property(property="exhibition_end_time", type="string", format="time", example="18:00:00"),
 *             @OA\Property(property="exhibition_location", type="string", example="서울시 종로구"),
 *             @OA\Property(property="exhibition_price", type="integer", example=15000),
 *             @OA\Property(property="exhibition_tag", type="string", example="미디어아트,전통"),
 *             @OA\Property(property="exhibition_status", type="string", enum={"scheduled", "exhibited", "ended"}, example="scheduled")
 *         )
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="등록 성공",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string"),
 *             @OA\Property(property="data", type="object",
 *                  @OA\Property(property="id", type="integer"),
 *                  @OA\Property(property="exhibition_title", type="string"),
 *                  @OA\Property(property="exhibition_poster", type="string"),
 *                  @OA\Property(property="exhibition_category", type="string"),
 *                  @OA\Property(property="exhibition_start_date", type="string", format="date"),
 *                  @OA\Property(property="exhibition_end_date", type="string", format="date"),
 *                  @OA\Property(property="exhibition_start_time", type="string", format="time"),
 *                  @OA\Property(property="exhibition_end_time", type="string", format="time"),
 *                  @OA\Property(property="exhibition_location", type="string"),
 *                  @OA\Property(property="exhibition_price", type="integer"),
 *                  @OA\Property(property="gallery_id", type="integer"),
 *                  @OA\Property(property="exhibition_tag", type="string"),
 *                  @OA\Property(property="exhibition_status", type="string"),
 *                  @OA\Property(property="create_dtm", type="string", format="date-time"),
 *                  @OA\Property(property="update_dtm", type="string", format="date-time")
 *             )
 *         )
 *     )
 * )
 */
    public function createExhibition() {
    $user = $this->auth->authenticate(); // JWT 검사
    $userId = $user->user_id;

    $userData = $this->userModel->getById($userId);
    $gallery_id = $userData['gallery_id'] ?? null;

    if (!isset($gallery_id) || $gallery_id === null || $gallery_id <= 0) {
        http_response_code(403);
        echo json_encode(['message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $createdExhibition = $this->model->create($data, $gallery_id);

    if ($createdExhibition) {
        http_response_code(201);
        echo json_encode(['message' => 'Exhibition created successfully', 'data' => $createdExhibition], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to create exhibition']);
    }
}


    /**
 * @OA\Put(
 *     path="/api/exhibitions/{id}",
 *     summary="전시회 수정",
 *     tags={"Exhibition"},
 *     security={{"bearerAuth":{}}},  
 *     @OA\Parameter(
 *         name="id", 
 *         in="path", 
 *         required=true, 
 *         description="수정할 전시회 ID", 
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="exhibition_title", type="string", example="빛의 정원"),
 *             @OA\Property(property="exhibition_poster", type="string", example="example.com"),
 *             @OA\Property(property="exhibition_category", type="string", example="회화"),
 *             @OA\Property(property="exhibition_start_date", type="string", format="date", example="2025-07-01"),
 *             @OA\Property(property="exhibition_end_date", type="string", format="date", example="2025-08-01"),
 *             @OA\Property(property="exhibition_start_time", type="string", format="time", example="10:00:00"),
 *             @OA\Property(property="exhibition_end_time", type="string", format="time", example="18:00:00"),
 *             @OA\Property(property="exhibition_location", type="string", example="서울시 종로구"),
 *             @OA\Property(property="exhibition_price", type="integer", example=15000),
 *             @OA\Property(property="exhibition_tag", type="string", example="미디어아트,전통"),
 *             @OA\Property(property="exhibition_status", type="string", enum={"scheduled", "exhibited", "ended"}, example="scheduled")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="수정 성공",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string"),
 *             @OA\Property(property="data", type="object",
 *                  @OA\Property(property="id", type="integer"),
 *                  @OA\Property(property="exhibition_title", type="string"),
 *                  @OA\Property(property="exhibition_poster", type="string"),
 *                  @OA\Property(property="exhibition_category", type="string"),
 *                  @OA\Property(property="exhibition_start_date", type="string", format="date"),
 *                  @OA\Property(property="exhibition_end_date", type="string", format="date"),
 *                  @OA\Property(property="exhibition_start_time", type="string", format="time"),
 *                  @OA\Property(property="exhibition_end_time", type="string", format="time"),
 *                  @OA\Property(property="exhibition_location", type="string"),
 *                  @OA\Property(property="exhibition_price", type="integer"),
 *                  @OA\Property(property="gallery_id", type="integer"),
 *                  @OA\Property(property="exhibition_tag", type="string"),
 *                  @OA\Property(property="exhibition_status", type="string"),
 *                  @OA\Property(property="create_dtm", type="string", format="date-time"),
 *                  @OA\Property(property="update_dtm", type="string", format="date-time")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=404, description="전시회 없음")
 * )
 */
     public function updateExhibition($id) {
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
    $gallery_id = $exhibition['gallery_id'];
    $success = $this->model->update($id, $data, $gallery_id);

    if ($success) {
        $updatedExhibition = $this->model->getById($id);
        echo json_encode(['message' => 'Exhibition updated successfully', 'data' => $updatedExhibition], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Exhibition not found or update failed']);
    }
}
    


    /**
     * @OA\Delete(
     *     path="/api/exhibitions/{id}",
     *     summary="전시회 삭제",
     *     tags={"Exhibition"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="삭제 성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
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





    /**Add commentMore actions
     * @OA\Post(
     *     path="/api/exhibitions/{id}/arts",
     *     summary="전시회 작품 등록",
     *     tags={"Exhibition"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="art_id", type="integer"),
     *             @OA\Property(property="display_order", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="등록 성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                  @OA\Property(property="id", type="integer"),
     *                  @OA\Property(property="exhibition_id", type="integer"),
     *                  @OA\Property(property="art_id", type="integer"),
     *                  @OA\Property(property="display_order", type="integer"),
     *                  @OA\Property(property="create_dtm", type="string", format="date-time"),
     *                  @OA\Property(property="update_dtm", type="string", format="date-time")
     *             )
     *         )
     *     )
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
     *     path="/api/exhibitions/{id}/artworks",
     *     summary="전시회 작가 등록",
     *     tags={"Exhibition"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="artist_id", type="integer"),
     *             @OA\Property(property="role", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="등록 성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                  @OA\Property(property="id", type="integer"),
     *                  @OA\Property(property="exhibition_id", type="integer"),
     *                  @OA\Property(property="artist_id", type="integer"),
     *                  @OA\Property(property="role", type="string"),
     *                  @OA\Property(property="create_dtm", type="string", format="date-time"),
     *                  @OA\Property(property="update_dtm", type="string", format="date-time")
     *             )
     *         )
     *     )
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
