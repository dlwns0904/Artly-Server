<?php

// backend/index.php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// .env 로드 (파일 없으면 조용히 스킵)
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();


// echo ">> index 실행됨 <<\n";
require_once __DIR__ . '/routes.php';

