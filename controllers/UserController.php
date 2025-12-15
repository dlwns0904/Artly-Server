<?php
namespace Controllers;

use OpenApi\Annotations as OA;

use Models\LikeModel;
use Models\UserModel;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Models\ArtModel;
use Middlewares\AuthMiddleware;




/**
 * @OA\Tag(
 *     name="User",
 *     description="사용자 관련 API"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class UserController {
    private $model;
    private $likeModel;
    private $galleryModel;
    private $exhibitionModel;
    private $artModel;

    public function __construct() {
        $this->model = new UserModel();
        $this->auth = new AuthMiddleware();
        $this->likeModel = new LikeModel();
        $this->galleryModel = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
        $this->artModel = new ArtModel();
    }

    /**
     * @OA\Get(
     *     path="/api/users/me",
     *     summary="마이페이지",
     *     tags={"User"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="login_id", type="string"),
     *             @OA\Property(property="login_pwd", type="string"),
     *             @OA\Property(property="user_name", type="string"),
     *             @OA\Property(property="user_gender", type="string"),
     *             @OA\Property(property="user_age", type="integer"),
     *             @OA\Property(property="user_email", type="string"),
     *             @OA\Property(property="user_phone", type="string"),
     *             @OA\Property(property="user_img", type="string"),
     *             @OA\Property(property="user_keyword", type="string"),
     *             @OA\Property(property="admin_flag", type="integer"),
     *             @OA\Property(property="gallery_id", type="integer"),
     *             @OA\Property(property="last_login_time", type="string", format="date-time"),
     *             @OA\Property(property="reg_time", type="string", format="date-time"),
     *             @OA\Property(property="update_dtm", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(response=404, description="프로필 없음")
     * )
     */
    public function getMe() {
        $user = $this->auth->authenticate(); // JWT 검사
        $userId = $user->user_id;

        $profile = $this->model->getById($userId);
        if ($profile) {
            echo json_encode($profile, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'User not found']);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/users/me",
     *     summary="프로필 수정",
     *     tags={"User"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="login_id", type="string"),
     *             @OA\Property(property="login_pwd", type="string"),
     *             @OA\Property(property="user_name", type="string"),
     *             @OA\Property(property="user_gender", type="string"),
     *             @OA\Property(property="user_age", type="integer"),
     *             @OA\Property(property="user_email", type="string"),
     *             @OA\Property(property="user_phone", type="string"),
     *             @OA\Property(property="user_img", type="string"),
     *             @OA\Property(property="user_keyword", type="string"),
     *             @OA\Property(property="admin_flag", type="integer"),
     *             @OA\Property(property="gallery_id", type="integer"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="수정 성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
        *             @OA\Property(property="id", type="integer"),
        *             @OA\Property(property="login_id", type="string"),
        *             @OA\Property(property="login_pwd", type="string"),
        *             @OA\Property(property="user_name", type="string"),
        *             @OA\Property(property="user_gender", type="string"),
        *             @OA\Property(property="user_age", type="integer"),
        *             @OA\Property(property="user_email", type="string"),
        *             @OA\Property(property="user_phone", type="string"),
        *             @OA\Property(property="user_img", type="string"),
        *             @OA\Property(property="user_keyword", type="string"),
        *             @OA\Property(property="admin_flag", type="integer"),
        *             @OA\Property(property="gallery_id", type="integer"),
        *             @OA\Property(property="last_login_time", type="string", format="date-time"),
        *             @OA\Property(property="reg_time", type="string", format="date-time"),
        *             @OA\Property(property="update_dtm", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="수정 실패")
     * )
     */
    public function updateMe() {
        $user = $this->auth->authenticate(); // JWT 검사
        $userId = $user->user_id;
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        $success = $this->model->update($userId, $data);

        if ($success) {
            $user = $this->model->getById($userId);
            echo json_encode([
                'message' => 'Profile updated successfully',
                'data' => $user
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Failed to update profile']);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/users/me/exhibitions",
     *     summary="내 전시 일정",
     *     tags={"User"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="성공",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="user_id", type="integer"),
     *             @OA\Property(property="session_id", type="integer"),
     *             @OA\Property(property="reservation_datetime", type="string", format="date-time"),
     *             @OA\Property(property="reservation_number_of_tickets", type="integer"),
     *             @OA\Property(property="reservation_total_price", type="integer"),
     *             @OA\Property(property="reservation_payment_method", type="string"),
     *             @OA\Property(property="reservation_status", type="string"),
     *             @OA\Property(property="create_dtm", type="string", format="date-time"),
     *             @OA\Property(property="update_dtm", type="string", format="date-time"),
     *             @OA\Property(property="exhibition_id", type="integer"),
     *             @OA\Property(property="session_datetime", type="string", format="date-time"),
     *             @OA\Property(property="session_total_capacity", type="integer"),
     *             @OA\Property(property="session_reservation_capacity", type="integer"),
     *             @OA\Property(property="exhibition_title", type="string"),
     *             @OA\Property(property="exhibition_poster", type="string", format="uri"),
     *             @OA\Property(property="exhibition_category", type="string"),
     *             @OA\Property(property="exhibition_start_date", type="string", format="date"),
     *             @OA\Property(property="exhibition_end_date", type="string", format="date"),
     *             @OA\Property(property="exhibition_start_time", type="string", format="date-time"),
     *             @OA\Property(property="exhibition_end_time", type="string", format="date-time"),
     *             @OA\Property(property="exhibition_location", type="string"),
     *             @OA\Property(property="exhibition_price", type="integer"),
     *             @OA\Property(property="gallery_id", type="integer"),
     *             @OA\Property(property="exhibition_tag", type="string"),
     *             @OA\Property(property="exhibition_status", type="string")
     *         ))
     *     ),
     *     @OA\Response(response=404, description="내 전시 일정 없음")
     * )
     */
    public function getMyReservations() {
        $user = $this->auth->authenticate(); // JWT 검사
        $userId = $user->user_id;

        $reservations = $this->model->getMyReservations($userId);
        if ($reservations) {
            echo json_encode($reservations, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Reservation not found']);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/users/me/purchases",
     *     summary="내 구매 내역",
     *     tags={"User"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="성공",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="user_id", type="integer"),
     *             @OA\Property(property="book_id", type="integer"),
     *             @OA\Property(property="user_book_payment_method", type="string"),
     *             @OA\Property(property="user_book_status", type="string"),
     *             @OA\Property(property="create_dtm", type="string", format="date-time"),
     *             @OA\Property(property="update_dtm", type="string", format="date-time"),
     *             @OA\Property(property="book_title", type="string"),
     *             @OA\Property(property="book_poster", type="string", format="uri")
     *         ))
     *     ),
     *     @OA\Response(response=404, description="내 구매 내역 없음")
     * )
     */
    public function getMyPurchases() {
        $user = $this->auth->authenticate(); // JWT 검사
        $userId = $user->user_id;

        $purchases = $this->model->getMyPurchases($userId);
        if ($purchases) {
            echo json_encode($purchases, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Purchases not found']);
        }
    }
    
    /**
 * @OA\Get(
 *     path="/api/users/me/likes",
 *     summary="내 좋아요 목록",
 *     tags={"User"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(
 *         response=200,
 *         description="성공",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(
 *                 property="like_exhibitions",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="id", type="integer"),
 *                     @OA\Property(property="exhibition_title", type="string"),
 *                     @OA\Property(property="exhibition_poster", type="string"),
 *                     @OA\Property(property="exhibition_category", type="string"),
 *                     @OA\Property(property="exhibition_start_date", type="string", format="date"),
 *                     @OA\Property(property="exhibition_end_date", type="string", format="date"),
 *                     @OA\Property(property="exhibition_start_time", type="string", format="date-time"),
 *                     @OA\Property(property="exhibition_end_time", type="string", format="date-time"),
 *                     @OA\Property(property="exhibition_location", type="string"),
 *                     @OA\Property(property="exhibition_price", type="integer"),
 *                     @OA\Property(property="gallery_id", type="integer"),
 *                     @OA\Property(property="exhibition_tag", type="string"),
 *                     @OA\Property(property="exhibition_status", type="string"),
 *                     @OA\Property(property="create_dtm", type="string", format="date-time"),
 *                     @OA\Property(property="update_dtm", type="string", format="date-time")
 *                 )
 *             ),
 *             @OA\Property(
 *                 property="like_galleries",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="id", type="integer"),
 *                     @OA\Property(property="gallery_name", type="string"),
 *                     @OA\Property(property="gallery_image", type="string"),
 *                     @OA\Property(property="gallery_address", type="string"),
 *                     @OA\Property(property="gallery_start_time", type="string", format="date"),
 *                     @OA\Property(property="gallery_end_time", type="string", format="date"),
 *                     @OA\Property(property="gallery_closed_day", type="string", format="date-time"),
 *                     @OA\Property(property="gallery_category", type="string", format="string"),
 *                     @OA\Property(property="gallery_description", type="string"),
 *                     @OA\Property(property="create_dtm", type="string", format="date-time"),
 *                     @OA\Property(property="update_dtm", type="string", format="date-time")
 *                 )
 *             ),
 *             @OA\Property(
 *                 property="like_artists",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="id", type="integer"),
 *                     @OA\Property(property="artist_image", type="string"),
 *                     @OA\Property(property="artist_name", type="string"),
 *                     @OA\Property(property="artist_category", type="string"),
 *                     @OA\Property(property="artist_nation", type="string", format="string"),
 *                     @OA\Property(property="artist_description", type="string", format="string"),
 *                     @OA\Property(property="create_dtm", type="string", format="date-time"),
 *                     @OA\Property(property="update_dtm", type="string", format="date-time")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(response=404, description="내 좋아요 목록 없음")
 * )
 */
    public function getMyLikes() {
        $user = $this->auth->authenticate(); // JWT 검사
        $userId = $user->user_id;

        $likeExhibitions = $this->model->getMyLikeExhibitions($userId);
        $likeGalleries = $this->model->getMyLikeGalleries($userId);
        $likeArtists = $this->model->getMyLikeArtists($userId);
        $likeArts = $this->model->getMyLikeArts($userId);
        echo json_encode([
            'like_exhibitions' => $likeExhibitions,
            'like_galleries' => $likeGalleries,
            'like_artists' => $likeArtists,
            'like_arts' => $likeArts
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Get(
     * path="/api/users/console/likes",
     * summary="관리자(Console)가 관리하는 콘텐츠의 좋아요 목록 조회",
     * description="특정 관리자(console_user_id)가 소유한 갤러리, 전시회, 작품에 달린 좋아요 목록을 조회합니다. liked_type에 따라 반환되는 상세 객체(gallery, exhibition, art)가 달라집니다.",
     * tags={"User"},
     * security={{"bearerAuth": {}}},
     * @OA\Parameter(
     * name="console_user_id",
     * in="query",
     * description="관리자 ID (이 ID가 관리하는 갤러리/전시/작품에 대한 좋아요만 필터링하여 조회)",
     * required=true,
     * @OA\Schema(type="integer", example=1)
     * ),
     * @OA\Parameter(
     * name="liked_type",
     * in="query",
     * description="조회할 좋아요 대상 타입 ('gallery', 'exhibition', 'art')",
     * required=true,
     * @OA\Schema(type="string", enum={"gallery", "exhibition", "art"})
     * ),
     * @OA\Parameter(
     * name="search",
     * in="query",
     * description="[선택] 검색어 (사용자 이름 또는 콘텐츠의 제목으로 필터링)",
     * required=false,
     * @OA\Schema(type="string")
     * ),
     * @OA\Response(
     * response=200,
     * description="성공적인 조회",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(
     * type="object",
     * description="좋아요 정보 객체",
     * @OA\Property(property="id", type="integer", description="좋아요 ID"),
     * @OA\Property(property="user_id", type="integer", description="좋아요를 누른 사용자 ID"),
     * @OA\Property(property="gallery_id", type="integer", description="대상 갤러리 ID (type=gallery일 때)", nullable=true),
     * @OA\Property(property="exhibition_id", type="integer", description="대상 전시 ID (type=exhibition일 때)", nullable=true),
     * @OA\Property(property="art_id", type="integer", description="대상 작품 ID (type=art일 때)", nullable=true),
     * @OA\Property(property="create_dtm", type="string", format="date-time", description="좋아요 생성 일시"),
     * * @OA\Property(
     * property="user",
     * type="object",
     * description="좋아요를 누른 사용자 상세 정보",
     * @OA\Property(property="id", type="integer"),
     * @OA\Property(property="user_name", type="string"),
     * @OA\Property(property="user_email", type="string"),
     * @OA\Property(property="user_image", type="string", nullable=true)
     * ),
     * * @OA\Property(
     * property="gallery",
     * type="object",
     * description="갤러리 상세 정보 (liked_type='gallery'일 때만 존재)",
     * nullable=true,
     * @OA\Property(property="id", type="integer"),
     * @OA\Property(property="gallery_name", type="string"),
     * @OA\Property(property="gallery_image", type="string")
     * ),
     * @OA\Property(
     * property="exhibition",
     * type="object",
     * description="전시회 상세 정보 (liked_type='exhibition'일 때만 존재)",
     * nullable=true,
     * @OA\Property(property="id", type="integer"),
     * @OA\Property(property="exhibition_title", type="string"),
     * @OA\Property(property="exhibition_poster", type="string")
     * ),
     * @OA\Property(
     * property="art",
     * type="object",
     * description="작품 상세 정보 (liked_type='art'일 때만 존재)",
     * nullable=true,
     * @OA\Property(property="id", type="integer"),
     * @OA\Property(property="art_title", type="string"),
     * @OA\Property(property="art_image", type="string")
     * )
     * )
     * )
     * ),
     * @OA\Response(response=400, description="필수 파라미터(liked_type) 누락 또는 잘못된 값"),
     * @OA\Response(response=401, description="인증 실패"),
     * @OA\Response(response=500, description="서버 내부 오류")
     * )
     */
    public function getLikedUserList() {
        try {
            $user = $this->auth->authenticate(); 
    
            // 1. 파라미터 검증
            if (empty($_GET['liked_type'])) {
                http_response_code(400);
                echo json_encode(['message' => 'liked_type 파라미터는 필수입니다.']);
                return;
            }
    
            $likedType = $_GET['liked_type'];
            $allowedTypes = ['gallery', 'exhibition', 'art'];
            
            if (!in_array($likedType, $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['message' => "잘못된 liked_type 파라미터입니다."]);
                return;
            }
    
            $searchTerm = $_GET['search'] ?? null;
            $consoleUserId = $_GET['console_user_id'] ?? null;
    
            // 관리자 필터링을 위한 ID 리스트 초기화
            $userGalleryIds = []; 
            $userExhibitionIds = [];
            $userArtIds = [];
    
            // 2. 관리자(Console User) 필터링 데이터 수집
            if (!empty($consoleUserId)) {
                $galleries = $this->galleryModel->getGalleriesBySearch(['user_id' => $consoleUserId]);
                $userGalleryIds = array_column($galleries, 'id');
    
                // 갤러리 -> 전시회 ID 추출
                foreach ($userGalleryIds as $galleryId) {
                    $exhibitions = $this->exhibitionModel->getExhibitions(['gallery_id' => $galleryId]);
                    foreach ($exhibitions as $exhibition) {
                        $userExhibitionIds[] = $exhibition['id'];
                    }
                }
    
                // 전시회 -> 작품 ID 추출
                foreach ($userExhibitionIds as $exhibitionId) {
                    $exhibitionDetail = $this->exhibitionModel->getExhibitionDetailById($exhibitionId);
    
                    if (!empty($exhibitionDetail['artworks'])) {
                        foreach ($exhibitionDetail['artworks'] as $art) {
                            $userArtIds[] = $art['id'];
                        }
                    }
                }
            }
    
            // 중복 제거 및 인덱스 초기화
            $userGalleryIds = array_values(array_unique($userGalleryIds));
            $userExhibitionIds = array_values(array_unique($userExhibitionIds));
            $userArtIds = array_values(array_unique($userArtIds));
    
            // 3. 좋아요 전체 데이터 조회
            $likedItems = $this->likeModel->getAll($likedType);
    
            // 4. 관리자 권한 필터링 (PHP 메모리 상에서 처리)
            if (!empty($consoleUserId)) {
                $likedItems = array_filter($likedItems, function ($item) use ($likedType, $userGalleryIds, $userExhibitionIds, $userArtIds) {
                    switch ($likedType) {
                        case 'gallery':
                            return in_array($item['gallery_id'], $userGalleryIds);
                        case 'exhibition':
                            return in_array($item['exhibition_id'], $userExhibitionIds);
                        case 'art':
                            return in_array($item['art_id'], $userArtIds);
                        default:
                            return false;
                    }
                });
                $likedItems = array_values($likedItems);
            }
    
            $results = $likedItems; 
    
            // 5. 데이터 Hydration (상세 정보 채우기)
            foreach ($results as &$item) {
                if (!empty($item['user_id'])) {
                    $item['user'] = (array) $this->userModel->getById($item['user_id']); 
                } else {
                    $item['user'] = null;
                }
    
                switch ($likedType) {
                    case 'gallery':
                        if (!empty($item['gallery_id'])) {
                            $item['gallery'] = (array) $this->galleryModel->getById($item['gallery_id']);
                        }
                        break;
                    case 'exhibition':
                        if (!empty($item['exhibition_id'])) {
                            $item['exhibition'] = (array) $this->exhibitionModel->getById($item['exhibition_id']);
                        }
                        break;
                    case 'art':
                        if (!empty($item['art_id'])) {
                            $item['art'] = (array) $this->artModel->getById($item['art_id']);
                        }
                        break;
                }
            }
            unset($item); // 참조 변수 해제
    
            // 6. 검색어 필터링 (Hydration 이후에 수행해야 이름 검색 가능)
            $finalResults = $results;
    
            if (!empty($searchTerm)) {
                $filteredResults = array_filter($results, function($item) use ($searchTerm, $likedType) {
                    // 사용자 이름 검색
                    if (isset($item['user']['user_name']) && is_string($item['user']['user_name'])) {
                        if (stripos($item['user']['user_name'], $searchTerm) !== false) return true;
                    }
    
                    // 대상(갤러리/전시/작품) 타이틀 검색
                    switch ($likedType) {
                        case 'gallery':
                            if (isset($item['gallery']['gallery_name']) && stripos($item['gallery']['gallery_name'], $searchTerm) !== false) return true;
                            break;
                        case 'exhibition':
                            if (isset($item['exhibition']['exhibition_title']) && stripos($item['exhibition']['exhibition_title'], $searchTerm) !== false) return true;
                            break;
                        case 'art':
                            if (isset($item['art']['art_title']) && stripos($item['art']['art_title'], $searchTerm) !== false) return true;
                            break;
                    }
                    return false;
                });
                $finalResults = array_values($filteredResults);
            }
    
            header('Content-Type: application/json');
            echo json_encode($finalResults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode(['message' => '서버 오류가 발생했습니다.']);
        }
    }
}
