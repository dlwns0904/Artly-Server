<?php
namespace Controllers;

use OpenApi\Annotations as OA;
use Middlewares\AuthMiddleware;

use Models\NotificationModel;
use Models\UserFcmTokenModel;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Exception;

/**
 * @OA\Tag(
 *     name="Notification",
 *     description="알림(발송, 관리) 관련 API"
 * )
 */
class NotificationController {
    private $notificationModel;
    private $tokenModel;

    public function __construct() {
        $this->notificationModel = new NotificationModel;
        $this->tokenModel = new UserFcmTokenModel;
        $this->auth = new AuthMiddleware();
    }

    /**
     * @OA\Post(
     * path="/api/notification/registerToken",
     * summary="유저 FCM 토큰 등록 및 갱신",
     * description="앱 실행 시 또는 로그인 직후, 클라이언트가 발급받은 FCM 토큰을 서버에 등록합니다. (이미 존재하면 갱신)",
     * tags={"Notification"},
     * @OA\RequestBody(
     * required=true,
     * description="등록할 유저 ID와 FCM 토큰",
     * @OA\JsonContent(
     * required={"user_id", "fcm_token"},
     * @OA\Property(property="user_id", type="integer", description="유저 ID (APIServer_user.id)", example=123),
     * @OA\Property(property="fcm_token", type="string", description="Firebase 발급 토큰", example="bk3RNwTe3H0:CI2k_HHwg...")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="성공",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="success"),
     * @OA\Property(property="message", type="string", example="FCM 토큰이 성공적으로 등록되었습니다."),
     * @OA\Property(property="data", type="object", description="등록된 토큰 정보",
     * @OA\Property(property="id", type="integer"),
     * @OA\Property(property="user_id", type="integer"),
     * @OA\Property(property="fcm_token", type="string"),
     * @OA\Property(property="update_dtm", type="string", format="date-time")
     * )
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="필수 파라미터 누락",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="error"),
     * @OA\Property(property="message", type="string", example="필수 파라미터(user_id, fcm_token)가 누락되었습니다.")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="서버 내부 오류",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="error"),
     * @OA\Property(property="message", type="string", example="Database Error...")
     * )
     * )
     * )
     */
    public function registerToken() {
        header('Content-Type: application/json');

        try {
            // Request Body 받기
            $input = json_decode(file_get_contents('php://input'), true);

            // 유효성 검사
            if (empty($input['user_id']) || empty($input['fcm_token'])) {
                throw new Exception('필수 파라미터(user_id, fcm_token)가 누락되었습니다.');
            }

            // 모델을 통해 DB에 저장 (UPSERT)
            $result = $this->tokenModel->register([
                'user_id'   => $input['user_id'],
                'fcm_token' => $input['fcm_token']
            ]);

            echo json_encode([
                'status'  => 'success',
                'message' => 'FCM 토큰이 성공적으로 등록되었습니다.',
                'data'    => $result
            ]);

        } catch (Exception $e) {
            http_response_code(500); // or 400 based on error
            echo json_encode([
                'status'  => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * @OA\Post(
     * path="/api/notification/send",
     * summary="푸시 알림 발송 (Firebase)",
     * description="특정 사용자들에게 푸시 알림을 전송합니다. 인증 토큰 내의 정보를 이용해 발송자를 식별합니다.",
     * tags={"Notification"},
     * security={{"bearerAuth":{}}}, 
     * @OA\RequestBody(
     * required=true,
     * description="알림 발송 정보",
     * @OA\JsonContent(
     * required={"userIds", "title", "message"},
     * @OA\Property(
     * property="userIds",
     * type="array",
     * description="수신자 ID 목록 (Array of Integers)",
     * @OA\Items(type="integer"),
     * example={10, 24, 35}
     * ),
     * @OA\Property(
     * property="title",
     * type="string",
     * description="알림 제목",
     * example="새로운 이벤트 알림"
     * ),
     * @OA\Property(
     * property="message",
     * type="string",
     * description="알림 본문",
     * example="회원님, 지금 접속하시면 포인트를 드려요!"
     * )
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="성공",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="success"),
     * @OA\Property(
     * property="result",
     * type="object",
     * @OA\Property(property="notification_id", type="integer", example=152),
     * @OA\Property(property="target_user_count", type="integer", example=2),
     * @OA\Property(property="success_count", type="integer", example=3),
     * @OA\Property(property="failure_count", type="integer", example=0)
     * )
     * )
     * ),
     * @OA\Response(
     * response=401,
     * description="인증 실패 (토큰 없음 또는 만료)",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="Invalid or missing token")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="서버 내부 오류",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="error"),
     * @OA\Property(property="message", type="string", example="Internal Server Error")
     * )
     * )
     * )
     */
    public function sendNotification() {
        header('Content-Type: application/json');

        $decoded = $this->auth->requireAdmin(); // Admin 권한 체크

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            $creatorId = $decoded->user_id ?? $decoded->id ?? null;
            $userIds   = $input['userIds']    ?? [];
            $title     = $input['title']      ?? '';
            $body      = $input['message']    ?? ''; 

            if (empty($userIds) || empty($title) || empty($body)) {
                throw new Exception('필수 정보 누락');
            }

            // [수정] 이제 모델이 공통 ID(숫자)를 반환합니다.
            $notificationId = $this->notificationModel->create([
                'userIds'    => $userIds,
                'creator_id' => $creatorId,
                'title'      => $title,
                'body'       => $body
            ]);

            if (!$notificationId) {
                throw new Exception('DB 저장 실패');
            }

            $tokens = $this->tokenModel->getTokensByUserIds($userIds);

            // 토큰 없어도 DB 저장은 성공했으므로 성공 처리하되 메시지로 알림
            if (empty($tokens)) {
                echo json_encode([
                    'status' => 'success',
                    'message' => '알림이 생성되었으나 전송할 토큰이 없습니다.',
                    'result' => [
                        'notification_id'   => $notificationId,
                        'target_user_count' => count($userIds),
                        'success_count'     => 0,
                        'failure_count'     => 0
                    ]
                ]);
                return;
            }

            // Firebase 발송
            $serviceAccountPath = __DIR__ . '/../secrets/soundgram-tot-firebase-adminsdk-awy7u-6246f7929f.json'; 
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
            $messaging = $factory->createMessaging();

            $notification = Notification::create($title, $body);

            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData([
                    'notification_id' => (string) $notificationId, 
                    'click_action'    => 'FLUTTER_NOTIFICATION_CLICK' 
                ]);

                $successCount = 0;
                $failureCount = 0;
    
                foreach ($tokens as $token) {
                    try {

                        $individualMessage = $message->withChangedTarget('token', $token);
                        
                        $messaging->send($individualMessage);
                        $successCount++;
                    } catch (\Exception $e) {
                        // 개별 전송 실패 시 카운트만 하고 계속 진행(로그 추가할까..?)
                        $failureCount++;
                        // error_log("FCM Send Error for token $token: " . $e->getMessage());
                    }
                }
    
                echo json_encode([
                    'status' => 'success',
                    'result' => [
                        'notification_id'   => $notificationId,
                        'target_user_count' => count($userIds),
                        'success_count'     => $successCount,
                        'failure_count'     => $failureCount
                    ]
                ]);
    
            } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * @OA\Get(
     * path="/api/notification/console",
     * summary="[관리자] 보낸 알림 이력 조회",
     * description="관리자가 자신이 발송한 알림 목록을 조회합니다.",
     * tags={"Notification"},
     * security={{"bearerAuth":{}}},
     * @OA\Response(
     * response=200,
     * description="성공",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="success"),
     * @OA\Property(
     * property="data",
     * type="array",
     * @OA\Items(
     * @OA\Property(property="id", type="integer", example=1),
     * @OA\Property(property="creator_id", type="integer", example=5),
     * @OA\Property(property="title", type="string", example="공지사항"),
     * @OA\Property(property="body", type="string", example="내용입니다."),
     * @OA\Property(property="create_dtm", type="string", format="date-time")
     * )
     * )
     * )
     * )
     * )
     */
    public function getByCreatorId() {
        header('Content-Type: application/json');

        try {
            // 관리자 권한 체크 및 ID 획득
            $decoded = $this->auth->requireAdmin();
            $creatorId = $decoded->user_id ?? $decoded->id;

            $list = $this->notificationModel->getByCreatorId($creatorId);

            echo json_encode([
                'status' => 'success',
                'data'   => $list
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * @OA\Get(
     * path="/api/notification/user",
     * summary="[유저] 내 알림함 조회",
     * description="로그인한 유저가 받은 알림 목록과 읽음 여부를 조회합니다.",
     * tags={"Notification"},
     * security={{"bearerAuth":{}}},
     * @OA\Response(
     * response=200,
     * description="성공",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="success"),
     * @OA\Property(
     * property="data",
     * type="array",
     * @OA\Items(
     * @OA\Property(property="notification_id", type="integer", example=10),
     * @OA\Property(property="title", type="string", example="이벤트 당첨 안내"),
     * @OA\Property(property="body", type="string", example="축하합니다!"),
     * @OA\Property(property="create_dtm", type="string", format="date-time"),
     * @OA\Property(property="is_checked", type="integer", description="0:안읽음, 1:읽음", example=0),
     * @OA\Property(property="read_id", type="integer", description="읽음처리용 ID", example=55)
     * )
     * )
     * )
     * )
     * )
     */
    public function getByTargetUserId() {
        header('Content-Type: application/json');

        try { 
            $decoded = $this->auth->decodeToken(); 
            
            $userId = $decoded->user_id ?? $decoded->id;

            $list = $this->notificationModel->getByTargetUserId($userId);

            echo json_encode([
                'status' => 'success',
                'data'   => $list
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
