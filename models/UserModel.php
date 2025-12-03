<?php
namespace Models;

use PDO;

class UserModel {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_user WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByLoginId($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_user WHERE login_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByEmail($email) {
        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_user WHERE user_email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    # 사용자의 예매 정보 가져오기
    public function getMyReservations($id) {
        $stmt = $this->pdo->prepare(
            "SELECT 
                A.id, A.user_id, A.exhibition_id, A.reservation_datetime, 
                A.reservation_number_of_tickets, A.reservation_total_price, 
                A.reservation_payment_method, A.reservation_status, 
                A.create_dtm, A.update_dtm,

                C.exhibition_title, C.exhibition_poster, C.exhibition_category, 
                C.exhibition_start_date, C.exhibition_end_date, 
                C.exhibition_start_time, C.exhibition_end_time, 
                C.exhibition_location, C.exhibition_price, 
                C.gallery_id, C.exhibition_tag, C.exhibition_status AS exhibition_status
            FROM 
                APIServer_reservation A
            LEFT JOIN 
                APIServer_exhibition C 
                ON A.exhibition_id = C.id
            WHERE 
                A.user_id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    # 사용자의 구매(도록) 정보 가져오기
    public function getMyPurchases($id) {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM APIServer_user_book A, APIServer_book B
             WHERE A.book_id = B.id
             AND A.user_id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    # 사용자의 좋아요한 전시회 정보 가져오기
    public function getMyLikeExhibitions($userId) {
        $stmt = $this->pdo->prepare(
            "SELECT A.*
             FROM APIServer_exhibition A, APIServer_exhibition_like B
             WHERE A.id = B.exhibition_id 
             AND B.user_id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    # 사용자의 좋아요한 갤러리 정보 가져오기
    public function getMyLikeGalleries($userId) {
        $stmt = $this->pdo->prepare(
            "SELECT A.*
             FROM APIServer_gallery A, APIServer_gallery_like B
             WHERE A.id = B.gallery_id 
             AND B.user_id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    # 사용자의 좋아요한 작가 정보 가져오기
    public function getMyLikeArtists($userId) {
        $stmt = $this->pdo->prepare(
            "SELECT A.*
             FROM APIServer_artist A, APIServer_artist_like B
             WHERE A.id = B.artist_id 
             AND B.user_id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    # 사용자의 좋아요한 작품 정보 가져오기
    public function getMyLikeArts($userId) {
        $stmt = $this->pdo->prepare(
            "SELECT A.*
             FROM APIServer_art A, APIServer_art_like B
             WHERE A.id = B.art_id 
             AND B.user_id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        // gallery_id 제거
        $stmt = $this->pdo->prepare("INSERT INTO APIServer_user 
            (login_id, login_pwd, user_name, user_gender, user_age, user_email, user_phone, user_img, user_keyword, admin_flag, last_login_time, reg_time, update_dtm)
            VALUES (:userId, :password, :name, :gender, :age, :email, :phone, :img, :keyword, :admin_flag, NOW(), NOW(), NOW())
        ");

        $stmt->execute([
            ':userId'      => $data['login_id']    ?? null,
            ':password'    => $data['login_pwd']   ?? null,
            ':name'        => $data['user_name']   ?? null,
            ':gender'      => $data['user_gender'] ?? null,
            ':age'         => $data['user_age']    ?? null,
            ':email'       => $data['user_email']  ?? null,
            ':phone'       => $data['user_phone']  ?? null,
            ':img'         => $data['user_img']    ?? null,
            ':keyword'     => $data['user_keyword']?? null,
            ':admin_flag'  => $data['admin_flag']  ?? 0,
        ]);

        $id = $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_user WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $data) {
        // gallery_id 제거
        $stmt = $this->pdo->prepare("UPDATE APIServer_user SET
            login_id     = :userId,
            login_pwd    = :password,
            user_name    = :name,
            user_gender  = :gender,
            user_age     = :age,
            user_email   = :email,
            user_phone   = :phone,
            user_img     = :img,
            user_keyword = :keyword,
            admin_flag   = :admin_flag,
            update_dtm   = NOW()
            WHERE id     = :id
        ");

        return $stmt->execute([
            ':userId'     => $data['login_id']    ?? null,
            ':password'   => $data['login_pwd']   ?? null,
            ':name'       => $data['user_name']   ?? null,
            ':gender'     => $data['user_gender'] ?? null,
            ':age'        => $data['user_age']    ?? null,
            ':email'      => $data['user_email']  ?? null,
            ':phone'      => $data['user_phone']  ?? null,
            ':img'        => $data['user_img']    ?? null,
            ':keyword'    => $data['user_keyword']?? null,
            ':admin_flag' => $data['admin_flag']  ?? 0,
            ':id'         => $id
        ]);
    }

    # 회원 탈퇴 (유저 삭제)
    public function delete($loginId) {
        // login_id를 기준으로 삭제합니다.
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_user WHERE login_id = ?");
        return $stmt->execute([$loginId]);
    }
}
