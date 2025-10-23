<?php
namespace Models;

use \PDO;

class AnnouncementModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function create($data) {
        $sql = "INSERT INTO APIServer_announcement (
            announcement_title, user_id, announcement_poster,
            announcement_start_datetime, announcement_end_datetime,
            announcement_organizer, announcement_contact, announcement_support_detail,
            announcement_site_url, announcement_attachment_url, announcement_content,
            announcement_category
        ) VALUES (
            :title, :user_id, :poster, :start, :end,
            :organizer, :contact, :support_detail,
            :site_url, :attachment_url, :content, :category
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':title' => $data['announcement_title'],
            ':user_id' => $data['user_id'],
            ':poster' => $data['announcement_poster'],
            ':start' => $data['announcement_start_datetime'],
            ':end' => $data['announcement_end_datetime'],
            ':organizer' => $data['announcement_organizer'],
            ':contact' => $data['announcement_contact'],
            ':support_detail' => $data['announcement_support_detail'],
            ':site_url' => $data['announcement_site_url'],
            ':attachment_url' => $data['announcement_attachment_url'],
            ':content' => $data['announcement_content'],
            ':category' => $data['announcement_category']
        ]);

        $id = $this->pdo->lastInsertId();
        return $this->getById($id);
    }

    public function update($id, $data) {
        $sql = "UPDATE APIServer_announcement SET
            announcement_title = :title,
            user_id = :user_id,
            announcement_poster = :poster,
            announcement_start_datetime = :start,
            announcement_end_datetime = :end,
            announcement_organizer = :organizer,
            announcement_contact = :contact,
            announcement_support_detail = :support_detail,
            announcement_site_url = :site_url,
            announcement_attachment_url = :attachment_url,
            announcement_content = :content,
            announcement_category = :category
        WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':title' => $data['announcement_title'],
            ':user_id' => $data['user_id'],
            ':poster' => $data['announcement_poster'],
            ':start' => $data['announcement_start_datetime'],
            ':end' => $data['announcement_end_datetime'],
            ':organizer' => $data['announcement_organizer'],
            ':contact' => $data['announcement_contact'],
            ':support_detail' => $data['announcement_support_detail'],
            ':site_url' => $data['announcement_site_url'],
            ':attachment_url' => $data['announcement_attachment_url'],
            ':content' => $data['announcement_content'],
            ':category' => $data['announcement_category']
        ]);

        return $this->getById($id);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_announcement WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }


public function getAnnouncements($filters = [])
{
    /* 기본 SELECT 절 ------------------------------------------------------ */
    $sql = "SELECT  id,
                    announcement_title,
                    announcement_start_datetime,
                    announcement_end_datetime,
                    announcement_organizer,
                    announcement_category,
                    announcement_status
            FROM    APIServer_announcement
            WHERE   1=1";
    $params = [];

    /* ① 카테고리 필터 ----------------------------------------------------- */
    if (!empty($filters['category'])) {
        /* 명시적으로 카테고리가 들어오면 그 값만 조회 */
        $sql               .= " AND announcement_category = :category";
        $params[':category'] = $filters['category'];           // (공모·프로그램·채용·공지사항·FAQ)
    } else {
        /* 파라미터가 없으면 공지사항·FAQ 자동 제외 */
        $sql .= " AND announcement_category NOT IN ('공지사항','FAQ')";
    }

    /* ② 진행 상태 필터 ---------------------------------------------------- */
    if (!empty($filters['status'])) {
        $sql               .= " AND announcement_status = :status";        // scheduled / ongoing / ended
        $params[':status']  = $filters['status'];
    }

    /* ③ 제목 검색 -------------------------------------------------------- */
    if (!empty($filters['search'])) {
        $sql               .= " AND announcement_title LIKE :search";
        $params[':search']  = '%' . $filters['search'] . '%';
    }

    /* ④ 정렬(ended 제외 조건 포함) --------------------------------------- */
    if (!empty($filters['sort'])) {
        switch ($filters['sort']) {
            case 'latest':                         // 최신순 (ended 제외)
                $sql .= " AND announcement_status != 'ended'
                          ORDER BY announcement_create_dtm DESC";
                break;

            case 'ending':                         // 종료 임박순 (ended 제외)
                $sql .= " AND announcement_status != 'ended'
                          ORDER BY announcement_end_datetime ASC";
                break;

            default:                               // 기타값 → 최신순
                $sql .= " ORDER BY announcement_create_dtm DESC";
        }
    } else {
        $sql .= " ORDER BY announcement_create_dtm DESC";     // 기본 최신순
    }

    /* ⑤ 실행 ------------------------------------------------------------- */
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


   public function getAnnouncementsBySearch($filters = []) {
    $search = $filters['search'];
    $stmt = $this->pdo->prepare("SELECT * FROM APIServer_announcement WHERE announcement_title LIKE :search");
    $stmt->execute([':search' => "%$search%"]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

   public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_announcement WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

