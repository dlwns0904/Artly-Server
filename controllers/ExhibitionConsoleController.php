<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\GalleryModel;
use Models\ExhibitionModel;

/**
 * @OA\Tag(
 *   name="ExhibitionConsole",
 *   description="[콘솔] 관리자 측 전시회 관련 API"
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
     *   path="/api/console/exhibitions",
     *   summary="[콘솔] 전시회 목록 조회",
     *   description="갤러리 이름으로 필터링하거나 전체 전시회 목록을 조회합니다.",
     *   tags={"ExhibitionConsole"},
     *   security={{"bearerAuth": {}}},
     *   @OA\Parameter(
     *     name="gallery_name",
     *     in="query",
     *     description="[필터] 특정 갤러리 이름으로 전시회를 검색합니다. (미입력 시 전체 조회)",
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="성공",
     *     @OA\JsonContent(
     *       type="array",
     *       @OA\Items(
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=1, description="전시회 ID"),
     *         @OA\Property(property="gallery_id", type="integer", example=5, description="갤러리 ID"),
     *         @OA\Property(property="title", type="string", example="빛의 예술", description="전시회 제목"),
     *         @OA\Property(property="description", type="string", example="빛을 주제로 한 전시", description="전시회 설명"),
     *         @OA\Property(property="start_date", type="string", format="date-time", description="전시 시작일"),
     *         @OA\Property(property="end_date", type="string", format="date-time", description="전시 종료일"),
     *         @OA\Property(property="status", type="string", example="ongoing", description="전시 상태"),
     *         @OA\Property(property="created_at", type="string", format="date-time", description="생성 시간"),
     *         @OA\Property(property="updated_at", type="string", format="date-time", description="마지막 수정 시간")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="인증 실패"),
     *   @OA\Response(response=404, description="갤러리 없음"),
     *   @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function getExhibitionList() {
        try {
            $this->authMiddleware->requireAdmin();

            $filters = [];

            if (!empty($_GET['gallery_name'])) {
                $galleryList = $this->galleryModel->getGalleries(['search' => $_GET['gallery_name']]);

                if (!empty($galleryList)) {
                    $filters['gallery_id'] = $galleryList[0]['id'];
                } else {
                    http_response_code(404);
                    header('Content-Type: application/json');
                    echo json_encode(['message' => '해당 이름의 갤러리를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            $exhibitions = $this->exhibitionModel->getExhibitions($filters);

            header('Content-Type: application/json');
            echo json_encode($exhibitions, JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['message' => '서버 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Get(
     *   path="/api/console/exhibitions/{id}",
     *   summary="[콘솔] 전시 상세 조회",
     *   description="특정 전시의 상세 정보와 해당 전시가 열리는 갤러리 정보를 함께 조회합니다.",
     *   tags={"ExhibitionConsole"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     description="조회할 전시 ID",
     *     @OA\Schema(type="integer", example=101)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="상세 조회 성공",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="id", type="integer", example=101, description="전시 ID"),
     *       @OA\Property(property="title", type="string", example="빛의 예술", description="전시 제목"),
     *       @OA\Property(property="description", type="string", example="빛을 주제로 한 전시", description="전시 설명"),
     *       @OA\Property(property="start_date", type="string", format="date-time", description="전시 시작일"),
     *       @OA\Property(property="end_date", type="string", format="date-time", description="전시 종료일"),
     *       @OA\Property(property="status", type="string", example="ongoing", description="전시 상태"),
     *       @OA\Property(
     *         property="gallery",
     *         type="object",
     *         nullable=true,
     *         @OA\Property(property="gallery_phone", type="string", example="010-1234-5678", description="갤러리 전화번호"),
     *         @OA\Property(property="gallery_homepage", type="string", example="https://artlygallery.com", description="갤러리 홈페이지")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="전시 없음",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Exhibition not found")
     *     )
     *   )
     * )
     */
    public function getExhibitionById($id) {
        $this->authMiddleware->requireAdmin();

        $exhibitionDetail = $this->exhibitionModel->getExhibitionDetailById($id);

        if (!$exhibitionDetail) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Exhibition not found']);
            return;
        }

        $gallery = null;
        if (!empty($exhibitionDetail['gallery_id'])) {
            $gallery = $this->galleryModel->getGalleryById($exhibitionDetail['gallery_id']);
        }

        $exhibitionDetail['gallery'] = $gallery;

        header('Content-Type: application/json');
        echo json_encode($exhibitionDetail, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

