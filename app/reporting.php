<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PDO;

function dashboard_stats(PDO $pdo): array
{
    $d = today_date();
    $stmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN a.check_in_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_in,
            SUM(CASE WHEN a.check_out_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_out,
            SUM(CASE WHEN a.location_type = "home" THEN 1 ELSE 0 END) AS home_count,
            SUM(CASE WHEN a.location_type = "official" THEN 1 ELSE 0 END) AS official_count,
            SUM(CASE WHEN a.location_type = "workout" THEN 1 ELSE 0 END) AS workout_count,
            SUM(CASE WHEN a.location_type = "other" THEN 1 ELSE 0 END) AS other_count
         FROM attendance_logs a
         WHERE a.work_date = :d'
    );
    $stmt->execute([':d' => $d]);
    $row = $stmt->fetch();
    $row = is_array($row) ? $row : [];

    return [
        'date' => $d,
        'checked_in' => (int)($row['checked_in'] ?? 0),
        'checked_out' => (int)($row['checked_out'] ?? 0),
        'home_count' => (int)($row['home_count'] ?? 0),
        'official_count' => (int)($row['official_count'] ?? 0),
        'workout_count' => (int)($row['workout_count'] ?? 0),
        'other_count' => (int)($row['other_count'] ?? 0),
    ];
}

function search_logs(PDO $pdo, array $filters, int $limit = 2000): array
{
    $where = [];
    $params = [];

    if (!empty($filters['date_from'])) {
        $where[] = 'a.work_date >= :df';
        $params[':df'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'a.work_date <= :dt';
        $params[':dt'] = $filters['date_to'];
    }
    if (!empty($filters['name'])) {
        $where[] = '(t.teacher_fullname LIKE :q OR t.teacher_username LIKE :q)';
        $params[':q'] = '%' . $filters['name'] . '%';
    }
    if (!empty($filters['group_id'])) {
        $where[] = 't.group_id = :gid';
        $params[':gid'] = (int)$filters['group_id'];
    }
    if (!empty($filters['location_type']) && in_array($filters['location_type'], ['home', 'official', 'workout', 'other'], true)) {
        $where[] = 'a.location_type = :lt';
        $params[':lt'] = $filters['location_type'];
    }

    $sql = '
        SELECT
            a.*,
            t.teacher_fullname,
            t.teacher_username,
            t.teacher_position,
            g.group_name
        FROM attendance_logs a
        INNER JOIN teachers t ON t.teacher_id = a.teacher_id
        LEFT JOIN groups g ON g.group_id = t.group_id
    ';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY a.work_date DESC, a.check_in_at DESC LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function export_excel(array $rows, string $title): string
{
    $sheet = new Spreadsheet();
    $ws = $sheet->getActiveSheet();
    $ws->setTitle('Report');

    $headers = [
        'วันที่',
        'ชื่อ-สกุล',
        'กลุ่ม/หน่วยงาน',
        'ตำแหน่ง',
        'ประเภทสถานที่',
        'สถานะ',
        'รายละเอียดสถานที่',
        'ภารกิจ/งานที่ทำ',
        'เวลาเข้า',
        'เวลาออก',
        'พิกัดเข้า',
        'พิกัดออก',
    ];

    $ws->fromArray($headers, null, 'A1');

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            (string)($r['work_date'] ?? ''),
            (string)($r['teacher_fullname'] ?? ''),
            (string)($r['group_name'] ?? ''),
            (string)($r['teacher_position'] ?? ''),
            (string)($r['location_type'] ?? ''),
            attendance_status_text($r),
            (string)($r['location_detail'] ?? ''),
            (string)($r['task_detail'] ?? ''),
            (string)($r['check_in_at'] ?? ''),
            (string)($r['check_out_at'] ?? ''),
            trim(((string)($r['checkin_lat'] ?? '')) . ',' . ((string)($r['checkin_lng'] ?? '')), ','),
            trim(((string)($r['checkout_lat'] ?? '')) . ',' . ((string)($r['checkout_lng'] ?? '')), ','),
        ];
    }
    if ($out) {
        $ws->fromArray($out, null, 'A2');
    }

    $ws->getStyle('A1:L1')->getFont()->setBold(true);
    foreach (range('A', 'L') as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }

    $writer = new Xlsx($sheet);
    ob_start();
    $writer->save('php://output');
    return (string)ob_get_clean();
}

function export_pdf(array $rows, string $title): string
{
    $html = '<html><head><meta charset="utf-8"><style>
        body { font-family: "TH Sarabun New", THSarabunNew, Sarabun, DejaVu Sans, sans-serif; font-size: 12px; }
        h2 { margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
        th { background: #f2f4f8; }
        </style></head><body>';
    $html .= '<h2>' . e($title) . '</h2>';
    $html .= '<table><thead><tr>
        <th>วันที่</th><th>ชื่อ-สกุล</th><th>กลุ่ม</th><th>ประเภท</th><th>สถานะ</th><th>เวลาเข้า</th><th>เวลาออก</th><th>ภารกิจ/งาน</th>
        </tr></thead><tbody>';

    foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td>' . e((string)($r['work_date'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['teacher_fullname'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['group_name'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['location_type'] ?? '')) . '</td>';
        $html .= '<td>' . e(attendance_status_text($r)) . '</td>';
        $html .= '<td>' . e((string)($r['check_in_at'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['check_out_at'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['task_detail'] ?? '')) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';

    $dompdf = new Dompdf(['isRemoteEnabled' => false]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    return $dompdf->output();
}

function active_teacher_count(PDO $pdo): int
{
    $row = $pdo->query('SELECT COUNT(*) AS c FROM teachers WHERE is_active = 1')->fetch();
    return (int)($row['c'] ?? 0);
}

function teachers_with_today_logs(PDO $pdo, string $workDate): array
{
    $stmt = $pdo->prepare(
        'SELECT
            t.teacher_id,
            t.teacher_fullname,
            t.teacher_position,
            t.teacher_username,
            g.group_name,
            a.work_date,
            a.check_in_at,
            a.check_out_at,
            a.location_type,
            a.location_detail,
            a.task_detail
        FROM teachers t
        LEFT JOIN groups g ON g.group_id = t.group_id
        LEFT JOIN attendance_logs a ON a.teacher_id = t.teacher_id AND a.work_date = :d
        WHERE t.is_active = 1
        ORDER BY g.group_name ASC, t.teacher_fullname ASC'
    );
    $stmt->execute([':d' => $workDate]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function daily_attendance_summary(PDO $pdo, string $workDate): array
{
    $total = active_teacher_count($pdo);

    $stmt = $pdo->prepare('SELECT work_date, check_in_at, check_out_at FROM attendance_logs WHERE work_date = :d');
    $stmt->execute([':d' => $workDate]);
    $rows = $stmt->fetchAll();
    $rows = is_array($rows) ? $rows : [];

    $now = new DateTimeImmutable('now');
    $checkedIn = 0;
    $checkedOut = 0;
    $missingCheckout = 0;
    $late = 0;

    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        if (!empty($r['check_in_at'])) {
            $checkedIn++;
        }
        if (!empty($r['check_out_at'])) {
            $checkedOut++;
        }

        $flags = attendance_flags($r, $now);
        if (in_array('missing_checkout', $flags, true)) {
            $missingCheckout++;
        }
        if (in_array('late', $flags, true)) {
            $late++;
        }
    }

    $absent = max(0, $total - $checkedIn);

    return [
        'date' => $workDate,
        'total' => $total,
        'checked_in' => $checkedIn,
        'checked_out' => $checkedOut,
        'missing_checkout' => $missingCheckout,
        'late' => $late,
        'absent' => $absent,
    ];
}

function month_calendar_summary(PDO $pdo, int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $startDt = new DateTimeImmutable($start . ' 00:00:00');
    $endDt = $startDt->modify('last day of this month');
    $end = $endDt->format('Y-m-d');

    $total = active_teacher_count($pdo);
    $now = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');

    $stmt = $pdo->prepare('SELECT work_date, check_in_at, check_out_at FROM attendance_logs WHERE work_date BETWEEN :s AND :e');
    $stmt->execute([':s' => $start, ':e' => $end]);
    $rows = $stmt->fetchAll();
    $rows = is_array($rows) ? $rows : [];

    $daysInMonth = (int)$endDt->format('j');
    $map = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $map[$date] = ['checked_in' => 0, 'checked_out' => 0, 'missing_checkout' => 0, 'late' => 0, 'absent' => 0];
    }

    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $date = (string)($r['work_date'] ?? '');
        if ($date === '' || !isset($map[$date])) {
            continue;
        }
        if (!empty($r['check_in_at'])) {
            $map[$date]['checked_in']++;
        }
        if (!empty($r['check_out_at'])) {
            $map[$date]['checked_out']++;
        }

        $flags = attendance_flags($r, $now);
        if (in_array('missing_checkout', $flags, true)) {
            $map[$date]['missing_checkout']++;
        }
        if (in_array('late', $flags, true)) {
            $map[$date]['late']++;
        }
    }

    foreach ($map as $date => $v) {
        if ($date <= $today) {
            $map[$date]['absent'] = max(0, $total - (int)$v['checked_in']);
        } else {
            $map[$date]['absent'] = 0;
        }
    }

    return [
        'year' => $year,
        'month' => $month,
        'start' => $start,
        'end' => $end,
        'total' => $total,
        'today' => $today,
        'days' => $map,
    ];
}

function location_label_from_type(string $type): string
{
    return match ($type) {
        'home' => 'บ้าน (WFH)',
        'official' => 'ปฏิบัติงานที่โรงเรียน',
        'workout' => 'ไปราชการ',
        default => 'อื่น ๆ',
    };
}

function daily_main_status_text(array $row, ?DateTimeImmutable $now = null): string
{
    $now = $now ?: new DateTimeImmutable('now');
    $cin = (string)($row['check_in_at'] ?? '');
    $cout = (string)($row['check_out_at'] ?? '');

    if ($cin === '') {
        return 'ยังไม่ได้ลงเวลา';
    }
    if ($cout !== '') {
        return 'ลงชื่อกลับแล้ว';
    }

    $flags = attendance_flags($row, $now);
    if (in_array('missing_checkout', $flags, true)) {
        return 'ไม่ได้ลงเวลากลับ';
    }
    return 'ยังไม่ลงเวลากลับ';
}

function export_daily_status_excel(array $rows, string $workDate): string
{
    $sheet = new Spreadsheet();
    $ws = $sheet->getActiveSheet();
    $ws->setTitle('Daily Status');

    $headers = [
        'วันที่',
        'รหัสครู',
        'ชื่อ-สกุล',
        'ตำแหน่ง',
        'กลุ่ม/หน่วยงาน',
        'สถานที่',
        'เวลาเข้า',
        'เวลากลับ',
        'สถานะหลัก',
        'สถานะเพิ่มเติม',
    ];
    $ws->fromArray($headers, null, 'A1');

    $now = new DateTimeImmutable('now');
    $out = [];
    foreach ($rows as $r) {
        $type = (string)($r['location_type'] ?? '');
        $out[] = [
            $workDate,
            (string)($r['teacher_id'] ?? ''),
            (string)($r['teacher_fullname'] ?? ''),
            (string)($r['teacher_position'] ?? ''),
            (string)($r['group_name'] ?? ''),
            $type !== '' ? location_label_from_type($type) : '',
            (string)($r['check_in_at'] ?? ''),
            (string)($r['check_out_at'] ?? ''),
            daily_main_status_text($r, $now),
            attendance_status_text($r, $now),
        ];
    }
    if ($out) {
        $ws->fromArray($out, null, 'A2');
    }

    $ws->getStyle('A1:J1')->getFont()->setBold(true);
    foreach (range('A', 'J') as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }

    $writer = new Xlsx($sheet);
    ob_start();
    $writer->save('php://output');
    return (string)ob_get_clean();
}

function export_daily_status_csv(array $rows, string $workDate): string
{
    $now = new DateTimeImmutable('now');
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, ['วันที่', 'รหัสครู', 'ชื่อ-สกุล', 'ตำแหน่ง', 'กลุ่ม/หน่วยงาน', 'สถานที่', 'เวลาเข้า', 'เวลากลับ', 'สถานะหลัก', 'สถานะเพิ่มเติม']);
    foreach ($rows as $r) {
        $type = (string)($r['location_type'] ?? '');
        fputcsv($fp, [
            $workDate,
            (string)($r['teacher_id'] ?? ''),
            (string)($r['teacher_fullname'] ?? ''),
            (string)($r['teacher_position'] ?? ''),
            (string)($r['group_name'] ?? ''),
            $type !== '' ? location_label_from_type($type) : '',
            (string)($r['check_in_at'] ?? ''),
            (string)($r['check_out_at'] ?? ''),
            daily_main_status_text($r, $now),
            attendance_status_text($r, $now),
        ]);
    }
    rewind($fp);
    return (string)stream_get_contents($fp);
}

function export_daily_status_pdf(array $rows, string $workDate): string
{
    $now = new DateTimeImmutable('now');
    $html = '<html><head><meta charset="utf-8"><style>
        body { font-family: "TH Sarabun New", THSarabunNew, Sarabun, DejaVu Sans, sans-serif; font-size: 12px; }
        h2 { margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
        th { background: #f2f4f8; }
        </style></head><body>';
    $html .= '<h2>สถานะการลงชื่อ (ทั้งโรงเรียน) วันที่ ' . e($workDate) . '</h2>';
    $html .= '<table><thead><tr>
        <th>รหัส</th><th>ชื่อ-สกุล</th><th>หน่วยงาน</th><th>เข้า</th><th>กลับ</th><th>สถานะ</th>
        </tr></thead><tbody>';
    foreach ($rows as $r) {
        $type = (string)($r['location_type'] ?? '');
        $status = daily_main_status_text($r, $now);
        $extra = attendance_status_text($r, $now);
        $statusText = trim($status . ($extra !== '' ? ' / ' . $extra : ''));
        $html .= '<tr>';
        $html .= '<td>' . e((string)($r['teacher_id'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['teacher_fullname'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['group_name'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['check_in_at'] ?? '')) . '</td>';
        $html .= '<td>' . e((string)($r['check_out_at'] ?? '')) . '</td>';
        $html .= '<td>' . e($statusText) . ($type !== '' ? '<div style="color:#666">' . e(location_label_from_type($type)) . '</div>' : '') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';

    $dompdf = new Dompdf(['isRemoteEnabled' => false]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}
