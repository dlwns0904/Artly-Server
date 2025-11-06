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

    /**
     * 기존 로직 유지: 텍스트 컬럼은 그대로. (art_image는 레거시/URL 등)
     * 새 이미지 컬럼(LONGBLOB 등)은 별도 saveImage*()에서 채움.
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

        $stmt = $this->pdo->prepare("SELECT * FROM APIServer_art WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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

        return $stmt->execute([
            ':image'       => $data['art_image']        ?? null, // 레거시/URL 문자열 등
            ':artist_id'   => $data['artist_id']        ?? null,
            ':title'       => $data['art_title']        ?? null,
            ':description' => $data['art_description']  ?? null,
            ':docent'      => $data['art_docent']       ?? null,
            ':material'    => $data['art_material']     ?? null,
            ':size'        => $data['art_size']         ?? null,
            ':year'        => $data['art_year']         ?? null,
            ':id'          => $id
        ]);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM APIServer_art WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /* ===========================
       이미지(Blob) 처리 보조 메서드
       =========================== */

    /** 목록/상세 레코드에서 blob 존재 여부 판단 */
    public function hasImage(array $artRow): bool {
        // fetchAll()/fetch() 기본은 문자열 반환이라 비어있지 않으면 존재로 봄
        return isset($artRow['art_images']) && $artRow['art_images'] !== null && $artRow['art_images'] !== '';
    }

    /** 업로드 파일(단일)에서 이미지 저장 */
    public function saveImageFromUpload(int $id, array $file): void {
        $tmp = $file['tmp_name'];
        $name = $file['name'] ?? null;
        $size = (int)($file['size'] ?? 0);
        $mime = $file['type'] ?? null;

        // 바이너리 읽기
        $data = file_get_contents($tmp);

        $sql = "UPDATE APIServer_art
                SET art_images = :img, art_image_mime = :mime, art_image_name = :name, art_image_size = :size, update_dtm = NOW()
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        // LOB 바인딩
        $stmt->bindParam(':img', $data, PDO::PARAM_LOB);
        $stmt->bindValue(':mime', $mime);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':size', $size, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * data URL 혹은 base64(순수) 지원
     * - dataURL 예: data:image/png;base64,iVBORw0...
     */
    public function saveImageFromBase64(int $id, string $base64, ?string $originalName = null): void {
        $mime = null;
        $raw = $base64;

        if (strpos($base64, 'data:') === 0) {
            // dataURL
            if (preg_match('#^data:(.*?);base64,(.*)$#', $base4 = $base64, $m)) {
                $mime = $m[1] ?: null;
                $raw  = $m[2] ?: '';
            } else {
                $raw = '';
            }
        }

        $bin = base64_decode($raw, true);
        if ($bin === false) {
            // 잘못된 base64면 무시
            return;
        }

        if ($mime === null) {
            // 간단 추정(선택): PNG/JPEG 시그니처
            if (strncmp($bin, "\x89PNG", 4) === 0) $mime = 'image/png';
            else if (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) $mime = 'image/jpeg';
            else $mime = 'application/octet-stream';
        }

        $size = strlen($bin);
        $sql = "UPDATE APIServer_art
                SET art_images = :img, art_image_mime = :mime, art_image_name = :name, art_image_size = :size, update_dtm = NOW()
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':img', $bin, PDO::PARAM_LOB);
        $stmt->bindValue(':mime', $mime);
        $stmt->bindValue(':name', $originalName);
        $stmt->bindValue(':size', $size, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** 메타(및 blob 존재) 체크용 */
    public function getImageMetaById(int $id): ?array {
        $stmt = $this->pdo->prepare("
            SELECT art_images, art_image_mime, art_image_name, art_image_size
            FROM APIServer_art
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 바이너리 스트리밍 (메모리 과다 방지 위해 LOB 스트림) */
    public function streamImageById(int $id): void {
        // LOB를 스트림으로 가져오기
        $stmt = $this->pdo->prepare("SELECT art_images FROM APIServer_art WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $stmt->bindColumn(1, $lob, PDO::PARAM_LOB);
        if ($stmt->fetch(PDO::FETCH_BOUND)) {
            if (is_resource($lob)) {
                // LOB 스트림을 그대로 출력
                fpassthru($lob);
            } else {
                // 드물게 문자열로 오는 드라이버 대비
                echo $lob;
            }
        } else {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Image not found']);
        }
    }
}
