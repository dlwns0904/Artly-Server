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

        // (옵션) 특정 전시에 속한 작품만 조회
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
              ON a.artist_id = ar.id
            WHERE 
                a.id = :id
        ");
        $stmt->execute([':id' => $id]);
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
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO APIServer_art 
                (art_image, artist_id, art_title, art_description, art_docent,
                 art_material, art_size, art_year,
                 create_dtm, update_dtm)
            VALUES 
                (:image, :artist_id, :title, :description, :docent,
                 :material, :size, :year,
                 NOW(), NOW())
        ");

        $stmt->execute([
            ':image'     => $data['art_image']       ?? null,
            ':artist_id' => $data['artist_id']       ?? null,
            ':title'     => $data['art_title']       ?? null,
            ':description'=> $data['art_description']?? null,
            ':docent'    => $data['art_docent']      ?? null,
            ':material'  => $data['art_material']    ?? null,   
            ':size'      => $data['art_size']        ?? null,   
            ':year'      => $data['art_year']        ?? null,   
        ]);

        $id = $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_art WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $data) {
        $setParts = [];
        $params = [':id' => $id];

        $fieldMap = [
            'art_image'       => ':image',
            'artist_id'       => ':artist_id',
            'art_title'       => ':title',
            'art_description' => ':description',
            'art_docent'      => ':docent',
            'art_material'    => ':material',
            'art_size'        => ':size',
            'art_year'        => ':year',
        ];

        foreach ($fieldMap as $column => $placeholder) {
            // "array_key_exists"를 사용하여 키 존재 여부 확인
            if (array_key_exists($column, $data)) {
                $setParts[] = "$column = $placeholder";
                $params[$placeholder] = $data[$column];
            }
        }

        if (empty($setParts)) {
            return true; // 변경된 내용이 없지만 성공으로 간주
        }

        $setParts[] = "update_dtm = NOW()";
        $sql = "UPDATE APIServer_art SET " . implode(', ', $setParts) . " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_art WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
