<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'ระบบลงชื่อปฏิบัติงานออนไลน์ (WFH) - โรงเรียนลำปลายมาศ',
        'timezone' => 'Asia/Bangkok',
        'base_path' => '',
        'upload_dir' => __DIR__ . '/../public/uploads',
        'upload_url' => '/uploads',
        'max_upload_mb' => 5,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'wfh_attendance',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'wfh_session',
    ],
];

