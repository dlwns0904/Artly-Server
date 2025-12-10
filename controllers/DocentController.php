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
    private DocentModel $service;
    private AuthMiddleware $auth;

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
     *         description="audio 또는 video (querystring 으로도 전달 가능, body에 있으면 body 우선)",
     *         @OA\Schema(type="string", enum={"audio","video"})
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="type",
     *                 type="string",
     *                 enum={"audio","video"},
     *                 description="audio: TTS mp3 생성, video: Hedra로 아바타 영상 생성",
     *                 example="audio"
     *             ),
     *             @OA\Property(
     *                 property="script",
     *                 type="string",
     *                 description="도슨트로 사용할 텍스트. 없으면 art_docent → art_description 순서로 사용"
     *             ),
     *             @OA\Property(
     *                 property="avatar_image_url",
     *                 type="string",
     *                 description="아바타 이미지 URL. 없으면 작품 대표 이미지(art_image)를 사용.
     * - http(s):// 로 시작하면 서버에서 다운로드해서 사용
     * - /media/... 형식이면 서버 로컬 media 디렉토리 기준으로 사용"
     *             ),
     *             @OA\Property(
     *                 property="art_name",
     *                 type="string",
     *                 description="파일명에 포함할 작품명. 없으면 art_title 사용"
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

        // 필요하면 권한 체크 (콘솔 전용이면 requireAdmin 등으로 변경)
        // $this->auth->requireAdmin();
        // $this->auth->requireAuth();

        $artId = (int)($params['id'] ?? 0);

        $rawBody = file_get_contents('php://input');
        $body    = $rawBody ? json_decode($rawBody, true) : [];
        if (!is_array($body)) {
            $body = [];
        }

        // type은 querystring 우선, 없으면 body, 둘 다 없으면 audio 기본
        $type           = $_GET['type']             ?? ($body['type']             ?? 'audio');
        $script         = $body['script']           ?? null;
        $avatarImageUrl = $body['avatar_image_url'] ?? null;
        $artName        = $body['art_name']         ?? null;

        try {
            $result = $this->service->generateDocent(
                $artId,
                $type,
                $script,
                $avatarImageUrl,
                $artName
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

        // 필요시 권한 체크
        // $this->auth->requireAuth();

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
