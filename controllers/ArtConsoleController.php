<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Models\ArtModel;
use Models\ArtistModel;

/**
 * @OA\Tag(
 *   name="ArtConsole",
 *   description="[콘솔] 관리자 측 작품 관련 API"
 * )
 */
class ArtConsoleController {
    private $authMiddleware;
    private $galleryModel;
    private $exhibitionModel;
    private $artModel;
    private $artistModel;

    public function __construct() {
        $this->authMiddleware = new AuthMiddleware();
        $this->galleryModel = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
        $this->artModel = new ArtModel();
        $this->artistModel = new ArtistModel();
    }

    /**
     * @OA\Get(
     *   path="/api/console/arts",
     *   summary="[콘솔] 작품 목록 조회",
     *   description="갤러리 이름으로 필터링하여 전체 작품 목록을 조회합니다.",
     *   tags={"ArtConsole"},
     *   security={{"bearerAuth": {}}},
     *   @OA\Parameter(
     *     name="gallery_name",
     *     in="query",
     *     description="[필터] 특정 갤러리 이름으로 작품을 검색합니다. (미입력 시 전체 조회)",
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="성공",
     *     @OA\JsonContent(
     *       type="array",
     *       @OA\Items(
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=101, description="작품 ID"),
     *         @OA\Property(property="exhibition_id", type="integer", example=1, description="전시회 ID"),
     *         @OA\Property(property="artist_name", type="string", example="빈센트 반 고흐", description="작가 이름"),
     *         @OA\Property(property="title", type="string", example="별이 빛나는 밤", description="작품 제목"),
     *         @OA\Property(property="description", type="string", example="고흐의 대표작 중 하나", description="작품 설명"),
     *         @OA\Property(property="image_url", type="string", format="uri", description="작품 이미지 URL"),
     *         @OA\Property(property="year", type="integer", example=1889, description="제작 연도"),
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
    public function getArtList() {
        try {
            $this->authMiddleware->requireAdmin();

            // gallery_name이 파라미터로 들어오면 제한해야하고 아니면 아니므로
            $targetGalleryId = null;

            if (!empty($_GET['gallery_name'])) {
                $galleryList = $this->galleryModel->getGalleries(['search' => $_GET['gallery_name']]);
                if (!empty($galleryList)) {
                    $targetGalleryId = $galleryList[0]['id'];
                } else {
                    http_response_code(404);
                    header('Content-Type: application/json');
                    echo json_encode(['message' => '해당 이름의 갤러리를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    return;
                }
            }

            // 해당하는 작품만 포함할 것임
            $results = [];

            // 모든 작품 리스트를 갖고와서 순회할 것임
            $arts = $this->artModel->getAll();

            foreach ($arts as $art) {
                // 해당 작품이 전시되어있는 모든 전시회 불러오기(작품과 전시회는 일대다 관계)
                $exhibitionsForArt = $this->artModel->getExhibitionIdByArtId($art['id']);

                // 최종 return에 추가로 포함시킬 항목들
                $artists = null;
                $galleries = [];

                foreach ($exhibitionsForArt as $exhibitionForArt) {
                    $exhibition = $this->exhibitionModel->getById($exhibitionForArt['exhibition_id']);
                    
                    // 파라미터로 gallery_name이 들어온 경우 이를 타깃과 맞지 않는 gallery_id면 모두 continue
                    if (!empty($targetGalleryId) && $exhibition['gallery_id'] != $targetGalleryId) {
                        continue;
                    }
                    // 위 조건문으로 걸러지지 않은 것은 모두 필드 추가해야하는 항목이므로
                    $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
                    $galleries[] = $gallery;
                }

                // 만약 $galleries가 없다면 이 루프에서의 $art는 dummy이므로,
                if (!empty($targetGalleryId) && empty($galleries)) {
                    continue;
                }

                $artist = $this->artistModel->getById($art['artist_id']);

                $art['artist'] = $artist;
                $art['galleries'] = $galleries;

                $results[] = $art;
            }

            header('Content-Type: application/json');
            echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['message' => '서버 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }

    /**
     * @OA\Get(
     *   path="/api/console/arts/{id}",
     *   summary="[콘솔] 작품 상세 조회",
     *   description="특정 작품의 상세 정보와 해당 작품이 포함된 전시 목록을 함께 조회합니다.",
     *   tags={"ArtConsole"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     description="조회할 작품 ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="상세 조회 성공",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="id", type="integer", example=1, description="작품 ID"),
     *       @OA\Property(property="title", type="string", example="별이 빛나는 밤", description="작품 제목"),
     *       @OA\Property(property="artist_name", type="string", example="빈센트 반 고흐", description="작가명"),
     *       @OA\Property(property="description", type="string", example="1889년에 제작된 유화", description="작품 설명"),
     *       @OA\Property(property="image_url", type="string", format="uri", example="https://.../starry_night.jpg", description="이미지 URL"),
     *       @OA\Property(
     *         property="exhibitions",
     *         type="array",
     *         description="해당 작품이 포함된 전시 목록",
     *         @OA\Items(
     *           type="object",
     *           @OA\Property(property="id", type="integer", example=101, description="전시 ID"),
     *           @OA\Property(property="title", type="string", example="빛의 향연", description="전시 제목"),
     *           @OA\Property(property="start_date", type="string", format="date", example="2025-11-01", description="시작일"),
     *           @OA\Property(property="end_date", type="string", format="date", example="2025-11-30", description="종료일")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="작품 없음",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Art not found")
     *     )
     *   )
     * )
     */
    public function getArtById($id) {
        $this->authMiddleware->requireAdmin();

        $art = $this->artModel->getById($id);

        if ($art) {
            $exhibitionIds = $this->artModel->getExhibitionIdByArtId($id);
            $exhibitions = [];

            foreach ($exhibitionIds as $exhibitionId) {
                $exhibitionDetail = $this->exhibitionModel->getById($exhibitionId);
                if ($exhibitionDetail) {
                    $exhibitions[] = $exhibitionDetail;
                }
            }

            if (is_object($art)) {
                $art->exhibitions = $exhibitions;
            } elseif (is_array($art)) {
                $art['exhibitions'] = $exhibitions;
            }

            header('Content-Type: application/json');
            echo json_encode($art, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        } else {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Art not found']);
        }
    }
}

