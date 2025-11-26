<?php
namespace Models;

use \PDO;

class ArtModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function getAll($filters = []) {
        $sql = "SELECT * FROM APIServer_art A WHERE 1=1 ";
        $params = [];

        if (!empty($filters['exhibition_id'])) {
            $sql .= "AND EXISTS (
                        SELECT 1
                          FROM APIServer_exhibition_art EA
                         WHERE EA.art_id = A.id
                           AND EA.exhibition_id = :exhibition_id
                     ) ";
            $params[':exhibition_id'] = $filters['exhibition_id'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById($id) {
        $stmt = $this->pdo->prepare("
            SELECT a.*, ar.artist_name
              FROM APIServer_art a
         LEFT JOIN APIServer_artist ar ON a.artist_id = ar.id
             WHERE a.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function getExhibitionIdByArtId($id) {
        $stmt = $this->pdo->prepare("
            SELECT ea.exhibition_id
              FROM APIServer_exhibition_art ea
             WHERE ea.art_id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll();
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO APIServer_art
                (art_image, artist_id, art_title, art_description, art_docent,
                 art_material, art_size, art_year, gallery_phone,
                 create_dtm, update_dtm)
            VALUES
                (:image, :artist_id, :title, :description, :docent,
                 :material, :size, :year, :gallery_phone,
                 NOW(), NOW())
        ");

        $stmt->execute([
            ':image'       => $data['art_image']        ?? null,   // 상대경로 or null
            ':artist_id'   => $data['artist_id']        ?? null,
            ':title'       => $data['art_title']        ?? null,
            ':description' => $data['art_description']  ?? null,
            ':docent'      => $data['art_docent']       ?? null,
            ':material'    => $data['art_material']     ?? null,
            ':size'        => $data['art_size']         ?? null,
            ':year'        => $data['art_year']         ?? null,
            ':gallery_phone' => $data['gallery_phone']   ?? null,
        ]);

        $id = $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_art WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * 부분 업데이트 지원: 제공된 키만 SET
     * art_image는 데이터에 있을 때만 변경(없으면 유지)
     */
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        $mapping = [
            'art_image'      => 'image',
            'artist_id'      => 'artist_id',
            'art_title'      => 'title',
            'art_description'=> 'description',
            'art_docent'     => 'docent',
            'art_material'   => 'material',
            'art_size'       => 'size',
            'art_year'       => 'year',
            'gallery_phone'  => 'gallery_phone',
        ];

        foreach ($mapping as $col => $param) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = :$param";
                $params[":$param"] = $data[$col];
            }
        }

        if (empty($fields)) {
            // 아무 것도 바꿀 게 없음
            return true;
        }

        $sql = "UPDATE APIServer_art SET " . implode(', ', $fields) . ", update_dtm = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_art WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
