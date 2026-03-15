<?php

declare(strict_types=1);

use App\DB;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

$config = require $root . '/app/config.php';

date_default_timezone_set((string)($config['app']['timezone'] ?? 'Asia/Bangkok'));

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

$sessionDir = $root . '/storage/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0775, true);
}
session_save_path($sessionDir);

$sessionName = (string)($config['security']['session_name'] ?? 'wfh_session');
session_name($sessionName);
session_start();

$pdo = DB::pdo($config);

return [
    'root' => $root,
    'config' => $config,
    'pdo' => $pdo,
];
