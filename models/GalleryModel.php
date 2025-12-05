<?php
namespace Models;

use \PDO;

class GalleryModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * 갤러리 생성
     */
    public function create($data) {
        $sql = "
            INSERT INTO APIServer_gallery (
                gallery_name, gallery_eng_name, gallery_image, gallery_address,
                gallery_start_time, gallery_end_time, gallery_closed_day,
                gallery_category, gallery_description,
                gallery_latitude, gallery_longitude,
                gallery_phone, gallery_email, gallery_homepage, gallery_sns,
                user_id
            ) VALUES (
                :name, :eng_name, :image, :address,
                :start_time, :end_time, :closed_day,
                :category, :description,
                :latitude, :longitude,
                :phone, :email, :homepage, :sns,
                :user_id
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':name'       => $data['gallery_name'],
            ':eng_name'   => $data['gallery_eng_name'],
            ':image'      => $data['gallery_image'] ?? null,
            ':address'    => $data['gallery_address'] ?? null,
            ':start_time' => $data['gallery_start_time'] ?? null,
            ':end_time'   => $data['gallery_end_time'] ?? null,
            ':closed_day' => $data['gallery_closed_day'] ?? null,
            ':category'   => $data['gallery_category'] ?? null,
            ':description'=> $data['gallery_description'] ?? null,
            ':latitude'   => $data['gallery_latitude'] ?? null,
            ':longitude'  => $data['gallery_longitude'] ?? null,
            ':phone'      => $data['gallery_phone'] ?? null,
            ':email'      => $data['gallery_email'] ?? null,
            ':homepage'   => $data['gallery_homepage'] ?? null,
            ':sns' => $this->normalizeSns($data['gallery_sns'] ?? null),
            ':user_id'    => $data['user_id'] ?? null,
        ]);

        $id = $this->pdo->lastInsertId();
        return $this->getById($id, $data['user_id'] ?? null);
    }

    /**
     * 갤러리 수정
     */
    public function update($id, $data) {
        $setParts = [];
        $params = [':id' => $id];

        $fieldMap = [
            'gallery_name'       => ':name',
            'gallery_eng_name'   => ':eng_name',
            'gallery_image'      => ':image',
            'gallery_address'    => ':address',
            'gallery_start_time' => ':start_time',
            'gallery_end_time'   => ':end_time',
            'gallery_closed_day' => ':closed_day',
            'gallery_category'   => ':category',
            'gallery_description'=> ':description',
            'gallery_latitude'   => ':latitude',
            'gallery_longitude'  => ':longitude',
            'gallery_phone'      => ':phone',
            'gallery_email'      => ':email',
            'gallery_homepage'   => ':homepage',
        ];

        foreach ($fieldMap as $column => $placeholder) {
            // "?? null" 대신 "array_key_exists" 사용
            if (array_key_exists($column, $data)) {
                $setParts[] = "$column = $placeholder";
                $params[$placeholder] = $data[$column]; // (null이 전송되어도 DB에 null이 반영됨)
            }
        }

        if (array_key_exists('gallery_sns', $data)) {
            $setParts[] = "gallery_sns = :sns";
            $params[':sns'] = $this->normalizeSns($data['gallery_sns']);
        }

        if (empty($setParts)) {
            // 수정할 내용이 없으므로 현재 데이터를 그대로 반환
            return $this->getById($id, $data['user_id'] ?? null);
        }

        $sql = "UPDATE APIServer_gallery SET " . implode(', ', $setParts) . " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->getById($id, $data['user_id'] ?? null);
    }

    /**
     * 갤러리 삭제
     */
    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_gallery WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function getGalleries($filters = []) {
        $sql = "
            SELECT
                g.id AS gallery_id,
                g.gallery_name,
                g.gallery_eng_name,
                g.gallery_image,
                g.gallery_latitude,
                g.gallery_longitude,
                g.gallery_address,
                g.gallery_category,
                DATE_FORMAT(g.gallery_start_time, '%H:%i') AS gallery_start_time,
                DATE_FORMAT(g.gallery_end_time, '%H:%i') AS gallery_end_time,
                g.gallery_closed_day,
                g.gallery_phone,
                g.gallery_email,
                g.gallery_homepage,
                g.gallery_sns,
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
    
        $user_id   = $filters['user_id'] ?? 0;
        $adminOnly = !empty($filters['admin_only']);
        $params = [':user_id_for_like' => $user_id];
    
        // 1️⃣ [관리자 모드] 내 갤러리만 보기
        // (else를 제거하여 이 조건이 적용된 후에도 아래 검색 필터가 동작하도록 함)
        if ($adminOnly && $user_id > 0) {
            $sql .= " AND g.user_id = :owner_id";
            $params[':owner_id'] = $user_id;
        }
    
        // 2️⃣ [찜한 목록]
        $likedOnly = !empty($filters['liked_only']) && filter_var($filters['liked_only'], FILTER_VALIDATE_BOOLEAN);
        if ($likedOnly && $user_id > 0) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM APIServer_gallery_like l
                WHERE l.gallery_id = g.id AND l.user_id = :user_id_only
            )";
            $params[':user_id_only'] = $user_id;
        }
    
        // 3️⃣ [검색어] (이제 관리자 모드에서도 동작합니다)
        if (!empty($filters['search'])) {
            $sql .= " AND (g.gallery_name LIKE :search OR g.gallery_address LIKE :search)";
            // 이름뿐만 아니라 주소 검색도 원하시면 OR 조건을 유지, 이름만 원하면 뒤쪽 제거
            $params[':search'] = '%' . $filters['search'] . '%';
        }
    
        // 4️⃣ [지역 필터]
        if (!empty($filters['regions'])) {
            $regionList = explode(',', $filters['regions']);
            $regionConds = [];
            foreach ($regionList as $i => $region) {
                $key = ":region$i";
                $regionConds[] = "g.gallery_address LIKE $key";
                $params[$key] = '%' . trim($region) . '%';
            }
            if ($regionConds) {
                $sql .= " AND (" . implode(" OR ", $regionConds) . ")";
            }
        }
    
        // 5️⃣ [타입 필터]
        if (!empty($filters['type'])) {
            $sql .= " AND g.gallery_category = :type";
            $params[':type'] = $filters['type'];
        }
    
        // 6️⃣ [거리 필터]
        if (!empty($filters['latitude']) && !empty($filters['longitude']) && !empty($filters['distance'])) {
            $sql .= " AND (
                6371000 * ACOS(
                    COS(RADIANS(:latitude)) * COS(RADIANS(g.gallery_latitude)) *
                    COS(RADIANS(g.gallery_longitude) - RADIANS(:longitude)) +
                    SIN(RADIANS(:latitude)) * SIN(RADIANS(g.gallery_latitude))
                )
            ) <= :distance";
            $params[':latitude']  = $filters['latitude'];
            $params[':longitude'] = $filters['longitude'];
            $params[':distance']  = $filters['distance'];
        }
    
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); // FETCH_ASSOC 명시 권장
    
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id'                 => (int)$row['gallery_id'],
                'gallery_name'       => $row['gallery_name'],
                'gallery_eng_name'   => $row['gallery_eng_name'],
                'gallery_image'      => $row['gallery_image'],
                'gallery_latitude'   => isset($row['gallery_latitude']) ? (float)$row['gallery_latitude'] : null,
                'gallery_longitude'  => isset($row['gallery_longitude']) ? (float)$row['gallery_longitude'] : null,
                'gallery_address'    => $row['gallery_address'],
                'gallery_category'   => $row['gallery_category'],
                'gallery_start_time' => $row['gallery_start_time'],
                'gallery_end_time'   => $row['gallery_end_time'],
                'gallery_closed_day' => $row['gallery_closed_day'],
                'gallery_phone'      => $row['gallery_phone'],
                'gallery_email'      => $row['gallery_email'],
                'gallery_homepage'   => $row['gallery_homepage'],
                'gallery_sns'        => $row['gallery_sns'],
                'like_count'         => (int)$row['like_count'],
                'is_liked'           => (bool)$row['is_liked'],
            ];
        }
    
        return $results;
    }

    /**
     * 갤러리 단건 조회 (+ 전시 일부 정보)
     */
    public function getById($id, $user_id = null) {
        $sql = "
            SELECT
                g.id AS gallery_id,
                g.user_id,
                g.gallery_name,
                g.gallery_eng_name,
                g.gallery_image,
                g.gallery_address,
                g.gallery_start_time,
                g.gallery_end_time,
                g.gallery_closed_day,
                g.gallery_category,
                g.gallery_description,
                g.gallery_latitude,
                g.gallery_longitude,
                g.gallery_phone,
                g.gallery_email,
                g.gallery_homepage,
                g.gallery_sns,
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

        $rows = $stmt->fetchAll();
        if (empty($rows)) return null;

        $firstRow = $rows[0];

        $gallery = [
            'id'                 => (int)$firstRow['gallery_id'],
            'user_id'            => $firstRow['user_id'],
            'gallery_name'       => $firstRow['gallery_name'],
            'gallery_eng_name'   => $firstRow['gallery_eng_name'],
            'gallery_image'      => $firstRow['gallery_image'],
            'gallery_address'    => $firstRow['gallery_address'],
            'gallery_start_time' => $firstRow['gallery_start_time'],
            'gallery_end_time'   => $firstRow['gallery_end_time'],
            'gallery_closed_day' => $firstRow['gallery_closed_day'],
            'gallery_category'   => $firstRow['gallery_category'],
            'gallery_description'=> $firstRow['gallery_description'],
            'gallery_latitude'   => isset($firstRow['gallery_latitude']) ? (float)$firstRow['gallery_latitude'] : null,
            'gallery_longitude'  => isset($firstRow['gallery_longitude']) ? (float)$firstRow['gallery_longitude'] : null,
            'gallery_phone'      => $firstRow['gallery_phone'],
            'gallery_email'      => $firstRow['gallery_email'],
            'gallery_homepage'   => $firstRow['gallery_homepage'],
            'gallery_sns'        => $firstRow['gallery_sns'],
            'like_count'         => (int)$firstRow['like_count'],
            'is_liked'           => (bool)$firstRow['is_liked'],
            'exhibitions'        => []
        ];

        foreach ($rows as $row) {
            if (!empty($row['exhibition_id'])) {
                $gallery['exhibitions'][] = [
                    'id'     => (int)$row['exhibition_id'],
                    'title'  => $row['exhibition_title'],
                    'poster' => $row['exhibition_poster'],
                    'status' => $row['exhibition_status']
                ];
            }
        }

        return $gallery;
    }

    public function getGalleriesBySearch($filters = []) {
        // 1. 기본 쿼리문과 WHERE 조건절 배열, 파라미터 배열을 준비합니다.
        $sql = "SELECT * FROM APIServer_gallery";
        $whereClauses = [];
        $params = [];

        // 2. 'search' 필터가 있으면 gallery_name 조건을 추가합니다.
        if (!empty($filters['search'])) {
            $whereClauses[] = "gallery_name LIKE :search";
            $params[':search'] = "%" . $filters['search'] . "%";
        }

        // 3. 'user_id' 필터가 있으면 user_id 조건을 추가합니다.
        if (!empty($filters['user_id'])) {
            $whereClauses[] = "user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }

        // 4. WHERE 조건이 하나라도 있으면 쿼리문에 추가합니다.
        // implode() 함수를 사용해 " AND "로 각 조건들을 연결합니다.
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        // 5. 완성된 쿼리와 파라미터로 실행합니다.
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function normalizeSns($snsInput) {
        // 1. 입력값 파싱 (NULL, JSON String, Array 대응)
        $inputArray = [];
        
        if (is_string($snsInput)) {
            // JSON 문자열인 경우 디코딩
            $decoded = json_decode($snsInput, true);
            if (is_array($decoded)) {
                $inputArray = $decoded;
            }
        } elseif (is_array($snsInput)) {
            // 이미 배열인 경우 그대로 사용
            $inputArray = $snsInput;
        } 
        // null이거나 형식이 안 맞으면 $inputArray는 빈 배열 [] 상태 유지

        // 2. 입력 데이터를 검색하기 쉽게 'type'을 Key로 하는 맵(Map)으로 변환
        // 예: [{'type':'youtube', 'url':'B'}, {'type':'instagram', 'url':'A'}] 
        //  -> ['youtube' => 'B', 'instagram' => 'A']
        $snsMap = [];
        foreach ($inputArray as $item) {
            // 배열이고 필수 키가 있는지 확인
            if (is_array($item) && isset($item['type']) && isset($item['url'])) {
                // 타입은 소문자로 통일하여 키로 사용
                $typeKey = strtolower(trim($item['type']));
                $snsMap[$typeKey] = trim($item['url']);
            }
        }

        // 3. 강제할 순서 및 항목 정의 (인스타그램 -> 유튜브 -> 트위터 -> 페이스북)
        $targetOrder = ['instagram', 'youtube', 'twitter', 'facebook'];

        // 4. 정의된 순서대로 결과 배열 조립
        $result = [];
        foreach ($targetOrder as $type) {
            // 맵에 해당 타입의 URL이 있으면 사용, 없으면 빈 문자열('')
            $url = isset($snsMap[$type]) ? $snsMap[$type] : '';
            
            $result[] = [
                'url'  => $url,
                'type' => $type
            ];
        }

        // 5. 최종 JSON 반환 (유니코드 보존, 슬래시 이스케이프 방지)
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}

