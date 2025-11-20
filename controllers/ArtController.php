<?php
namespace Controllers;
use OpenApi\Annotations as OA;

use Models\ArtModel;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Models\ArtistModel;
use Models\LikeModel;

class ArtController {
    private $model;
    private $galleryModel;
    private $exhibitionModel;
    private $artistModel;
    private $likeModel;

    public function __construct() {
        $this->model = new ArtModel();
        $this->galleryModel = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
        $this->artistModel = new ArtistModel();
        $this->likeModel = new LikeModel();
    }

    /** 내부 유틸: 외부 URL 여부 */
    private function isExternalUrl(?string $val): bool {
        return is_string($val) && preg_match('#^https?://#i', $val);
    }

    /** 내부 유틸: 상대경로를 절대 URL로 변환 */
    private function buildMediaUrl(?string $relativePath): ?string {
        if (!$relativePath) return null;
        if ($this->isExternalUrl($relativePath)) return $relativePath;

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $rel    = ltrim($relativePath, '/');
        return sprintf('%s://%s/media/%s', $scheme, $host, $rel);
    }

    /**
     * 내부 유틸: 업로드 파일 저장 (field= image)
     * 성공 시 'art/YYYY/MM/랜덤.확장자' (상대경로) 반환, 없으면 null 반환
     */
    private function storeUploadedImage(string $field = 'image'): ?string {
        if (empty($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
            return null; // 파일 미첨부
        }

        // 허용 확장자/타입
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];

        $tmp  = $_FILES[$field]['tmp_name'];
        $size = (int)($_FILES[$field]['size'] ?? 0);
        $type = mime_content_type($tmp) ?: ($_FILES[$field]['type'] ?? '');

        if (!isset($allowed[$type])) {
            throw new \RuntimeException('허용되지 않은 이미지 형식입니다.');
        }
        // (옵션) 10MB 제한
        if ($size > 10 * 1024 * 1024) {
            throw new \RuntimeException('이미지 용량 초과(최대 10MB).');
        }

        $ext = $allowed[$type];
        $yyyy = date('Y'); $mm = date('m');

        // backend 디렉토리 기준 media 경로 구성
        // __DIR__ = backend/controllers
        $baseMedia = dirname(__DIR__) . '/media'; // backend/media
        $targetDir = $baseMedia . '/art/' . $yyyy . '/' . $mm . '/';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new \RuntimeException('이미지 저장 경로 생성 실패');
        }

        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = $targetDir . $filename;

        if (!move_uploaded_file($tmp, $targetPath)) {
            throw new \RuntimeException('이미지 저장 실패');
        }

        // DB에는 상대경로만 저장
        return 'art/' . $yyyy . '/' . $mm . '/' . $filename;
    }

    /**
     * @OA\Get(
     *   path="/api/arts",
     *   summary="작품 목록 조회",
     *   tags={"Art"},
     *   @OA\Parameter(
     *     name="gallery_name", in="query", description="[필터] 특정 갤러리 이름",
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(
     *     response=200, description="성공",
     *     @OA\JsonContent(type="array", @OA\Items(
     *       @OA\Property(property="id", type="integer"),
     *       @OA\Property(property="art_title", type="string"),
     *       @OA\Property(property="art_image_url", type="string", description="접근 가능한 절대 URL"),
     *       @OA\Property(property="is_liked", type="boolean")
     *     )))
     * )
     */
    public function getArtList() {
        $userId = \Middlewares\AuthMiddleware::getUserId();

        // 선택적 필터: gallery_name
        $searchTargetGalleryId = null;
        if (!empty($_GET['gallery_name'])) {
            $galleryList = $this->galleryModel->getGalleries(['search' => $_GET['gallery_name']]);
            if (!empty($galleryList)) {
                $searchTargetGalleryId = $galleryList[0]['id'];
            } else {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['message' => '해당 이름의 갤러리를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return;
            }
        }

        $results = [];
        $arts = $this->model->getAll();

        foreach ($arts as $art) {
            $exhibitionIds = $this->model->getExhibitionIdByArtId($art['id']);
            $exhibitions = [];

            foreach ($exhibitionIds as $exhibitionId) {
                $exhibition = $this->exhibitionModel->getById($exhibitionId['exhibition_id']);
                if (!empty($searchTargetGalleryId) && $exhibition['gallery_id'] != $searchTargetGalleryId) {
                    continue;
                }
                $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
                $exhibition['gallery'] = $gallery;
                $exhibitions[] = $exhibition;
            }

            $artists = $this->artistModel->getById($art['artist_id']);
            $likesInfo = $this->likeModel->getLikesWithStatusAndCount('art', $art['id'], $userId);

            // 이미지 URL 변환(상대경로 → 절대 URL)
            $art['art_image'] = $this->buildMediaUrl($art['art_image'] ?? null);
            $art['artist']        = $artists;
            $art['exhibitions']   = $exhibitions;
            $art['is_liked']      = $likesInfo['isLikedByUser'];

            $results[] = $art;
        }

        header('Content-Type: application/json');
        echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @OA\Get(
     *   path="/api/arts/{id}",
     *   summary="작품 상세 조회",
     *   tags={"Art"},
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="성공"),
     *   @OA\Response(response=404, description="Not Found")
     * )
     */
    public function getArtById($id) {
        $userId = \Middlewares\AuthMiddleware::getUserId();

        $art = $this->model->getById($id);
        if (!$art) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Art not found']);
            return;
        }

        $exhibitionIds = $this->model->getExhibitionIdByArtId($id);
        $exhibitions = [];
        foreach ($exhibitionIds as $exhibitionId) {
            $exhibition = $this->exhibitionModel->getById($exhibitionId['exhibition_id']);
            $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
            $exhibition['gallery'] = $gallery;
            $exhibitions[] = $exhibition;
        }

        $artists   = $this->artistModel->getById($art['artist_id']);
        $likesInfo = $this->likeModel->getLikesWithStatusAndCount('art', $art['id'], $userId);

        $art['artist']        = $artists;
        $art['exhibitions']   = $exhibitions;
        $art['is_liked']      = $likesInfo['isLikedByUser'];
        $art['art_image'] = $this->buildMediaUrl($art['art_image'] ?? null);

        header('Content-Type: application/json');
        echo json_encode($art, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @OA\Post(
     *   path="/api/arts",
     *   summary="작품 등록 (multipart/form-data 업로드)",
     *   tags={"Art"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         required={"artist_id","art_title"},
     *         @OA\Property(property="image", type="string", format="binary", description="작품 이미지 파일"),
     *         @OA\Property(property="artist_id", type="integer"),
     *         @OA\Property(property="art_title", type="string"),
     *         @OA\Property(property="art_description", type="string"),
     *         @OA\Property(property="art_docent", type="string"),
     *         @OA\Property(property="art_material", type="string"),
     *         @OA\Property(property="art_size", type="string"),
     *         @OA\Property(property="art_year", type="string"),
     *         @OA\Property(property="gallery_phone", type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=201, description="Created")
     * )
     */
    public function createArt() {
        try {
            // 1) 이미지 저장 (없으면 null)
            $relativeImagePath = $this->storeUploadedImage('image');

            // 2) 폼 필드 수집 (multipart/form-data의 텍스트 파트는 $_POST)
            $data = [
                'art_image'      => $relativeImagePath, // null일 수 있음
                'artist_id'      => $_POST['artist_id']       ?? null,
                'art_title'      => $_POST['art_title']       ?? null,
                'art_description'=> $_POST['art_description'] ?? null,
                'art_docent'     => $_POST['art_docent']      ?? null,
                'art_material'   => $_POST['art_material']    ?? null,
                'art_size'       => $_POST['art_size']        ?? null,
                'art_year'       => $_POST['art_year']        ?? null,
                'gallery_phone'       => $_POST['gallery_phone']        ?? null,
            ];

            $createdArt = $this->model->create($data);
            if ($createdArt) {
                // 응답용 절대 URL 포함
                $createdArt['art_image'] = $this->buildMediaUrl($createdArt['art_image'] ?? null);

                http_response_code(201);
                header('Content-Type: application/json');
                echo json_encode([
                    'message' => 'Art created successfully',
                    'data'    => $createdArt
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Failed to create art'], JSON_UNESCAPED_UNICODE);
            }
        } catch (\Throwable $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Put(
     *   path="/api/arts/{id}",
     *   summary="작품 수정 (multipart/form-data 업로드)",
     *   tags={"Art"},
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         @OA\Property(property="image", type="string", format="binary", description="새 이미지(선택)"),
     *         @OA\Property(property="artist_id", type="integer"),
     *         @OA\Property(property="art_title", type="string"),
     *         @OA\Property(property="art_description", type="string"),
     *         @OA\Property(property="art_docent", type="string"),
     *         @OA\Property(property="art_material", type="string"),
     *         @OA\Property(property="art_size", type="string"),
     *         @OA\Property(property="art_year", type="string")
     *         @OA\Property(property="gallery_phone", type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=200, description="Updated")
     * )
     */
    public function updateArt($id) {
        try {
            $exists = $this->model->getById($id);
            if (!$exists) {
                http_response_code(404);
                echo json_encode(['message' => 'Art not found']);
                return;
            }

            // 파일이 올라온 경우에만 새로 저장 (없으면 기존 이미지 유지)
            $newRelativeImagePath = $this->storeUploadedImage('image');

            // 부분수정: 제공된 필드만 반영
            $data = [];
            if (!empty($_POST)) {
                foreach (['artist_id','art_title','art_description','art_docent','art_material','art_size','art_year'] as $k) {
                    if (array_key_exists($k, $_POST)) {
                        $data[$k] = $_POST[$k];
                    }
                }
            }
            if ($newRelativeImagePath !== null) {
                $data['art_image'] = $newRelativeImagePath;
            }

            $success = $this->model->update($id, $data);
            if ($success) {
                $updatedArt = $this->model->getById($id);
                $updatedArt['art_image'] = $this->buildMediaUrl($updatedArt['art_image'] ?? null);
                header('Content-Type: application/json');
                echo json_encode([
                    'message' => 'Art updated successfully',
                    'data'    => $updatedArt
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                http_response_code(400);
                echo json_encode(['message' => 'Art not found or update failed']);
            }
        } catch (\Throwable $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Delete(
     *   path="/api/arts/{id}",
     *   summary="작품 삭제",
     *   tags={"Art"},
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="Deleted")
     * )
     */
    public function deleteArt($id) {
        $success = $this->model->delete($id);
        header('Content-Type: application/json');
        if ($success) {
            echo json_encode(['message' => 'Art deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Art not found or delete failed']);
        }
    }
}
