<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\LikeModel;
use Models\UserModel;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Models\ArtModel;

/**
 * @OA\Tag(
 * name="UserConsole",
 * description="[콘솔] 관리자 측 유저 관련 API"
 * )
 */
class UserConsoleController {
    private $authMiddleware;
    private $likeModel;
    private $userModel;
    private $galleryModel;
    private $exhibitionModel;
    private $artModel;

    public function __construct() {
        $this->authMiddleware = new AuthMiddleware();
        $this->likeModel = new LikeModel();
        $this->userModel = new UserModel();
        $this->galleryModel = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
        $this->artModel = new ArtModel();
    }

    /**
     * @OA\Get(
     * path="/api/console/users/likes",
     * summary="[콘솔] 좋아요 목록 조회",
     * description="타입(gallery, exhibition, art)별로 좋아요 목록을 조회하고, 선택적으로 검색어로 필터링합니다.",
     * tags={"UserConsole"},
     * security={{"bearerAuth": {}}},
     * @OA\Parameter(
     * name="liked_type",
     * in="query",
     * required=true,
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
            $this->authMiddleware->requireAdmin();

            // liked_type 파라미터 유효성 검사
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

            // 데이터 조회
            $likedItems = $this->likeModel->getAll($likedType);
            $results = [];

            // search 파라미터가 있을 경우에만 데이터 필터링
            if (!empty($searchTerm)) {
                $results = array_filter($likedItems, function($item) use ($searchTerm) {
                    // item의 모든 string 값에 대해 검색어 포함 여부 확인
                    foreach ($item as $value) {
                        if (is_string($value) && stripos($value, $searchTerm) !== false) {
                            return true; 
                        }
                    }
                    return false;
                });
                // 인덱스를 재정렬 (0부터 시작하도록)
                $results = array_values($results);
            } else {
                // 검색어가 없으면 전체 결과를 반환
                $results = $likedItems;
            }

            foreach ($results as &$item) {
                // 공통 : user_id를 바탕으로 userModel의 getById를 바탕으로 새로운 user필드에 조회한 정보 추가
                if (!empty($item['user_id'])) {
                    $item['user'] = $this->userModel->getById($item['user_id']);
                } else {
                    $item['user'] = null;
                }

                // $likedType에 따라 적절한 모델을 사용하여 상세 정보 추가
                switch ($likedType) {
                    case 'gallery':
                        // gallery -> gallery_id를 바탕으로 galleryModel의 getById를 바탕으로 새로운 gallery 필드에 조회한 정보 추가
                        if (!empty($item['gallery_id'])) {
                            $item['gallery'] = $this->galleryModel->getById($item['gallery_id']);
                        } else {
                            $item['gallery'] = null;
                        }
                        break;
                    case 'exhibition':
                        // exhibition -> exhibition_id를 바탕으로 exhibitionModel의 getById를 바탕으로 새로운 exhibition 필드에 조회한 정보 추가
                        if (!empty($item['exhibition_id'])) {
                            $item['exhibition'] = $this->exhibitionModel->getById($item['exhibition_id']);
                        } else {
                            $item['exhibition'] = null;
                        }
                        break;
                    case 'art':
                        // art -> art_id를 바탕으로 artModel의 getById를 바탕으로 새로운 art 필드에 조회한 정보 추가
                        if (!empty($item['art_id'])) {
                            $item['art'] = $this->artModel->getById($item['art_id']);
                        } else {
                            $item['art'] = null;
                        }
                        break;
                }
            }
            unset($item);

            header('Content-Type: application/json');
            echo json_encode($results, JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode(['message' => '서버 오류가 발생했습니다.']);
        }
    }
}