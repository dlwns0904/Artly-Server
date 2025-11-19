<?php
namespace Models;

use \PDO;

class NotificationModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function create($data) {
        if (empty($data['userIds']) || !is_array($data['userIds'])) {
            return null;
        }

        try {
            $this->pdo->beginTransaction();

            // 1. 마스터 테이블(APIServer_notification)에 알림 내용 저장
            $stmtMaster = $this->pdo->prepare("
                INSERT INTO APIServer_notification 
                    (creator_id, title, body, create_dtm)
                VALUES 
                    (:creator_id, :title, :body, NOW())
            ");
            
            $stmtMaster->execute([
                ':creator_id' => $data['creator_id'],
                ':title'      => $data['title'],
                ':body'       => $data['body']
            ]);

            // 생성된 공통 Notification ID 획득
            $notificationId = $this->pdo->lastInsertId();

            // 2. 수신여부 테이블(APIServer_notification_read)에 유저별로 저장
            $stmtRead = $this->pdo->prepare("
                INSERT INTO APIServer_notification_read
                    (notification_id, target_user_id, is_checked)
                VALUES 
                    (:noti_id, :target_id, 0)
            ");

            foreach ($data['userIds'] as $userId) {
                $stmtRead->execute([
                    ':noti_id'   => $notificationId,
                    ':target_id' => $userId
                ]);
            }

            $this->pdo->commit();
            
            return $notificationId;

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getByCreatorId($creatorId) {
        $sql = "SELECT * FROM APIServer_notification 
                WHERE creator_id = :creator_id 
                ORDER BY create_dtm DESC"; // 최신순 정렬

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':creator_id' => $creatorId]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function getByTargetUserId($targetUserId) {
        // 최신 알림을 먼저보도록 내림차순정렬함
        $sql = "SELECT 
                    N.id AS notification_id,
                    N.title,
                    N.body,
                    N.create_dtm,
                    R.is_checked,
                    R.id AS read_id
                FROM APIServer_notification_read R
                JOIN APIServer_notification N ON R.notification_id = N.id
                WHERE R.target_user_id = :user_id
                ORDER BY N.create_dtm DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $targetUserId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}