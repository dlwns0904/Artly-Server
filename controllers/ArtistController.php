<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Models\ArtistModel;
use Middlewares\AuthMiddleware;

/**
 * @OA\Tag(
 *     name="Artist",
 *     description="작가 관련 API"
 * )
 */
class ArtistController {
    private $model;
    private $auth;

    public function __construct() {
        $this->model = new ArtistModel();
        $this->auth = new AuthMiddleware();
    }

    /** 외부 URL 여부 */
    private function isExternalUrl(?string $val): bool {
        return is_string($val) && preg_match('#^https?://#i', $val);
    }

    /** 상대경로 -> 절대 URL 변환 (Artist 이미지용) */
    private function toAbsoluteUrl(?string $path): ?string {
        if (!$path) return null;
        if ($this->isExternalUrl($path)) return $path;   // 이미 http(s)면 그대로 반환

        $clean  = ltrim($path, '/'); // "media/artist/..." 또는 "/media/artist/..."
        $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
            ? $_SERVER['HTTP_X_FORWARDED_PROTO']
            : (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http');
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . '/' . $clean;
    }

    /**
     * 내부 유틸: 업로드된 작가 이미지 저장
     * - 실제 파일: backend/media/artist/YYYY/MM/파일명
     * - DB에는: media/artist/YYYY/MM/파일명 (상대경로 저장)
     */
    private function saveArtistImage(array $file): ?string {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            $ext = 'jpg';
        }

        $datePath = date('Y/m'); // 예: 2025/11
        $baseDir = dirname(__DIR__) . '/media/artist/' . $datePath;
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $filename = uniqid('artist_', true) . '.' . $ext;
        $destPath = $baseDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return null;
        }

        @chmod($destPath, 0644);

        // DB에는 상대경로만 저장 (Exhibition이랑 동일 패턴)
        return 'media/artist/' . $datePath . '/' . $filename;
    }

    /**
     * @OA\Get(
     *   path="/api/artist",
     *   summary="작가 목록 조회",
     *   tags={"Artist"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="category", in="query",
     *     description="카테고리(all | onExhibition)", @OA\Schema(type="string")
     *   ),
     *   @OA\Parameter(name="liked_only", in="query", description="좋아요한 작가만", @OA\Schema(type="boolean")),
     *   @OA\Parameter(name="search", in="query", description="검색어", @OA\Schema(type="string")),
     *   @OA\Parameter(name="nation", in="query", description="국가 필터", @OA\Schema(type="string")),
     *   @OA\Parameter(name="decade", in="query", description="년도 (예: 1920년대)", @OA\Schema(type="string")),
     *   @OA\Response(
     *     response=200, description="성공",
     *     @OA\JsonContent(type="array", @OA\Items(
     *       @OA\Property(property="id",    type="integer", example=1),
     *       @OA\Property(property="artist_name",  type="string",  example="김길동"),
     *       @OA\Property(property="artist_category", type="string",  example="회화"),
     *       @OA\Property(property="artist_image", type="string", example="https://... 또는 /media/artist/...")
     *     ))
     *   )
     * )
     */
    public function getArtistList() {
        $decoded = $this->auth->decodeToken();
        $user_id = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;

        $likedOnly = $_GET['liked_only'] ?? null;
        $likedOnlyBool = filter_var($likedOnly, FILTER_VALIDATE_BOOLEAN);
        if ($likedOnlyBool && !$user_id) {
            http_response_code(401);
            echo json_encode(['message' => '로그인 후 사용 가능합니다.']);
            return;
        }

        $filters = [
            'category'   => $_GET['category'] ?? 'all',
            'liked_only' => $likedOnly,
            'user_id'    => $user_id,
            'search'     => $_GET['search'] ?? null,
            'nation'     => $_GET['nation'] ?? null,
            'decade'     => $_GET['decade'] ?? null
        ];

        $artists = $this->model->fetchArtists($filters);

        // 🔥 여기서 artist_image를 Exhibition과 동일하게 처리
        foreach ($artists as &$a) {
            if (isset($a['artist_image'])) {
                $a['artist_image'] = $this->toAbsoluteUrl($a['artist_image']);
            }
        }
        unset($a);

        header('Content-Type: application/json');
        echo json_encode($artists, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Get(
     *   path="/api/artists/{id}",
     *   summary="작가 상세 조회",
     *   tags={"Artist"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true,
     *       @OA\Schema(type="integer", example=1)),
     *   @OA\Response(
     *       response=200, description="성공",
     *       @OA\JsonContent(
     *           @OA\Property(property="id", type="integer"),
     *           @OA\Property(property="artist_name", type="string"),
     *           @OA\Property(property="artist_category", type="string"),
     *           @OA\Property(property="artist_image", type="string"),
     *           @OA\Property(property="artist_nation", type="string"),
     *           @OA\Property(property="artist_description", type="string")
     *       )
     *   ),
     *   @OA\Response(response=404, description="Not Found")
     * )
     */
    public function getArtistById($id) {
        $decoded = $this->auth->decodeToken();
        $user_id = $decoded && isset($decoded->user_id) ? $decoded->user_id : null;

        $artist = $this->model->getById($id, $user_id);
        if ($artist) {
            // 메인 프로필 이미지
            if (isset($artist['artist_image'])) {
                $artist['artist_image'] = $this->toAbsoluteUrl($artist['artist_image']);
            }

            // 관련 전시 포스터/작품 이미지도 있으면 같이 처리
            if (!empty($artist['exhibitions']) && is_array($artist['exhibitions'])) {
                foreach ($artist['exhibitions'] as &$ex) {
                    if (isset($ex['exhibition_poster'])) {
                        $ex['exhibition_poster'] = $this->toAbsoluteUrl($ex['exhibition_poster']);
                    }
                }
                unset($ex);
            }
            if (!empty($artist['artworks']) && is_array($artist['artworks'])) {
                foreach ($artist['artworks'] as &$aw) {
                    if (isset($aw['art_image'])) {
                        $aw['art_image'] = $this->toAbsoluteUrl($aw['art_image']);
                    }
                }
                unset($aw);
            }

            header('Content-Type: application/json');
            echo json_encode($artist, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Artist not found']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/artists",
     *     summary="작가 생성",
     *     tags={"Artist"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="artist_name", type="string"),
     *                 @OA\Property(property="artist_category", type="string"),
     *                 @OA\Property(property="artist_nation", type="string"),
     *                 @OA\Property(property="artist_description", type="string"),
     *                 @OA\Property(
     *                     property="artist_image",
     *                     type="string",
     *                     format="binary"
     *                 ),
     *                 @OA\Property(
     *                     property="artist_image_url",
     *                     type="string",
     *                     description="외부 이미지 URL"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="작가 생성 완료")
     * )
     */
    public function createArtist() {
        $name        = $_POST['artist_name']        ?? null;
        $category    = $_POST['artist_category']    ?? null;
        $nation      = $_POST['artist_nation']      ?? null;
        $description = $_POST['artist_description'] ?? null;

        $imagePath = null;
        if (!empty($_FILES['artist_image']['tmp_name'])) {
            $imagePath = $this->saveArtistImage($_FILES['artist_image']); // 상대경로 저장
        } elseif (!empty($_POST['artist_image_url'])) {
            $imagePath = $_POST['artist_image_url']; // 외부 URL 그대로 저장
        }

        $data = [
            'artist_name'        => $name,
            'artist_category'    => $category,
            'artist_image'       => $imagePath,
            'artist_nation'      => $nation,
            'artist_description' => $description
        ];

        $created = $this->model->create($data);

        // 응답에서만 절대 URL 변환
        if ($created && isset($created['artist_image'])) {
            $created['artist_image'] = $this->toAbsoluteUrl($created['artist_image']);
        }

        http_response_code(201);
        echo json_encode($created, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Put(
     *     path="/api/artists/{id}",
     *     summary="작가 수정",
     *     tags={"Artist"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="artist_name", type="string"),
     *                 @OA\Property(property="artist_category", type="string"),
     *                 @OA\Property(property="artist_nation", type="string"),
     *                 @OA\Property(property="artist_description", type="string"),
     *                 @OA\Property(property="artist_image", type="string", format="binary"),
     *                 @OA\Property(property="artist_image_url", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="작가 수정 완료")
     * )
     */
    public function updateArtist($id) {
        $current = $this->model->getById($id);
        if (!$current) {
            http_response_code(404);
            echo json_encode(['message' => 'Artist not found'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $name        = $_POST['artist_name']        ?? $current['artist_name'];
        $category    = $_POST['artist_category']    ?? $current['artist_category'];
        $nation      = $_POST['artist_nation']      ?? $current['artist_nation'];
        $description = $_POST['artist_description'] ?? $current['artist_description'];

        $imagePath = $current['artist_image'];

        if (!empty($_FILES['artist_image']['tmp_name'])) {
            $newPath = $this->saveArtistImage($_FILES['artist_image']);
            if ($newPath) {
                $imagePath = $newPath;
            }
        } elseif (!empty($_POST['artist_image_url'])) {
            $imagePath = $_POST['artist_image_url'];
        }

        $data = [
            'artist_name'        => $name,
            'artist_category'    => $category,
            'artist_image'       => $imagePath,
            'artist_nation'      => $nation,
            'artist_description' => $description
        ];

        $updated = $this->model->update($id, $data);

        if ($updated && isset($updated['artist_image'])) {
            $updated['artist_image'] = $this->toAbsoluteUrl($updated['artist_image']);
        }

        http_response_code(200);
        echo json_encode($updated, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Delete(
     *     path="/api/artists/{id}",
     *     summary="작가 삭제",
     *     tags={"Artist"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="작가 삭제 완료")
     * )
     */
    public function deleteArtist($id) {
        $this->model->delete($id);
        http_response_code(200);
        echo json_encode(['message' => 'Artist deleted'], JSON_UNESCAPED_UNICODE);
    }
}
