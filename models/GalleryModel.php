<?php
namespace Models;

use \PDO;

class GalleryModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function create($data) {
        $sql = "INSERT INTO APIServer_gallery (
            gallery_name, gallery_image, gallery_address, gallery_start_time,
            gallery_end_time, gallery_closed_day, gallery_category, gallery_description, gallery_latitude, gallery_longitude, user_id
        ) VALUES (
            :name, :image, :address, :start_time,
            :end_time, :closed_day, :category, :description, :latitude, :longitude
        )";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['gallery_name'],
            ':image' => $data['gallery_image'],
            ':address' => $data['gallery_address'],
            ':start_time' => $data['gallery_start_time'],
            ':end_time' => $data['gallery_end_time'],
            ':closed_day' => $data['gallery_closed_day'],
            ':category' => $data['gallery_category'],
            ':description' => $data['gallery_description'],
            ':latitude' => $data['gallery_latitude'] ?? null,
            ':longitude' => $data['gallery_longitude'] ?? null,
	    ':user_id'     => $data['user_id'] ?? null, // 임시로 NULL 허용
        ]);

        $id = $this->pdo->lastInsertId();
        return $this->getById($id);
    }

    public function update($id, $data) {
        $sql = "UPDATE APIServer_gallery SET
            gallery_name = :name,
            gallery_image = :image,
            gallery_address = :address,
            gallery_start_time = :start_time,
            gallery_end_time = :end_time,
            gallery_closed_day = :closed_day,
            gallery_category = :category,
            gallery_description = :description
        WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':name' => $data['gallery_name'],
            ':image' => $data['gallery_image'],
            ':address' => $data['gallery_address'],
            ':start_time' => $data['gallery_start_time'],
            ':end_time' => $data['gallery_end_time'],
            ':closed_day' => $data['gallery_closed_day'],
            ':category' => $data['gallery_category'],
            ':description' => $data['gallery_description']
        ]);

        return $this->getById($id);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_gallery WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function getGalleries($filters = []) {
        $sql = "
            SELECT
                g.id AS gallery_id,
                g.gallery_name,
                g.gallery_image,
                g.gallery_latitude,
                g.gallery_longitude,
                g.gallery_address,
                g.gallery_category,
		g.gallery_start_time,
		g.gallery_end_time,
                IFNULL(lc.like_count, 0) AS like_count,
                IF(EXISTS (
                    SELECT 1 FROM APIServer_gallery_like l
                    WHERE l.gallery_id = g.id AND l.user_id = :user_id_for_like
                ), 1, 0) AS is_liked
            FROM APIServer_gallery g
            LEFT JOIN (
                SELECT gallery_id, COUNT(*) AS like_count
                FROM APIServer_gallery_like
                GROUP BY gallery_id
            ) lc ON g.id = lc.gallery_id
            WHERE 1=1
        ";

        $user_id = $filters['user_id'] ?? 0;
        $params = [':user_id_for_like' => $user_id];

        // liked_only 처리
        $likedOnly = !empty($filters['liked_only']) && filter_var($filters['liked_only'], FILTER_VALIDATE_BOOLEAN);
        if ($likedOnly && $user_id > 0) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM APIServer_gallery_like l
                WHERE l.gallery_id = g.id AND l.user_id = :user_id_only
            )";
            $params[':user_id_only'] = $user_id;
        }

        // 지역 필터
        if (!empty($filters['regions'])) {
            $regionList = explode(',', $filters['regions']);
            $regionConditions = [];
            foreach ($regionList as $index => $region) {
                $key = ":region$index";
                $regionConditions[] = "g.gallery_address LIKE $key";
                $params[$key] = '%' . trim($region) . '%';
            }
            $sql .= " AND (" . implode(" OR ", $regionConditions) . ")";
        }

        // 타입 필터
        if (!empty($filters['type'])) {
            $sql .= " AND g.gallery_category = :type";
            $params[':type'] = $filters['type'];
        }

        // 검색어 필터
        if (!empty($filters['search'])) {
            $sql .= " AND g.gallery_name LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // 거리 필터
        if (!empty($filters['latitude']) && !empty($filters['longitude']) && !empty($filters['distance'])) {
            $sql .= " AND (
                6371000 * ACOS(
                    COS(RADIANS(:latitude)) * COS(RADIANS(g.gallery_latitude)) *
                    COS(RADIANS(g.gallery_longitude) - RADIANS(:longitude)) +
                    SIN(RADIANS(:latitude)) * SIN(RADIANS(g.gallery_latitude))
                )
            ) <= :distance";
            $params[':latitude'] = $filters['latitude'];
            $params[':longitude'] = $filters['longitude'];
            $params[':distance'] = $filters['distance'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 결과 가공
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => (int)$row['gallery_id'],
                'gallery_name' => $row['gallery_name'],
                'gallery_image' => $row['gallery_image'],
                'gallery_latitude' => $row['gallery_latitude'],
                'gallery_longitude' => $row['gallery_longitude'],
                'gallery_address' => $row['gallery_address'],
                'gallery_category' => $row['gallery_category'],
                'like_count' => (int)$row['like_count'],
		'gallery_start_time' => $row['gallery_start_time'],
		'gallery_end_tiem' => $row['gallery_end_time'],
                'is_liked' => (bool)$row['is_liked'],
            ];
        }

        return $results;
    }

   public function getById($id, $user_id = null) {
    $sql = "
        SELECT
            g.id AS gallery_id,
            g.gallery_name,
            g.gallery_image,
            g.gallery_address,
            g.gallery_start_time,
            g.gallery_end_time,
            g.gallery_closed_day,
            g.gallery_category,
            g.gallery_description,
            g.gallery_latitude,
            g.gallery_longitude,
            IFNULL(lc.like_count, 0) AS like_count,
            IF(EXISTS (
                SELECT 1 FROM APIServer_gallery_like l
                WHERE l.gallery_id = g.id AND l.user_id = :user_id_for_like
            ), 1, 0) AS is_liked,
            e.id AS exhibition_id,
            e.exhibition_title,
            e.exhibition_poster,
            e.exhibition_status
        FROM APIServer_gallery g
        LEFT JOIN (
            SELECT gallery_id, COUNT(*) AS like_count
            FROM APIServer_gallery_like
            GROUP BY gallery_id
        ) lc ON g.id = lc.gallery_id
        LEFT JOIN APIServer_exhibition e
            ON g.id = e.gallery_id AND e.exhibition_status = 'exhibited'
        WHERE g.id = :id
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':user_id_for_like' => $user_id ?? 0
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) return null;

    $firstRow = $rows[0];

    $gallery = [
        'id' => (int)$firstRow['gallery_id'],
        'gallery_name' => $firstRow['gallery_name'],
        'gallery_image' => $firstRow['gallery_image'],
        'gallery_address' => $firstRow['gallery_address'],
        'gallery_start_time' => $firstRow['gallery_start_time'],
        'gallery_end_time' => $firstRow['gallery_end_time'],
        'gallery_closed_day' => $firstRow['gallery_closed_day'],
        'gallery_category' => $firstRow['gallery_category'],
        'gallery_description' => $firstRow['gallery_description'],
        'gallery_latitude' => $firstRow['gallery_latitude'],
        'gallery_longitude' => $firstRow['gallery_longitude'],
        'like_count' => (int)$firstRow['like_count'],
        'is_liked' => (bool)$firstRow['is_liked'],
        'exhibitions' => []
    ];

    foreach ($rows as $row) {
        if (!empty($row['exhibition_id'])) {
            $gallery['exhibitions'][] = [
                'id' => (int)$row['exhibition_id'],
                'title' => $row['exhibition_title'],
                'poster' => $row['exhibition_poster'],
                'status' => $row['exhibition_status']
            ];
        }
    }

    return $gallery;
}


   public function getGalleriesBySearch($filters = []) {
    $search = $filters['search'];
    $stmt = $this->pdo->prepare("SELECT * FROM APIServer_gallery WHERE gallery_name LIKE :search");
    $stmt->execute([':search' => "%$search%"]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

