<?php
namespace Controllers;

use Models\DocentModel;
use Middlewares\AuthMiddleware;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Docent",
 *     description="작품 도슨트(음성/영상) 생성 및 조회 API"
 * )
 */
class DocentController
{
    /** @var DocentModel */
    private $service;

    /** @var AuthMiddleware */
    private $auth;

    public function __construct()
    {
        $this->service = new DocentModel();
        $this->auth    = new AuthMiddleware();
    }

    /**
     * @OA\Post(
     *     path="/api/docents/{id}",
     *     summary="작품 도슨트 생성 (TTS mp3 또는 아바타 영상)",
     *     description="
     * 작품에 대한 도슨트 음성(mp3) 또는 영상(mp4)을 생성합니다.
     * - type=audio: Google TTS로 mp3만 생성 (docent_audio_path 저장)
     * - type=video: Google TTS로 mp3 생성 후, Hedra API로 영상을 생성하여 mp4 저장 (audio+video 모두 저장)
     *
     * 공통 파라미터:
     * - docent_script: 도슨트로 사용할 텍스트
     * - art_name: 파일명에 넣을 작품명
     *
     * type=video 일 때만 이미지 파일(donent_img)을 multipart/form-data로 업로드합니다.
     * docent_img 가 비어 있으면 media/docent/docent_default.jpg 를 기본 이미지로 사용합니다.
     * ",
     *     tags={"Docent"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="작품 ID (APIServer_art.id)",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         required=false,
     *         description="audio 또는 video (기본값 audio)",
     *         @OA\Schema(type="string", enum={"audio","video"})
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="docent_script",
     *                     type="string",
     *                     description="도슨트 텍스트"
     *                 ),
     *                 @OA\Property(
     *                     property="art_name",
     *                     type="string",
     *                     description="파일명에 넣을 작품명"
     *                 ),
     *                 @OA\Property(
     *                     property="docent_img",
     *                     type="string",
     *                     format="binary",
     *                     description="동영상용 아바타 이미지 (type=video일 때만 사용)"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="도슨트 생성 성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="mode",   type="string", example="audio"),
     *             @OA\Property(
     *                 property="docent_audio_url",
     *                 type="string",
     *                 nullable=true,
     *                 example="/media/docent/mp3/1_작품명_1710000000.mp3"
     *             ),
     *             @OA\Property(
     *                 property="docent_video_url",
     *                 type="string",
     *                 nullable=true,
     *                 example="/media/docent/video/1_작품명_1710000000.mp4"
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="작품을 찾을 수 없음"),
     *     @OA\Response(response=500, description="내부 서버 오류 또는 외부 API 오류")
     * )
     */
    public function generate($params): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // $this->auth->requireAuth(); // 필요 시 활성화

        $artId = (int)($params['id'] ?? 0);

        // JSON 바디도 같이 지원 (audio 옛 요청 호환용)
        $rawBody = file_get_contents('php://input');
        $body    = [];
        if ($rawBody) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        // type: query → POST → JSON → 기본 audio
        $type = $_GET['type']
            ?? ($_POST['type'] ?? ($body['type'] ?? 'audio'));
        $type = ($type === 'video') ? 'video' : 'audio';

        // 공통 파라미터
        // 우선순위: multipart/form-data(docent_script) → JSON(docent_script) → JSON(script, 기존 이름)
        $script =
            $_POST['docent_script']
            ?? ($body['docent_script'] ?? ($body['script'] ?? null));

        $artName =
            $_POST['art_name']
            ?? ($body['art_name'] ?? null);

        // video 모드일 때만 이미지 처리
        $uploadedImagePath = null;
        $avatarImageUrl    = null; // /media/... or docent/... 형식

        if ($type === 'video') {
            if (
                isset($_FILES['docent_img']) &&
                $_FILES['docent_img']['error'] === UPLOAD_ERR_OK
            ) {
                // 업로드된 파일 실제 경로 (임시)
                $uploadedImagePath = $_FILES['docent_img']['tmp_name'];
            } else {
                // 업로드 이미지가 없으면 기본 이미지 사용
                // media/docent/docent_default.jpg  ← 실제 파일은
                // backend/media/docent/docent_default.jpg 에 있어야 함
                $avatarImageUrl = 'docent/docent_default.jpg';
            }
        }

        try {
            $result = $this->service->generateDocent(
                $artId,
                $type,
                $script,
                $avatarImageUrl,
                $artName,
                $uploadedImagePath // audio 일 때는 null
            );

            $response = [
                'status'           => 'success',
                'mode'             => $result['mode'], // audio | video
                'docent_audio_url' => $result['audio_path']
                    ? $this->buildMediaUrl($result['audio_path'])
                    : null,
                'docent_video_url' => $result['video_path']
                    ? $this->buildMediaUrl($result['video_path'])
                    : null,
            ];

            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $code = $e->getCode() === 404 ? 404 : 500;
            http_response_code($code);

            echo json_encode([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/docents/{id}",
     *     summary="작품 도슨트(음성/영상) 조회",
     *     description="APIServer_art.docent_audio_path / docent_video_path를 기반으로 도슨트 파일 경로를 반환합니다.",
     *     tags={"Docent"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="작품 ID (APIServer_art.id)",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="도슨트 정보 반환",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(
     *                 property="docent_audio_url",
     *                 type="string",
     *                 nullable=true,
     *                 example="/media/docent/mp3/1_작품명_1710000000.mp3"
     *             ),
     *             @OA\Property(
     *                 property="docent_video_url",
     *                 type="string",
     *                 nullable=true,
     *                 example="/media/docent/video/1_작품명_1710000000.mp4"
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="도슨트가 없거나 작품을 찾을 수 없음"),
     *     @OA\Response(response=500, description="내부 서버 오류")
     * )
     */
    public function show($params): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $artId = (int)($params['id'] ?? 0);

        try {
            $docent = $this->service->getDocent($artId);
            if (!$docent || (!$docent['docent_audio_path'] && !$docent['docent_video_path'])) {
                http_response_code(404);
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Docent not found for this artwork',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                'status'           => 'success',
                'docent_audio_url' => $docent['docent_audio_path']
                    ? $this->buildMediaUrl($docent['docent_audio_path'])
                    : null,
                'docent_video_url' => $docent['docent_video_path']
                    ? $this->buildMediaUrl($docent['docent_video_path'])
                    : null,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * media 상대경로를 /media/... 절대 URL로 변환
     * - DB에는 docent/mp3/..., docent/video/... 만 저장
     * - 클라이언트에는 /media/docent/... 형태로 전달
     */
    private function buildMediaUrl(?string $relativePath): ?string
    {
        if (!$relativePath) return null;
        return '/media/' . ltrim($relativePath, '/');
    }
}
