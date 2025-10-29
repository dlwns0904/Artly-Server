<?php
namespace Controllers;

use Models\UserModel;
use Firebase\JWT\JWT;
/**
 * @OA\Tag(
 *     name="Auth",
 *     description="로그인/회원가입 API"
 * )
 */

class AuthController {
    private $model;
    private $jwtSecret = 'jwt-secret-key'; # 임시로 작성

    public function __construct() {
        $this->model = new UserModel();
    }

    /**
     * @OA\Post(
     *     path="/api/auth/login",
     *     summary="로그인",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="login_id", type="string", example="test1"),
     *             @OA\Property(property="login_pwd", type="string", example="test1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="로그인 성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="jwt", type="string"),
     *             @OA\Property(property="data", type="object",
        *             @OA\Property(property="id", type="integer"),
        *             @OA\Property(property="login_id", type="string"),
        *             @OA\Property(property="login_pwd", type="string"),
        *             @OA\Property(property="user_name", type="string"),
        *             @OA\Property(property="user_gender", type="string"),
        *             @OA\Property(property="user_age", type="integer"),
        *             @OA\Property(property="user_email", type="string"),
        *             @OA\Property(property="user_phone", type="string"),
        *             @OA\Property(property="user_img", type="string"),
        *             @OA\Property(property="user_keyword", type="string"),
        *             @OA\Property(property="admin_flag", type="integer"),
        *             @OA\Property(property="gallery_id", type="integer"),
        *             @OA\Property(property="last_login_time", type="string", format="date-time"),
        *             @OA\Property(property="reg_time", type="string", format="date-time"),
        *             @OA\Property(property="update_dttm", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="로그인 실패")
     * )
     */
    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        $user = $this->model->getByLoginId($data['login_id']);

        # 로그인 실패
        if (!$user || $user['login_pwd'] !== $data['login_pwd']) {
            http_response_code(401);
            echo json_encode(['message' => 'Invalid credentials']);
            return;
        }

	$isAdmin = (int)($user['admin_flag'] ?? $user['adminFlag'] ?? 0) === 1;

        $jwt = $this->createJwtToken($user,$isAdmin, 60); # jwt 토큰 생성 / 유효시간 60분
        echo json_encode([
            'message' => 'Login successful',
            'jwt' => $jwt,
            'data' => $user
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/register",
     *     summary="회원가입",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="login_id", type="string"),
     *             @OA\Property(property="login_pwd", type="string"),
     *             @OA\Property(property="user_name", type="string"),
     *             @OA\Property(property="user_gender", type="string"),
     *             @OA\Property(property="user_age", type="integer"),
     *             @OA\Property(property="user_email", type="string"),
     *             @OA\Property(property="user_phone", type="string"),
     *             @OA\Property(property="user_img", type="string"),
     *             @OA\Property(property="user_keyword", type="string"),
     *             @OA\Property(property="admin_flag", type="integer"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="회원가입 성공",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="jwt", type="string"),
     *             @OA\Property(property="data", type="object",
        *             @OA\Property(property="id", type="integer"),
        *             @OA\Property(property="login_id", type="string"),
        *             @OA\Property(property="login_pwd", type="string"),
        *             @OA\Property(property="user_name", type="string"),
        *             @OA\Property(property="user_gender", type="string"),
        *             @OA\Property(property="user_age", type="integer"),
        *             @OA\Property(property="user_email", type="string"),
        *             @OA\Property(property="user_phone", type="string"),
        *             @OA\Property(property="user_img", type="string"),
        *             @OA\Property(property="user_keyword", type="string"),
        *             @OA\Property(property="admin_flag", type="integer"),
        *             @OA\Property(property="last_login_time", type="string", format="date-time"),
        *             @OA\Property(property="reg_time", type="string", format="date-time"),
        *             @OA\Property(property="update_dttm", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="회원가입 실패")
     * )
     */
    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);

        // 아이디 중복 검사
        if ($this->model->getByLoginId($data['login_id'])) {
            http_response_code(409); // Conflict
            echo json_encode(['message' => '이미 존재하는 아이디입니다.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $newUser = $this->model->create($data);

        if ($newUser) {
            http_response_code(201);
            echo json_encode([
                'message' => 'User registered successfully',
                'data' => $newUser
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Registration failed']);
        }
    }

    public function createJwtToken($user, bool $isAdmin ,$time) {
        $payload = [
                    'iat' => time(),                 // 발급 시간
                    'exp' => time() + ($time * 60),  // 만료 시간 / time 파라미터의 단위는 minute
                    'sub' => (string)$user['id'],
                    'user_id' => $user['id'],
                    'role' => $isAdmin ? 'admin' : 'user',
                    'login_id' => $user['login_id'],
        ];

        $jwt = JWT::encode($payload, $this->jwtSecret, 'HS256');
        return $jwt;
    }
}

