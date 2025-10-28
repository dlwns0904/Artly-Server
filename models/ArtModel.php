<?php
namespace Models;

use \PDO;


class ArtModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getAll($filters = []) {
        $sql = "SELECT *
                FROM APIServer_art A
                WHERE 1=1 ";

        $params = [];

        if (!empty($filter['exhibition_id'])) {
            $sql .= "AND A.exhibition_id = :exhibition_id ";
            $params[':exhibition_id'] = $filters['exhibition_id'];
        }

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function getById($id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                a.*, 
                ar.artist_name 
            FROM 
                APIServer_art a
            LEFT JOIN 
                APIServer_artist ar 
            ON 
                a.artist_id = ar.id
            WHERE 
                a.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public function getExhibitionIdByArtId($id) {
        $stmt = $this->pdo->prepare("
            SELECT
                ea.exhibition_id
            FROM
                APIServer_exhibition_art ea
            WHERE
                ea.art_id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function create($data) {
        $stmt = $this->pdo->prepare("INSERT INTO APIServer_art 
            (art_image, artist_id, art_title, art_description, art_docent, create_dttm, update_dttm)
            VALUES (:image, :artist_id, :title, :description, :docent, NOW(), NOW())");

        $stmt->execute([
            ':image' => $data['art_image'],
            ':artist_id' => $data['artist_id'],
            ':title' => $data['art_title'],
            ':description' => $data['art_description'],
            ':docent' => $data['art_docent']
        ]);
        
        // 생성된 데이터의 ID 가져오기
        $id = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_art WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $data) {
        $stmt = $this->pdo->prepare("UPDATE APIServer_art SET
            art_image = :image,
            artist_id = :artist_id,
            art_title = :title,
            art_description = :description,
            art_docent = :docent,
            update_dttm = NOW()
            WHERE id = :id");

        return $stmt->execute([
            ':image' => $data['art_image'],
            ':artist_id' => $data['artist_id'],
            ':title' => $data['art_title'],
            ':description' => $data['art_description'],
            ':docent' => $data['art_docent'],
            ':id' => $id
        ]);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_art WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
}

