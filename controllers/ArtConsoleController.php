<?php
namespace Controllers; // [FutureWarning] 후에 user/admin 디렉토리 분리 예정

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Models\ArtModel;
use Models\ArtistModel;


/**
 * @OA\Tag(
 * name="ArtConsole",
 * description="[콘솔] 관리자 측 작품 관련 API"
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
     * path="/api/console/arts",
     * summary="[콘솔] 작품 목록 조회",
     * description="갤러리 이름으로 필터링하여 전체 작품 목록을 조회하는 API입니다.",
     * tags={"ArtConsole"},
     * security={{"bearerAuth": {}}},
     * @OA\Parameter(
     * name="gallery_name",
     * in="query",
     * description="[필터] 특정 갤러리 이름으로 작품을 검색합니다. (입력하지 않으면 전체 조회)",
     * @OA\Schema(type="string")
     * ),
     * @OA\Response(
     * response=200,
     * description="성공적인 조회",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(
     * type="object",
     * @OA\Property(property="id", type="integer", description="작품 ID", example=101),
     * @OA\Property(property="exhibition_id", type="integer", description="전시회 ID", example=1),
     * @OA\Property(property="artist_name", type="string", description="작가 이름", example="빈센트 반 고흐"),
     * @OA\Property(property="title", type="string", description="작품 제목", example="별이 빛나는 밤"),
     * @OA\Property(property="description", type="string", description="작품 설명", example="고흐의 대표작 중 하나로..."),
     * @OA\Property(property="image_url", type="string", format="uri", description="작품 이미지 URL"),
     * @OA\Property(property="year", type="integer", description="제작 연도", example=1889),
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
    public function getArtList() {
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

            // 전시회 목록 조회
            $exhibitions = $this->exhibitionModel->getExhibitions($filters);

            // 전시회별 작품 조회 
            $allArts = [];
            foreach ($exhibitions as $exhibition) {
                $artsForOneExhibition = $this->artModel->getAll(['exhibition_id' => $exhibition['id']]);
             
                foreach ($artsForOneExhibition as &$art) { // '$art'를 참조(&)로 받음
                    // "artist_id" 필드의 값을 갖고
                    if (!empty($art['artist_id'])) {
                        // artistModel의 getById를 이용해서
                        $artistData = $this->artistModel->getById($art['artist_id']);
                        
                        // $art의 'artist'라는 새로운 필드에 저장하기
                        $art['artist'] = $artistData;
                    } else {
                        // artist_id가 없는 경우 처리
                        $art['artist'] = null;
                    }
                }
                // 참조 해제
                unset($art);

                $allArts = array_merge($allArts, $artsForOneExhibition);
            }

            header('Content-Type: application/json');
            echo json_encode($allArts, JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode(['message' => '서버 오류가 발생했습니다.']);
        }
    }

    /**
     * @OA\Get(
     * path="/api/console/arts/{id}",
     * summary="[콘솔] 작품 상세 조회",
     * description="특정 작품의 상세 정보와 이 작품이 전시된(또는 전시될) 전시 목록을 함께 조회합니다.",
     * tags={"ArtConsole"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(
     * name="id", 
     * in="path", 
     * required=true, 
     * @OA\Schema(type="integer", example=1), 
     * description="조회할 작품 ID"
     * ),
     * * // --- 200 OK 응답 스키마 ---
     * @OA\Response(
     * response=200, 
     * description="상세 조회 성공",
     * @OA\JsonContent(
     * type="object",
     * description="작품 상세 정보 및 전시 목록(아직 프로퍼티명 수정 X)",
     * * // ===========================================
     * // (1) 작품(Art)의 기본 프로퍼티
     * // ===========================================
     * @OA\Property(property="id", type="integer", example=1, description="작품 ID"),
     * @OA\Property(property="title", type="string", example="별이 빛나는 밤", description="작품 제목"),
     * @OA\Property(property="artist_name", type="string", example="빈센트 반 고흐", description="작가명"),
     * @OA\Property(property="description", type="string", example="1889년에 제작된 유화...", description="작품 설명"),
     * @OA\Property(property="image_url", type="string", format="uri", example="https://.../starry_night.jpg"),
     * * // ===========================================
     * // (2) 작품에 포함된 전시(Exhibition) 배열 프로퍼티
     * // ===========================================
     * @OA\Property(
     * property="exhibitions",
     * type="array",
     * description="이 작품이 포함된 전시 목록",
     * @OA\Items(
     * type="object",
     * description="작품이 속한 전시회 정보",
     * @OA\Property(property="id", type="integer", example=101, description="전시 ID"),
     * @OA\Property(property="title", type="string", example="빛의 향연", description="전시 제목"),
     * @OA\Property(property="start_date", type="string", format="date", example="2025-11-01"),
     * @OA\Property(property="end_date", type="string", format="date", example="2025-11-30")
     * )
     * )
     * )
     * ),
     * * // --- 404 Not Found 응답 스키마 ---
     * @OA\Response(
     * response=404, 
     * description="작품 없음", 
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="message", type="string", example="Art not found")
     * )
     * )
     * )
     */
    public function getArtById($id) {
        $this->authMiddleware->requireAdmin();

        $art = $this->artModel->getById($id);

        if ($art) {
            $exhibitionIds = $this->artModel->getExhibitionIdByArtId($id);

            $exhibitions = [];

            foreach ($exhibitionIds as $exhibitionId) {
                $exhibitionDetail = $this.exhibitionModel->getById($exhibitionId); 
                
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



    



//     // TODO_3_1 : 작품 삭제(기존 API 활용) (251017부 더미)
//     public function deleteArt($art_id) {
//         $this->authMiddleware->requireAdmin();

//         $this->artController->deleteArt($art_id);
//     }

//     // TODO_3_2 : 작품 생성(기존 API 활용) (251017부 더미)
//     // 작품 이미지, 작품명, 작가 이미지, 작가명, 제작연도, 재료, 크기, 작품설명(이미지도 포함되어야 함)
//     public function createArt() {
//         $this->authMiddleware->requireAdmin();

//         $this->artController->createArt();
//     }

//     // TODO_3_3 : 작품 수정(기존 API 활용) (251017부 더미)
//     public function updateArt($art_id) {
//         $this->authMiddleware->requireAdmin();

//         $this->artController->updateArt($art_id);
//     }
}