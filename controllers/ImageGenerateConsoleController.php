<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Models\ImageGenerateModel;

/**
 * @OA\Tag(name="ImageGenerate", description="이미지 생성 콘솔 API")
 */
class ImageGenerateConsoleController
{
    /** @var \Models\ImageGenerateModel */
    private $svc;

    /** @var string */
    private $modelExtraction = 'gpt-5-mini';
    /** @var string */
    private $modelImage = 'gpt-image-1';

    public function __construct()
    {
        $this->svc = new ImageGenerateModel($_ENV['openaiApiKey'], $_ENV['pixabayApiKey']);
    }

    /**
     * @OA\Post(
     *   path="/api/console/images/generate",
     *   summary="전시 포스터 배경 이미지 생성 (콘솔용)",
     *   tags={"ImageGenerate"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"text"},
     *       @OA\Property(property="text", type="string", example="시간과 예술을 주제로 한 몽환적 배경")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="성공",
     *     @OA\JsonContent(
     *       @OA\Property(property="image", type="string", example="data:image/png;base64,..."),
     *       @OA\Property(property="keywords", type="array", @OA\Items(type="string")),
     *       @OA\Property(property="seedImages", type="array", @OA\Items(type="string")),
     *       @OA\Property(property="usedPrompt", type="string")
     *     )
     *   ),
     *   @OA\Response(response=400, description="요청 오류"),
     *   @OA\Response(response=404, description="시드 이미지 없음"),
     *   @OA\Response(response=429, description="요청 과다"),
     *   @OA\Response(response=500, description="서버 오류")
     * )
     */
    public function create()
    {
        header('Content-Type: application/json; charset=utf-8');

        $body = json_decode(file_get_contents('php://input'), true);
        $userText = trim(isset($body['text']) ? $body['text'] : '');
        if ($userText === '') {
            http_response_code(400);
            echo json_encode(['message' => 'text 파라미터가 필요합니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // 1) 키워드 추출
            $keywords = $this->svc->extractKeywords($userText, $this->modelExtraction);
            if (empty($keywords)) $keywords = $this->svc->fallbackKeywords($userText);

            // 2) Pixabay 검색(무작위 2장)
            $seedUrls = $this->svc->fetchPixabayImages($keywords, 2);
            if (empty($seedUrls)) {
                http_response_code(404);
                echo json_encode(['message' => '관련 이미지를 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 3) 시드 이미지 다운로드
            $tmpFiles = $this->svc->downloadTempFiles($seedUrls);

            // 4) OpenAI 이미지 편집 호출
            $prompt  = $this->svc->buildImagePrompt($userText);
            $dataUrl = $this->svc->callImagesEdits($this->modelImage, $prompt, $tmpFiles);

            // 5) 정리
            $this->svc->cleanup($tmpFiles);

            if (!$dataUrl) {
                http_response_code(500);
                echo json_encode(['message' => '이미지 생성 결과를 받지 못했습니다.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                'image'      => $dataUrl,
                'keywords'   => $keywords,
                'seedImages' => $seedUrls,
                'usedPrompt' => $prompt
            ], JSON_UNESCAPED_UNICODE);

        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), '429') !== false) {
                http_response_code(429);
            } else {
                http_response_code(500);
            }
            echo json_encode(['message' => '이미지 생성 중 오류', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['message' => '서버 오류', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
