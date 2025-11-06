<?php
namespace Controllers;
use OpenApi\Annotations as OA;

use Models\ArtModel;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Models\ArtistModel;
use Models\LikeModel;
use Middlewares\AuthMiddleware;

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

    /**
     * @OA\Get(
     *     path="/api/arts",
     *     summary="작품 목록 조회",
     *     tags={"Art"},
     *     @OA\Parameter(
     *       name="gallery_name",
     *       in="query",
     *       description="[필터] 특정 갤러리 이름으로 작품을 검색합니다. (미입력 시 전체 조회)",
     *       @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="성공",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="art_title", type="string"),
     *             @OA\Property(property="art_image_url", type="string", example="/api/arts/1/image")
     *         ))
     *     )
     * )
     */
    public function getArtList() {
        $userId = \Middlewares\AuthMiddleware::getUserId();

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

            // blob 존재 시 URL 추가
            $art['art_image_url'] = $this->model->hasImage($art) ? ("/api/arts/{$art['id']}/image") : null;

            $art['artist'] = $artists;
            $art['exhibitions'] = $exhibitions;
            $art['is_liked'] = $likesInfo['isLikedByUser'];

            $results[] = $art;
        }

        header('Content-Type: application/json');
        echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @OA\Get(
     *     path="/api/arts/{id}",
     *     summary="작품 상세 조회",
     *     tags={"Art"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="성공"),
     *     @OA\Response(response=404, description="Not Found")
     * )
     */
    public function getArtById($id) {
        $userId = \Middlewares\AuthMiddleware::getUserId();
        $art = $this->model->getById($id);

        if ($art) {
            $exhibitionIds = $this->model->getExhibitionIdByArtId($id);
            $exhibitions = [];

            foreach ($exhibitionIds as $exhibitionId) {
                $exhibition = $this->exhibitionModel->getById($exhibitionId['exhibition_id']);
                $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
                $exhibition['gallery'] = $gallery;
                $exhibitions[] = $exhibition;
            }

            $artists = $this->artistModel->getById($art['artist_id']);
            $likesInfo = $this->likeModel->getLikesWithStatusAndCount('art', $art['id'], $userId);

            $art['artist'] = $artists;
            $art['exhibitions'] = $exhibitions;
            $art['is_liked'] = $likesInfo['isLikedByUser'];
            $art['art_image_url'] = $this->model->hasImage($art) ? ("/api/arts/{$art['id']}/image") : null;

            header('Content-Type: application/json');
            echo json_encode($art, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Art not found']);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/arts/{id}/image",
     *     summary="작품 이미지 바이너리",
     *     tags={"Art"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="바이너리 이미지 응답"),
     *     @OA\Response(response=404, description="Not Found")
     * )
     */
    public function streamArtImage($id) {
        $meta = $this->model->getImageMetaById($id);
        if (!$meta || empty($meta['art_images'])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Image not found']);
            return;
        }

        $mime = $meta['art_image_mime'] ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        if (!empty($meta['art_image_size'])) {
            header('Content-Length: ' . $meta['art_image_size']);
        }
        // 캐시 헤더(원하면 수정)
        header('Cache-Control: public, max-age=86400');

        // 실제 바이너리 스트리밍
        $this->model->streamImageById($id);
    }

    /**
     * @OA\Post(
     *   path="/api/arts",
     *   summary="작품 등록",
     *   tags={"Art"},
     *   @OA\RequestBody(
     *     required=true,
     *     description="JSON 또는 multipart/form-data 지원. multipart일 경우 파일 필드명은 'art_image'.",
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         type="object",
     *         required={"artist_id","art_title"},
     *         @OA\Property(property="artist_id", type="integer", example=1, description="작가 ID"),
     *         @OA\Property(property="art_title", type="string", example="밤하늘", description="작품 제목"),
     *         @OA\Property(property="art_description", type="string", example="별 가득한 밤 풍경"),
     *         @OA\Property(property="art_docent", type="string", example="이 작품은..."),
     *         @OA\Property(property="art_material", type="string", example="캔버스에 유화"),
     *         @OA\Property(property="art_size", type="string", example="72.7 x 60.6 cm"),
     *         @OA\Property(property="art_year", type="string", example="2024"),
     *         @OA\Property(
     *           property="art_image",
     *           type="string",
     *           format="binary",
     *           description="업로드 이미지 파일 (jpg/png 등)"
     *         )
     *       )
     *     ),
     *     @OA\MediaType(
     *       mediaType="application/json",
     *       @OA\Schema(
     *         type="object",
     *         required={"artist_id","art_title"},
     *         @OA\Property(property="artist_id", type="integer", example=1),
     *         @OA\Property(property="art_title", type="string", example="밤하늘"),
     *         @OA\Property(property="art_description", type="string", example="별 가득한 밤 풍경"),
     *         @OA\Property(property="art_docent", type="string", example="이 작품은..."),
     *         @OA\Property(property="art_material", type="string", example="캔버스에 유화"),
     *         @OA\Property(property="art_size", type="string", example="72.7 x 60.6 cm"),
     *         @OA\Property(property="art_year", type="string", example="2024"),
     *         @OA\Property(
     *           property="art_image_base64",
     *           type="string",
     *           example="data:image/png;base64,iVBORw0KGgoAAA...",
     *           description="data URL 또는 순수 base64 문자열"
     *         ),
     *         @OA\Property(property="art_image_name", type="string", example="starry-night.png", description="(선택) 원본 파일명")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Art created successfully"),
     *       @OA\Property(
     *         property="data",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=123),
     *         @OA\Property(property="artist_id", type="integer", example=1),
     *         @OA\Property(property="art_title", type="string", example="밤하늘"),
     *         @OA\Property(property="art_description", type="string", example="별 가득한 밤 풍경"),
     *         @OA\Property(property="art_docent", type="string", example="이 작품은..."),
     *         @OA\Property(property="art_material", type="string", example="캔버스에 유화"),
     *         @OA\Property(property="art_size", type="string", example="72.7 x 60.6 cm"),
     *         @OA\Property(property="art_year", type="string", example="2024"),
     *         @OA\Property(property="art_image", type="string", nullable=true, example=null, description="레거시/외부 URL 문자열(있는 경우)"),
     *         @OA\Property(property="art_image_url", type="string", nullable=true, example="/api/arts/123/image", description="Blob 저장 시 접근 URL"),
     *         @OA\Property(property="create_dtm", type="string", example="2025-11-06 13:05:21"),
     *         @OA\Property(property="update_dtm", type="string", example="2025-11-06 13:05:21")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Failed to create art",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Failed to create art")
     *     )
     *   )
     * )
     */
    public function createArt() {
        // 1) multipart/form-data 업로드(우선)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'multipart/form-data') !== false) {
            $payload = $_POST; // 텍스트 필드
            $createdArt = $this->model->create($payload);

            if (!empty($_FILES['art_image']) && $_FILES['art_image']['error'] === UPLOAD_ERR_OK) {
                $this->model->saveImageFromUpload($createdArt['id'], $_FILES['art_image']);
                // 최신 메타 반영
                $createdArt = $this->model->getById($createdArt['id']);
            }

            if ($createdArt) {
                http_response_code(201);
                $createdArt['art_image_url'] = $this->model->hasImage($createdArt) ? ("/api/arts/{$createdArt['id']}/image") : null;
                echo json_encode([
                    'message' => 'Art created successfully',
                    'data'    => $createdArt
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            http_response_code(500);
            echo json_encode(['message' => 'Failed to create art']);
            return;
        }

        // 2) JSON 입력 (기존 로직 유지)
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $createdArt = $this->model->create($data);

        // JSON에서도 data URL(base64)로 올 수 있게 지원 (선택)
        if (!empty($data['art_image_base64'])) {
            $this->model->saveImageFromBase64($createdArt['id'], $data['art_image_base64'], $data['art_image_name'] ?? null);
            $createdArt = $this->model->getById($createdArt['id']);
        }

        if ($createdArt) {
            http_response_code(201);
            $createdArt['art_image_url'] = $this->model->hasImage($createdArt) ? ("/api/arts/{$createdArt['id']}/image") : null;
            echo json_encode([
                'message' => 'Art created successfully',
                'data'    => $createdArt
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to create art']);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/arts/{id}",
     *     summary="작품 수정",
     *     tags={"Art"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true),
     *     @OA\Response(response=200, description="Updated")
     * )
     */
    public function updateArt($id) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($contentType, 'multipart/form-data') !== false) {
            $payload = $_POST;
            $success = $this->model->update($id, $payload);

            if ($success && !empty($_FILES['art_image']) && $_FILES['art_image']['error'] === UPLOAD_ERR_OK) {
                $this->model->saveImageFromUpload($id, $_FILES['art_image']);
            }
        } else {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $success = $this->model->update($id, $data);

            // JSON에서 base64 교체 가능
            if ($success && !empty($data['art_image_base64'])) {
                $this->model->saveImageFromBase64($id, $data['art_image_base64'], $data['art_image_name'] ?? null);
            }
        }

        if (!empty($success)) {
            $updatedArt = $this->model->getById($id);
            $updatedArt['art_image_url'] = $this->model->hasImage($updatedArt) ? ("/api/arts/{$id}/image") : null;

            echo json_encode([
                'message' => 'Art updated successfully',
                'data'    => $updatedArt
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Art not found or update failed']);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/arts/{id}",
     *     summary="작품 삭제",
     *     tags={"Art"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deleted")
     * )
     */
    public function deleteArt($id) {
        $success = $this->model->delete($id);

        if ($success) {
            echo json_encode(['message' => 'Art deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Art not found or delete failed']);
        }
    }
}
