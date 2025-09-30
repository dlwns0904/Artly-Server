<?php
namespace Models;

use \PDO;

class ArtistModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function fetchArtists($filters = []) {
    $user_id = $filters['user_id'] ?? 0;
    $likedOnly = !empty($filters['liked_only']) && filter_var($filters['liked_only'], FILTER_VALIDATE_BOOLEAN);
    $search = $filters['search'] ?? null;
    $category = $filters['category'] ?? 'all';
    $nation = $filters['nation'] ?? null;
    $decade = $filters['decade'] ?? null;

    $sql = "
        SELECT
            a.id,
            a.artist_name AS name,
            a.artist_category AS field,
            a.artist_nation AS nation,
            a.artist_image AS imageUrl,
            IFNULL(lc.like_count, 0) AS like_count,
            IF(EXISTS (
                SELECT 1 FROM APIServer_artist_like l
                WHERE l.artist_id = a.id AND l.user_id = :user_id_for_like
            ), 1, 0) AS is_liked,
            IF(EXISTS (
                SELECT 1 FROM APIServer_exhibition_participation ep
                JOIN APIServer_exhibition e ON ep.exhibition_id = e.id
                WHERE ep.artist_id = a.id AND CURDATE() BETWEEN e.exhibition_start_date AND e.exhibition_end_date
            ), 1, 0) AS is_on_exhibition
        FROM APIServer_artist a
        LEFT JOIN (
            SELECT artist_id, COUNT(*) as like_count
            FROM APIServer_artist_like
            GROUP BY artist_id
        ) lc ON a.id = lc.artist_id
        WHERE 1=1
    ";

    $params = [':user_id_for_like' => $user_id];

    // liked_only 필터
    if ($likedOnly && $user_id) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM APIServer_artist_like l
            WHERE l.artist_id = a.id AND l.user_id = :user_id_only
        )";
        $params[':user_id_only'] = $user_id;
    }

    //  category
    if ($category === 'onExhibition') {
        $sql .= " AND EXISTS (
            SELECT 1 FROM APIServer_exhibition_participation ep
            JOIN APIServer_exhibition e ON ep.exhibition_id = e.id
            WHERE ep.artist_id = a.id 
              AND CURDATE() BETWEEN e.exhibition_start_date AND e.exhibition_end_date
        )";
    }

    //  검색어 필터
    if (!empty($search)) {
        $sql .= " AND a.artist_name LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }



    // nation 필터
    if (!empty($nation) && $nation !== '전체') {
        $sql .= " AND a.artist_nation = :nation";
        $params[':nation'] = $nation;
    }

    // decade 필터
    if (!empty($decade) && $decade !== '전체') {
        $sql .= " AND a.artist_decade = :decade";
        $params[':decade'] = $decade;
    }


    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


    public function getById($id, $user_id = null) {
    // ① 기본 아티스트 정보
    $sql = "
        SELECT
            a.id,
            a.artist_name AS name,
            a.artist_category AS field,
            a.artist_nation AS nation,
            a.artist_image AS imageUrl,
            a.artist_description AS description,
            IFNULL(lc.like_count, 0) AS like_count,
            IF(EXISTS (
                SELECT 1 FROM APIServer_artist_like l
                WHERE l.artist_id = a.id AND l.user_id = :user_id_for_like
            ), 1, 0) AS is_liked,
            IF(EXISTS (
                SELECT 1 FROM APIServer_exhibition_participation ep
                JOIN APIServer_exhibition e ON ep.exhibition_id = e.id
                WHERE ep.artist_id = a.id AND CURDATE() BETWEEN e.exhibition_start_date AND e.exhibition_end_date
            ), 1, 0) AS is_on_exhibition
        FROM APIServer_artist a
        LEFT JOIN (
            SELECT artist_id, COUNT(*) as like_count
            FROM APIServer_artist_like
            GROUP BY artist_id
        ) lc ON a.id = lc.artist_id
        WHERE a.id = :id
    ";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':user_id_for_like' => $user_id ?? 0
    ]);
    $artist = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$artist) return null;

    // ② 관련 전시 조회
    $stmt = $this->pdo->prepare("
        SELECT e.id, e.exhibition_title, e.exhibition_poster, e.exhibition_start_date, e.exhibition_end_date
        FROM APIServer_exhibition_participation ep
        JOIN APIServer_exhibition e ON ep.exhibition_id = e.id
        WHERE ep.artist_id = :artist_id
        ORDER BY e.exhibition_start_date DESC
    ");
    $stmt->execute([':artist_id' => $id]);
    $exhibitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ③ 관련 작품 조회
    $stmt = $this->pdo->prepare("
        SELECT ar.id, ar.art_title AS title, ar.art_image AS imageUrl
        FROM APIServer_art ar
        WHERE ar.artist_id = :artist_id
        ORDER BY ar.id DESC
    ");
    $stmt->execute([':artist_id' => $id]);
    $artworks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ④ 통합 결과 반환
    return [
        'id' => (int)$artist['id'],
        'name' => $artist['name'],
        'field' => $artist['field'],
        'nation' => $artist['nation'],
        'imageUrl' => $artist['imageUrl'],
        'description' => $artist['description'],
        'like_count' => (int)$artist['like_count'],
        'is_liked' => (bool)$artist['is_liked'],
        'is_on_exhibition' => (bool)$artist['is_on_exhibition'],
        'exhibitions' => $exhibitions,
        'artworks' => $artworks
    ];
}

    public function create($data) {
        $sql = "
            INSERT INTO APIServer_artist (
                artist_name, artist_category, artist_image,
                artist_nation, artist_description
            ) VALUES (
                :name, :category, :image, :nation, :description
            )
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['artist_name'],
            ':category' => $data['artist_category'],
            ':image' => $data['artist_image'],
            ':nation' => $data['artist_nation'],
            ':description' => $data['artist_description']
        ]);
        $id = $this->pdo->lastInsertId();
        return $this->getById($id);
    }

    public function update($id, $data) {
        $sql = "
            UPDATE APIServer_artist SET
                artist_name = :name,
                artist_category = :category,
                artist_image = :image,
                artist_nation = :nation,
                artist_description = :description
            WHERE id = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':name' => $data['artist_name'],
            ':category' => $data['artist_category'],
            ':image' => $data['artist_image'],
            ':nation' => $data['artist_nation'],
            ':description' => $data['artist_description']
        ]);
        return $this->getById($id);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_artist WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function getArtistsBySearch($filters = []) {
        $search = $filters['search'];
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_artist WHERE artist_name LIKE :search");
        $stmt->execute([':search' => "%$search%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

