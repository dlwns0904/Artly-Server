<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\GalleryModel;
use Models\ExhibitionModel;

/**
 * @OA\Tag(
 * name="ExhibitionConsole",
 * description="[콘솔] 관리자 측 전시회 관련 API"
 * )
 */
class ExhibitionConsoleController {
    private $authMiddleware;    
    private $galleryModel;
    private $exhibitionModel;

    public function __construct() {
        $this->authMiddleware = new AuthMiddleware();
        $this->galleryModel = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
    }

    /**
     * @OA\Get(
     * path="/api/console/exhibitions",
     * summary="[콘솔] 전시회 목록 조회",
     * description="갤러리 이름으로 필터링하거나 전체 전시회 목록을 조회하는 API입니다.",
     * tags={"ExhibitionConsole"},
     * security={{"bearerAuth": {}}},
     * @OA\Parameter(
     * name="gallery_name",
     * in="query",
     * description="[필터] 특정 갤러리 이름으로 전시회를 검색합니다. (입력하지 않으면 전체 조회)",
     * @OA\Schema(type="string")
     * ),
     * @OA\Response(
     * response=200,
     * description="성공적인 조회",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(
     * type="object",
     * @OA\Property(property="id", type="integer", description="전시회 ID", example=1),
     * @OA\Property(property="gallery_id", type="integer", description="갤러리 ID", example=5),
     * @OA\Property(property="title", type="string", description="전시회 제목", example="빛의 예술"),
     * @OA\Property(property="description", type="string", description="전시회 설명", example="빛을 주제로 한 다양한 작품들을 선보입니다."),
     * @OA\Property(property="start_date", type="string", format="date-time", description="전시 시작일"),
     * @OA\Property(property="end_date", type="string", format="date-time", description="전시 종료일"),
     * @OA\Property(property="status", type="string", description="전시 상태 (예: 'upcoming', 'ongoing', 'finished')", example="ongoing"),
     * @OA\Property(property="created_at", type="string", format="date-time", description="생성 시간"),
     * @OA\Property(property="updated_at", type="string", format="date-time", description="마지막 수정 시간")
     * )
     * )
     * ),
     * @OA\Response(response=401, description="인증 실패 (관리자 권한 필요)"),
     * @OA\Response(response=404, description="해당 이름의 갤러리를 찾을 수 없음"),
     * @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function getExhibitionList() {
        try {
            $this->authMiddleware->requireAdmin();

            $filters = [];

            // 'gallery_name' 파라미터가 있을 경우에만 필터링 로직을 실행
            if (!empty($_GET['gallery_name'])) {
                $galleryList = $this->galleryModel->getGalleries(['search' => $_GET['gallery_name']]);

                if (!empty($galleryList)) {
                    $filters['gallery_id'] = $galleryList[0]['id'];
                } else {
                    // 해당 이름의 갤러리를 찾지 못하면 404 응답 후 종료
                    http_response_code(404);
                    echo json_encode(['message' => '해당 이름의 갤러리를 찾을 수 없습니다.']);
                    return;
                }
            }

            // 최종 구성된 필터로 전시회 목록 조회
            $exhibitions = $this->exhibitionModel->getExhibitions($filters);
            
            header('Content-Type: application/json');
            echo json_encode($exhibitions, JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode(['message' => '서버 오류가 발생했습니다.']);
        }
    }
}