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
     *       @OA\Property(property="name",  type="string",  example="김길동"),
     *       @OA\Property(property="field", type="string",  example="회화")
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
     *           @OA\Property(property="name", type="string"),
     *           @OA\Property(property="field", type="string"),
     *           @OA\Property(property="imageUrl", type="string"),
     *           @OA\Property(property="nation", type="string"),
     *           @OA\Property(property="description", type="string")
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
     *         @OA\JsonContent(
     *             @OA\Property(property="artist_name", type="string"),
     *             @OA\Property(property="artist_category", type="string"),
     *             @OA\Property(property="artist_image", type="string"),
     *             @OA\Property(property="artist_nation", type="string"),
     *             @OA\Property(property="artist_description", type="string")
     *         )
     *     ),
     *     @OA\Response(response=201, description="작가 생성 완료")
     * )
     */
    public function createArtist() {
        $data = json_decode(file_get_contents("php://input"), true);
        $created = $this->model->create($data);
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
     *         @OA\JsonContent(
     *             @OA\Property(property="artist_name", type="string"),
     *             @OA\Property(property="artist_category", type="string"),
     *             @OA\Property(property="artist_image", type="string"),
     *             @OA\Property(property="artist_nation", type="string"),
     *             @OA\Property(property="artist_description", type="string")
     *         )
     *     ),
     *     @OA\Response(response=200, description="작가 수정 완료")
     * )
     */
    public function updateArtist($id) {
        $data = json_decode(file_get_contents("php://input"), true);
        $updated = $this->model->update($id, $data);
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

