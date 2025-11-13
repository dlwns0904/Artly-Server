<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;

/**
 * @OA\Tag(
 * name="InvitationGenerate",
 * description="초대장 문구 생성 콘솔 API"
 * )
 */
class InvitationGenerateConsoleController {

    private $apiKey;

    /**
     * 생성자: API 키를 미리 로드하고 검증합니다.
     */
    public function __construct() {
        $this->apiKey = getenv('OPENAI_API_KEY');
        if (empty($this->apiKey)) {
            // API 키가 없으면 컨트롤러 생성을 중단시킵니다.
            throw new \Exception('OPENAI_API_KEY가 .env 파일에 설정되지 않았습니다.');
        }
    }

    /**
     * @OA\Post(
     * path="/api/console/invitation/create",
     * summary="초대장 문구 3가지 초안 생성",
     * tags={"InvitationGenerate"},
     * description="행사 주제와 요구사항을 바탕으로 AI(GPT)가 3가지 버전의 초대장 문구 초안을 생성하여 반환합니다.",
     * @OA\RequestBody(
     * required=true,
     * description="초대장 생성을 위한 정보",
     * @OA\MediaType(
     * mediaType="application/json",
     * @OA\Schema(
     * type="object",
     * required={"eventTopic", "userRequirements"},
     * @OA\Property(
     * property="eventTopic",
     * type="string",
     * example="KAU 2025년 AI 졸업작품 전시회"
     * ),
     * @OA\Property(
     * property="userRequirements",
     * type="string",
     * example="#따뜻한 #격려 #미래지향적, '모두의 미래를 위한 AI' 문구 반드시 포함"
     * )
     * )
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="초대장 문구 3가지 생성 성공",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(type="string"),
     * example={
     * "초대장 버전 1: AI가 그리는 미래, KAU 졸업작품전...",
     * "초대장 버전 2: 따뜻한 격려의 박수를 보낼 시간...",
     * "초대장 버전 3: '모두의 미래를 위한 AI'가 이곳에서 시작됩니다..."
     * }
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="API 호출 오류 또는 서버 내부 오류",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="error", type="string", example="OpenAI API 오류 (HTTP 401): Incorrect API key provided...")
     * )
     * )
     * )
     */
    public function createInvitations($eventTopic, $userRequirements) {
        // ✅ 오늘 날짜 설정 (예: 2025년 11월 13일)
        setlocale(LC_TIME, 'ko_KR.UTF-8');
        $todayDate = date('Y년 n월 j일');
    
        // ✅ 가이드라인 (시스템 프롬프트)
        $guidelines = "
            오늘의 날짜는 {$todayDate}입니다.
            초대장 문구 생성에 있어 다음과 같은 지침을 따르십시오.
            1. 본 서비스는 전시회 또는 기타 예술 관련 행사의 초대장 문구를 생성함을 목표로 합니다.
            2. 사용자가 입력한 행사 주제와 요청사항을 반영하여 문구를 작성하십시오.
            3. 단순한 정보 전달 형식이 아닌, 미사여구를 활용하는 등 독자가 흥미를 느낄 수 있도록 매력적이고 창의적인 문구로 작성하십시오.
            4. '#'으로 시작하는 해시태그 형식의 요청사항은 글의 분위기에 참고하되, 본문에 포함하지 마십시오.
            5. 요청사항에 포함해야 하는 문구가 주어졌을 경우 해당 문구를 반드시 포함하십시오.
            6. 주제가 명확하지 않거나 요청사항이 없을 경우, 해당 내용을 포함하지 않고 일반적인 초대장 문구를 작성해 주세요.
            7. 예술 관련 행사라는 범위를 벗어났거나 장난성 요청이 주어진 경우, 정중하게 생성을 거절해야 합니다.
            8. 공개하기에 부적합한 폭력적, 선정적 또는 차별적인 표현은 사용해서는 안됩니다.
            9. 주최기관명이나 장소, 일시 등이 주어지지 않은 경우, 해당 내용에 대한 문구는 포함하지 마십시오.
            
            생성에 있어 아래와 같은 예시를 참고할 수 있습니다.
            그러나 예시에 주어진 문구를 그대로 가져다 써서는 안됩니다.
            
            예시 1.
            봄 한 송이
            여름 한 컵
            가을 한 장
            겨울 한 숨
            
            봄은 새싹을 틔우고
            여름의 잎은 푸르고
            가을은 열매를 맺고
            겨울의 씨앗을 품는
            
            전시회의 주제는 사계입니다.
            학생 개개인의 작품을 선보일 예정이오니
            여러분의 많은 격려와 축하 부탁드립니다.
            
            예시 2.
            지난 해 열렸던 통일박람회를 통해
            우리는 통일로 하나 될 수 있음을 확인했습니다.
            
            이제, 그 마음을 다시 모으고자 합니다.
            '그래서 통일입니다!'라는 슬로건 아래
            함께 통일을 즐기고 노래하고 소통하여
            온 국민과 함께 행복한 통일시대를 준비하고자 합니다.
            
            뜻 깊은 자리에 참석하시어,
            새로운 한반도의 비전을 함께 열어주시기 바랍니다.
            
            예시 3.
            풍요로운 결실의 계절을 맞이하여
            충북도립대학교 조리제빵과에서
            '제 25회 졸업작품전시회'를 개최합니다.
            
            이번 졸업작품전시회는
            '옥천특산물과 조리제빵과의 만남'이라는
            주제로 디저트류 제품과
            그동안 학생들이 갈고 닦은 솜씨를 발휘하여
            준비한 다양한 작품을 함께 출품하게 되었습니다.
            
            전시된 작품들은 학생들의
            오랜 땀과 노력으로 탄생하였습니다.
            
            부디 참석하시어 자리를
            빛내어 주시면 감사하겠습니다.
        ";
    
        // ✅ 프롬프트 구성 (유저 프롬프트)
        $userPrompt = "아래의 정보를 바탕으로, 매력적이고 창의적인 초대장 문구를 3가지 생성해주세요.\n\n".
            "사용자가 입력한 정보:\n".
            "- 행사 주제: {$eventTopic}\n".
            "- 기타 요청사항: {$userRequirements}";
        
        $messages = [
            ["role" => "system", "content" => $guidelines],
            ["role" => "user", "content" => $userPrompt],
        ];

        // ✨ 헬퍼 함수 호출 (n=3, max_tokens=2000)
        $result = $this->callOpenAI($messages, 3, 0.9, 2000);

        // ✅ 결과 파싱
        $invitations = [];
        if (isset($result['choices']) && is_array($result['choices'])) {
            foreach ($result['choices'] as $choice) {
                $content = trim($choice['message']['content'] ?? '');
                if ($content) $invitations[] = $content;
            }
        }
    
        // ✅ 예외 처리
        if (empty($invitations)) {
            // (오류가 발생했어도, API 오류는 callOpenAI에서 throw 되었을 것이므로)
            // (이 경우는 choices가 비어있는 정상이지만 비정상인 응답)
            $invitations[] = "초대장 문구 생성에 실패했습니다. (API 응답은 받았으나 내용이 비어있습니다)";
        }
    
        return $invitations;
    }

    /**
     * @OA\Post(
     * path="/api/console/invitation/refine",
     * summary="선택된 초대장 문구 다듬기 (1개 생성)",
     * tags={"InvitationGenerate"},
     * description="사용자가 선택한 초대장 초안과 추가 요구사항을 바탕으로 AI(GPT)가 최종 1개의 다듬어진 문구를 생성하여 반환합니다.",
     * @OA\RequestBody(
     * required=true,
     * description="초대장 문구를 다듬기 위한 정보",
     * @OA\MediaType(
     * mediaType="application/json",
     * @OA\Schema(
     * type="object",
     * required={"selectedInvitation", "eventTopic", "userRequirements"},
     * @OA\Property(
     * property="selectedInvitation",
     * type="string",
     * description="사용자가 '초안 생성'에서 선택한 초대장 문구 원본",
     * example="초대장 버전 1: AI가 그리는 미래..."
     * ),
     * @OA\Property(
     * property="eventTopic",
     * type="string",
     * description="참고할 기존 행사 주제",
     * example="KAU 2025년 AI 졸업작품 전시회"
     * ),
     * @OA\Property(
     * property="userRequirements",
     * type="string",
     * description="다듬기 위한 추가/변경 요구사항",
     * example="#좀 더 감성적으로, #전문적인 느낌 추가"
     * )
     * )
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="초대장 문구 1개 다듬기 성공",
     * @OA\JsonContent(
     * type="array",
     * @OA\Items(type="string"),
     * example={
     * "초대장 최종본: AI가 그리는 미래, 그 눈부신 서막이 KAU 2025년 졸업작품전에서 펼쳐집니다..."
     * }
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="API 호출 오류 또는 서버 내부 오류",
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="error", type="string", example="OpenAI API 오류 (HTTP 500): The server had an error...")
     * )
     * )
     * )
     */
    public function refineInvitation($selectedInvitation, $eventTopic, $userRequirements) {
        
        // ✅ 가이드라인 (시스템 프롬프트)
        $guidelines = "
            아래 초대장 문구를 기반으로, 새로운 초대장 문구를 생성해주세요. 
            기존의 문구 형식은 전체적으로 유지하되, 더 매력적이고 세련된 표현을 사용하며 변화를 줘보세요.

            ---
            {$selectedInvitation}
            ---
        ";

        // ✅ 프롬프트 구성 (유저 프롬프트)
        $userPrompt = "아래의 정보를 바탕으로, 초대장 문구를 더 매력적이고 세련되게 변화를 주세요.\n\n".
            "사용자가 입력한 정보:\n".
            "- 행사 주제: {$eventTopic}\n".
            "- 기타 요청사항: {$userRequirements}";

        $messages = [
            ["role" => "system", "content" => $guidelines],
            ["role" => "user", "content" => $userPrompt],
        ];

        // ✨ 헬퍼 함수 호출 (n=1, max_tokens=2000)
        $result = $this->callOpenAI($messages, 1, 0.9, 2000);

        // ✅ 결과 파싱
        $finalInvitation = [];
        if (isset($result['choices']) && is_array($result['choices'])) {
            foreach ($result['choices'] as $choice) {
                $content = trim($choice['message']['content'] ?? '');
                if ($content) $finalInvitation[] = $content;
            }
        }
    
        // ✅ 예외 처리
        if (empty($finalInvitation)) {
            $finalInvitation[] = "초대장 문구 다듬기에 실패했습니다.";
        }
    
        return $finalInvitation;
    }


    /**
     * OpenAI API를 호출하는 공통 헬퍼 함수
     * (이 함수는 비즈니스 로직을 담고 있으므로 컨트롤러 내부에 private으로 존재)
     *
     * @param array $messages 메시지 배열 (system, user)
     * @param int $n 생성할 응답 수
     * @param float $temperature 창의성 (0.0 ~ 2.0)
     * @param int $maxTokens 최대 토큰
     * @return array API 응답 결과 (json_decode)
     * @throws \Exception API 호출 실패 시
     */
    private function callOpenAI($messages, $n, $temperature, $maxTokens) {
        $endpoint = "https://api.openai.com/v1/chat/completions";
        $data = [
            "model" => "gpt-4o-mini", // (오류 방지를 위해 실제 모델명으로 수정)
            "messages" => $messages,
            "temperature" => $temperature,
            "max_tokens" => $maxTokens,
            "n" => $n
        ];
    
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer {$this->apiKey}"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        // (네트워크 타임아웃 설정 - 권장)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10초
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60초
    
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // cURL 연결 자체의 실패
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch); 
            throw new \Exception('OpenAI API cURL 오류: ' . $error);
        }
    
        // API 레벨의 오류 (4xx, 5xx)
        if ($httpCode !== 200) {
            $errorResult = json_decode($response, true);
            $errorMessage = $errorResult['error']['message'] ?? $response;
            curl_close($ch);
            throw new \Exception("OpenAI API 오류 (HTTP {$httpCode}): {$errorMessage}");
        }
        
        curl_close($ch);
    
        return json_decode($response, true);
    }
}