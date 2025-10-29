<?php
namespace Models;

use \PDO;


class ChatModel {
    private $pdo;

    public function __construct() {
        // 1) config.php (폴백용)
        $fileConfig = [];
        $confPath = __DIR__ . '/../config/config.php';
        if (is_file($confPath)) {
            $tmp = require $confPath; // ['dsn'=>'...', 'user'=>'...', 'password'=>'...']
            if (is_array($tmp)) $fileConfig = $tmp;
        }

        // 2) .env 우선 → config.php 폴백
        $dsn      = $_ENV['DSN']        ?? $fileConfig['dsn']      ?? null;
        $user     = $_ENV['DB_USER']    ?? $fileConfig['user']     ?? null;
        $password = $_ENV['DB_PASSWORD']?? $fileConfig['password'] ?? null;

        if (!$dsn || $user === null || $password === null) {
            throw new \RuntimeException("DB 설정 누락: .env(DSN/DB_USER/DB_PASSWORD) 또는 config.php(dsn/user/password)를 확인하세요.");
        }

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException("PDO 연결 실패: ".$e->getMessage(), 0, $e);
        }
      }


            # 채팅 목록
    // ChatModel.php
    public function getConversations($userId) {
    $stmt = $this->pdo->prepare("SELECT * FROM APIServer_conversation WHERE user_id = :user_id ORDER BY id ASC"); // ✅ 정렬
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    # 채팅 생성
    public function addConversations($userId, $role, $content) {
        $stmt = $this->pdo->prepare("INSERT INTO APIServer_conversation
            (user_id, role, content, create_dtm, update_dtm)
            VALUES (:user_id, :role, :content, NOW(), NOW())");

        $stmt->execute([
            ':user_id' => $userId,
            ':role' => $role,
            ':content' => $content
        ]);

        // 생성된 데이터의 ID 가져오기
        $id = $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_conversation WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getExhibitions($filters = []) {
        $sql = "SELECT * FROM APIServer_exhibition WHERE 1=1";
        $params = [];

        if (!empty($filters['title'])) {
            $keywords = preg_split('/\s+/', trim($filters['title']));
            $titleParts = [];
            foreach ($keywords as $index => $word) {
                $placeholder = ":title_keyword_$index";
                $titleParts[] = "exhibition_title LIKE $placeholder";
                $params[$placeholder] = '%' . $word . '%';
            }
            if (!empty($titleParts)) {
                $sql .= " AND (" . implode(" OR ", $titleParts) . ")";
            }
        }
        // location
        if (!empty($filters['location'])) {
            $sql .= " AND exhibition_location LIKE :location";
            $params[':location'] = '%' . $filters['location'] . '%';
        }
        // price
        if (!empty($filters['price'])) {
            $sql .= " AND exhibition_price <= :price";
            $params[':price'] = $filters['price'];
        }
        // category
        if (!empty($filters['category'])) {
            $sql .= " AND exhibition_category LIKE :category";
            $params[':category'] = '%' . $filters['category'] . '%';
        }
        // date range
        if (!empty($filters['date_range']) && count($filters['date_range']) === 2) {
            $sql .= " AND exhibition_start_date <= :end_date AND exhibition_end_date >= :start_date";
            $params[':start_date'] = $filters['date_range'][0];
            $params[':end_date'] = $filters['date_range'][1];
        }
        // time range
        if (!empty($filters['time_range']) && count($filters['time_range']) === 2) {
            $sql .= " AND exhibition_start_time <= :end_time AND exhibition_end_time >= :start_time";
            $params[':start_time'] = $filters['time_range'][0];
            $params[':end_time'] = $filters['time_range'][1];
        }
        // tag (키워드 검색 - LIKE OR 조합)
        if (!empty($filters['tag'])) {
            $keywords = preg_split('/\s+/', trim($filters['tag']));
            $tagParts = [];
            foreach ($keywords as $index => $word) {
                $placeholder = ":tag_keyword_$index";
                $tagParts[] = "exhibition_tag LIKE $placeholder";
                $params[$placeholder] = '%' . $word . '%';
            }
            if (!empty($tagParts)) {
                $sql .= " AND (" . implode(" OR ", $tagParts) . ")";
            }
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getArtists($filters = []) {
        $sql = "SELECT * FROM APIServer_artist WHERE 1=1";
        $params = [];

        // name
        if (!empty($filters['name'])) {
            $sql .= " AND artist_name LIKE :name";
            $params[':name'] = '%' . $filters['name'] . '%';
        }
        // category
        if (!empty($filters['category'])) {
            $sql .= " AND artist_category LIKE :category";
            $params[':category'] = '%' . $filters['category'] . '%';
        }
        // nation
        if (!empty($filters['nation'])) {
            $sql .= " AND artist_nation LIKE :nation";
            $params[':nation'] = '%' . $filters['nation'] . '%';
        }
        // description
        if (!empty($filters['description'])) {
            $keywords = preg_split('/\s+/', trim($filters['description']));
            $tagParts = [];
            foreach ($keywords as $index => $word) {
                $placeholder = ":description_keyword_$index";
                $tagParts[] = "artist_description LIKE $placeholder";
                $params[$placeholder] = '%' . $word . '%';
            }
            if (!empty($tagParts)) {
                $sql .= " AND (" . implode(" OR ", $tagParts) . ")";
            }
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGalleries($filters = []) {
        $sql = "SELECT * FROM APIServer_gallery WHERE 1=1";
        $params = [];

        // name
        if (!empty($filters['name'])) {
            $keywords = preg_split('/\s+/', trim($filters['name']));
            $tagParts = [];
            foreach ($keywords as $index => $word) {
                $placeholder = ":gallery_name_keyword_$index";
                $tagParts[] = "gallery_name LIKE $placeholder";
                $params[$placeholder] = '%' . $word . '%';
            }
            if (!empty($tagParts)) {
                $sql .= " AND (" . implode(" OR ", $tagParts) . ")";
            }
        }
        // location
        if (!empty($filters['location'])) {
            $sql .= " AND gallery_address LIKE :location";
            $params[':location'] = '%' . $filters['location'] . '%';
        }
        // time range
        if (!empty($filters['time_range']) && count($filters['time_range']) === 2) {
            $sql .= " AND gallery_start_time <= :end_time AND gallery_end_time >= :start_time";
            $params[':start_time'] = $filters['time_range'][0];
            $params[':end_time'] = $filters['time_range'][1];
        }
        // category
        if (!empty($filters['category'])) {
            $sql .= " AND gallery_category LIKE :category";
            $params[':category'] = '%' . $filters['category'] . '%';
        }
        // description
        if (!empty($filters['description'])) {
            $keywords = preg_split('/\s+/', trim($filters['description']));
            $tagParts = [];
            foreach ($keywords as $index => $word) {
                $placeholder = ":description_keyword_$index";
                $tagParts[] = "gallery_description LIKE $placeholder";
                $params[$placeholder] = '%' . $word . '%';
            }
            if (!empty($tagParts)) {
                $sql .= " AND (" . implode(" OR ", $tagParts) . ")";
            }
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAnnouncements($filters = []) {
        $sql = "SELECT * FROM APIServer_announcement WHERE 1=1";
        $params = [];

        // title
        if (!empty($filters['title'])) {
            $keywords = preg_split('/\s+/', trim($filters['title']));
            $tagParts = [];
            foreach ($keywords as $index => $word) {
                $placeholder = ":title_keyword_$index";
                $tagParts[] = "announcement_title LIKE $placeholder";
                $params[$placeholder] = '%' . $word . '%';
            }
            if (!empty($tagParts)) {
                $sql .= " AND (" . implode(" OR ", $tagParts) . ")";
            }
        }
        // date range
        if (!empty($filters['date_range']) && count($filters['date_range']) === 2) {
            $sql .= " AND announcement_start_datetime <= :end_date AND announcement_end_datetime >= :start_date";
            $params[':start_date'] = $filters['date_range'][0];
            $params[':end_date'] = $filters['date_range'][1];
        }
        // description
        if (!empty($filters['content'])) {
            $keywords = preg_split('/\s+/', trim($filters['content']));
            $tagParts = [];
            foreach ($keywords as $index => $word) {
                $placeholder = ":content_keyword_$index";
                $tagParts[] = "content LIKE $placeholder";
                $params[$placeholder] = '%' . $word . '%';
            }
            if (!empty($tagParts)) {
                $sql .= " AND (" . implode(" OR ", $tagParts) . ")";
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}


