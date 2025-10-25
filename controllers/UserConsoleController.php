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
                    $item['user'] = (array) $this->userModel->getById($item['user_id']);
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
                            if (isset($item['exhibition']) && isset($item['exhibition']['exhibition_name']) && is_string($item['exhibition']['exhibition_name'])) {
                                if (stripos($item['exhibition']['exhibition_name'], $searchTerm) !== false) {
                                    return true; // exhibition_name에서 일치
                                }
                            }
                            break;
                        case 'art':
                            if (isset($item['art']) && isset($item['art']['art_name']) && is_string($item['art']['art_name'])) {
                                if (stripos($item['art']['art_name'], $searchTerm) !== false) {
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