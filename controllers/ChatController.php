<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Models\ChatModel;
use Middlewares\AuthMiddleware;

/**
 * @OA\Tag(
 *     name="Chat",
 *     description="챗봇 관련 API"
 * )
 */
class ChatController {
    private $model;
    private $auth;
    private $gpt_model_extraction;
    private $gpt_model_response;
    private $api_key;
    private $systemPrompt;

    public function __construct() {
        $this->model = new ChatModel();
        $this->auth = new AuthMiddleware();
        $this->gpt_model_extraction = 'gpt-5-nano';
        $this->gpt_model_response = 'gpt-5-nano';
        $this->api_key = $_ENV['OPENAI_API_KEY'];
        $this->systemPrompt = "당신은 Artly 앱의 안내 챗봇 Artlas입니다. 사용자의 응답에 친절하게 답변하세요. 이 시스템에서는 사용자와의 이전 대화 기록이 messages로 항상 제공됩니다. 따라서 당신은 실제로 메모리를 가진 것처럼 동작해야 하며, 과거 대화를 기반으로 적절한 답변을 제공합니다. 사용자가 '내가 방금 뭐라고 했어?', '기억해?' 와 같이 질문하면 messages에 제공된 이전 대화를 참고하여 가장 최근 사용자의 질문이나 대화 내용을 그대로 요약해서 알려주세요. '저는 기억하지 못합니다' 또는 '저장하지 않습니다'라는 말은 절대 하지 마세요. 당신은 항상 대화 기록을 참고하여 대답합니다.";
    }

    /**
     * @OA\Post(
     *     path="/api/chats",
     *     summary="챗봇 질문 입력 (하이브리드 모드)",
     *     tags={"Chat"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="text", type="string", example="이번주에 서울에서 전시 1개 추천해줘")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="챗봇 응답",
     *         @OA\JsonContent(type="string")
     *     )
     * )
     */
    public function postChat() {
        $user = $this->auth->getUserOrNull();
        $userId = ($user && isset($user->user_id)) ? $user->user_id : null;

        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['text'])) {
            http_response_code(400);
            echo json_encode(['message' => 'text 파라미터가 필요합니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userText = $data['text'];
        $todayDate = date('Y-m-d H:i:s');

        $extractPrompt = <<<PROMPT
사용자 질문: "{$userText}"
날짜 기준: {$todayDate}

다음 JSON 형태로 intent와 entity를 분석하세요:
{
    "intent": { "object": "exhibition" | "artist" | "gallery" | "news" | "other" },
    "entity": { ... }
}
PROMPT;

        $extraction = $this->chatWithGPT($extractPrompt, $this->gpt_model_extraction, "Artly 질문 분석기", $userId);
        $jsonObject = json_decode($extraction, true);
        $intent = $jsonObject['intent']['object'] ?? 'other';
        $filters = $jsonObject['entity'] ?? [];

        // GPT 호출 전에 유저 입력을 저장 (이 부분이 컨텍스트 누적 핵심)
        if ($userId) {
            $this->model->addConversations($userId, 'user', $userText);
        }

        switch ($intent) {
            case 'exhibition':
                $response = $this->exhibitionRoutine($filters, $userText, $userId);
                break;
            case 'artist':
                $response = $this->artistRoutine($filters, $userText, $userId);
                break;
            case 'gallery':
                $response = $this->galleryRoutine($filters, $userText, $userId);
                break;
            case 'news':
                $response = $this->announcementRoutine($filters, $userText, $userId);
                break;
            default:
                $response = $this->defaultRoutine($userText, $userId);
        }

        if ($userId) {
            $this->model->addConversations($userId, 'assistant', $response);
        }

        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    private function exhibitionRoutine($filters, $userText, $userId) {
        $exhibitions = $this->model->getExhibitions($filters);
        if (!$exhibitions) return "해당 조건에 맞는 전시회를 찾을 수 없습니다.";

        $exhibitionList = '';
        foreach (array_slice($exhibitions, 0, 10) as $idx => $row) {
            $exhibitionList .= ($idx + 1) . '. "' . $row['exhibition_title'] . '", ' . $row['exhibition_location'] . ', ' . $row['exhibition_start_date'] . ' ~ ' . $row['exhibition_end_date'] . "\n";
        }

        $finalUserText = $userText . "\n\n검색된 전시회 목록:\n" . $exhibitionList . "가장 적절한 전시회 1개만 자연스럽게 추천해 주세요.";
        return $this->chatWithGPT($finalUserText, $this->gpt_model_response, $this->systemPrompt, $userId);
    }

    private function artistRoutine($filters, $userText, $userId) {
        $artists = $this->model->getArtists($filters);
        if (!$artists) return "조건에 맞는 작가를 찾을 수 없습니다.";

        $artistList = '';
        foreach (array_slice($artists, 0, 10) as $idx => $row) {
            $artistList .= ($idx + 1) . '. "' . $row['artist_name'] . '", ' . $row['artist_category'] . ', ' . $row['artist_nation'] . "\n";
        }

        $finalUserText = $userText . "\n\n검색된 작가 목록:\n" . $artistList . "가장 적절한 작가 1명만 자연스럽게 추천해 주세요.";
        return $this->chatWithGPT($finalUserText, $this->gpt_model_response, $this->systemPrompt, $userId);
    }

    private function galleryRoutine($filters, $userText, $userId) {
        $galleries = $this->model->getGalleries($filters);
        if (!$galleries) return "조건에 맞는 갤러리를 찾을 수 없습니다.";

        $galleryList = '';
        foreach (array_slice($galleries, 0, 10) as $idx => $row) {
            $galleryList .= ($idx + 1) . '. "' . $row['gallery_name'] . '", ' . $row['gallery_address'] . "\n";
        }

        $finalUserText = $userText . "\n\n검색된 갤러리 목록:\n" . $galleryList . "가장 적절한 갤러리 1곳만 자연스럽게 추천해 주세요.";
        return $this->chatWithGPT($finalUserText, $this->gpt_model_response, $this->systemPrompt, $userId);
    }

    private function announcementRoutine($filters, $userText, $userId) {
        $announcements = $this->model->getAnnouncements($filters);
        if (!$announcements) return "조건에 맞는 공고가 없습니다.";

        $announcementList = '';
        foreach (array_slice($announcements, 0, 10) as $idx => $row) {
            $announcementList .= ($idx + 1) . '. "' . $row['announcement_title'] . '", ' . $row['announcement_start_datetime'] . " ~ " . $row['announcement_end_datetime'] . "\n";
        }

        $finalUserText = $userText . "\n\n검색된 공고 목록:\n" . $announcementList . "적절한 공고 1개만 자연스럽게 추천해 주세요.";
        return $this->chatWithGPT($finalUserText, $this->gpt_model_response, $this->systemPrompt, $userId);
    }

    private function defaultRoutine($userText, $userId) {
        return $this->chatWithGPT($userText, $this->gpt_model_response, $this->systemPrompt, $userId);
    }


    private function chatWithGPT($userText, $gpt_model, $systemPrompt, $userId = null) {
    if (empty($this->api_key)) {
        return '서버 설정 오류: OPENAI_API_KEY 누락';
    }

    $messages = [['role' => 'system', 'content' => $systemPrompt]];

    if ($userId) {
        $conversations = $this->model->getConversations($userId, 30); // ← 최근 30개만
        foreach ($conversations as $conv) {
            $messages[] = ['role' => $conv['role'], 'content' => $conv['content']];
        }
    }

    $messages[] = ['role' => 'user', 'content' => $userText];

    $postData = [
        'model'       => $gpt_model,
        'messages'    => $messages,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer {$this->api_key}"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    $result = curl_exec($ch);
    $http   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        error_log("[OpenAI CURL] $err");
        return 'GPT 통신 오류';
    }
    curl_close($ch);

    $response = json_decode($result, true);
    if ($http >= 400 || isset($response['error'])) {
        // ★ 서버 로그에 원문 남기기
        error_log("[OpenAI $http] ".$result);
        // 사용자 응답은 에러 메시지 요약
        return $response['error']['message'] ?? ('GPT 호출 오류 (HTTP '.$http.')');
    }

    return $response['choices'][0]['message']['content'] ?? 'GPT 응답 오류 발생';
}


}

