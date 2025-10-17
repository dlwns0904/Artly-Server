<?php
namespace Models;

use \PDO;


class LikeModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function create($userId, $data) {
        $likedId = $data['liked_id'];
        $likedType = $data['liked_type'];

        $allowedTables = [
            'gallery' => 'APIServer_gallery_like',
            'exhibition' => 'APIServer_exhibition_like',
            'artist' => 'APIServer_artist_like',
            'art' => 'APIServer_art_like'
        ];
        $allowedColumn = [
            'gallery' => 'gallery_id',
            'exhibition' => 'exhibition_id',
            'artist' => 'artist_id',
            'art' => 'art_id'
        ];
        $table = $allowedTables[$likedType];
        $column = $allowedColumn[$likedType];
        
        $stmt = $this->pdo->prepare("INSERT INTO `{$table}`
            (user_id, `{$column}`, create_dtm, update_dtm)
            VALUES (:user_id, :liked_id, NOW(), NOW())");

        return $stmt->execute([
            ':user_id' => $userId,
            ':liked_id' => $likedId
        ]);
    }

    public function delete($userId, $data) {
        $likedId = $data['liked_id'];
        $likedType = $data['liked_type'];

        $allowedTables = [
            'gallery' => 'APIServer_gallery_like',
            'exhibition' => 'APIServer_exhibition_like',
            'artist' => 'APIServer_artist_like',
            'art' => 'APIServer_art_like'
        ];
        $allowedColumn = [
            'gallery' => 'gallery_id',
            'exhibition' => 'exhibition_id',
            'artist' => 'artist_id',
            'art' => 'art_id'
        ];
        $table = $allowedTables[$likedType];
        $column = $allowedColumn[$likedType];

        $stmt = $this->pdo->prepare(
            "DELETE FROM `{$table}`
             WHERE user_id = :user_id and `{$column}` = :liked_id");
             
        $stmt->execute([
            ':user_id' => $userId,
            ':liked_id' => $data['liked_id'],
        ]);

        return $stmt->rowCount() > 0; // 삭제된 행이 있어야 true
    }

    public function targetExists($data) {
        $likedId = $data['liked_id'];
        $likedType = $data['liked_type'];

        $allowedTables = [
            'gallery' => 'APIServer_gallery',
            'exhibition' => 'APIServer_exhibition',
            'artist' => 'APIServer_artist',
            'art' => 'APIServer_art'
        ];

        if (!array_key_exists($likedType, $allowedTables)) {
            return false;
        }

        $table = $allowedTables[$likedType];
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id = :id");
             
        $stmt->execute([':id' => $likedId]);
        return $stmt->fetchColumn() > 0;
    }

    public function getAll($likedType = null) {
        $allowedTables = [
            'gallery' => 'APIServer_gallery_like',
            'exhibition' => 'APIServer_exhibition_like',
            'artist' => 'APIServer_artist_like',
            'art' => 'APIServer_art_like'
        ];
        $allowedColumn = [
            'gallery' => 'gallery_id',
            'exhibition' => 'exhibition_id',
            'artist' => 'artist_id',
            'art' => 'art_id'
        ];
    
        if (is_null($likedType) || !array_key_exists($likedType, $allowedTables)) {
            return []; // 요청대로 아무것도 반환하지 않습니다.
        }
    
        $tableName = $allowedTables[$likedType];

        $sql = "SELECT * FROM {$tableName}";
    
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
