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

    /** 내부 유틸: 외부 URL 여부 */
    private function isExternalUrl(?string $val): bool {
        return is_string($val) && preg_match('#^https?://#i', $val);
    }

    /** 내부 유틸: 상대경로를 절대 URL로 변환 */
    private function toAbsoluteUrl(?string $path): ?string {
        if (!$path) return null;
        if ($this->isExternalUrl($path)) return $path;

        // /media/... 또는 media/... 모두 지원
        $clean = ltrim($path, '/');
        $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
            ? $_SERVER['HTTP_X_FORWARDED_PROTO']
            : (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/' . $clean;
    }

    /** 내부 유틸: 업로드 저장 (media/gallery/YYYY/MM) */
    private function saveUploadedImage(array $file, string $subdir = 'gallery'): string {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('이미지 업로드 실패');
        }

        // MIME 확인
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            throw new \InvalidArgumentException('허용되지 않는 이미지 형식입니다 (jpg/png/webp/gif)');
        }

        // 디렉토리
        $ym   = date('Y/m');
        $base = __DIR__ . '/../media/' . $subdir . '/' . $ym;      // 실제 서버 저장 경로
        $rel  = 'media/' . $subdir . '/' . $ym;                    // DB 저장용 상대경로 베이스

        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('이미지 디렉토리를 생성할 수 없습니다');
        }

        // 파일명
        $ext  = $allowed[$mime];
        $name = bin2hex(random_bytes(8)) . '.' . $ext;

        $dest = $base . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('이미지 저장 실패');
        }

        // 퍼미션(선택)
        @chmod($dest, 0644);

        // DB에는 상대경로로 저장 (예: media/gallery/2025/11/abc1234.png)
        return $rel . '/' . $name;
    }

    /**
     * @OA\Post(
     *     path="/api/galleries",
     *     summary="갤러리 생성 (multipart 또는 JSON)",
     *     tags={"Gallery"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *           mediaType="multipart/form-data",
     *           @OA\Schema(
     *             type="object",
     *             @OA\Property(property="gallery_name", type="string"),
     *             @OA\Property(property="gallery_eng_name", type="string"),
     *             @OA\Property(property="gallery_address", type="string"),
     *             @OA\Property(property="gallery_start_time", type="string", example="10:00"),
     *             @OA\Property(property="gallery_end_time", type="string", example="19:00"),
     *             @OA\Property(property="gallery_closed_day", type="string"),
     *             @OA\Property(property="gallery_category", type="string", example="미술관"),
     *             @OA\Property(property="gallery_description", type="string"),
     *             @OA\Property(property="gallery_latitude", type="number", format="float"),
     *             @OA\Property(property="gallery_longitude", type="number", format="float"),
     *             @OA\Property(property="gallery_phone", type="string"),
     *             @OA\Property(property="gallery_email", type="string"),
     *             @OA\Property(property="gallery_homepage", type="string"),
     *             @OA\Property(property="gallery_sns", type="string", description="JSON 배열 문자열"),
     *             @OA\Property(property="gallery_image_file", type="string", format="binary"),
     *             @OA\Property(property="gallery_image_url", type="string", description="이미지 URL을 그대로 저장하고 싶을 때")
     *           )
     *         )
     *     ),
     *     @OA\Response(response=201, description="갤러리 생성 완료")
     * )
     */
    public function createGallery() {
        $decoded = $this->auth->decodeToken();
        $userId  = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;

        // 1) multipart라면 $_POST/$_FILES, 2) 아니면 JSON으로 처리
        $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;

        if ($isMultipart) {
            $data = [
                'gallery_name'       => $_POST['gallery_name']       ?? null,
                'gallery_eng_name'   => $_POST['gallery_eng_name']   ?? null,
                'gallery_address'    => $_POST['gallery_address']    ?? null,
                'gallery_start_time' => $_POST['gallery_start_time'] ?? null,
                'gallery_end_time'   => $_POST['gallery_end_time']   ?? null,
                'gallery_closed_day' => $_POST['gallery_closed_day'] ?? null,
                'gallery_category'   => $_POST['gallery_category']   ?? null,
                'gallery_description'=> $_POST['gallery_description']?? null,
                'gallery_latitude'   => $_POST['gallery_latitude']   ?? null,
                'gallery_longitude'  => $_POST['gallery_longitude']  ?? null,
                'gallery_phone'      => $_POST['gallery_phone']      ?? null,
                'gallery_email'      => $_POST['gallery_email']      ?? null,
                'gallery_homepage'   => $_POST['gallery_homepage']   ?? null,
                'gallery_sns'        => $_POST['gallery_sns']        ?? null,
                'user_id'            => $userId,
            ];

            // 파일 우선
            if (!empty($_FILES['gallery_image_file']) && $_FILES['gallery_image_file']['error'] === UPLOAD_ERR_OK) {
                $relPath = $this->saveUploadedImage($_FILES['gallery_image_file'], 'gallery');
                $data['gallery_image'] = $relPath;   // DB에는 상대경로 저장
            } else {
                // 파일이 없고, URL이 왔다면 그대로 저장
                $url = $_POST['gallery_image_url'] ?? null;
                $data['gallery_image'] = $url ?: null;
            }
        } else {
            // JSON
            $data = json_decode(file_get_contents("php://input"), true) ?? [];
            $data['user_id'] = $userId;
        }

        $created = $this->model->create($data);

        // 응답 시 이미지 절대 URL 변환
        if (is_array($created) && isset($created['gallery_image'])) {
            $created['gallery_image'] = $this->toAbsoluteUrl($created['gallery_image']);
        }

        http_response_code(201);
        header('Content-Type: application/json');
        echo json_encode($created, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Post(
     * path="/api/galleries/{id}",
     * summary="[PATCH with method spoofing] 갤러리 일부 수정 (multipart 또는 JSON)",
     * tags={"Gallery"},
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     * description="[매우중요] multipart/form-data로 파일과 함께 요청 시, 실제로는 POST 메서드를 사용하고 본문에 `_method=PATCH` 필드를 포함해야 합니다. (Method Spoofing)",
     * @OA\RequestBody(
     * required=true,
     * description="수정할 필드만 포함하여 전송합니다. (부분 업데이트)",
     * @OA\MediaType(
     * mediaType="multipart/form-data",
     * @OA\Schema(
     * type="object",
     * @OA\Property(
     * property="_method", 
     * type="string", 
     * enum={"PATCH"}, 
     * example="PATCH", 
     * description="Method Spoofing을 위해 'PATCH' 값을 전송해야 합니다."
     * ),
     * @OA\Property(property="gallery_name", type="string", description="갤러리 이름", nullable=true),
     * @OA\Property(property="gallery_eng_name", type="string", description="갤러리 영문 이름", nullable=true),
     * @OA\Property(property="gallery_address", type="string", description="갤러리 주소", nullable=true),
     * @OA\Property(property="gallery_start_time", type="string", example="10:00", description="오픈 시간", nullable=true),
     * @OA\Property(property="gallery_end_time", type="string", example="19:00", description="마감 시간", nullable=true),
     * @OA\Property(property="gallery_closed_day", type="string", description="휴관일", nullable=true),
     * @OA\Property(property="gallery_category", type="string", description="갤러리 카테고리", nullable=true),
     * @OA\Property(property="gallery_description", type="string", description="갤러리 설명", nullable=true),
     * @OA\Property(property="gallery_latitude", type="number", format="float", description="위도", nullable=true),
     * @OA\Property(property="gallery_longitude", type="number", format="float", description="경도", nullable=true),
     * @OA\Property(property="gallery_phone", type="string", description="전화번호", nullable=true),
     * @OA\Property(property="gallery_email", type="string", description="이메일", nullable=true),
     * @OA\Property(property="gallery_homepage", type="string", description="홈페이지 URL", nullable=true),
     * @OA\Property(property="gallery_sns", type="string", description="SNS 링크 (JSON 배열 문자열)", nullable=true),
     * @OA\Property(property="gallery_image_file", type="string", format="binary", description="새로 업로드할 이미지 파일", nullable=true),
     * @OA\Property(property="gallery_image_url", type="string", description="이미지 URL을 직접 지정할 때 (파일 업로드와 동시 사용 불가)", nullable=true)
     * )
     * ),
     * @OA\MediaType(
     * mediaType="application/json",
     * @OA\Schema(
     * type="object",
     * @OA\Property(property="gallery_name", type="string", nullable=true),
     * @OA\Property(property="gallery_address", type="string", nullable=true),
     * @OA\Property(property="gallery_start_time", type="string", example="10:00", nullable=true),
     * @OA\Property(property="gallery_image_url", type="string", description="이미지 URL을 직접 지정할 때", nullable=true)
     * )
     * )
     * ),
     * @OA\Response(response=200, description="갤러리 수정 완료"),
     * @OA\Response(response=404, description="갤러리를 찾을 수 없음")
     * )
     */
    public function updateGallery($id) { 
        $decoded = $this->auth->decodeToken();
        $userId  = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;

        $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;

        // PATCH 로직을 위해 빈 배열로 시작
        $data = []; 
        $data['user_id'] = $userId; // user_id는 항상 포함

        if ($isMultipart) {
            // isset()으로 체크하여 '존재하는 값만' $data에 추가
            if (isset($_POST['gallery_name'])) {
                $data['gallery_name'] = $_POST['gallery_name'];
            }
            if (isset($_POST['gallery_eng_name'])) {
                $data['gallery_eng_name'] = $_POST['gallery_eng_name'];
            }
            if (isset($_POST['gallery_address'])) {
                $data['gallery_address'] = $_POST['gallery_address'];
            }
            if (isset($_POST['gallery_start_time'])) {
                $data['gallery_start_time'] = $_POST['gallery_start_time'];
            }
            if (isset($_POST['gallery_end_time'])) {
                $data['gallery_end_time'] = $_POST['gallery_end_time'];
            }
            if (isset($_POST['gallery_closed_day'])) {
                $data['gallery_closed_day'] = $_POST['gallery_closed_day'];
            }
            if (isset($_POST['gallery_category'])) {
                $data['gallery_category'] = $_POST['gallery_category'];
            }
            if (isset($_POST['gallery_description'])) {
                $data['gallery_description'] = $_POST['gallery_description'];
            }
            if (isset($_POST['gallery_latitude'])) {
                $data['gallery_latitude'] = $_POST['gallery_latitude'];
            }
            if (isset($_POST['gallery_longitude'])) {
                $data['gallery_longitude'] = $_POST['gallery_longitude'];
            }
            if (isset($_POST['gallery_phone'])) {
                $data['gallery_phone'] = $_POST['gallery_phone'];
            }
            if (isset($_POST['gallery_email'])) {
                $data['gallery_email'] = $_POST['gallery_email'];
            }
            if (isset($_POST['gallery_homepage'])) {
                $data['gallery_homepage'] = $_POST['gallery_homepage'];
            }
            if (isset($_POST['gallery_sns'])) {
                $data['gallery_sns'] = $_POST['gallery_sns'];
            }
            
            // --- 파일 처리 로직 (이 부분은 PUT과 동일해도 됨) ---
            if (!empty($_FILES['gallery_image_file']) && $_FILES['gallery_image_file']['error'] === UPLOAD_ERR_OK) {
                // 새 파일 업로드가 있으면 교체
                $relPath = $this->saveUploadedImage($_FILES['gallery_image_file'], 'gallery');
                $data['gallery_image'] = $relPath;
            } elseif (isset($_POST['gallery_image_url'])) {
                // URL로 교체 요청이 있으면 반영 (빈 문자열을 보내면 null로 업데이트 가능)
                $data['gallery_image'] = $_POST['gallery_image_url'] ?: null;
            }
            // --- 파일 처리 끝 ---

        } else {
            // JSON 방식은 이미 PATCH처럼 동작
            $jsonData = json_decode(file_get_contents("php://input"), true) ?? [];
            $data = array_merge($data, $jsonData); // user_id와 JSON 데이터 병합
        }

        // $data 배열에 '있는' 키만 업데이트하도록 구현
        $updated = $this->model->update($id, $data);

        // --- 응답 로직 (동일) ---
        if (is_array($updated) && isset($updated['gallery_image'])) {
            $updated['gallery_image'] = $this->toAbsoluteUrl($updated['gallery_image']);
        }

        http_response_code(200);
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Gallery deleted'], JSON_UNESCAPED_UNICODE);
    }

    /**
 * @OA\Get(
 *     path="/api/galleries",
 *     summary="갤러리 목록 조회",
 *     tags={"Gallery"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="regions", in="query", description="여러개이면 콤마로 구분", @OA\Schema(type="string")),
 *     @OA\Parameter(name="type", in="query", description="미술관/박물관/갤러리/복합문화공간/대안공간", @OA\Schema(type="string")),
 *     @OA\Parameter(name="latitude", in="query", @OA\Schema(type="number", format="float")),
 *     @OA\Parameter(name="longitude", in="query", @OA\Schema(type="number", format="float")),
 *     @OA\Parameter(name="distance", in="query", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="search", in="query", description="gallery_name 검색", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liked_only", in="query", description="내가 좋아요한 갤러리만 (true/false)", @OA\Schema(type="boolean")),
 *     @OA\Parameter(
*         name="userId",
*         in="query",
*         description="해당 userId가 소유한 갤러리만 조회 (admin 또는 본인만)",
*         @OA\Schema(type="integer")
*     ),
 *     @OA\Response(response=200, description="조회 성공")
 * )
 */
public function getGalleryList() {
    $decoded = $this->auth->decodeToken();
    $user_id = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;
    $role    = $decoded && isset($decoded->role) ? $decoded->role : null;

    $likedOnly     = $_GET['liked_only'] ?? null;
    $likedOnlyBool = filter_var($likedOnly, FILTER_VALIDATE_BOOLEAN);

    // 🔁 기존 liked_only 로직 그대로 유지
    if ($likedOnlyBool && !$user_id) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['message' => '로그인 후 사용 가능합니다.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $targetUserId = isset($_GET['userId']) ? (int)$_GET['userId'] : null;

    // 0 이하인 값은 무시
    if ($targetUserId !== null && $targetUserId > 0) {
        if ($role !== 'admin' && $targetUserId !== (int)$user_id) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['message' => '해당 사용자 갤러리에 접근 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $filters = [
            'admin_only' => true,
            'user_id'    => $targetUserId,
        ];
    } else {
        // 기존 공개용 필터 그대로
        $filters = [
            'regions'   => $_GET['regions'] ?? null,
            'type'      => $_GET['type'] ?? null,
            'latitude'  => $_GET['latitude'] ?? null,
            'longitude' => $_GET['longitude'] ?? null,
            'distance'  => $_GET['distance'] ?? null,
            'search'    => $_GET['search'] ?? null,
            'liked_only'=> $likedOnly,
            'user_id'   => $user_id,
        ];
    }

    $galleries = $this->model->getGalleries($filters);
    if (empty($galleries)) {
        header('Content-Type: application/json');
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 이미지 경로 절대 URL 변환 
    foreach ($galleries as &$g) {
        if (isset($g['gallery_image'])) {
            $g['gallery_image'] = $this->toAbsoluteUrl($g['gallery_image']);
        }
    }
    unset($g);

    // 전시 연결 
    foreach ($galleries as &$gallery) {
        $galleryId = is_array($gallery) ? $gallery['id'] : (is_object($gallery) ? $gallery->id : null);
        if (!$galleryId) continue;

        $exhibitionFilters = ['gallery_id' => $galleryId];
        $exhibitions = $this->exhibitionModel->getExhibitions($exhibitionFilters);
        $exhibitionCount = count($exhibitions);

        if (is_array($gallery)) {
            $gallery['exhibitions'] = $exhibitions;
            $gallery['exhibition_count'] = $exhibitionCount;
        } else {
            $gallery->exhibitions = $exhibitions;
            $gallery->exhibition_count = $exhibitionCount;
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
            echo json_encode(['message' => 'Gallery not found']);
            return;
        }

        // 
        $filters = ['gallery_id' => $id];
        $exhibitions = $this->exhibitionModel->getExhibitions($filters);
        $exhibitionCount = count($exhibitions);

        // 배열/객체 양쪽 대응 + 이미지 절대 URL 변환 추가
        if (is_array($gallery)) {
            $gallery['exhibitions'] = $exhibitions;
            $gallery['exhibition_count'] = $exhibitionCount;
            if (isset($gallery['gallery_image'])) {
                $gallery['gallery_image'] = $this->toAbsoluteUrl($gallery['gallery_image']);
            }
        } else {
            $gallery->exhibitions = $exhibitions;
            $gallery->exhibition_count = $exhibitionCount;
            if (isset($gallery->gallery_image)) {
                $gallery->gallery_image = $this->toAbsoluteUrl($gallery->gallery_image);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
