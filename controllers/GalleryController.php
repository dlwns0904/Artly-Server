<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Middlewares\AuthMiddleware;

/**
 * @OA\Tag(
 *     name="Gallery",
 *     description="갤러리 관련 API"
 * )
 */
class GalleryController {
    private $model;
    private $auth;
    private $exhibitionModel;

    public function __construct() {
        $this->model = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
        $this->auth = new AuthMiddleware();
    }

    /**
     * @OA\Post(
     *     path="/api/galleries",
     *     summary="갤러리 생성",
     *     tags={"Gallery"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="gallery_name", type="string"),
     *             @OA\Property(property="gallery_image", type="string"),
     *             @OA\Property(property="gallery_address", type="string"),
     *             @OA\Property(property="gallery_start_time", type="string"),
     *             @OA\Property(property="gallery_end_time", type="string"),
     *             @OA\Property(property="gallery_closed_day", type="string"),
     *             @OA\Property(property="gallery_category", type="string"),
     *             @OA\Property(property="gallery_description", type="string"),
     *             @OA\Property(property="gallery_latitude", type="number", format="float"),
     *             @OA\Property(property="gallery_longitude", type="number", format="float")
     *         )
     *     ),
     *     @OA\Response(response=201, description="갤러리 생성 완료")
     * )
     */
    public function createGallery() {
        $data = json_decode(file_get_contents("php://input"), true);
        $created = $this->model->create($data);
        http_response_code(201);
        echo json_encode($created, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Put(
     *     path="/api/galleries/{id}",
     *     summary="갤러리 수정",
     *     tags={"Gallery"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="gallery_name", type="string"),
     *             @OA\Property(property="gallery_image", type="string"),
     *             @OA\Property(property="gallery_address", type="string"),
     *             @OA\Property(property="gallery_start_time", type="string"),
     *             @OA\Property(property="gallery_end_time", type="string"),
     *             @OA\Property(property="gallery_closed_day", type="string"),
     *             @OA\Property(property="gallery_category", type="string"),
     *             @OA\Property(property="gallery_description", type="string")
     *         )
     *     ),
     *     @OA\Response(response=200, description="갤러리 수정 완료")
     * )
     */
    public function updateGallery($id) {
        $data = json_decode(file_get_contents("php://input"), true);
        $updated = $this->model->update($id, $data);
        http_response_code(200);
        echo json_encode($updated, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Delete(
     *     path="/api/galleries/{id}",
     *     summary="갤러리 삭제",
     *     tags={"Gallery"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="갤러리 삭제 완료")
     * )
     */
    public function deleteGallery($id) {
        $this->model->delete($id);
        http_response_code(200);
        echo json_encode(['message' => 'Gallery deleted'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Get(
     *     path="/api/galleries",
     *     summary="갤러리 목록 조회",
     *     tags={"Gallery"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="regions", in="query", description="서울/경기,인천/부산,울산,경남(여러개이면 콤마로 구분)", @OA\Schema(type="string")),
     *     @OA\Parameter(name="type", in="query", description="미술관/박물관/갤러리/복합문화공간/대안공간", @OA\Schema(type="string")),
     *     @OA\Parameter(name="latitude", in="query", @OA\Schema(type="number", format="float")),
     *     @OA\Parameter(name="longitude", in="query", @OA\Schema(type="number", format="float")),
     *     @OA\Parameter(name="distance", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="search", in="query", description="gallery_name 기반 검색 시 사용", @OA\Schema(type="string")),
     *     @OA\Parameter(name="liked_only", in="query",description="내가 좋아요한 갤러리만 보기 (true/false)",required=false,@OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="조회 성공")
     * )
     */
     public function getGalleryList() {
        $decoded = $this->auth->decodeToken();
        $user_id = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;
        $likedOnly = $_GET['liked_only'] ?? null;
        $likedOnlyBool = filter_var($likedOnly, FILTER_VALIDATE_BOOLEAN);

        if ($likedOnlyBool && !$user_id) {
            http_response_code(401);
            echo json_encode(['message' => '로그인 후 사용 가능합니다.']);
            return;
        }
        
        // 콘솔 API 통합하며 검색을 위한 gallery_name파라미터가 search로 변경 및 통일됨
        $filters = [
            'regions'   => $_GET['regions'] ?? null,
            'type'      => $_GET['type'] ?? null,
            'latitude'  => $_GET['latitude'] ?? null,
            'longitude' => $_GET['longitude'] ?? null,
            'distance'  => $_GET['distance'] ?? null,
            'search'    => $_GET['search'] ?? null,
            'liked_only'=> $likedOnly,
            'user_id'   => $user_id
        ];

        // gallery 정보 불러옴
        $galleries = $this->model->getGalleries($filters);

        // 조회된 gallery가 없으면 빈 배열 반환함
        if (empty($galleries)) {
            header('Content-Type: application/json');
            echo json_encode([], JSON_UNESCAPED_UNICODE);
            return;
        }

        // $galleries 배열을 순회하며 각 $gallery에 전시회 정보 추가
        foreach ($galleries as $gallery) {
            // $gallery의 id조회
            $galleryId = is_object($gallery) ? $gallery->id : $gallery['id'];
            
            // 전시회 관련 정보 및 전시회 총 개수 계산
            $exhibitionFilters = ['gallery_id' => $galleryId];
            $exhibitions = $this->exhibitionModel->getExhibitions($exhibitionFilters);
            $exhibitionCount = count($exhibitions);

            // $gallery에 전시회 관련 정보 추가
            if (is_object($gallery)) {
                $gallery->exhibitions = $exhibitions;
                $gallery->exhibition_count = $exhibitionCount;
            } else {
                $gallery['exhibitions'] = $exhibitions;
                $gallery['exhibition_count'] = $exhibitionCount;
            }
        }
        unset($gallery);

        header('Content-Type: application/json');
        echo json_encode($galleries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

      /**
     * @OA\Get(
     *     path="/api/galleries/{id}",
     *     summary="갤러리 상세 조회",
     *     tags={"Gallery"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="상세 조회 성공"),
     *     @OA\Response(response=404, description="갤러리 없음")
     * )
     */
    public function getGalleryById($id) {
        $decoded = $this->auth->decodeToken();
        $user_id = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;

        $gallery = $this->model->getById($id, $user_id);
        
        // $gallery에 전시회 관련 정보 추가
        if ($gallery) {
            $filters = ['gallery_id' => $id];
            $exhibitions = $this->exhibitionModel->getExhibitions($filters);
            $exhibitionCount = count($exhibitions);

            if (is_object($gallery)) {
                $gallery->exhibitions = $exhibitions;
                $gallery->exhibition_count = $exhibitionCount;
            } else {
                $gallery['exhibitions'] = $exhibitions;
                $gallery['exhibition_count'] = $exhibitionCount;
            }

            header('Content-Type: application/json');
            echo json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Gallery not found']);
        }
    }


}

