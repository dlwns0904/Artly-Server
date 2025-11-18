<?php
namespace Models;

use \PDO;

class UserFcmTokenModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // 토큰 등록 또는 갱신
    public function register($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO APIServer_user_fcm_token 
                (user_id, fcm_token, update_dtm)
            VALUES 
                (:user_id, :fcm_token, NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                update_dtm = NOW()
        ");

        $stmt->execute([
            ':user_id'   => $data['user_id']   ?? null,
            ':fcm_token' => $data['fcm_token'] ?? null,
        ]);

        // 토큰은 UNIQUE 키이므로, 방금 작업한 토큰 기준으로 조회해서 반환
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_user_fcm_token WHERE fcm_token = :token");
        $stmt->execute([':token' => $data['fcm_token'] ?? null]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 알림 발송용: 여러 유저 ID로 토큰 리스트 조회
    public function getTokensByUserIds(array $userIds) {
        if (empty($userIds)) {
            return [];
        }

        // IN 절을 위한 플레이스홀더 생성 (?,?,?)
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $stmt = $this->pdo->prepare("
            SELECT fcm_token 
            FROM APIServer_user_fcm_token 
            WHERE user_id IN ($placeholders)
        ");
        
        $stmt->execute($userIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}