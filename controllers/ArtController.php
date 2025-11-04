<?php
namespace Controllers;
use OpenApi\Annotations as OA; 

use Models\ArtModel;
use Models\GalleryModel;
use Models\ExhibitionModel;
use Models\ArtistModel;

class ArtController {
    private $model;
    private $galleryModel;
    private $exhibitionModel;
    private $artistModel;

    public function __construct() {
        $this->model = new ArtModel();
        $this->galleryModel = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
        $this->artistModel = new ArtistModel();
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
     *             @OA\Property(property="art_title", type="string")
     *         ))
     *     )
     * )
     */
    public function getArtList() {

        // 파라미터로 특정 gallery_name만 검색하고자 하면 검색 결과를 제한해야 하므로,
        $searchTargetGalleryId = null;
        if(!empty($_GET['gallery_name'])) {
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

        // 파라미터로 특정 gallery_name이 들어온 경우 표시할 작품만 담을 배열
        $results = [];        

        // 일단 모든 작품을 대상으로 순회 할 것임
        $arts = $this->model->getAll();

        // 각 작품에 [전시회>>갤러리] 정보와 [작가] 정보 추가
        foreach ($arts as $art) {
            $exhibitionIds = $this->model->getExhibitionIdByArtId($art['id']);
            $exhibitions = [];

            // [전시회>>갤러리] 정보 추가
            foreach ($exhibitionIds as $exhibitionId) {
                $exhibition = $this->exhibitionModel->getById($exhibitionId['exhibition_id']);
                
                // 파라미터로 gallery_name이 들어온 경우 이를 타깃과 맞지 않는 gallery_id면 모두 continue
                if (!empty($searchTargetGalleryId) && $exhibition['gallery_id'] != $searchTargetGalleryId) {
                    continue;
                }

                $gallery = $this->galleryModel->getById($exhibition['gallery_id']);
                $exhibition['gallery'] = $gallery;

                $exhibitions[] = $exhibition;
            }

            // [작가] 정보 추가
            $artists = $this->artistModel->getById($art['artist_id']);

            $art['artist'] = $artist;
            $art['exhibitions'] = $exhibitions;

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
        $art = $this->model->getById($id);
        
        if ($art) {
            $exhibitionIds = $this->model->getExhibitionIdByArtId($id);
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

    /**
     * @OA\Post(
     *     path="/api/arts",
     *     summary="작품 등록",
     *     tags={"Art"},
     *     @OA\RequestBody(required=true),
     *     @OA\Response(response=201, description="Created")
     * )
     */
    public function createArt() {
        $data       = json_decode(file_get_contents('php://input'), true);
        $createdArt = $this->model->create($data);

        if ($createdArt) {
            http_response_code(201);
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
        $data    = json_decode(file_get_contents('php://input'), true);
        $success = $this->model->update($id, $data);

        if ($success) {
            $updatedArt = $this->model->getById($id);
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

