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

    /**
     * 작품 목록 조회
     * - 성능/안전 위해 LONGBLOB 컬럼(art_images)은 SELECT 하지 않음
     * - 응답에는 art_image_url만 추가
     */
    public function getAll($filters = []) {
        $sql = "
            SELECT
                A.id,
                A.artist_id,
                A.art_title,
                A.art_description,
                A.art_docent,
                A.art_material,
                A.art_size,
                A.art_year,
                A.create_dtm,
                A.update_dtm,
                A.art_image,             -- (레거시/외부 URL 문자열일 수 있음)
                A.art_image_mime,
                A.art_image_name,
                A.art_image_size
            FROM APIServer_art A
            WHERE 1=1
        ";
        $params = [];

        // (옵션) 특정 전시에 속한 작품만 조회
        if (!empty($filters['exhibition_id'])) {
            $sql .= "
                AND EXISTS (
                    SELECT 1
                    FROM APIServer_exhibition_art EA
                    WHERE EA.art_id = A.id
                      AND EA.exhibition_id = :exhibition_id
                )
            ";
            $params[':exhibition_id'] = $filters['exhibition_id'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // 응답 변환: BLOB 대신 URL만
        foreach ($rows as &$row) {
            $row['art_image_url'] = $this->hasImage($row)
                ? ("/api/arts/{$row['id']}/image")
                : ($row['art_image'] ?? null); // 레거시 문자열이 있다면 그대로 폴백
            // art_images 컬럼은 애초에 SELECT 하지 않음
        }

        return $rows;
    }

    /**
     * 작품 단건 조회
     * - LONGBLOB 제외
     * - 아티스트 이름 조인
     */
    public function getById($id) {
        $stmt = $this->pdo->prepare("
            SELECT
                a.id,
                a.artist_id,
                a.art_title,
                a.art_description,
                a.art_docent,
                a.art_material,
                a.art_size,
                a.art_year,
                a.create_dtm,
                a.update_dtm,
                a.art_image,               -- (레거시/외부 URL 문자열)
                a.art_image_mime,
                a.art_image_name,
                a.art_image_size,
                ar.artist_name
            FROM APIServer_art a
            LEFT JOIN APIServer_artist ar
              ON a.artist_id = ar.id
            WHERE a.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $row['art_image_url'] = $this->hasImage($row)
            ? ("/api/arts/{$row['id']}/image")
            : ($row['art_image'] ?? null);

        return $row; // art_images 컬럼 없음
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

    /**
     * 기존 텍스트 컬럼(art_image = 레거시/외부 URL 문자열) 유지
     * BLOB은 별도 saveImage*()로 세팅
     */
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
            ':image'       => $data['art_image']        ?? null,  // 레거시/URL 문자열 등
            ':artist_id'   => $data['artist_id']        ?? null,
            ':title'       => $data['art_title']        ?? null,
            ':description' => $data['art_description']  ?? null,
            ':docent'      => $data['art_docent']       ?? null,
            ':material'    => $data['art_material']     ?? null,
            ':size'        => $data['art_size']         ?? null,
            ':year'        => $data['art_year']         ?? null,
        ]);

        $id = $this->pdo->lastInsertId();
        return $this->getById($id);
    }

    public function update($id, $data) {
        $stmt = $this->pdo->prepare("
            UPDATE APIServer_art SET
                art_image       = :image,
                artist_id       = :artist_id,
                art_title       = :title,
                art_description = :description,
                art_docent      = :docent,
                art_material    = :material,
                art_size        = :size,
                art_year        = :year,
                update_dtm      = NOW()
            WHERE id = :id
        ");

        $ok = $stmt->execute([
            ':image'       => $data['art_image']        ?? null,
            ':artist_id'   => $data['artist_id']        ?? null,
            ':title'       => $data['art_title']        ?? null,
            ':description' => $data['art_description']  ?? null,
            ':docent'      => $data['art_docent']       ?? null,
            ':material'    => $data['art_material']     ?? null,
            ':size'        => $data['art_size']         ?? null,
            ':year'        => $data['art_year']         ?? null,
            ':id'          => $id
        ]);

        return $ok ? $this->getById($id) : null;
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_art WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /* ===========================
       이미지(Blob) 처리 보조 메서드
       =========================== */

    /** BLOB 존재 여부: 메타 컬럼 기반으로 판단 */
    public function hasImage(array $row): bool {
        return !empty($row['art_image_size']) || !empty($row['art_image_mime']);
    }

    /** 업로드 파일(단일)에서 이미지 저장 */
    public function saveImageFromUpload(int $id, array $file): void {
        $tmp  = $file['tmp_name'];
        $name = $file['name'] ?? null;
        $size = (int)($file['size'] ?? 0);
        $mime = $file['type'] ?? null;

        $data = file_get_contents($tmp);

        $sql = "
            UPDATE APIServer_art
               SET art_images     = :img,
                   art_image_mime = :mime,
                   art_image_name = :name,
                   art_image_size = :size,
                   update_dtm     = NOW()
             WHERE id = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':img',  $data, PDO::PARAM_LOB);
        $stmt->bindValue(':mime', $mime);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':size', $size, PDO::PARAM_INT);
        $stmt->bindValue(':id',   $id,   PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * data URL 혹은 순수 base64를 BLOB으로 저장
     * 예) data:image/png;base64,iVBORw0...
     */
    public function saveImageFromBase64(int $id, string $base64, ?string $originalName = null): void {
        $mime = null;
        $raw  = $base64;

        if (strpos($base64, 'data:') === 0) {
            if (preg_match('#^data:(.*?);base64,(.*)$#', $base64, $m)) {
                $mime = $m[1] ?: null;
                $raw  = $m[2] ?: '';
            } else {
                $raw = '';
            }
        }

        $bin = base64_decode($raw, true);
        if ($bin === false) {
            // 잘못된 base64는 무시
            return;
        }

        if ($mime === null) {
            // 간단한 시그니처 추정
            if (strncmp($bin, "\x89PNG", 4) === 0) {
                $mime = 'image/png';
            } else if (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) {
                $mime = 'image/jpeg';
            } else {
                $mime = 'application/octet-stream';
            }
        }

        $size = strlen($bin);
        $sql = "
            UPDATE APIServer_art
               SET art_images     = :img,
                   art_image_mime = :mime,
                   art_image_name = :name,
                   art_image_size = :size,
                   update_dtm     = NOW()
             WHERE id = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':img',  $bin, PDO::PARAM_LOB);
        $stmt->bindValue(':mime', $mime);
        $stmt->bindValue(':name', $originalName);
        $stmt->bindValue(':size', $size, PDO::PARAM_INT);
        $stmt->bindValue(':id',   $id,   PDO::PARAM_INT);
        $stmt->execute();
    }

    /** 이미지 메타 + BLOB 존재 여부 확인용 (스트리밍 전) */
    public function getImageMetaById(int $id): ?array {
        $stmt = $this->pdo->prepare("
            SELECT art_images, art_image_mime, art_image_name, art_image_size
            FROM APIServer_art
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** 바이너리 스트리밍 (LOB 스트림으로 메모리 효율) */
    public function streamImageById(int $id): void {
        $stmt = $this->pdo->prepare("SELECT art_images FROM APIServer_art WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $stmt->bindColumn(1, $lob, PDO::PARAM_LOB);
        if ($stmt->fetch(PDO::FETCH_BOUND)) {
            if (is_resource($lob)) {
                fpassthru($lob);
            } else {
                echo $lob; // 드라이버가 문자열로 반환하는 경우
            }
        } else {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['message' => 'Image not found']);
        }
    }
}
