<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = $config['db'] ?? [];
        $host = (string)($db['host'] ?? '127.0.0.1');
        $port = (int)($db['port'] ?? 3306);
        $database = (string)($db['database'] ?? '');
        $charset = (string)($db['charset'] ?? 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        try {
            self::$pdo = new PDO(
                $dsn,
                (string)($db['username'] ?? ''),
                (string)($db['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            $username = (string)($db['username'] ?? '');
            $safeDsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<title>เชื่อมต่อฐานข้อมูลไม่สำเร็จ</title>';
            echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
            echo '</head><body class="bg-light"><div class="container py-5"><div class="row justify-content-center"><div class="col-lg-8">';
            echo '<div class="card border-0 shadow-sm"><div class="card-body p-4">';
            echo '<h4 class="mb-2">เชื่อมต่อฐานข้อมูลไม่สำเร็จ</h4>';
            echo '<div class="text-muted mb-3">กรุณาตรวจสอบ MySQL และค่าการเชื่อมต่อใน <code>app/config.php</code></div>';
            echo '<div class="mb-3"><div class="fw-semibold mb-1">ค่าที่กำลังใช้งาน</div>';
            echo '<div class="small"><code>' . htmlspecialchars($safeDsn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></div>';
            echo '<div class="small">username: <code>' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></div></div>';
            echo '<div class="fw-semibold mb-1">วิธีแก้ไข (สรุป)</div>';
            echo '<ol class="mb-0">';
            echo '<li>สร้างฐานข้อมูลและตาราง โดยนำเข้าไฟล์ <code>database/schema.sql</code></li>';
            echo '<li>ตั้งค่าชื่อฐานข้อมูล/ผู้ใช้/รหัสผ่านให้ถูกต้องใน <code>app/config.php</code></li>';
            echo '<li>รีเฟรชหน้าอีกครั้ง</li>';
            echo '</ol>';
            echo '</div></div></div></div></div></div></body></html>';
            exit;
        }

        return self::$pdo;
    }
}

