<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\GalleryModel;

/**
 * @OA\Tag(
 * name="GalleryConsole",
 * description="[콘솔] 관리자 측 갤러리 관련 API"
 * )
 */
class GalleryConsoleController {
    private $authMiddleware;    
    private $galleryModel;

    public function __construct() {
        $this->authMiddleware = new AuthMiddleware();
        $this->galleryModel = new GalleryModel();
    }

    /**
     * @OA\Get(
     * path="/api/console/galleries",
     * summary="[콘솔] 갤러리 목록 조회",
     * description="관리자(사용자)가 소유한 모든 갤러리의 상세 정보 목록을 조회합니다.",
     * tags={"GalleryConsole"},
     * security={{"bearerAuth": {}}},
     * @OA\Response(
     * response=200,
     * description="성공적인 조회",
     * @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Gallery"))
     * ),
     * @OA\Response(response=401, description="인증 실패 (관리자 권한 필요)"),
     * @OA\Response(response=500, description="서버 오류")
     * )
    */ 
    public function getGalleryList() {
        try {
            // 관리자 인증 및 사용자 ID 확보
            $decoded = $this->authMiddleware->requireAdmin();
            $user_id = $decoded->sub;

            // 해당 사용자의 기본 갤러리 목록 조회
            $galleries = $this->galleryModel->getGalleries(['user_id' => $user_id]);
            if (empty($galleries)) {
                header('Content-Type: application/json');
                echo json_encode([], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 각 갤러리의 상세 정보를 조회
            $allGalleriesWithDetails = [];
            foreach ($galleries as $gallery) {
                $galleryInfo = $this->galleryModel->getByGalleryID($gallery['id']);
                if ($galleryInfo) {
                    $allGalleriesWithDetails[] = $galleryInfo;
                }
            }

            header('Content-Type: application/json');
            echo json_encode($allGalleriesWithDetails, JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode(['message' => '서버 오류가 발생했습니다.']);
        }
    }
}