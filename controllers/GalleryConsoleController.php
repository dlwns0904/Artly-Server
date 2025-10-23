<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;
use Models\GalleryModel;
use Models\ExhibitionModel;

/**
 * @OA\Tag(
 * name="GalleryConsole",
 * description="[콘솔] 관리자 측 갤러리 관련 API"
 * )
 */
class GalleryConsoleController {
    private $authMiddleware;    
    private $galleryModel;
    private $exhibitionModel;


    public function __construct() {
        $this->authMiddleware = new AuthMiddleware();
        $this->galleryModel = new GalleryModel();
        $this->exhibitionModel = new ExhibitionModel();
    }

    /**
     * @OA\Get(
     * path="/api/console/galleries",
     * summary="[콘솔] 갤러리 목록 조회",
     * description="관리자(사용자)가 소유한 모든 갤러리의 상세 정보 목록을 조회합니다.",
     * tags={"GalleryConsole"},
     * security={{"bearerAuth": {}}},
     * @OA\Response(
     * response=200,
     * description="성공적인 조회",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(
     * type="object",
     * @OA\Property(property="id", type="integer", description="갤러리 ID", example=1),
     * @OA\Property(property="user_id", type="integer", description="사용자 ID", example=12),
     * @OA\Property(property="name", type="string", description="갤러리 이름", example="My First Gallery"),
     * @OA\Property(property="description", type="string", description="갤러리 상세 설명", example="This is a collection of my favorite pieces."),
     * @OA\Property(property="is_default", type="boolean", description="기본 갤러리 여부", example=true),
     * @OA\Property(property="created_at", type="string", format="date-time", description="생성 시간"),
     * @OA\Property(property="updated_at", type="string", format="date-time", description="마지막 수정 시간")
     * )
     * )
     * ),
     * @OA\Response(response=401, description="인증 실패 (관리자 권한 필요)"),
     * @OA\Response(response=500, description="서버 오류")
     * )
     */ 
    public function getGalleryList() {
        try {
            // 관리자 인증 및 사용자 ID 확보
            $decoded = $this->authMiddleware->requireAdmin();
            $user_id = $decoded->sub;

            // 해당 사용자의 기본 갤러리 목록 조회
            $galleries = $this->galleryModel->getGalleries(['user_id' => $user_id]);
            if (empty($galleries)) {
                header('Content-Type: application/json');
                echo json_encode([], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 각 갤러리의 상세 정보를 조회
            $allGalleriesWithDetails = [];
            foreach ($galleries as $gallery) {
                $galleryInfo = $this->galleryModel->getById($gallery['id'], $user_id);
                if ($galleryInfo) {
                    $allGalleriesWithDetails[] = $galleryInfo;
                }
            }

            header('Content-Type: application/json');
            echo json_encode($allGalleriesWithDetails, JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode(['message' => '서버 오류가 발생했습니다.']);
        }
    }

    /**
     * @OA\Get(
     * path="/api/console/galleries/{id}",
     * summary="[콘솔] 갤러리 상세 조회",
     * description="특정 갤러리의 상세 정보와 해당 갤러리에서 진행 중인/진행 예정인 전시 목록을 함께 조회합니다.",
     * tags={"GalleryConsole"},
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(
     * name="id", 
     * in="path", 
     * required=true, 
     * @OA\Schema(type="integer", example=1), 
     * description="조회할 갤러리 ID"
     * ),
     * * // --- 200 OK 응답 스키마 ---
     * @OA\Response(
     * response=200, 
     * description="상세 조회 성공",
     * @OA\JsonContent(
     * type="object",
     * description="갤러리 상세 정보 및 전시 목록",
     * * // ===========================================
     * // (1) 갤러리(Gallery)의 기본 프로퍼티
     * // ===========================================
     * @OA\Property(property="gallery_name", type="string"),
     * @OA\Property(property="gallery_image", type="string"),
     * @OA\Property(property="gallery_address", type="string"),
     * @OA\Property(property="gallery_start_time", type="string"),
     * @OA\Property(property="gallery_end_time", type="string"),
     * @OA\Property(property="gallery_closed_day", type="string"),
     * @OA\Property(property="gallery_category", type="string"),
     * @OA\Property(property="gallery_description", type="string"),
     * @OA\Property(property="gallery_latitude", type="number", format="float"),
     * @OA\Property(property="gallery_longitude", type="number", format="float")
     * @OA\Property(property="gallery_phone", type="string")
     * @OA\Property(property="gallery_email", type="string")
     * @OA\Property(property="gallery_homepage", type="string")
     * @OA\Property(property="gallery_sns", type="string")
     * * // ===========================================
     * // (2) 갤러리에 포함된 전시(Exhibition) 배열 프로퍼티
     * // ===========================================
     * @OA\Property(
     * property="exhibitions",
     * type="array",
     * description="해당 갤러리의 전시 목록",
     * @OA\Items(
     * type="object",
     * description="전시 정보",
     * * // --- 'exhibitions' 배열 안의 객체 프로퍼티 ---
     * @OA\Property(property="id", type="integer", example=101, description="전시 ID"),
     * @OA\Property(property="title", type="string", example="빛의 향연", description="전시 제목"),
     * @OA\Property(property="start_date", type="string", format="date", example="2025-11-01", description="전시 시작일"),
     * @OA\Property(property="end_date", type="string", format="date", example="2025-11-30", description="전시 종료일"),
     * @OA\Property(property="status", type="string", example="upcoming", description="전시 상태 (upcoming, current, past)")
     * )
     * )
     * )
     * ),
     * * // --- 404 Not Found 응답 스키마 ---
     * @OA\Response(
     * response=404, 
     * description="갤러리 없음", 
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="message", type="string", example="Gallery not found")
     * )
     * )
     * )
     */
    public function getGalleryById($id) {
        $decoded = $this->authMiddleware->requireAdmin();
        $user_id = $decoded->sub;

        $gallery = $this->galleryModel->getById($id, $user_id);

        if ($gallery) {
            $filters = ['gallery_id' => $id];
            $exhibitions = $this->exhibitionModel->getExhibitions($filters);

            if (is_object($gallery)) {
                $gallery->exhibitions = $exhibitions;
            } elseif (is_array($gallery)) {
                $gallery['exhibitions'] = $exhibitions;
            }

            header('Content-Type: application/json');
            echo json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Gallery not found']);
        }
    }
}