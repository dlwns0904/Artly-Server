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

    /* ----------------------------------------------------------
       아티스트 목록 조회 (필터/검색 포함)
    ---------------------------------------------------------- */
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
                a.artist_name,
                a.artist_category,
                a.artist_nation,
                a.artist_image,
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

        // 좋아요한 아티스트만
        if ($likedOnly && $user_id) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM APIServer_artist_like l
                WHERE l.artist_id = a.id AND l.user_id = :user_id_only
            )";
            $params[':user_id_only'] = $user_id;
        }

        // 전시 중 아티스트만
        if ($category === 'onExhibition') {
            $sql .= " AND EXISTS (
                SELECT 1 FROM APIServer_exhibition_participation ep
                JOIN APIServer_exhibition e ON ep.exhibition_id = e.id
                WHERE ep.artist_id = a.id 
                  AND CURDATE() BETWEEN e.exhibition_start_date AND e.exhibition_end_date
            )";
        }

        // 이름 검색
        if (!empty($search)) {
            $sql .= " AND a.artist_name LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }

        // 국적 필터
        if (!empty($nation) && $nation !== '전체') {
            $sql .= " AND a.artist_nation = :nation";
            $params[':nation'] = $nation;
        }

        // 시대 필터
        if (!empty($decade) && $decade !== '전체') {
            $sql .= " AND a.artist_decade = :decade";
            $params[':decade'] = $decade;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ----------------------------------------------------------
       단일 아티스트 상세 조회
    ---------------------------------------------------------- */
    public function getById($id, $user_id = null) {
        $sql = "
            SELECT
                a.id,
                a.artist_name,
                a.artist_category,
                a.artist_nation,
                a.artist_image,
                a.artist_description,
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

        // 관련 전시
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.exhibition_title, e.exhibition_poster, e.exhibition_start_date, e.exhibition_end_date
            FROM APIServer_exhibition_participation ep
            JOIN APIServer_exhibition e ON ep.exhibition_id = e.id
            WHERE ep.artist_id = :artist_id
            ORDER BY e.exhibition_start_date DESC
        ");
        $stmt->execute([':artist_id' => $id]);
        $exhibitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 관련 작품
        $stmt = $this->pdo->prepare("
            SELECT ar.id, ar.art_title, ar.art_image
            FROM APIServer_art ar
            WHERE ar.artist_id = :artist_id
            ORDER BY ar.id DESC
        ");
        $stmt->execute([':artist_id' => $id]);
        $artworks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 통합 반환
        return [
            'id' => (int)$artist['id'],
            'artist_name' => $artist['artist_name'],
            'artist_category' => $artist['artist_category'],
            'artist_nation' => $artist['artist_nation'],
            'artist_image' => $artist['artist_image'],
            'artist_description' => $artist['artist_description'],
            'like_count' => (int)$artist['like_count'],
            'is_liked' => (bool)$artist['is_liked'],
            'is_on_exhibition' => (bool)$artist['is_on_exhibition'],
            'exhibitions' => $exhibitions,
            'artworks' => $artworks
        ];
    }

    /* ----------------------------------------------------------
       생성 / 수정 / 삭제
    ---------------------------------------------------------- */
    public function create($data) {
        $sql = "
            INSERT INTO APIServer_artist (
                artist_name, artist_category, artist_image,
                artist_nation, artist_description
            ) VALUES (
                :artist_name, :artist_category, :artist_image,
                :artist_nation, :artist_description
            )
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':artist_name' => $data['artist_name'],
            ':artist_category' => $data['artist_category'],
            ':artist_image' => $data['artist_image'],
            ':artist_nation' => $data['artist_nation'],
            ':artist_description' => $data['artist_description']
        ]);
        $id = $this->pdo->lastInsertId();
        return $this->getById($id);
    }

    public function update($id, $data) {
        $sql = "
            UPDATE APIServer_artist SET
                artist_name = :artist_name,
                artist_category = :artist_category,
                artist_image = :artist_image,
                artist_nation = :artist_nation,
                artist_description = :artist_description
            WHERE id = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':artist_name' => $data['artist_name'],
            ':artist_category' => $data['artist_category'],
            ':artist_image' => $data['artist_image'],
            ':artist_nation' => $data['artist_nation'],
            ':artist_description' => $data['artist_description']
        ]);
        return $this->getById($id);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_artist WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /* ----------------------------------------------------------
       이름 검색
    ---------------------------------------------------------- */
    public function getArtistsBySearch($filters = []) {
        $search = $filters['search'];
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_artist WHERE artist_name LIKE :search");
        $stmt->execute([':search' => "%$search%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
