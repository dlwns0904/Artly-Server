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
     * summary="관리자 측에서 관리하는 갤러리/전시회/작품의 좋아요 목록 조회",
     * description="타입(gallery, exhibition, art)별로 좋아요 목록을 조회하고, 선택적으로 검색어로 필터링합니다.",
     * tags={"User"},
     * security={{"bearerAuth": {}}},
     * @OA\Parameter(
     * name="user_name",
     * in="query",
     * description="사용자 이름으로 검색",
     * @OA\Schema(type="string")
     * ),
     * @OA\Parameter(
     * name="liked_type",
     * in="query",
     * description="좋아요 구분 ('gallery', 'exhibition', 'art')",
     * @OA\Schema(type="string", enum={"gallery", "exhibition", "art"})
     * ),
     * @OA\Parameter(
     * name="search",
     * in="query",
     * description="[선택] 특정 키워드로 조회된 목록을 필터링합니다.",
     * @OA\Schema(type="string")
     * ),
     * @OA\Response(
     * response=200,
     * description="성공적인 조회",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(
     * type="object",
     * @OA\Property(property="like_id", type="integer", description="좋아요 ID", example=1),
     * @OA\Property(property="user_id", type="integer", description="사용자 ID", example=10),
     * @OA\Property(property="user_name", type="string", description="사용자 이름", example="홍길동"),
     * @OA\Property(property="liked_item_id", type="integer", description="좋아요 대상 아이템의 ID", example=5),
     * @OA\Property(property="liked_item_title", type="string", description="좋아요 대상 아이템의 제목", example="빛의 갤러리"),
     * @OA\Property(property="created_at", type="string", format="date-time", description="좋아요 누른 시간")
     * )
     * )
     * ),
     * @OA\Response(response=400, description="필수 파라미터 누락 또는 잘못된 타입"),
     * @OA\Response(response=401, description="인증 실패 (관리자 권한 필요)"),
     * @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function getLikedUserList() {
        try {
            $user = $this->auth->authenticate(); // JWT 검사

            // 파라미터 유효성 검사
            if (empty($_GET['liked_type'])) {
                http_response_code(400);
                echo json_encode(['message' => 'liked_type 파라미터는 필수입니다.']);
                return;
            }

            $likedType = $_GET['liked_type'];
            $allowedTypes = ['gallery', 'exhibition', 'art'];
            
            if (!in_array($likedType, $allowedTypes)) {
                http_response_code(400);
                $allowed = implode(', ', $allowedTypes);
                echo json_encode(['message' => "잘못된 liked_type 파라미터입니다. 허용된 값: {$allowed}"]);
                return;
            }

            // search 파라미터 가져오기
            $searchTerm = $_GET['search'] ?? null;

            // likedType에 따른 {likedType}_like 테이블의 데이터 조회
            $likedItems = $this->likeModel->getAll($likedType);

            // $results 변수에 바로 담아서 처리
            $results = $likedItems; 
            foreach ($results as &$item) {
                // 공통 : user_id를 바탕으로 userModel의 getById를 바탕으로 새로운 user필드에 조회한 정보 추가
                if (!empty($item['user_id'])) {
                    $item['user'] = (array) $this->model->getById($item['user_id']);
                } else {
                    $item['user'] = null;
                }

                // $likedType에 따라 적절한 모델을 사용하여 상세 정보 추가
                switch ($likedType) {
                    case 'gallery':
                        if (!empty($item['gallery_id'])) {
                            $item['gallery'] = (array) $this->galleryModel->getById($item['gallery_id']);
                        } else {
                            $item['gallery'] = null;
                        }
                        break;
                    case 'exhibition':
                        if (!empty($item['exhibition_id'])) {
                            $item['exhibition'] = (array) $this->exhibitionModel->getById($item['exhibition_id']);
                        } else {
                            $item['exhibition'] = null;
                        }
                        break;
                    case 'art':
                        if (!empty($item['art_id'])) {
                            $item['art'] = (array) $this->artModel->getById($item['art_id']);
                        } else {
                            $item['art'] = null;
                        }
                        break;
                }
            }
            unset($item);

            $finalResults = $results; // 기본값은 전체 결과

            if (!empty($searchTerm)) {
                
                $filteredResults = array_filter($results, function($item) use ($searchTerm, $likedType) {
                    
                    // 사용자명(user_name) 검색
                    if (isset($item['user']) && isset($item['user']['user_name']) && is_string($item['user']['user_name'])) {
                        if (stripos($item['user']['user_name'], $searchTerm) !== false) {
                            return true; // 사용자명에서 일치!
                        }
                    }

                    // likedType에 따른 대상 이름 검색
                    switch ($likedType) {
                        case 'gallery':
                            if (isset($item['gallery']) && isset($item['gallery']['gallery_name']) && is_string($item['gallery']['gallery_name'])) {
                                if (stripos($item['gallery']['gallery_name'], $searchTerm) !== false) {
                                    return true; // gallery_name에서 일치
                                }
                            }
                            break;
                        case 'exhibition':
                            if (isset($item['exhibition']) && isset($item['exhibition']['exhibition_title']) && is_string($item['exhibition']['exhibition_title'])) {
                                if (stripos($item['exhibition']['exhibition_title'], $searchTerm) !== false) {
                                    return true; // exhibition_name에서 일치
                                }
                            }
                            break;
                        case 'art':
                            if (isset($item['art']) && isset($item['art']['art_title']) && is_string($item['art']['art_title'])) {
                                if (stripos($item['art']['art_title'], $searchTerm) !== false) {
                                    return true; // art_name에서 일치!
                                }
                            }
                            break;
                    }

                    return false; // 어디에도 일치하지 않음
                });
                
                // 인덱스를 재정렬
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
