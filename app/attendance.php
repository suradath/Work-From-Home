<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;
use RuntimeException;

function today_date(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}

function now_datetime(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function get_today_log(PDO $pdo, int $teacherId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM attendance_logs WHERE teacher_id = :tid AND work_date = :d LIMIT 1');
    $stmt->execute([':tid' => $teacherId, ':d' => today_date()]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function create_checkin(
    PDO $pdo,
    int $teacherId,
    string $locationType,
    string $locationDetail,
    string $taskDetail,
    ?float $lat,
    ?float $lng,
    ?string $photoPath,
    ?string $photoOriginal,
    string $clientIp,
    string $userAgent
): int {
    $existing = get_today_log($pdo, $teacherId);
    if (is_array($existing) && !empty($existing['check_in_at'])) {
        throw new RuntimeException('ลงชื่อเข้าแล้วสำหรับวันนี้');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO attendance_logs
        (teacher_id, work_date, check_in_at, location_type, location_detail, task_detail, checkin_lat, checkin_lng, checkin_photo_path, checkin_photo_original, client_ip, user_agent)
        VALUES
        (:tid, :d, :cin, :lt, :ld, :task, :lat, :lng, :pp, :po, :ip, :ua)'
    );
    $stmt->execute([
        ':tid' => $teacherId,
        ':d' => today_date(),
        ':cin' => now_datetime(),
        ':lt' => $locationType,
        ':ld' => $locationDetail,
        ':task' => $taskDetail,
        ':lat' => $lat,
        ':lng' => $lng,
        ':pp' => $photoPath,
        ':po' => $photoOriginal,
        ':ip' => $clientIp,
        ':ua' => $userAgent,
    ]);

    return (int)$pdo->lastInsertId();
}

function update_checkout(
    PDO $pdo,
    int $teacherId,
    string $taskDetail,
    ?float $lat,
    ?float $lng,
    ?string $photoPath,
    ?string $photoOriginal,
    string $clientIp,
    string $userAgent
): void {
    $log = get_today_log($pdo, $teacherId);
    if (!is_array($log) || empty($log['check_in_at'])) {
        throw new RuntimeException('ยังไม่ได้ลงชื่อเข้า');
    }
    if (!empty($log['check_out_at'])) {
        throw new RuntimeException('ลงชื่อออกแล้วสำหรับวันนี้');
    }

    $stmt = $pdo->prepare(
        'UPDATE attendance_logs
        SET check_out_at = :cout,
            checkout_lat = :lat,
            checkout_lng = :lng,
            checkout_photo_path = :pp,
            checkout_photo_original = :po,
            task_detail = :task,
            client_ip = :ip,
            user_agent = :ua
        WHERE id = :id AND teacher_id = :tid'
    );
    $stmt->execute([
        ':cout' => now_datetime(),
        ':lat' => $lat,
        ':lng' => $lng,
        ':pp' => $photoPath,
        ':po' => $photoOriginal,
        ':task' => $taskDetail,
        ':ip' => $clientIp,
        ':ua' => $userAgent,
        ':id' => (int)$log['id'],
        ':tid' => $teacherId,
    ]);
}

function time_to_minutes(?string $datetime): ?int
{
    if (!is_string($datetime) || trim($datetime) === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime);
    if (!$dt) {
        $dt = new DateTimeImmutable($datetime);
    }
    $h = (int)$dt->format('H');
    $m = (int)$dt->format('i');
    return ($h * 60) + $m;
}

function attendance_flags(array $row, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now');

    $lateAfter = (8 * 60) + 30;
    $earlyBefore = (15 * 60) + 30;
    $missingAfter = (18 * 60) + 0;

    $checkInAt = isset($row['check_in_at']) ? (string)$row['check_in_at'] : null;
    $checkOutAt = isset($row['check_out_at']) ? (string)$row['check_out_at'] : null;
    $workDate = isset($row['work_date']) ? (string)$row['work_date'] : '';

    $flags = [];

    $inMin = time_to_minutes($checkInAt);
    if ($inMin !== null && $inMin > $lateAfter) {
        $flags[] = 'late';
    }

    $outMin = time_to_minutes($checkOutAt);
    if ($outMin !== null) {
        if ($outMin > $missingAfter) {
            return ['missing_checkout'];
        }
        if ($outMin < $earlyBefore) {
            $flags[] = 'early_checkout';
        }
        return $flags;
    }

    if ($workDate !== '') {
        $today = $now->format('Y-m-d');
        if ($workDate < $today) {
            return ['missing_checkout'];
        }
        if ($workDate === $today) {
            $nowMin = ((int)$now->format('H') * 60) + (int)$now->format('i');
            if ($nowMin >= $missingAfter) {
                return ['missing_checkout'];
            }
        }
    }

    return $flags;
}

function attendance_status_text(array $row, ?DateTimeImmutable $now = null): string
{
    $flags = attendance_flags($row, $now);
    $labels = [];
    foreach ($flags as $f) {
        if ($f === 'late') {
            $labels[] = 'ลงเวลาสาย';
        } elseif ($f === 'early_checkout') {
            $labels[] = 'ลงเวลาก่อนถึงกำหนด';
        } elseif ($f === 'missing_checkout') {
            $labels[] = 'ไม่ได้ลงเวลากลับ';
        }
    }
    return implode(' / ', $labels);
}
