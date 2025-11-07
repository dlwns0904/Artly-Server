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

    // ★ 추가: 응답용 image_url 생성(상대 경로)
    private function buildImageUrl($id) {               // ★ 추가
        return "/api/galleries/{$id}/image";            // ★ 추가
    }                                                   // ★ 추가

    /**
     * @OA\Post(
     *   path="/api/galleries",
     *   summary="갤러리 생성",
     *   tags={"Gallery"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         type="object",
     *         required={"gallery_name","gallery_image"},
     *         @OA\Property(property="gallery_name", type="string"),
     *         @OA\Property(property="gallery_image", type="string", format="binary"),
     *         @OA\Property(property="gallery_address", type="string"),
     *         @OA\Property(property="gallery_start_time", type="string"),
     *         @OA\Property(property="gallery_end_time", type="string"),
     *         @OA\Property(property="gallery_closed_day", type="string"),
     *         @OA\Property(property="gallery_category", type="string"),
     *         @OA\Property(property="gallery_description", type="string"),
     *         @OA\Property(property="gallery_latitude", type="number", format="float"),
     *         @OA\Property(property="gallery_longitude", type="number", format="float"),
     *         @OA\Property(property="gallery_phone", type="string"),
     *         @OA\Property(property="gallery_email", type="string"),
     *         @OA\Property(property="gallery_homepage", type="string"),
     *         @OA\Property(property="gallery_sns", type="string", description="JSON 문자열")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=201, description="갤러리 생성 완료")
     * )
     */
    public function createGallery() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // 항상 JSON으로 응답
    header('Content-Type: application/json; charset=utf-8');

    if (stripos($contentType, 'multipart/form-data') !== false) {
        $post = $_POST;
        $file = $_FILES['gallery_image'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => '이미지 업로드 실패'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // MIME 검증
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        if (!in_array($mime, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => '지원하지 않는 이미지 타입'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $data = [
            'gallery_name'        => $post['gallery_name']        ?? null,
            'gallery_address'     => $post['gallery_address']     ?? null,
            'gallery_start_time'  => $post['gallery_start_time']  ?? null,
            'gallery_end_time'    => $post['gallery_end_time']    ?? null,
            'gallery_closed_day'  => $post['gallery_closed_day']  ?? null,
            'gallery_category'    => $post['gallery_category']    ?? null,
            'gallery_description' => $post['gallery_description'] ?? null,
            'gallery_latitude'    => $post['gallery_latitude']    ?? null,
            'gallery_longitude'   => $post['gallery_longitude']   ?? null,
            'gallery_phone'       => $post['gallery_phone']       ?? null,
            'gallery_email'       => $post['gallery_email']       ?? null,
            'gallery_homepage'    => $post['gallery_homepage']    ?? null,
            'gallery_sns'         => $post['gallery_sns']         ?? null,
            'user_id'             => $post['user_id']             ?? null,

            // BLOB
            'gallery_image_stream'=> fopen($file['tmp_name'], 'rb'),
            'gallery_image_mime'  => $mime,
            'gallery_image_name'  => $file['name'],
            'gallery_image_size'  => (int)$file['size'],
        ];

        $created = $this->model->create($data); // ← 메서드명 유지
        if (is_resource($data['gallery_image_stream'])) fclose($data['gallery_image_stream']);

        // BLOB은 응답에서 제외하고, id/메타만 리턴
        http_response_code(201);
        echo json_encode([
            'id'                  => $created['id'],
            'gallery_name'        => $created['gallery_name'],
            'gallery_image_mime'  => $created['gallery_image_mime'] ?? null,
            'gallery_image_name'  => $created['gallery_image_name'] ?? null,
            'gallery_image_size'  => $created['gallery_image_size'] ?? null,
            'image_url'           => $this->buildImageUrl($created['id']),   // ★ 추가
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // (폴백) JSON 경로
    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $created = $this->model->create($data);

    http_response_code(201);
    echo json_encode([
        'id'           => $created['id'],
        'gallery_name' => $created['gallery_name'],
        'image_url'    => $this->buildImageUrl($created['id']),          // ★ 추가
    ], JSON_UNESCAPED_UNICODE);
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
        foreach ($galleries as &$gallery) {
            // $gallery의 id조회
            $galleryId = is_object($gallery) ? $gallery->id : $gallery['id'];
            
            // ★ 추가: JSON 응답에서 BLOB 제거 + image_url 부여
            if (is_object($gallery)) {                                    // ★ 추가
                if (isset($gallery->gallery_image)) unset($gallery->gallery_image); // ★ 추가
                $gallery->image_url = $this->buildImageUrl($galleryId);   // ★ 추가
            } else {                                                       // ★ 추가
                if (isset($gallery['gallery_image'])) unset($gallery['gallery_image']); // ★ 추가
                $gallery['image_url'] = $this->buildImageUrl($galleryId);  // ★ 추가
            }                                                               // ★ 추가

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
    if (!$gallery) {
        http_response_code(404);
        echo json_encode(['message' => 'Gallery not found'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 🔧 최종 보정: 모델에서 못 넣어줬더라도 여기서 보장
    if (is_array($gallery)) {
        if (!isset($gallery['image_url'])) {
            $gallery['image_url'] = "/api/galleries/{$id}/image";
        }
        unset($gallery['gallery_image']); // BLOB 제거
    } elseif (is_object($gallery)) {
        if (!isset($gallery->image_url)) {
            $gallery->image_url = "/api/galleries/{$id}/image";
        }
        unset($gallery->gallery_image); // BLOB 제거
    }

    // 전시 추가 …
    $filters = ['gallery_id' => $id];
    $exhibitions = $this->exhibitionModel->getExhibitions($filters);
    $exhibitionCount = count($exhibitions);

    if (is_array($gallery)) {
        $gallery['exhibitions'] = $exhibitions;
        $gallery['exhibition_count'] = $exhibitionCount;
    } else {
        $gallery->exhibitions = $exhibitions;
        $gallery->exhibition_count = $exhibitionCount;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}



    /**
     * @OA\Get(
     *   path="/api/galleries/{id}/image",
     *   summary="갤러리 이미지 다운로드",
     *   tags={"Gallery"},
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="image binary"),
     *   @OA\Response(response=404, description="not found")
     * )
     */
    public function getGalleryImage($id) {                      // ★ 추가
        $row = $this->model->getImageById($id);                 // ★ 추가
        if (!$row || empty($row['gallery_image'])) {            // ★ 추가
            http_response_code(404);                            // ★ 추가
            echo 'Not Found';                                   // ★ 추가
            return;                                             // ★ 추가
        }                                                       // ★ 추가
        $mime = $row['gallery_image_mime'] ?? 'application/octet-stream'; // ★ 추가
        header('Content-Type: ' . $mime);                       // ★ 추가
        if (!empty($row['gallery_image_size'])) {               // ★ 추가
            header('Content-Length: ' . (int)$row['gallery_image_size']); // ★ 추가
        }                                                       // ★ 추가
        header('Cache-Control: public, max-age=31536000, immutable'); // ★ 추가
        $img = $row['gallery_image'];                           // ★ 추가
        if (is_resource($img)) { fpassthru($img); } else { echo $img; } // ★ 추가
    }                                                           // ★ 추가
}
