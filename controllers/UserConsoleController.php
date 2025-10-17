<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\LikeModel;

/**
 * @OA\Tag(
 * name="UserConsole",
 * description="[콘솔] 관리자 측 유저 관련 API"
 * )
 */
class UserConsoleController {
    private $authMiddleware;
    private $likeModel;

    public function __construct() {
        $this->authMiddleware = new AuthMiddleware();
        $this->likeModel = new LikeModel();
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
     * @OA\JsonContent(type="array", @OA\Items(type="object"))
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

            // search 파라미터가 있을 경우, 데이터 필터링
            if (!empty($searchTerm)) {
                $finalResult = array_filter($likedItems, function($item) use ($searchTerm) {
                    foreach ($item as $value) {
                        if (is_string($value) && stripos($value, $searchTerm) !== false) {
                            return true; 
                        }
                    }
                    return false;
                });
                $finalResult = array_values($finalResult);
            } else {
                // 검색어가 없으면 결과는 없다ㅋ
                $finalResult = [];
            }

            header('Content-Type: application/json');
            echo json_encode($finalResult, JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode(['message' => '서버 오류가 발생했습니다.']);
        }
    }
}