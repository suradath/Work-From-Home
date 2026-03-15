<?php

declare(strict_types=1);

namespace App;

use PDO;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(array $config): string
{
    $base = (string)($config['app']['base_path'] ?? '');
    if ($base === '' || $base === '/') {
        return '';
    }
    return rtrim($base, '/');
}

function url(array $config, string $path): string
{
    $base = base_path($config);
    if ($path === '' || $path === '/') {
        return $base . '/';
    }
    return $base . '/' . ltrim($path, '/');
}

function flash_set(string $key, string $message, string $type = 'info'): void
{
    $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
}

function flash_get(string $key): ?array
{
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return is_array($value) ? $value : null;
}

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_check(?string $token): bool
{
    $sessionToken = $_SESSION['_csrf'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }
    if (!is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

function current_user(): ?array
{
    $u = $_SESSION['user'] ?? null;
    return is_array($u) ? $u : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(array $config): void
{
    if (is_logged_in()) {
        return;
    }
    header('Location: ' . url($config, '/index.php?page=login'));
    exit;
}

function require_admin(array $config): void
{
    require_login($config);
    $user = current_user();
    if (($user['is_admin'] ?? 0) == 1) {
        return;
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function attempt_login(PDO $pdo, string $username, string $password): ?array
{
    $stmt = $pdo->prepare('SELECT teacher_id, teacher_username, teacher_password, teacher_fullname, group_id, teacher_position, is_admin, is_active FROM teachers WHERE teacher_username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    if (($row['is_active'] ?? 0) != 1) {
        return null;
    }
    $hash = (string)($row['teacher_password'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return null;
    }

    return [
        'teacher_id' => (int)$row['teacher_id'],
        'teacher_username' => (string)$row['teacher_username'],
        'teacher_fullname' => (string)$row['teacher_fullname'],
        'group_id' => $row['group_id'] !== null ? (int)$row['group_id'] : null,
        'teacher_position' => (string)($row['teacher_position'] ?? ''),
        'is_admin' => (int)($row['is_admin'] ?? 0),
    ];
}

