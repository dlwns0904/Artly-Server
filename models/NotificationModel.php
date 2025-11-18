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
        $stmt = $this->pdo->prepare("
            INSERT INTO APIServer_notification
                (creator_id, title, body, create_dtm)
            VALUES
                (:creator_user_id, :title, :body, NOW())
        ");

        $stmt->execute([
            ':creator_user_id' => $data['creator_user_id'],
            ':title'           => $data['title'],
            ':body'            => $data['body'],
        ]);

        $id = $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_notification WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    }
}