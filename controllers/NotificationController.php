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
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
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
     * summary="관리자 알림 발송",
     * description="관리자가 특정 유저 목록(userIds)에게 푸시 알림을 전송하고, 발송 이력을 DB에 저장합니다.",
     * tags={"Notification"},
     * @OA\RequestBody(
     * required=true,
     * description="알림 발송 정보",
     * @OA\JsonContent(
     * required={"userIds", "title", "message"},
     * @OA\Property(property="creator_id", type="integer", description="작성자(관리자) ID", example=1),
     * @OA\Property(
     * property="userIds",
     * type="array",
     * description="알림을 수신할 유저 ID 목록",
     * @OA\Items(type="integer", example=101)
     * ),
     * @OA\Property(property="title", type="string", description="알림 제목", example="긴급 공지"),
     * @OA\Property(property="message", type="string", description="알림 본문 내용", example="금일 밤 12시 서버 점검이 있습니다.")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="발송 성공",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="success"),
     * @OA\Property(
     * property="result",
     * type="object",
     * description="발송 결과 요약",
     * @OA\Property(property="notification_id", type="integer", description="생성된 알림 로그 ID", example=55),
     * @OA\Property(property="success_count", type="integer", description="전송 성공한 기기 수", example=10),
     * @OA\Property(property="failure_count", type="integer", description="전송 실패한 기기 수", example=0)
     * )
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="필수 파라미터 누락",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="error"),
     * @OA\Property(property="message", type="string", example="수신자(userIds), 제목(title), 본문(message)은 필수입니다.")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="서버 내부 오류",
     * @OA\JsonContent(
     * @OA\Property(property="status", type="string", example="error"),
     * @OA\Property(property="message", type="string", example="Server Error Message...")
     * )
     * )
     * )
     */
    public function sendNotification() {
        header('Content-Type: application/json');

        try {
            // Request Body 받기
            $input = json_decode(file_get_contents('php://input'), true);

            $creatorId = $input['creator_id'] ?? 1; 
            $userIds   = $input['userIds']    ?? [];
            $title     = $input['title']      ?? '';
            $body      = $input['message']    ?? ''; 

            if (empty($userIds) || empty($title) || empty($body)) {
                throw new Exception('수신자(userIds), 제목(title), 본문(message)은 필수입니다.');
            }

            $savedNoti = $this->notificationModel->create([
                'creator_id' => $creatorId,
                'title'      => $title,
                'body'       => $body
            ]);

            $tokens = $this->tokenModel->getTokensByUserIds($userIds);

            if (empty($tokens)) {
                echo json_encode([
                    'status' => 'success',
                    'message' => '알림은 저장되었으나, 발송 가능한 디바이스 토큰이 없습니다.',
                    'saved_data' => $savedNoti
                ]);
                return;
            }

            // Firebase 발송 
            
            // 서비스 계정 키 파일 경로
            $serviceAccountPath = __DIR__ . '/../secrets/soundgram-tot-firebase-adminsdk-awy7u-6246f7929f.json'; 
            
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
            $messaging = $factory->createMessaging();

            // 알림 객체 생성
            $notification = Notification::create($title, $body);

            // 메시지 패키징
            $message = CloudMessage::new()
                ->withNotification($notification) // 앱 알림 (Notification)
                ->withData([                      // 추가 데이터 (선택)
                    'notification_id' => $savedNoti['id'],
                    'click_action'    => 'FLUTTER_NOTIFICATION_CLICK' 
                ]);

            // Multicast 전송
            $report = $messaging->sendMulticast($message, $tokens);

            echo json_encode([
                'status' => 'success',
                'result' => [
                    'notification_id' => $savedNoti['id'],
                    'success_count'   => $report->successCount(),
                    'failure_count'   => $report->failureCount()
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
}
