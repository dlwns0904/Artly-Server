<?php
namespace Models;

use PDO;

class LeafletModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_leaflet WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByCreateUserId($createUserId) {
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_leaflet WHERE create_user_id = ?");
        $stmt->execute([$createUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO APIServer_leaflet 
            (create_user_id, title, image_urls, category)
            VALUES (:user_id, :title, :image_urls, :category)
        ");
        $stmt->execute([
            ':user_id'    => $data['user_id'],
            ':title'      => $data['title'],
            ':image_urls' => $data['image_urls'],
            ':category'   => $data['category'],
        ]);

        $id = $this->pdo->lastInsertId();

        return $this->getById($id);
    }

    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        // 데이터가 넘어왔는지 하나씩 확인해서 쿼리 조각 만들기
        if (isset($data['title'])) {
            $fields[] = "title = :title";
            $params[':title'] = $data['title'];
        }

        if (isset($data['image_urls'])) {
            $fields[] = "image_urls = :image_urls";
            $params[':image_urls'] = $data['image_urls'];
        }

        if (isset($data['category'])) {
            $fields[] = "category = :category";
            $params[':category'] = $data['category'];
        }

        // 업데이트할 내용이 하나도 없으면 바로 리턴
        if (empty($fields)) {
            return $this->getById($id);
        }

        $fields[] = "update_dtm = NOW()";

        $sql = "UPDATE APIServer_leaflet SET " . implode(', ', $fields) . " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->getById($id);
    }
}