<?php
namespace Controllers;

use Models\ExhibitionModel;
use Models\UserModel;
use Models\GalleryModel;
use Middlewares\AuthMiddleware;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Exhibition",
 *     description="전시회 관련 API"
 * )
 */
class ExhibitionController {
    private $model;
    private $userModel;
    private $galleryModel;
    private $auth;

    public function __construct() {
        $this->model = new ExhibitionModel();
        $this->userModel = new UserModel();
        $this->galleryModel = new GalleryModel();
        $this->auth = new AuthMiddleware();
    }

    /** 외부 URL 여부 */
    private function isExternalUrl(?string $val): bool {
        return is_string($val) && preg_match('#^https?://#i', $val);
    }

    /** 상대경로 -> 절대 URL 변환 */
    private function toAbsoluteUrl(?string $path): ?string {
        if (!$path) return null;
        if ($this->isExternalUrl($path)) return $path;
        $clean  = ltrim($path, '/'); // media/... or /media/...
        $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
            ? $_SERVER['HTTP_X_FORWARDED_PROTO']
            : (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http');
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/' . $clean;
    }

    /** 이미지 저장 (media/exhibition/YYYY/MM) 후 DB용 상대경로 반환 */
    private function saveUploadedImage(array $file, string $subdir = 'exhibition'): string {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('포스터 업로드 실패');
        }
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

        $ym   = date('Y/m');
        $base = __DIR__ . '/../media/' . $subdir . '/' . $ym; // 서버 실제 경로
        $rel  = 'media/' . $subdir . '/' . $ym;               // DB 저장 상대경로

        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('이미지 디렉토리 생성 실패');
        }

        $ext  = $allowed[$mime];
        $name = bin2hex(random_bytes(8)) . '.' . $ext;

        $dest = $base . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('이미지 저장 실패');
        }
        @chmod($dest, 0644);
        return $rel . '/' . $name; // 예: media/exhibition/2025/11/abcd1234.png
    }

    /**
     * @OA\Get(
     * path="/api/exhibitions",
     * summary="전시회 목록 조회",
     * tags={"Exhibition"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(name="status", in="query", description="scheduled/exhibited/ended", @OA\Schema(type="string")),
     * @OA\Parameter(name="category", in="query", @OA\Schema(type="string")),
     * @OA\Parameter(name="region", in="query", @OA\Schema(type="string")),
     * @OA\Parameter(name="sort", in="query", description="latest/ending/popular", @OA\Schema(type="string")),
     * @OA\Parameter(name="liked_only", in="query", @OA\Schema(type="boolean")),
     * @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     * @OA\Parameter(
     * name="gallery_name", 
     * in="query", 
     * description="갤러리명 검색 (쉼표로 구분하여 여러 개 검색 가능)", 
     * @OA\Schema(type="string", example="서울 현대 미술관,예술의전당")
     * ),
     * @OA\Response(response=200, description="전시회 목록 조회 성공")
     * )
     */
    public function getExhibitionList() {
        $auth = new AuthMiddleware();
        $decoded = $auth->decodeToken();
        $user_id = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;
        $likedOnly = $_GET['liked_only'] ?? null;
        $likedOnlyBool = filter_var($likedOnly, FILTER_VALIDATE_BOOLEAN);

        if ($likedOnlyBool && !$user_id) {
            http_response_code(401);
            echo json_encode(['message' => 'Authentication required for liked_only filter.']);
            return;
        }

        $filters = [
            'status'     => $_GET['status'] ?? null,
            'category'   => $_GET['category'] ?? null,
            'region'     => $_GET['region'] ?? null,
            'sort'       => $_GET['sort'] ?? null,
            'liked_only' => $likedOnly,
            'user_id'    => $user_id,
            'search'     => $_GET['search'] ?? null
        ];

        // ---------------------------------------------------------
        // [수정] 갤러리 ID 구하기 (배열로 수집)
        // ---------------------------------------------------------
        $targetGalleryIds = [];
        if (!empty($_GET['gallery_name'])) {
            $gNames = explode(',', $_GET['gallery_name']);
            $gNames = array_map('trim', $gNames);
            
            foreach ($gNames as $name) {
                if (empty($name)) continue;
                $galleryList = $this->galleryModel->getGalleries(['search' => $name]);
                if (!empty($galleryList)) {
                    foreach ($galleryList as $g) {
                        $targetGalleryIds[] = $g['id'];
                    }
                }
            }
            
            // 검색어는 있는데 결과가 없으면 404
            if (empty($targetGalleryIds)) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['message' => '해당 이름의 갤러리를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return;
            }
            
            // 중복 ID 제거
            $targetGalleryIds = array_unique($targetGalleryIds);
        }

        // ---------------------------------------------------------
        // [수정] Model 호출 로직 (다중 ID 대응)
        // ---------------------------------------------------------
        $exhibitions = [];

        if (!empty($targetGalleryIds)) {
            // Case 1: 갤러리 필터가 있는 경우 -> ID 별로 각각 호출해서 합침
            foreach ($targetGalleryIds as $gId) {
                // 모델은 단일 ID(int/string)만 받을 수 있으므로 하나씩 넣음
                $filters['gallery_id'] = $gId; 
                
                // 해당 갤러리의 전시회 가져오기
                $part = $this->model->getExhibitions($filters);
                
                // 결과 합치기 (기존 목록 + 새 목록)
                $exhibitions = array_merge($exhibitions, $part);
            }
        } else {
            // Case 2: 갤러리 필터가 없는 경우 -> 그냥 한 번만 호출
            $exhibitions = $this->model->getExhibitions($filters);
        }

        // ---------------------------------------------------------
        // [추가] PHP 레벨 재정렬 (선택 사항)
        // 모델을 여러번 호출해서 합쳤기 때문에, [A갤러리 최신순] + [B갤러리 최신순] 형태로 되어있음.
        // 이를 전체 기준으로 다시 섞어주는 것이 좋음. (기본값: 최신순)
        // ---------------------------------------------------------
        if (!empty($targetGalleryIds) && count($targetGalleryIds) > 1) {
            $sortType = $_GET['sort'] ?? 'latest';
            usort($exhibitions, function($a, $b) use ($sortType) {
                if ($sortType === 'ending') {
                    // 마감임박순 (날짜 오름차순)
                    return strcmp($a['exhibition_end_date'], $b['exhibition_end_date']);
                } elseif ($sortType === 'popular') {
                    // 인기순 (좋아요 내림차순)
                    return $b['like_count'] - $a['like_count'];
                } else {
                    // 최신순 (생성일 내림차순) - 기본값
                    return strcmp($b['create_dtm'], $a['create_dtm']);
                }
            });
        }

        // ✅ 포스터/조직 이미지 절대 URL 변환
        foreach ($exhibitions as &$e) {
            if (isset($e['exhibition_poster'])) {
                $e['exhibition_poster'] = $this->toAbsoluteUrl($e['exhibition_poster']);
            }
            if (isset($e['exhibition_organization']['image'])) {
                $e['exhibition_organization']['image'] = $this->toAbsoluteUrl($e['exhibition_organization']['image']);
            }
        }
        unset($e);

        header('Content-Type: application/json');
        echo json_encode($exhibitions, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Get(
     *     path="/api/exhibitions/{id}",
     *     summary="전시회 상세 조회",
     *     tags={"Exhibition"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="전시회 상세 조회 성공"),
     *     @OA\Response(response=404, description="전시회 없음")
     * )
     */
    public function getExhibitionById($id) {
    $user_id = AuthMiddleware::getUserId();
    $exhibition = $this->model->getExhibitionDetailById($id, $user_id);

    if (!$exhibition) {
        http_response_code(404);
        echo json_encode(['message' => 'Exhibition not found']);
        return;
    }

    //갤러리 상세 붙이기
    if (!empty($exhibition['gallery_id'])) {
        // 사용자 ID 없이 호출 (동일 동작 유지)
        $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
        // 갤러리 이미지도 절대 URL로 변환
        if (is_array($gallery) && isset($gallery['gallery_image'])) {
            $gallery['gallery_image'] = $this->toAbsoluteUrl($gallery['gallery_image']);
        }
        $exhibition['gallery'] = $gallery;
    } else {
        $exhibition['gallery'] = null;
    }

    // 전시 포스터 절대 URL 변환
    if (isset($exhibition['exhibition_poster'])) {
        $exhibition['exhibition_poster'] = $this->toAbsoluteUrl($exhibition['exhibition_poster']);
    }

    header('Content-Type: application/json');
    echo json_encode($exhibition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

    /**
     * @OA\Post(
     *   path="/api/exhibitions",
     *   summary="전시회 등록 (multipart 또는 JSON)",
     *   tags={"Exhibition"},
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         type="object",
     *         @OA\Property(property="gallery_id", type="integer", example=123),
     *         @OA\Property(property="exhibition_title", type="string"),
     *         @OA\Property(property="exhibition_category", type="string"),
     *         @OA\Property(property="exhibition_start_date", type="string", format="date"),
     *         @OA\Property(property="exhibition_end_date", type="string", format="date"),
     *         @OA\Property(property="exhibition_start_time", type="string", format="time"),
     *         @OA\Property(property="exhibition_end_time", type="string", format="time"),
     *         @OA\Property(property="exhibition_location", type="string"),
     *         @OA\Property(property="exhibition_price", type="integer"),
     *         @OA\Property(property="exhibition_tag", type="string"),
     *         @OA\Property(property="exhibition_status", type="string", enum={"scheduled","exhibited","ended"}),
     *         @OA\Property(property="exhibition_phone", type="string"),
     *         @OA\Property(property="exhibition_homepage", type="string"),
     *         @OA\Property(property="exhibition_poster_file", type="string", format="binary"),
     *         @OA\Property(property="exhibition_poster_url", type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=201, description="등록 성공")
     * )
     */
    public function createExhibition() {
    $user = $this->auth->authenticate(); // JWT 검사만 유지

    // 요청 형태 판단
    $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;

    // 공통 데이터 파싱
    if ($isMultipart) {
        // ✅ gallery_id 프론트에서 받기 (필수)
        $gallery_id = isset($_POST['gallery_id']) ? (int)$_POST['gallery_id'] : 0;
        if ($gallery_id <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'gallery_id는 필수입니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $data = [
            'exhibition_title'      => $_POST['exhibition_title']      ?? null,
            'exhibition_category'   => $_POST['exhibition_category']   ?? null,
            'exhibition_start_date' => $_POST['exhibition_start_date'] ?? null,
            'exhibition_end_date'   => $_POST['exhibition_end_date']   ?? null,
            'exhibition_start_time' => $_POST['exhibition_start_time'] ?? null,
            'exhibition_end_time'   => $_POST['exhibition_end_time']   ?? null,
            'exhibition_location'   => $_POST['exhibition_location']   ?? null,
            'exhibition_price'      => $_POST['exhibition_price']      ?? null,
            'exhibition_tag'        => $_POST['exhibition_tag']        ?? null,
            'exhibition_phone'      => $_POST['exhibition_phone']      ?? null,
            'exhibition_homepage'   => $_POST['exhibition_homepage']   ?? null,
        ];

        // 파일 우선 저장, 없으면 URL
        if (!empty($_FILES['exhibition_poster_file']) && $_FILES['exhibition_poster_file']['error'] === UPLOAD_ERR_OK) {
            $relPath = $this->saveUploadedImage($_FILES['exhibition_poster_file'], 'exhibition');
            $data['exhibition_poster'] = $relPath; // DB엔 상대경로 저장
        } else {
            $url = $_POST['exhibition_poster_url'] ?? null;
            $data['exhibition_poster'] = $url ?: null;
        }
    } else {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        // ✅ gallery_id 프론트에서 받기 (필수)
        $gallery_id = isset($raw['gallery_id']) ? (int)$raw['gallery_id'] : 0;
        if ($gallery_id <= 0) {
            http_response_code(400);
            echo json_encode(['message' => 'gallery_id는 필수입니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $data = $raw;
    }

    $today = date('Y-m-d'); // 오늘 날짜 (서버 시간 기준)
    $startDate = $data['exhibition_start_date'] ?? null;
    $endDate   = $data['exhibition_end_date'] ?? null;

    if ($startDate && $endDate) {
        if ($today < $startDate) {
            // 오늘이 시작일보다 이전 -> 예정됨
            $data['exhibition_status'] = 'scheduled';
        } elseif ($today > $endDate) {
            // 오늘이 종료일보다 이후 -> 종료됨
            $data['exhibition_status'] = 'ended';
        } else {
            // 시작일 <= 오늘 <= 종료일 -> 전시중
            $data['exhibition_status'] = 'exhibited';
        }
    } else {
        // 날짜 정보가 없으면 기본값 (예: scheduled) 또는 null 유지
        $data['exhibition_status'] = null;
    }

    // 생성
    $createdExhibition = $this->model->create($data, $gallery_id);
    if ($createdExhibition) {
        // 응답 시 포스터 절대 URL 변환
        $createdExhibition['exhibition_poster'] = $this->toAbsoluteUrl($createdExhibition['exhibition_poster'] ?? null);
        http_response_code(201);
        echo json_encode(['message' => 'Exhibition created successfully', 'data' => $createdExhibition], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to create exhibition']);
    }
}


    /**
     * @OA\Post(
     * path="/api/exhibitions/{id}",
     * summary="[PATCH with method spoofing] 전시회 일부 수정 (multipart 또는 JSON)",
     * tags={"Exhibition"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     * description="[매우중요] multipart/form-data로 파일과 함께 요청 시, 실제로는 POST 메서드를 사용하고 본문에 `_method=PATCH` 필드를 포함해야 합니다. (Method Spoofing)",
     * @OA\RequestBody(
     * required=true,
     * description="수정할 필드만 포함하여 전송합니다. (부분 업데이트)",
     * @OA\MediaType(
     * mediaType="multipart/form-data",
     * @OA\Schema(
     * type="object",
     * required={"_method"},
     * @OA\Property(
     * property="_method",
     * type="string",
     * enum={"PATCH"},
     * example="PATCH",
     * description="Method Spoofing을 위해 'PATCH' 값을 전송해야 합니다."
     * ),
     * @OA\Property(property="exhibition_title", type="string", nullable=true),
     * @OA\Property(property="exhibition_description", type="string", nullable=true),
     * @OA\Property(property="exhibition_category", type="string", nullable=true),
     * @OA\Property(property="exhibition_start_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_end_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_start_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_end_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_location", type="string", nullable=true),
     * @OA\Property(property="exhibition_price", type="integer", nullable=true),
     * @OA\Property(property="exhibition_tag", type="string", nullable=true),
     * @OA\Property(property="exhibition_status", type="string", enum={"scheduled","exhibited","ended"}, nullable=true),
     * @OA\Property(property="exhibition_phone", type="string", nullable=true),
     * @OA\Property(property="exhibition_homepage", type="string", nullable=true),
     * @OA\Property(property="exhibition_poster_file", type="string", format="binary", description="새로 업로드할 포스터 파일", nullable=true),
     * @OA\Property(property="exhibition_poster_url", type="string", description="포스터 URL을 직접 지정할 때", nullable=true)
     * )
     * ),
     * @OA\MediaType(
     * mediaType="application/json",
     * @OA\Schema(
     * type="object",
     * @OA\Property(property="exhibition_title", type="string", nullable=true),
     * @OA\Property(property="exhibition_description", type="string", nullable=true),
     * @OA\Property(property="exhibition_category", type="string", nullable=true),
     * @OA\Property(property="exhibition_start_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_end_date", type="string", format="date", nullable=true),
     * @OA\Property(property="exhibition_start_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_end_time", type="string", format="time", nullable=true),
     * @OA\Property(property="exhibition_location", type="string", nullable=true),
     * @OA\Property(property="exhibition_price", type="integer", nullable=true),
     * @OA\Property(property="exhibition_tag", type="string", nullable=true),
     * @OA\Property(property="exhibition_status", type="string", enum={"scheduled","exhibited","ended"}, nullable=true),
     * @OA\Property(property="exhibition_phone", type="string", nullable=true),
     * @OA\Property(property="exhibition_homepage", type="string", nullable=true),
     * @OA\Property(property="exhibition_poster_url", type="string", description="포스터 URL을 직접 지정할 때", nullable=true)
     * )
     * )
     * ),
     * @OA\Response(response=200, description="수정 성공"),
     * @OA\Response(response=403, description="권한 없음"),
     * @OA\Response(response=404, description="전시회 없음")
     * )
     */
    public function updateExhibition($id) {
        // 1. 인증 및 사용자 ID 획득
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        // $userData는 권한 체크에 불필요하므로 제거
        // $userData = $this->userModel->getById($userId);

        // 2. 전시회 정보 조회
        $exhibition = $this->model->getById($id);

        if (!$exhibition) {
            http_response_code(404);
            echo json_encode(['message' => 'Exhibition not found']);
            return;
        }

        // 3. [수정 핵심] 권한 체크 로직 변경
        // 전시회가 소속된 갤러리 정보를 가져와서, 그 갤러리의 주인인지 확인
        $gallery = $this->galleryModel->getById($exhibition['gallery_id']);

        if (!$gallery || $gallery['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['message' => '권한이 없습니다. (본인의 갤러리 전시회만 수정 가능)'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. 데이터 처리
        $isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
        $data = [];

        if ($isMultipart) {
            // 반복되는 isset 체크를 배열로 처리하여 코드 간소화 (기능은 동일)
            $fields = [
                'exhibition_title', 'exhibition_description', 'exhibition_category',
                'exhibition_start_date', 'exhibition_end_date', 'exhibition_start_time',
                'exhibition_end_time', 'exhibition_location', 'exhibition_price',
                'exhibition_tag', 'exhibition_status', 'exhibition_phone', 'exhibition_homepage'
            ];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $data[$field] = $_POST[$field];
                }
            }

            // 파일 처리 로직
            if (!empty($_FILES['exhibition_poster_file']) && $_FILES['exhibition_poster_file']['error'] === UPLOAD_ERR_OK) {
                $relPath = $this->saveUploadedImage($_FILES['exhibition_poster_file'], 'exhibition');
                $data['exhibition_poster'] = $relPath;
            } elseif (isset($_POST['exhibition_poster_url'])) { 
                // URL로 이미지 변경 시 DB 컬럼명(exhibition_poster)에 맞춰 매핑
                $data['exhibition_poster'] = $_POST['exhibition_poster_url'] ?: null;
            }

        } else {
            // JSON 방식
            $jsonData = json_decode(file_get_contents('php://input'), true) ?? [];
            
            // [추가] JSON에서도 poster_url이 들어오면 DB 컬럼명(exhibition_poster)으로 변경해줘야 함
            if (array_key_exists('exhibition_poster_url', $jsonData)) {
                $jsonData['exhibition_poster'] = $jsonData['exhibition_poster_url'] ?: null;
                unset($jsonData['exhibition_poster_url']); // 기존 키 삭제
            }

            $data = array_merge($data, $jsonData);
        }

        // 변경할 데이터가 없으면 바로 성공 처리
        if (empty($data)) {
            http_response_code(200);
            // 변경된 게 없으니 기존 데이터를 그대로 리턴
            $exhibition['exhibition_poster'] = $this->toAbsoluteUrl($exhibition['exhibition_poster'] ?? null);
            echo json_encode(['message' => 'No fields to update', 'data' => $exhibition], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 5. 업데이트 실행
        // update 함수에 $gallery_id를 넘기는 것은 Model 내부 로직에 따라 필요할 수 있으니 유지
        $success = $this->model->update($id, $data, $exhibition['gallery_id']);

        if ($success) {
            $updatedExhibition = $this->model->getById($id);
            if ($updatedExhibition) {
                $updatedExhibition['exhibition_poster'] = $this->toAbsoluteUrl($updatedExhibition['exhibition_poster'] ?? null);
            }
            echo json_encode(['message' => 'Exhibition updated successfully', 'data' => $updatedExhibition], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Exhibition update failed']);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/exhibitions/{id}",
     *     summary="전시회 삭제",
     *     tags={"Exhibition"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="삭제 성공"),
     *     @OA\Response(response=404, description="전시회 없음 또는 삭제 실패")
     * )
     */
    public function deleteExhibition($id) {
        // 1. 인증 및 사용자 ID 확보
        $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        // 2. 삭제하려는 전시회 정보 조회
        $exhibition = $this->model->getById($id);

        if (!$exhibition) {
            http_response_code(404);
            echo json_encode(['message' => '존재하지 않는 전시회입니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 3. 권한 체크: "이 전시회가 속한 갤러리가 내 것인가?"
        // 내가 관리하는 갤러리 목록 가져오기
        $myGalleries = $this->galleryModel->getGalleriesBySearch(['user_id' => $userId]);
        
        // 내 갤러리 ID들만 추출 (예: [1, 5, 10])
        $myGalleryIds = array_column($myGalleries, 'id');

        // 전시회의 gallery_id가 내 갤러리 목록에 포함되어 있는지 확인
        if (!in_array($exhibition['gallery_id'], $myGalleryIds)) {
            http_response_code(403);
            echo json_encode(['message' => '해당 전시회가 열리는 갤러리의 관리자가 아니므로 삭제 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 4. 삭제 실행
        $success = $this->model->delete($id);
        
        if ($success) {
            // 200 OK (성공 시 보통 상태코드 200이나 204 사용)
            http_response_code(200);
            echo json_encode(['message' => 'Exhibition deleted successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            // DB 오류 등으로 실패 시
            http_response_code(500);
            echo json_encode(['message' => 'Exhibition delete failed'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/exhibitions/{id}/arts",
     *   summary="전시회 작품 등록",
     *   tags={"Exhibition"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="전시회 ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"art_id","display_order"},
     *       @OA\Property(property="art_id", type="integer"),
     *       @OA\Property(property="display_order", type="integer")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="등록 성공",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Artworks registered successfully"),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="exhibition_id", type="integer"),
     *         @OA\Property(property="art_id", type="integer"),
     *         @OA\Property(property="display_order", type="integer"),
     *         @OA\Property(property="create_dtm", type="string", format="date-time"),
     *         @OA\Property(property="update_dtm", type="string", format="date-time")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthorized"),
     *   @OA\Response(response=403, description="권한이 없습니다."),
     *   @OA\Response(response=500, description="등록 실패")
     * )
     */
    public function registerArts($id) {
        // 1. 인증 및 사용자 ID 획득
        $user = $this->auth->authenticate();
        $userId = $this->auth->getUserId();

        // 2. 전시회 정보 가져오기
        $exhibition = $this->model->getById($id);

        if (!$exhibition) {
            http_response_code(404);
            echo json_encode(['message' => '해당 전시회를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            return; // exit 대신 return 권장
        }

        // 3. [수정 핵심] 권한 체크 로직 변경
        // 전시회가 소속된 '갤러리' 정보를 가져옵니다.
        $gallery = $this->galleryModel->getById($exhibition['gallery_id']);

        // 갤러리가 존재하지 않거나, 갤러리의 주인(user_id)이 현재 사용자가 아니라면 차단
        // (만약 관리자(admin)는 통과시키고 싶다면 $user->role === 'admin' 조건 추가 가능)
        if (!$gallery || $gallery['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['message' => '이 전시회에 작품을 등록할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. 데이터 등록
        $data = json_decode(file_get_contents('php://input'), true);
        
        // 입력값 유효성 검사 (Swagger와 일치하도록)
        if (empty($data['art_id'])) {
            http_response_code(400);
            echo json_encode(['message' => 'art_id is required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $registeredArt = $this->model->registerArt($id, $data);

        if ($registeredArt) {
            http_response_code(201);
            echo json_encode(['message' => 'Artworks registered successfully', 'data' => $registeredArt], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to register Artworks']);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/exhibitions/{id}/artists",
     *   summary="전시회 작가 등록",
     *   tags={"Exhibition"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="전시회 ID",
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"artist_ids"},
     *       @OA\Property(
     *         property="artist_ids",
     *         type="array",
     *         @OA\Items(type="integer", example=1)
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="등록 성공",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Artist registered successfully"),
     *       @OA\Property(
     *         property="data",
     *         type="array",
     *         @OA\Items(
     *           type="object",
     *           @OA\Property(property="id", type="integer"),
     *           @OA\Property(property="exhibition_id", type="integer"),
     *           @OA\Property(property="artist_id", type="integer"),
     *           @OA\Property(property="create_dtm", type="string", format="date-time"),
     *           @OA\Property(property="update_dtm", type="string", format="date-time")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=400, description="잘못된 요청"),
     *   @OA\Response(response=401, description="Unauthorized"),
     *   @OA\Response(response=403, description="권한이 없습니다."),
     *   @OA\Response(response=500, description="등록 실패")
     * )
     */
    public function registerArtists($id) {
        // 1. 인증 및 사용자 ID 획득
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        // $userData는 권한 체크에 필요 없으므로 제거해도 됩니다.
        // $userData = $this->userModel->getById($userId); 

        // 2. 전시회 정보 가져오기
        $exhibition = $this->model->getById($id);

        if (!$exhibition) {
            http_response_code(404);
            echo json_encode(['message' => 'Exhibition not found'], JSON_UNESCAPED_UNICODE);
            return; // exit 대신 return 사용 권장
        }

        // 3. [수정 핵심] 권한 체크
        // 전시회가 소속된 갤러리 정보를 가져와서, 그 갤러리의 주인(user_id)인지 확인
        $gallery = $this->galleryModel->getById($exhibition['gallery_id']);

        if (!$gallery || $gallery['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['message' => '권한이 없습니다. (본인의 갤러리 전시회만 수정 가능)'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. 데이터 처리 (기존 로직 유지)
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['artist_ids']) || !is_array($data['artist_ids'])) {
            http_response_code(400);
            echo json_encode(['message' => 'artist_ids (array)를 전달해 주세요.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 숫자로 캐스팅 + 0 이하 제거 (PHP 7.3 호환)
        $artistIds = array_values(array_filter(
            array_map('intval', $data['artist_ids']),
            function ($v) {
                return $v > 0;
            }
        ));

        if (empty($artistIds)) {
            http_response_code(400);
            echo json_encode(['message' => '유효한 artist_ids 가 없습니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $registered = $this->model->registerArtists($id, $artistIds);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to register Artist', 'error' => $e->getMessage()]);
            return;
        }

        // 성공 응답
        if ($registered && count($registered) > 0) {
            http_response_code(201);
            echo json_encode([
                'message' => 'Artists registered successfully',
                'data'    => $registered
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // DB에는 성공적으로 다녀왔으나, 이미 등록된 작가들이라 추가된 게 0개일 수도 있음
            // 상황에 따라 200 OK로 처리하기도 함. 여기서는 500 유지.
            http_response_code(500);
            echo json_encode(['message' => 'Failed to register Artist or No new artists added'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Delete(
     * path="/api/exhibitions/{id}/arts/{art_id}",
     * summary="전시회 작품 삭제 (연결 해제)",
     * description="전시회 목록에서 특정 작품을 제외합니다. 작품 데이터 자체가 삭제되는 것이 아니라 매핑만 해제됩니다.",
     * tags={"Exhibition"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(name="id", in="path", required=true, description="전시회 ID", @OA\Schema(type="integer")),
     * @OA\Parameter(name="art_id", in="path", required=true, description="삭제할 작품 ID", @OA\Schema(type="integer")),
     * @OA\Response(response=200, description="삭제 성공"),
     * @OA\Response(response=403, description="권한 없음"),
     * @OA\Response(response=404, description="전시회 없음")
     * )
     */
    public function deleteExhibitionArt($id, $artId) {
        // 1. 인증
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        // 2. 전시회 정보 조회
        $exhibition = $this->model->getById($id);
        if (!$exhibition) {
            http_response_code(404);
            echo json_encode(['message' => 'Exhibition not found'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 3. 권한 체크 (갤러리 주인인지)
        $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
        if (!$gallery || $gallery['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. 삭제 실행 (Model 호출)
        // deleteExhibitionArt 메서드는 Model에 추가해야 합니다.
        $success = $this->model->deleteExhibitionArt($id, $artId);

        if ($success) {
            http_response_code(200);
            echo json_encode(['message' => 'Artwork removed from exhibition successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500); // 404일 수도 있으나, 서버 로직상 실패로 간주
            echo json_encode(['message' => 'Failed to remove artwork'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Delete(
     * path="/api/exhibitions/{id}/artists/{artist_id}",
     * summary="전시회 작가 삭제 (연결 해제)",
     * description="전시회 참여 작가 목록에서 특정 작가를 제외합니다.",
     * tags={"Exhibition"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(name="id", in="path", required=true, description="전시회 ID", @OA\Schema(type="integer")),
     * @OA\Parameter(name="artist_id", in="path", required=true, description="삭제할 작가 ID", @OA\Schema(type="integer")),
     * @OA\Response(response=200, description="삭제 성공"),
     * @OA\Response(response=403, description="권한 없음"),
     * @OA\Response(response=404, description="전시회 없음")
     * )
     */
    public function deleteExhibitionArtist($id, $artistId) {
        // 1. 인증
        $user = $this->auth->authenticate();
        $userId = $user->user_id;

        // 2. 전시회 정보 조회
        $exhibition = $this->model->getById($id);
        if (!$exhibition) {
            http_response_code(404);
            echo json_encode(['message' => 'Exhibition not found'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 3. 권한 체크 (갤러리 주인인지)
        $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
        if (!$gallery || $gallery['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. 삭제 실행 (Model 호출)
        // deleteExhibitionArtist 메서드는 Model에 추가해야 합니다.
        $success = $this->model->deleteExhibitionArtist($id, $artistId);

        if ($success) {
            http_response_code(200);
            echo json_encode(['message' => 'Artist removed from exhibition successfully'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to remove artist'], JSON_UNESCAPED_UNICODE);
        }
    }
}
