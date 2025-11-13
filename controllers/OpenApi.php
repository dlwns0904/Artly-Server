<?php
declare(strict_types=1);

namespace Controllers;

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *   title="Artly API",
 *   version="1.0.0",
 *   description="Artly 백엔드 REST API 문서"
 * )
 *
 * @OA\Server(
 *   url="https://artly.soundgram.co.kr",
 *   description="Production"
 * )
 * @OA\Server(
 *   url="http://localhost",
 *   description="Local"
 * )
 */
final class OpenApi {} // 내용 없는 더미 클래스 (주석만 사용)

