<?php

declare(strict_types=1);

$ctx = require __DIR__ . '/../app/bootstrap.php';
$config = $ctx['config'];
$pdo = $ctx['pdo'];

require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/attendance.php';
require_once __DIR__ . '/../app/reporting.php';

use function App\attempt_login;
use function App\csrf_check;
use function App\csrf_token;
use function App\current_user;
use function App\dashboard_stats;
use function App\e;
use function App\flash_get;
use function App\flash_set;
use function App\get_today_log;
use function App\is_logged_in;
use function App\attendance_flags;
use function App\now_datetime;
use function App\teachers_with_today_logs;
use function App\daily_attendance_summary;
use function App\month_calendar_summary;
use function App\export_daily_status_excel;
use function App\export_daily_status_pdf;
use function App\export_daily_status_csv;
use function App\require_admin;
use function App\require_login;
use function App\search_logs;
use function App\today_date;
use function App\update_checkout;
use function App\url;
use function App\create_checkin;
use function App\export_excel;
use function App\export_pdf;

$page = (string)($_GET['page'] ?? '');
if ($page === '') {
    $page = is_logged_in() ? 'dashboard' : 'login';
}

$stmt = $pdo->query('SELECT COUNT(*) AS c FROM teachers');
$teacherCountRow = $stmt->fetch();
$teacherCount = (int)($teacherCountRow['c'] ?? 0);
if ($teacherCount === 0 && $page !== 'setup') {
    header('Location: ' . url($config, '/index.php?page=setup'));
    exit;
}

function nav_active(string $current, string $target): string
{
    return $current === $target ? 'active' : '';
}

function location_label(string $type): string
{
    return match ($type) {
        'home' => 'บ้าน (WFH)',
        'official' => 'ปฏิบัติงานที่โรงเรียน',
        'workout' => 'ไปราชการ',
        default => 'อื่น ๆ',
    };
}

function badge_class(string $type): string
{
    return match ($type) {
        'home' => 'bg-primary-subtle text-primary',
        'official' => 'bg-warning-subtle text-warning-emphasis',
        'workout' => 'bg-success-subtle text-success-emphasis',
        default => 'bg-secondary-subtle text-secondary',
    };
}

function status_badges(array $row): string
{
    $flags = attendance_flags($row);
    if (!$flags) {
        return '';
    }

    $out = [];
    foreach ($flags as $f) {
        if ($f === 'missing_checkout') {
            $out[] = '<span class="badge bg-dark-subtle text-dark">ไม่ได้ลงเวลากลับ</span>';
        } elseif ($f === 'late') {
            $out[] = '<span class="badge bg-danger-subtle text-danger">ลงเวลาสาย</span>';
        } elseif ($f === 'early_checkout') {
            $out[] = '<span class="badge bg-warning-subtle text-warning-emphasis">ลงเวลาก่อนถึงกำหนด</span>';
        }
    }

    return implode(' ', $out);
}

function main_status_badge(array $row): string
{
    $checkIn = (string)($row['check_in_at'] ?? '');
    $checkOut = (string)($row['check_out_at'] ?? '');
    if ($checkIn === '') {
        return '<span class="badge bg-secondary-subtle text-secondary">ยังไม่ได้ลงเวลา</span>';
    }
    if ($checkOut !== '') {
        return '<span class="badge bg-success-subtle text-success">ลงชื่อกลับแล้ว</span>';
    }

    $flags = attendance_flags($row);
    if (in_array('missing_checkout', $flags, true)) {
        return '<span class="badge bg-dark-subtle text-dark">ไม่ได้ลงเวลากลับ</span>';
    }
    return '<span class="badge bg-info-subtle text-info-emphasis">ยังไม่ลงเวลากลับ</span>';
}

function build_month_calendar_html(array $cal): string
{
    $year = (int)($cal['year'] ?? 0);
    $month = (int)($cal['month'] ?? 0);
    $today = (string)($cal['today'] ?? '');
    $days = is_array($cal['days'] ?? null) ? $cal['days'] : [];

    $first = sprintf('%04d-%02d-01', $year, $month);
    $firstDt = new DateTimeImmutable($first);
    $startWeekday = (int)$firstDt->format('N');
    $daysInMonth = (int)$firstDt->modify('last day of this month')->format('j');

    $labels = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];

    $html = '<div class="table-responsive"><table class="table table-borderless align-middle mb-0">';
    $html .= '<thead><tr>';
    foreach ($labels as $l) {
        $html .= '<th class="text-center small text-muted">' . e($l) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    $day = 1;
    $cell = 1;
    while ($day <= $daysInMonth) {
        $html .= '<tr>';
        for ($col = 1; $col <= 7; $col++, $cell++) {
            if ($cell < $startWeekday || $day > $daysInMonth) {
                $html .= '<td></td>';
                continue;
            }

            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $v = isset($days[$date]) && is_array($days[$date]) ? $days[$date] : ['checked_in' => 0, 'checked_out' => 0, 'missing_checkout' => 0, 'absent' => 0];
            $isToday = ($date === $today);
            $isFuture = ($today !== '' && $date > $today);

            $wrapClass = $isToday ? 'bg-primary-subtle' : 'bg-white';
            $borderClass = $isToday ? 'border border-primary-subtle' : 'border border-light';
            $textClass = $isFuture ? 'text-muted' : 'text-dark';

            $html .= '<td class="p-1">';
            $html .= '<div class="' . e($wrapClass) . ' ' . e($borderClass) . ' rounded-14 p-2" style="min-height:92px">';
            $html .= '<div class="d-flex align-items-center justify-content-between mb-1">';
            $html .= '<div class="fw-semibold ' . e($textClass) . '">' . e((string)$day) . '</div>';
            if ($isToday) {
                $html .= '<span class="badge badge-gov">วันนี้</span>';
            }
            $html .= '</div>';

            if ($isFuture) {
                $html .= '<div class="small text-muted">-</div>';
            } else {
                $html .= '<div class="small">';
                $html .= '<div>มา: <span class="fw-semibold">' . e((string)((int)($v['checked_in'] ?? 0))) . '</span></div>';
                $html .= '<div>กลับ: <span class="fw-semibold">' . e((string)((int)($v['checked_out'] ?? 0))) . '</span></div>';
                $html .= '<div>ไม่ลงกลับ: <span class="fw-semibold">' . e((string)((int)($v['missing_checkout'] ?? 0))) . '</span></div>';
                $html .= '<div>ขาด: <span class="fw-semibold">' . e((string)((int)($v['absent'] ?? 0))) . '</span></div>';
                $html .= '</div>';
            }

            $html .= '</div></td>';
            $day++;
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
}

function groups(PDO $pdo): array
{
    $rows = $pdo->query('SELECT group_id, group_name FROM groups ORDER BY group_name ASC')->fetchAll();
    return is_array($rows) ? $rows : [];
}

function ensure_upload_dir(array $config): void
{
    $dir = (string)($config['app']['upload_dir'] ?? '');
    if ($dir === '') {
        return;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function handle_upload(array $config, string $field): ?array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ');
    }

    $maxMb = (int)($config['app']['max_upload_mb'] ?? 5);
    $maxBytes = $maxMb * 1024 * 1024;
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('ขนาดไฟล์เกินกำหนด (' . $maxMb . 'MB)');
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    $original = (string)($f['name'] ?? '');
    $mime = mime_content_type($tmp) ?: '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('รองรับเฉพาะไฟล์รูปภาพ (JPG/PNG/WEBP)');
    }

    ensure_upload_dir($config);
    $baseDir = rtrim((string)$config['app']['upload_dir'], '\\/');
    $sub = date('Y/m');
    $destDir = $baseDir . DIRECTORY_SEPARATOR . $sub;
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }

    $ext = $allowed[$mime];
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    $dest = $destDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
    }

    $relative = $sub . '/' . $name;
    return ['path' => $relative, 'original' => $original];
}

function render_layout(array $config, string $title, string $contentHtml, string $activePage = ''): void
{
    $user = current_user();
    $flash = flash_get('main');
    $isAdmin = (int)($user['is_admin'] ?? 0) === 1;

    $appName = (string)($config['app']['name'] ?? 'WFH');
    $fullTitle = $title === '' ? $appName : ($title . ' | ' . $appName);

    $base = url($config, '/index.php');
    $assetCss = url($config, '/assets/app.css');
    $assetJs = url($config, '/assets/app.js');
    $logoUrl = url($config, '/assets/logo.png');

    echo '<!doctype html><html lang="th"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($fullTitle) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">';
    echo '<link href="' . e($assetCss) . '" rel="stylesheet">';
    echo '</head><body>';

    echo '<div class="d-flex">';
    echo '<aside class="sidebar d-none d-lg-block p-3" style="width:280px">';
    echo '<div class="d-flex align-items-center gap-2 mb-4">';
    echo '<img class="brand-mark" src="' . e($logoUrl) . '" alt="logo">';
    echo '<div><div class="fw-bold text-gov small">' . e($appName) . '</div>';
    echo '<div class="text-muted small">โรงเรียนลำปลายมาศ</div></div></div>';

    if ($user) {
        echo '<div class="card p-3 mb-3">';
        echo '<div class="fw-semibold">' . e((string)($user['teacher_fullname'] ?? '')) . '</div>';
        echo '<div class="text-muted small">' . e((string)($user['teacher_position'] ?? '')) . '</div>';
        echo $isAdmin ? '<span class="badge badge-gov mt-2">Admin</span>' : '';
        echo '</div>';
    }

    echo '<nav class="nav flex-column gap-1">';
    echo '<a class="nav-link ' . e(nav_active($activePage, 'dashboard')) . '" href="' . e($base . '?page=dashboard') . '"><i class="fa-solid fa-gauge me-2"></i>Dashboard</a>';
    echo '<a class="nav-link ' . e(nav_active($activePage, 'check')) . '" href="' . e($base . '?page=check') . '"><i class="fa-solid fa-user-check me-2"></i>ลงชื่อปฏิบัติงาน</a>';
    echo '<a class="nav-link ' . e(nav_active($activePage, 'history')) . '" href="' . e($base . '?page=history') . '"><i class="fa-solid fa-clock-rotate-left me-2"></i>ประวัติของฉัน</a>';
    if ($isAdmin) {
        echo '<div class="text-muted small mt-3 mb-1">ผู้ดูแลระบบ</div>';
        echo '<a class="nav-link ' . e(nav_active($activePage, 'reports')) . '" href="' . e($base . '?page=reports') . '"><i class="fa-solid fa-file-lines me-2"></i>รายงาน</a>';
        echo '<a class="nav-link ' . e(nav_active($activePage, 'admin_users')) . '" href="' . e($base . '?page=admin_users') . '"><i class="fa-solid fa-users-gear me-2"></i>จัดการผู้ใช้งาน</a>';
        echo '<a class="nav-link ' . e(nav_active($activePage, 'admin_import')) . '" href="' . e($base . '?page=admin_import') . '"><i class="fa-solid fa-file-import me-2"></i>นำเข้าครู (Excel)</a>';
    }
    echo '<a class="nav-link mt-3" href="' . e($base . '?page=logout') . '"><i class="fa-solid fa-right-from-bracket me-2"></i>ออกจากระบบ</a>';
    echo '</nav>';
    echo '</aside>';

    echo '<main class="flex-grow-1">';
    echo '<div class="topbar p-3">';
    echo '<div class="container-fluid">';
    echo '<div class="d-flex align-items-center justify-content-between">';
    echo '<div class="d-flex align-items-center gap-2">';
    echo '<a class="btn btn-light d-lg-none" data-bs-toggle="offcanvas" href="#mobileNav" role="button"><i class="fa-solid fa-bars"></i></a>';
    echo '<div class="fw-semibold">' . e($title) . '</div>';
    echo '</div>';
    echo '<div class="text-muted small">' . e(now_datetime()) . '</div>';
    echo '</div></div></div>';

    echo '<div class="container-fluid py-4">';
    if ($flash) {
        $type = (string)($flash['type'] ?? 'info');
        $msg = (string)($flash['message'] ?? '');
        echo '<div class="alert alert-' . e($type) . ' rounded-14">' . e($msg) . '</div>';
    }
    echo $contentHtml;
    echo '</div></main></div>';

    echo '<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileNav">';
    echo '<div class="offcanvas-header">';
    echo '<h5 class="offcanvas-title">' . e($appName) . '</h5>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>';
    echo '</div><div class="offcanvas-body">';
    echo '<div class="d-grid gap-2">';
    echo '<a class="btn btn-outline-primary" href="' . e($base . '?page=dashboard') . '">Dashboard</a>';
    echo '<a class="btn btn-outline-primary" href="' . e($base . '?page=check') . '">ลงชื่อปฏิบัติงาน</a>';
    echo '<a class="btn btn-outline-primary" href="' . e($base . '?page=history') . '">ประวัติของฉัน</a>';
    if ($isAdmin) {
        echo '<a class="btn btn-outline-warning" href="' . e($base . '?page=reports') . '">รายงาน</a>';
        echo '<a class="btn btn-outline-warning" href="' . e($base . '?page=admin_users') . '">จัดการผู้ใช้งาน</a>';
        echo '<a class="btn btn-outline-warning" href="' . e($base . '?page=admin_import') . '">นำเข้าครู (Excel)</a>';
    }
    echo '<a class="btn btn-outline-secondary" href="' . e($base . '?page=logout') . '">ออกจากระบบ</a>';
    echo '</div></div></div>';

    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script src="' . e($assetJs) . '"></script>';
    echo '</body></html>';
}

if ($page === 'setup') {
    if ($teacherCount > 0) {
        header('Location: ' . url($config, '/index.php?page=login'));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check($_POST['_csrf'] ?? null)) {
            flash_set('main', 'CSRF ไม่ถูกต้อง', 'danger');
            header('Location: ' . url($config, '/index.php?page=setup'));
            exit;
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $fullname = trim((string)($_POST['fullname'] ?? ''));
        $position = trim((string)($_POST['position'] ?? 'Admin'));
        $groupId = (int)($_POST['group_id'] ?? 0);

        if ($username === '' || $password === '' || $fullname === '') {
            flash_set('main', 'กรุณากรอกข้อมูลให้ครบ', 'warning');
            header('Location: ' . url($config, '/index.php?page=setup'));
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO teachers (teacher_username, teacher_password, teacher_fullname, group_id, teacher_position, is_admin, is_active) VALUES (:u,:p,:f,:g,:pos,1,1)');
        $stmt->execute([
            ':u' => $username,
            ':p' => $hash,
            ':f' => $fullname,
            ':g' => $groupId > 0 ? $groupId : null,
            ':pos' => $position,
        ]);

        flash_set('main', 'สร้างผู้ดูแลระบบสำเร็จ สามารถเข้าสู่ระบบได้ทันที', 'success');
        header('Location: ' . url($config, '/index.php?page=login'));
        exit;
    }

    $groupOptions = groups($pdo);
    $csrf = csrf_token();
    $assetCss = url($config, '/assets/app.css');

    echo '<!doctype html><html lang="th"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>ตั้งค่าครั้งแรก</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="' . e($assetCss) . '" rel="stylesheet">';
    echo '</head><body class="d-flex align-items-center" style="min-height:100vh">';
    echo '<div class="container"><div class="row justify-content-center"><div class="col-md-6 col-lg-5">';
    echo '<div class="card p-4">';
    echo '<div class="mb-3"><div class="fw-bold text-gov h5 mb-1">ตั้งค่าครั้งแรก</div><div class="text-muted small">สร้างบัญชีผู้ดูแลระบบ (Admin)</div></div>';
    $flash = flash_get('main');
    if ($flash) {
        echo '<div class="alert alert-' . e((string)$flash['type']) . '">' . e((string)$flash['message']) . '</div>';
    }
    echo '<form method="post">';
    echo '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
    echo '<div class="mb-3"><label class="form-label">ชื่อผู้ใช้</label><input class="form-control" name="username" required></div>';
    echo '<div class="mb-3"><label class="form-label">รหัสผ่าน</label><input class="form-control" type="password" name="password" required></div>';
    echo '<div class="mb-3"><label class="form-label">ชื่อ-สกุล</label><input class="form-control" name="fullname" required></div>';
    echo '<div class="mb-3"><label class="form-label">ตำแหน่ง</label><input class="form-control" name="position" value="Admin"></div>';
    echo '<div class="mb-3"><label class="form-label">กลุ่ม/หน่วยงาน</label><select class="form-select" name="group_id"><option value="0">- เลือก -</option>';
    foreach ($groupOptions as $g) {
        echo '<option value="' . e((string)$g['group_id']) . '">' . e((string)$g['group_name']) . '</option>';
    }
    echo '</select></div>';
    echo '<button class="btn btn-gov w-100" type="submit">สร้าง Admin</button>';
    echo '</form></div></div></div></div></div>';
    echo '</body></html>';
    exit;
}

if ($page === 'logout') {
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
    header('Location: ' . url($config, '/index.php?page=login'));
    exit;
}

if ($page === 'login') {
    if (is_logged_in()) {
        header('Location: ' . url($config, '/index.php?page=dashboard'));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check($_POST['_csrf'] ?? null)) {
            flash_set('main', 'CSRF ไม่ถูกต้อง', 'danger');
            header('Location: ' . url($config, '/index.php?page=login'));
            exit;
        }

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $user = attempt_login($pdo, $username, $password);
        if (!$user) {
            flash_set('main', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'danger');
            header('Location: ' . url($config, '/index.php?page=login'));
            exit;
        }
        $_SESSION['user'] = $user;
        flash_set('main', 'เข้าสู่ระบบสำเร็จ', 'success');
        header('Location: ' . url($config, '/index.php?page=dashboard'));
        exit;
    }

    $csrf = csrf_token();
    $appName = (string)($config['app']['name'] ?? 'WFH');
    $assetCss = url($config, '/assets/app.css');
    $logoUrl = url($config, '/assets/logo.png');

    echo '<!doctype html><html lang="th"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Login | ' . e($appName) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">';
    echo '<link href="' . e($assetCss) . '" rel="stylesheet">';
    echo '</head><body class="d-flex align-items-center" style="min-height:100vh">';
    echo '<div class="container"><div class="row justify-content-center"><div class="col-md-6 col-lg-5">';
    echo '<div class="card p-4">';
    echo '<div class="d-flex align-items-center gap-2 mb-3">';
    echo '<img class="brand-mark" src="' . e($logoUrl) . '" alt="logo">';
    echo '<div><div class="fw-bold text-gov h5 mb-0">เข้าสู่ระบบ</div><div class="text-muted small">ระบบลงชื่อปฏิบัติงานออนไลน์</div></div>';
    echo '</div>';
    $flash = flash_get('main');
    if ($flash) {
        echo '<div class="alert alert-' . e((string)$flash['type']) . ' rounded-14">' . e((string)$flash['message']) . '</div>';
    }
    echo '<form method="post">';
    echo '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
    echo '<div class="mb-3"><label class="form-label">ชื่อผู้ใช้</label><input class="form-control" name="username" autocomplete="username" required></div>';
    echo '<div class="mb-3"><label class="form-label">รหัสผ่าน</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div>';
    echo '<button class="btn btn-gov w-100" type="submit"><i class="fa-solid fa-right-to-bracket me-2"></i>Login</button>';
    echo '</form>';
    echo '<div class="text-muted small mt-3">รองรับมือถือและคอมพิวเตอร์</div>';
    echo '</div></div></div></div>';
    echo '</body></html>';
    exit;
}

require_login($config);

$user = current_user();
$teacherId = (int)($user['teacher_id'] ?? 0);
$isAdmin = (int)($user['is_admin'] ?? 0) === 1;

if ($page === 'dashboard') {
    $stats = dashboard_stats($pdo);
    $today = get_today_log($pdo, $teacherId);
    $todayFlags = $today ? status_badges($today) : '';
    $todayDate = (string)($stats['date'] ?? today_date());

    $statusHtml = '<div class="card p-4 h-100">';
    $statusHtml .= '<div class="d-flex align-items-center justify-content-between mb-2">';
    $statusHtml .= '<div class="fw-semibold">สถานะวันนี้</div>';
    $statusHtml .= '<span class="badge badge-gov">' . e($stats['date']) . '</span>';
    $statusHtml .= '</div>';

    if (!$today) {
        $statusHtml .= '<div class="text-muted">ยังไม่มีการลงชื่อเข้า</div>';
        $statusHtml .= '<a class="btn btn-gov mt-3" href="' . e(url($config, '/index.php?page=check')) . '"><i class="fa-solid fa-user-check me-2"></i>ไปหน้าลงชื่อ</a>';
    } else {
        $statusHtml .= '<div class="mb-1"><span class="badge ' . e(badge_class((string)$today['location_type'])) . '">' . e(location_label((string)$today['location_type'])) . '</span></div>';
        if ($todayFlags !== '') {
            $statusHtml .= '<div class="d-flex flex-wrap gap-2 mb-1">' . $todayFlags . '</div>';
        }
        $statusHtml .= '<div class="small text-muted">เข้า: ' . e((string)($today['check_in_at'] ?? '-')) . '</div>';
        $statusHtml .= '<div class="small text-muted">ออก: ' . e((string)($today['check_out_at'] ?? '-')) . '</div>';
        $statusHtml .= '<div class="mt-3"><a class="btn btn-outline-primary" href="' . e(url($config, '/index.php?page=check')) . '">จัดการการลงชื่อวันนี้</a></div>';
    }
    $statusHtml .= '</div>';

    $cards = '';
    $cards .= '<div class="row g-3 mb-3">';
    $cards .= '<div class="col-12 col-md-3"><div class="card p-3"><div class="d-flex align-items-center justify-content-between">';
    $cards .= '<div><div class="text-muted small">ลงชื่อเข้า (วันนี้)</div><div class="h4 fw-bold mb-0">' . e((string)$stats['checked_in']) . '</div></div>';
    $cards .= '<div class="stat-icon"><i class="fa-solid fa-right-to-bracket"></i></div></div></div></div>';
    $cards .= '<div class="col-12 col-md-3"><div class="card p-3"><div class="d-flex align-items-center justify-content-between">';
    $cards .= '<div><div class="text-muted small">ลงชื่อออก (วันนี้)</div><div class="h4 fw-bold mb-0">' . e((string)$stats['checked_out']) . '</div></div>';
    $cards .= '<div class="stat-icon"><i class="fa-solid fa-right-from-bracket"></i></div></div></div></div>';
    $cards .= '<div class="col-12 col-md-3"><div class="card p-3"><div class="d-flex align-items-center justify-content-between">';
    $cards .= '<div><div class="text-muted small">WFH</div><div class="h4 fw-bold mb-0">' . e((string)$stats['home_count']) . '</div></div>';
    $cards .= '<div class="stat-icon"><i class="fa-solid fa-house"></i></div></div></div></div>';
    $cards .= '<div class="col-12 col-md-3"><div class="card p-3"><div class="d-flex align-items-center justify-content-between">';
    $cards .= '<div><div class="text-muted small">ไปราชการ</div><div class="h4 fw-bold mb-0">' . e((string)($stats['workout_count'] ?? 0)) . '</div></div>';
    $cards .= '<div class="stat-icon"><i class="fa-solid fa-briefcase"></i></div></div></div></div>';
    $cards .= '</div>';

    $chart = '<div class="card p-4 h-100">';
    $chart .= '<div class="fw-semibold mb-2">สรุปภาพรวม (วันนี้)</div>';
    $otherCount = (int)($stats['checked_in'] ?? 0) - (int)($stats['home_count'] ?? 0) - (int)($stats['official_count'] ?? 0) - (int)($stats['workout_count'] ?? 0);
    if ($otherCount < 0) {
        $otherCount = 0;
    }

    $doughnutPayload = json_encode([
        'labels' => ['บ้าน (WFH)', 'ปฏิบัติงานที่โรงเรียน', 'ไปราชการ', 'อื่น ๆ'],
        'data' => [(int)($stats['home_count'] ?? 0), (int)($stats['official_count'] ?? 0), (int)($stats['workout_count'] ?? 0), (int)$otherCount],
        'colors' => ['#0b2a5b', '#f6c400', '#198754', '#adb5bd'],
    ], JSON_UNESCAPED_UNICODE);
    $chart .= '<canvas id="todayChart" height="180" data-doughnut=\'' . e((string)$doughnutPayload) . '\'></canvas>';
    $chart .= '<div class="d-flex flex-wrap gap-3 justify-content-center mt-3 small text-muted">';
    $chart .= '<span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#0b2a5b;margin-right:6px"></span>บ้าน (WFH)</span>';
    $chart .= '<span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#f6c400;margin-right:6px"></span>ปฏิบัติงานที่โรงเรียน</span>';
    $chart .= '<span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#198754;margin-right:6px"></span>ไปราชการ</span>';
    $chart .= '<span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#adb5bd;margin-right:6px"></span>อื่น ๆ</span>';
    $chart .= '</div>';
    $chart .= '</div>';

    $content = $cards;
    $content .= '<div class="row g-3">';
    $content .= '<div class="col-12 col-lg-5">' . $statusHtml . '</div>';
    $content .= '<div class="col-12 col-lg-7">' . $chart . '</div>';
    $content .= '</div>';

    if ($isAdmin) {
        $workDate = (string)($_GET['work_date'] ?? $todayDate);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $workDate = $todayDate;
        }

        $summary = daily_attendance_summary($pdo, $workDate);
        $teacherRows = teachers_with_today_logs($pdo, $workDate);
        $cal = month_calendar_summary($pdo, (int)date('Y'), (int)date('m'));

        $adminSummary = '<div class="card p-4 h-100">';
        $adminSummary .= '<div class="d-flex align-items-center justify-content-between mb-2">';
        $adminSummary .= '<div class="fw-semibold">สรุป (ทั้งโรงเรียน)</div>';
        $adminSummary .= '<span class="badge badge-gov">' . e((string)($summary['date'] ?? $workDate)) . '</span>';
        $adminSummary .= '</div>';
        $adminSummary .= '<div class="row g-2">';
        $adminSummary .= '<div class="col-6"><div class="p-3 rounded-14 bg-white border border-light"><div class="text-muted small">มาทำงาน</div><div class="h4 fw-bold mb-0">' . e((string)($summary['checked_in'] ?? 0)) . '</div></div></div>';
        $adminSummary .= '<div class="col-6"><div class="p-3 rounded-14 bg-white border border-light"><div class="text-muted small">ลงชื่อกลับ</div><div class="h4 fw-bold mb-0">' . e((string)($summary['checked_out'] ?? 0)) . '</div></div></div>';
        $adminSummary .= '<div class="col-6"><div class="p-3 rounded-14 bg-white border border-light"><div class="text-muted small">ไม่ลงเวลากลับ</div><div class="h4 fw-bold mb-0">' . e((string)($summary['missing_checkout'] ?? 0)) . '</div></div></div>';
        $adminSummary .= '<div class="col-6"><div class="p-3 rounded-14 bg-white border border-light"><div class="text-muted small">ขาด/ยังไม่ได้ลงเวลา</div><div class="h4 fw-bold mb-0">' . e((string)($summary['absent'] ?? 0)) . '</div></div></div>';
        $adminSummary .= '</div>';
        $adminSummary .= '<div class="d-flex flex-wrap gap-2 mt-3">';
        $adminSummary .= '<span class="badge bg-danger-subtle text-danger">ลงเวลาสาย: ' . e((string)($summary['late'] ?? 0)) . '</span>';
        $adminSummary .= '<span class="badge bg-secondary-subtle text-secondary">ครูทั้งหมด (Active): ' . e((string)($summary['total'] ?? 0)) . '</span>';
        $adminSummary .= '</div>';
        $adminSummary .= '</div>';

        $calendarHtml = '<div class="card p-4 h-100">';
        $calendarHtml .= '<div class="d-flex align-items-center justify-content-between mb-2">';
        $calendarHtml .= '<div class="fw-semibold">ปฏิทินสรุป (รายวัน)</div>';
        $calendarHtml .= '<div class="text-muted small">' . e((string)($cal['year'] ?? '')) . '-' . e(str_pad((string)($cal['month'] ?? ''), 2, '0', STR_PAD_LEFT)) . '</div>';
        $calendarHtml .= '</div>';
        $calendarHtml .= build_month_calendar_html($cal);
        $calendarHtml .= '</div>';

        $exportBase = url($config, '/index.php?page=export_daily_status');
        $excelUrl = $exportBase . '&format=excel&work_date=' . rawurlencode($workDate);
        $pdfUrl = $exportBase . '&format=pdf&work_date=' . rawurlencode($workDate);
        $csvUrl = $exportBase . '&format=csv&work_date=' . rawurlencode($workDate);

        $teacherTable = '<div class="card p-4">';
        $teacherTable .= '<div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-3">';
        $teacherTable .= '<div><div class="fw-bold h5 mb-1">สถานะการลงชื่อ (ครูทั้งโรงเรียน)</div><div class="text-muted small">เลือกวันที่ + ค้นหา/จัดหน้า + ส่งออก Excel/PDF/CSV</div></div>';
        $teacherTable .= '<form class="d-flex flex-wrap gap-2" method="get" action="' . e(url($config, '/index.php')) . '">';
        $teacherTable .= '<input type="hidden" name="page" value="dashboard">';
        $teacherTable .= '<div><label class="form-label small text-muted mb-1">วันที่</label><input class="form-control" type="date" name="work_date" value="' . e($workDate) . '" style="min-width:160px"></div>';
        $teacherTable .= '<div class="d-flex align-items-end gap-2">';
        $teacherTable .= '<button class="btn btn-gov" type="submit"><i class="fa-solid fa-calendar-day me-2"></i>แสดง</button>';
        $teacherTable .= '<div class="btn-group" role="group">';
        $teacherTable .= '<a class="btn btn-outline-success" href="' . e($excelUrl) . '"><i class="fa-solid fa-file-excel me-2"></i>Excel</a>';
        $teacherTable .= '<a class="btn btn-outline-danger" href="' . e($pdfUrl) . '"><i class="fa-solid fa-file-pdf me-2"></i>PDF</a>';
        $teacherTable .= '<a class="btn btn-outline-secondary" href="' . e($csvUrl) . '"><i class="fa-solid fa-file-csv me-2"></i>CSV</a>';
        $teacherTable .= '</div>';
        $teacherTable .= '</div>';
        $teacherTable .= '</form>';
        $teacherTable .= '</div>';

        $teacherTable .= '<div data-dt>';
        $teacherTable .= '<div class="row g-2 align-items-end mb-3">';
        $teacherTable .= '<div class="col-12 col-md-5"><label class="form-label small text-muted mb-1">ค้นหา</label><input class="form-control" data-dt-search placeholder="ค้นหา ชื่อ/หน่วยงาน/สถานะ..."></div>';
        $teacherTable .= '<div class="col-12 col-md-2"><label class="form-label small text-muted mb-1">แสดง</label><select class="form-select" data-dt-length><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option></select></div>';
        $teacherTable .= '<div class="col-12 col-md-5 d-flex justify-content-md-end align-items-end"><div class="small text-muted" data-dt-info></div></div>';
        $teacherTable .= '</div>';

        $teacherTable .= '<div class="table-responsive"><table class="table align-middle mb-0" data-dt-table>';
        $teacherTable .= '<thead><tr><th data-dt-sort>ครู</th><th data-dt-sort>หน่วยงาน</th><th data-dt-sort>เข้า</th><th data-dt-sort>กลับ</th><th data-dt-sort>สถานะ</th></tr></thead><tbody>';
        foreach ($teacherRows as $r) {
            $name = (string)($r['teacher_fullname'] ?? '');
            $pos = (string)($r['teacher_position'] ?? '');
            $group = (string)($r['group_name'] ?? '');
            $cin = (string)($r['check_in_at'] ?? '');
            $cout = (string)($r['check_out_at'] ?? '');
            $locType = (string)($r['location_type'] ?? '');

            $teacherTable .= '<tr>';
            $teacherTable .= '<td class="fw-semibold">' . e($name) . '<div class="text-muted small">' . e($pos) . '</div></td>';
            $teacherTable .= '<td>' . e($group) . '</td>';
            $teacherTable .= '<td data-sort="' . e($cin) . '">' . e($cin !== '' ? $cin : '-') . '</td>';
            $teacherTable .= '<td data-sort="' . e($cout) . '">' . e($cout !== '' ? $cout : '-') . '</td>';
            $teacherTable .= '<td class="d-flex flex-wrap gap-2">';
            $teacherTable .= main_status_badge($r);
            if ($cin !== '' && $locType !== '') {
                $teacherTable .= '<span class="badge ' . e(badge_class($locType)) . '">' . e(location_label($locType)) . '</span>';
            }
            $flags = status_badges($r);
            if ($flags !== '') {
                $teacherTable .= $flags;
            }
            $teacherTable .= '</td>';
            $teacherTable .= '</tr>';
        }
        if (!$teacherRows) {
            $teacherTable .= '<tr><td colspan="5" class="text-muted">ไม่พบข้อมูล</td></tr>';
        }
        $teacherTable .= '</tbody></table></div>';
        $teacherTable .= '<div class="d-flex justify-content-between align-items-center mt-3">';
        $teacherTable .= '<div class="small text-muted">เรียงลำดับได้โดยคลิกหัวตาราง</div>';
        $teacherTable .= '<div class="btn-group" data-dt-pager></div>';
        $teacherTable .= '</div>';
        $teacherTable .= '</div>';
        $teacherTable .= '</div>';

        $content .= '<div class="row g-3 mt-1">';
        $content .= '<div class="col-12 col-xl-4">' . $adminSummary . '</div>';
        $content .= '<div class="col-12 col-xl-8">' . $calendarHtml . '</div>';
        $content .= '</div>';
        $content .= '<div class="mt-3">' . $teacherTable . '</div>';
    } else {
        $startDt = (new DateTimeImmutable('today'))->modify('-6 days');
        $endDt = new DateTimeImmutable('today');
        $start = $startDt->format('Y-m-d');
        $end = $endDt->format('Y-m-d');
        $stmt = $pdo->prepare('SELECT work_date, check_in_at, check_out_at, location_type FROM attendance_logs WHERE teacher_id = :tid AND work_date BETWEEN :s AND :e ORDER BY work_date DESC');
        $stmt->execute([':tid' => $teacherId, ':s' => $start, ':e' => $end]);
        $rows = $stmt->fetchAll();
        $rows = is_array($rows) ? $rows : [];
        $map = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['work_date'])) {
                $map[(string)$r['work_date']] = $r;
            }
        }

        $userTable = '<div class="card p-4 mt-3">';
        $userTable .= '<div class="d-flex align-items-center justify-content-between mb-3">';
        $userTable .= '<div><div class="fw-bold h5 mb-1">สรุปสถานะการลงชื่อ (7 วันล่าสุด)</div><div class="text-muted small">แสดงวัน-เวลาเข้า/กลับ และสถานะ (สาย/ไม่ได้ลงกลับ/ยังไม่ได้ลงเวลา)</div></div>';
        $userTable .= '<a class="btn btn-outline-primary" href="' . e(url($config, '/index.php?page=history')) . '"><i class="fa-solid fa-clock-rotate-left me-2"></i>ดูประวัติทั้งหมด</a>';
        $userTable .= '</div>';
        $userTable .= '<div class="table-responsive"><table class="table align-middle mb-0">';
        $userTable .= '<thead><tr><th>วันที่</th><th>เข้า</th><th>กลับ</th><th>สถานะ</th></tr></thead><tbody>';
        for ($i = 0; $i < 7; $i++) {
            $d = $endDt->modify('-' . $i . ' days')->format('Y-m-d');
            $r = $map[$d] ?? null;
            $cin = is_array($r) ? (string)($r['check_in_at'] ?? '') : '';
            $cout = is_array($r) ? (string)($r['check_out_at'] ?? '') : '';
            $userTable .= '<tr>';
            $userTable .= '<td class="fw-semibold">' . e($d) . '</td>';
            $userTable .= '<td>' . e($cin !== '' ? $cin : '-') . '</td>';
            $userTable .= '<td>' . e($cout !== '' ? $cout : '-') . '</td>';
            $userTable .= '<td class="d-flex flex-wrap gap-2">';
            if (is_array($r)) {
                $userTable .= main_status_badge($r);
                $flags = status_badges($r);
                if ($flags !== '') {
                    $userTable .= $flags;
                }
            } else {
                $userTable .= '<span class="badge bg-secondary-subtle text-secondary">ยังไม่ได้ลงเวลา</span>';
            }
            $userTable .= '</td></tr>';
        }
        $userTable .= '</tbody></table></div></div>';
        $content .= $userTable;
    }

    render_layout($config, 'Dashboard', $content, 'dashboard');
    exit;
}

if ($page === 'check') {
    $today = get_today_log($pdo, $teacherId);
    $csrf = csrf_token();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check($_POST['_csrf'] ?? null)) {
            flash_set('main', 'CSRF ไม่ถูกต้อง', 'danger');
            header('Location: ' . url($config, '/index.php?page=check'));
            exit;
        }

        $mode = (string)($_POST['mode'] ?? '');
        $task = trim((string)($_POST['task_detail'] ?? ''));
        $locationType = (string)($_POST['location_type'] ?? 'home');
        $locationDetail = trim((string)($_POST['location_detail'] ?? ''));
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $lat = is_numeric($lat) ? (float)$lat : null;
        $lng = is_numeric($lng) ? (float)$lng : null;

        try {
            $upload = handle_upload($config, 'photo');
            $photoPath = $upload['path'] ?? null;
            $photoOriginal = $upload['original'] ?? null;

            if ($mode === 'checkin') {
                if (!in_array($locationType, ['home', 'official', 'workout', 'other'], true)) {
                    $locationType = 'home';
                }
                create_checkin(
                    $pdo,
                    $teacherId,
                    $locationType,
                    $locationDetail,
                    $task,
                    $lat,
                    $lng,
                    $photoPath,
                    $photoOriginal,
                    $clientIp,
                    $userAgent
                );
                flash_set('main', 'บันทึกลงชื่อเข้าเรียบร้อย', 'success');
            } elseif ($mode === 'checkout') {
                update_checkout(
                    $pdo,
                    $teacherId,
                    $task,
                    $lat,
                    $lng,
                    $photoPath,
                    $photoOriginal,
                    $clientIp,
                    $userAgent
                );
                flash_set('main', 'บันทึกลงชื่อออกเรียบร้อย', 'success');
            } else {
                flash_set('main', 'คำสั่งไม่ถูกต้อง', 'warning');
            }
        } catch (Throwable $e) {
            flash_set('main', $e->getMessage(), 'danger');
        }

        header('Location: ' . url($config, '/index.php?page=check'));
        exit;
    }

    $mode = 'checkin';
    $headline = 'ลงชื่อปฏิบัติงานเข้า (Check-in)';
    if ($today && !empty($today['check_in_at']) && empty($today['check_out_at'])) {
        $mode = 'checkout';
        $headline = 'ลงชื่อปฏิบัติงานออก (Check-out)';
    } elseif ($today && !empty($today['check_in_at']) && !empty($today['check_out_at'])) {
        $mode = 'done';
        $headline = 'ลงชื่อวันนี้เสร็จสิ้น';
    }

    $content = '<div class="row g-3">';
    $content .= '<div class="col-12 col-lg-7">';
    $content .= '<div class="card p-4">';
    $content .= '<div class="d-flex align-items-center justify-content-between mb-3">';
    $content .= '<div><div class="fw-bold h5 mb-1">' . e($headline) . '</div><div class="text-muted small">บันทึกวัน-เวลาอัตโนมัติ และรองรับ GPS/แนบรูป</div></div>';
    $content .= '<span class="badge badge-gov">' . e(today_date()) . '</span>';
    $content .= '</div>';

    if ($mode === 'done') {
        $content .= '<div class="alert alert-success rounded-14 mb-0">วันนี้ได้ลงชื่อเข้าและออกเรียบร้อยแล้ว</div>';
        $content .= '</div></div>';
    } else {
        $content .= '<form method="post" enctype="multipart/form-data">';
        $content .= '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
        $content .= '<input type="hidden" name="mode" value="' . e($mode) . '">';
        $content .= '<div class="row g-3">';

        if ($mode === 'checkin') {
            $content .= '<div class="col-12 col-md-6">';
            $content .= '<label class="form-label">สถานที่ปฏิบัติงาน</label>';
            $content .= '<select class="form-select" name="location_type" required>';
            $content .= '<option value="home">บ้าน (WFH)</option><option value="official">ปฏิบัติงานที่โรงเรียน</option><option value="workout">ไปราชการ</option><option value="other">อื่น ๆ</option>';
            $content .= '</select></div>';
            $content .= '<div class="col-12 col-md-6">';
            $content .= '<label class="form-label">รายละเอียดสถานที่ (ถ้ามี)</label>';
            $content .= '<input class="form-control" name="location_detail" placeholder="เช่น บ้านพักครู / สำนักงานเขต / สถานที่ประชุม">';
            $content .= '</div>';
        } else {
            $content .= '<div class="col-12">';
            $content .= '<div class="alert alert-primary rounded-14 mb-0">ลงชื่อเข้าแล้วเวลา: <b>' . e((string)($today['check_in_at'] ?? '-')) . '</b> (' . e(location_label((string)($today['location_type'] ?? 'home'))) . ')</div>';
            $content .= '</div>';
        }

        $content .= '<div class="col-12">';
        $content .= '<label class="form-label">ภารกิจ/รายละเอียดงานที่ทำ</label>';
        $content .= '<textarea class="form-control" name="task_detail" rows="4" placeholder="กรอกสรุปงานที่ทำวันนี้" required>' . e((string)($today['task_detail'] ?? '')) . '</textarea>';
        $content .= '</div>';

        $content .= '<div class="col-12 col-md-6">';
        $content .= '<label class="form-label">แนบรูปภาพหลักฐาน (ถ้ามี)</label>';
        $content .= '<input class="form-control" type="file" name="photo" accept="image/*">';
        $content .= '<div class="form-text">รองรับ JPG/PNG/WEBP (ไม่เกิน ' . e((string)($config['app']['max_upload_mb'] ?? 5)) . 'MB)</div>';
        $content .= '</div>';

        $content .= '<div class="col-12 col-md-6">';
        $content .= '<label class="form-label">พิกัด GPS</label>';
        $content .= '<input type="hidden" name="lat" id="gps_lat">';
        $content .= '<input type="hidden" name="lng" id="gps_lng">';
        $content .= '<div class="d-flex gap-2">';
        $content .= '<button class="btn btn-outline-primary" data-gps data-lat="gps_lat" data-lng="gps_lng" data-status="gps_status"><i class="fa-solid fa-location-crosshairs me-2"></i>ดึงพิกัด</button>';
        $content .= '<div class="small text-muted align-self-center" id="gps_status">ยังไม่ได้ดึงพิกัด</div>';
        $content .= '</div>';
        $content .= '</div>';

        $content .= '</div>';
        $btnText = $mode === 'checkin' ? 'บันทึก Check-in' : 'บันทึก Check-out';
        $btnIcon = $mode === 'checkin' ? 'fa-right-to-bracket' : 'fa-right-from-bracket';
        $btnClass = $mode === 'checkin' ? 'btn-gov' : 'btn-warning';
        $content .= '<div class="d-flex gap-2 mt-3">';
        $content .= '<button class="btn ' . e($btnClass) . ' px-4" type="submit"><i class="fa-solid ' . e($btnIcon) . ' me-2"></i>' . e($btnText) . '</button>';
        $content .= '<a class="btn btn-light" href="' . e(url($config, '/index.php?page=history')) . '"><i class="fa-solid fa-clock-rotate-left me-2"></i>ดูประวัติ</a>';
        $content .= '</div>';
        $content .= '</form>';

        $content .= '</div></div>';
    }

    $content .= '<div class="col-12 col-lg-5">';
    $content .= '<div class="card p-4">';
    $content .= '<div class="fw-semibold mb-2">สรุปวันนี้</div>';
    if (!$today) {
        $content .= '<div class="text-muted">ยังไม่ลงชื่อเข้า</div>';
    } else {
        $content .= '<div class="mb-2"><span class="badge ' . e(badge_class((string)$today['location_type'])) . '">' . e(location_label((string)$today['location_type'])) . '</span></div>';
        $status = status_badges($today);
        if ($status !== '') {
            $content .= '<div class="d-flex flex-wrap gap-2 mb-2">' . $status . '</div>';
        }
        $content .= '<div class="small text-muted">เวลาเข้า: ' . e((string)($today['check_in_at'] ?? '-')) . '</div>';
        $content .= '<div class="small text-muted">เวลาออก: ' . e((string)($today['check_out_at'] ?? '-')) . '</div>';
        $content .= '<hr>';
        $content .= '<div class="small text-muted">สถานที่: ' . e((string)($today['location_detail'] ?? '-')) . '</div>';
        $content .= '<div class="small text-muted">พิกัดเข้า: ' . e(trim(((string)($today['checkin_lat'] ?? '')) . ',' . ((string)($today['checkin_lng'] ?? '')), ',')) . '</div>';
    }
    $content .= '</div>';
    $content .= '</div></div>';

    render_layout($config, 'ลงชื่อปฏิบัติงาน', $content, 'check');
    exit;
}

if ($page === 'history') {
    $stmt = $pdo->prepare('SELECT * FROM attendance_logs WHERE teacher_id = :tid ORDER BY work_date DESC, check_in_at DESC LIMIT 200');
    $stmt->execute([':tid' => $teacherId]);
    $rows = $stmt->fetchAll();
    $rows = is_array($rows) ? $rows : [];

    $content = '<div class="card p-4">';
    $content .= '<div class="d-flex align-items-center justify-content-between mb-3">';
    $content .= '<div><div class="fw-bold h5 mb-1">ประวัติการลงชื่อย้อนหลัง</div><div class="text-muted small">เฉพาะของคุณ (ล่าสุด 200 รายการ)</div></div>';
    $content .= '<a class="btn btn-outline-primary" href="' . e(url($config, '/index.php?page=check')) . '"><i class="fa-solid fa-user-check me-2"></i>ไปหน้าลงชื่อ</a>';
    $content .= '</div>';

    if (!$rows) {
        $content .= '<div class="text-muted">ยังไม่มีข้อมูล</div>';
        $content .= '</div>';
        render_layout($config, 'ประวัติของฉัน', $content, 'history');
        exit;
    }

    $content .= '<div class="table-responsive">';
    $content .= '<table class="table align-middle">';
    $content .= '<thead><tr><th>วันที่</th><th>ประเภท</th><th>สถานะ</th><th>เวลาเข้า</th><th>เวลาออก</th><th>ภารกิจ/งาน</th><th>รูป</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $type = (string)($r['location_type'] ?? 'home');
        $photo = (string)($r['checkin_photo_path'] ?? '');
        $photoUrl = $photo !== '' ? url($config, $config['app']['upload_url'] . '/' . $photo) : '';
        $status = status_badges($r);
        $content .= '<tr>';
        $content .= '<td class="fw-semibold">' . e((string)($r['work_date'] ?? '')) . '</td>';
        $content .= '<td><span class="badge ' . e(badge_class($type)) . '">' . e(location_label($type)) . '</span></td>';
        $content .= '<td>' . ($status !== '' ? $status : '<span class="text-muted small">-</span>') . '</td>';
        $content .= '<td>' . e((string)($r['check_in_at'] ?? '-')) . '</td>';
        $content .= '<td>' . e((string)($r['check_out_at'] ?? '-')) . '</td>';
        $content .= '<td style="max-width:420px" class="small">' . e((string)($r['task_detail'] ?? '')) . '</td>';
        $content .= '<td>';
        if ($photoUrl !== '') {
            $content .= '<a class="btn btn-sm btn-light" target="_blank" href="' . e($photoUrl) . '"><i class="fa-regular fa-image me-1"></i>ดู</a>';
        } else {
            $content .= '<span class="text-muted small">-</span>';
        }
        $content .= '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table></div></div>';

    render_layout($config, 'ประวัติของฉัน', $content, 'history');
    exit;
}

if ($page === 'reports') {
    require_admin($config);

    $filters = [
        'date_from' => (string)($_GET['date_from'] ?? ''),
        'date_to' => (string)($_GET['date_to'] ?? ''),
        'name' => trim((string)($_GET['name'] ?? '')),
        'group_id' => (string)($_GET['group_id'] ?? ''),
        'location_type' => (string)($_GET['location_type'] ?? ''),
    ];

    if ($filters['date_from'] === '') {
        $filters['date_from'] = today_date();
    }
    if ($filters['date_to'] === '') {
        $filters['date_to'] = today_date();
    }

    $rows = search_logs($pdo, $filters, 2000);
    $groupOptions = groups($pdo);

    $exportBase = url($config, '/index.php?page=export');
    $query = http_build_query($filters);
    $excelUrl = $exportBase . '&format=excel&' . $query;
    $pdfUrl = $exportBase . '&format=pdf&' . $query;

    $content = '<div class="row g-3">';
    $content .= '<div class="col-12">';
    $content .= '<div class="card p-4">';
    $content .= '<div class="d-flex align-items-center justify-content-between mb-3">';
    $content .= '<div><div class="fw-bold h5 mb-1">รายงานการลงชื่อเข้า-ออก</div><div class="text-muted small">ค้นหา/กรองข้อมูล และ Export เป็น Excel/PDF</div></div>';
    $content .= '<div class="d-flex gap-2"><a class="btn btn-outline-success" href="' . e($excelUrl) . '"><i class="fa-solid fa-file-excel me-2"></i>Excel</a>';
    $content .= '<a class="btn btn-outline-danger" href="' . e($pdfUrl) . '"><i class="fa-solid fa-file-pdf me-2"></i>PDF</a></div>';
    $content .= '</div>';

    $content .= '<form class="row g-3 mb-3" method="get">';
    $content .= '<input type="hidden" name="page" value="reports">';
    $content .= '<div class="col-12 col-md-2"><label class="form-label">จากวันที่</label><input class="form-control" type="date" name="date_from" value="' . e($filters['date_from']) . '"></div>';
    $content .= '<div class="col-12 col-md-2"><label class="form-label">ถึงวันที่</label><input class="form-control" type="date" name="date_to" value="' . e($filters['date_to']) . '"></div>';
    $content .= '<div class="col-12 col-md-3"><label class="form-label">ชื่อ/Username</label><input class="form-control" name="name" value="' . e($filters['name']) . '" placeholder="ค้นหา..."></div>';
    $content .= '<div class="col-12 col-md-3"><label class="form-label">หน่วยงาน</label><select class="form-select" name="group_id"><option value="">ทั้งหมด</option>';
    foreach ($groupOptions as $g) {
        $sel = ((string)$g['group_id'] === (string)$filters['group_id']) ? 'selected' : '';
        $content .= '<option ' . $sel . ' value="' . e((string)$g['group_id']) . '">' . e((string)$g['group_name']) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div class="col-12 col-md-2"><label class="form-label">ประเภท</label><select class="form-select" name="location_type">';
    $lt = $filters['location_type'];
    $content .= '<option value="" ' . ($lt === '' ? 'selected' : '') . '>ทั้งหมด</option>';
    $content .= '<option value="home" ' . ($lt === 'home' ? 'selected' : '') . '>บ้าน (WFH)</option>';
    $content .= '<option value="official" ' . ($lt === 'official' ? 'selected' : '') . '>ปฏิบัติงานที่โรงเรียน</option>';
    $content .= '<option value="workout" ' . ($lt === 'workout' ? 'selected' : '') . '>ไปราชการ</option>';
    $content .= '<option value="other" ' . ($lt === 'other' ? 'selected' : '') . '>อื่น ๆ</option>';
    $content .= '</select></div>';
    $content .= '<div class="col-12 d-flex gap-2">';
    $content .= '<button class="btn btn-gov" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>ค้นหา</button>';
    $content .= '<a class="btn btn-light" href="' . e(url($config, '/index.php?page=reports')) . '">รีเซ็ต</a>';
    $content .= '</div></form>';

    $content .= '<div class="table-responsive">';
    $content .= '<table class="table align-middle">';
    $content .= '<thead><tr><th>วันที่</th><th>ชื่อ</th><th>หน่วยงาน</th><th>ประเภท</th><th>สถานะ</th><th>เข้า</th><th>ออก</th><th>ภารกิจ/งาน</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $type = (string)($r['location_type'] ?? 'home');
        $status = status_badges($r);
        $content .= '<tr>';
        $content .= '<td class="fw-semibold">' . e((string)($r['work_date'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($r['teacher_fullname'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($r['group_name'] ?? '')) . '</td>';
        $content .= '<td><span class="badge ' . e(badge_class($type)) . '">' . e(location_label($type)) . '</span></td>';
        $content .= '<td>' . ($status !== '' ? $status : '<span class="text-muted small">-</span>') . '</td>';
        $content .= '<td>' . e((string)($r['check_in_at'] ?? '-')) . '</td>';
        $content .= '<td>' . e((string)($r['check_out_at'] ?? '-')) . '</td>';
        $content .= '<td class="small" style="max-width:420px">' . e((string)($r['task_detail'] ?? '')) . '</td>';
        $content .= '</tr>';
    }
    if (!$rows) {
        $content .= '<tr><td colspan="8" class="text-muted">ไม่พบข้อมูล</td></tr>';
    }
    $content .= '</tbody></table></div>';
    $content .= '</div></div></div></div>';

    render_layout($config, 'รายงาน', $content, 'reports');
    exit;
}

if ($page === 'export') {
    require_admin($config);

    $format = (string)($_GET['format'] ?? 'excel');
    $filters = [
        'date_from' => (string)($_GET['date_from'] ?? ''),
        'date_to' => (string)($_GET['date_to'] ?? ''),
        'name' => trim((string)($_GET['name'] ?? '')),
        'group_id' => (string)($_GET['group_id'] ?? ''),
        'location_type' => (string)($_GET['location_type'] ?? ''),
    ];
    $rows = search_logs($pdo, $filters, 20000);
    $title = 'รายงานการลงชื่อเข้า-ออก (' . ($filters['date_from'] ?: '-') . ' ถึง ' . ($filters['date_to'] ?: '-') . ')';

    if ($format === 'pdf') {
        $bin = export_pdf($rows, $title);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="report.pdf"');
        echo $bin;
        exit;
    }

    $bin = export_excel($rows, $title);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="report.xlsx"');
    echo $bin;
    exit;
}

if ($page === 'export_daily_status') {
    require_admin($config);

    $format = (string)($_GET['format'] ?? 'excel');
    $workDate = (string)($_GET['work_date'] ?? today_date());
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
        $workDate = today_date();
    }

    $rows = teachers_with_today_logs($pdo, $workDate);

    if ($format === 'pdf') {
        $bin = export_daily_status_pdf($rows, $workDate);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="daily_status_' . $workDate . '.pdf"');
        echo $bin;
        exit;
    }

    if ($format === 'csv') {
        $csv = export_daily_status_csv($rows, $workDate);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="daily_status_' . $workDate . '.csv"');
        echo $csv;
        exit;
    }

    $bin = export_daily_status_excel($rows, $workDate);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="daily_status_' . $workDate . '.xlsx"');
    echo $bin;
    exit;
}

if ($page === 'admin_users') {
    require_admin($config);
    $csrf = csrf_token();
    $action = (string)($_GET['action'] ?? 'list');
    $groupOptions = groups($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check($_POST['_csrf'] ?? null)) {
            flash_set('main', 'CSRF ไม่ถูกต้อง', 'danger');
            header('Location: ' . url($config, '/index.php?page=admin_users'));
            exit;
        }

        $mode = (string)($_POST['mode'] ?? '');
        if ($mode === 'delete') {
            $id = (int)($_POST['teacher_id'] ?? 0);
            $pdo->prepare('DELETE FROM teachers WHERE teacher_id = :id')->execute([':id' => $id]);
            flash_set('main', 'ลบผู้ใช้งานแล้ว', 'success');
            header('Location: ' . url($config, '/index.php?page=admin_users'));
            exit;
        }

        $teacherIdForm = (int)($_POST['teacher_id'] ?? 0);
        $username = trim((string)($_POST['teacher_username'] ?? ''));
        $fullname = trim((string)($_POST['teacher_fullname'] ?? ''));
        $position = trim((string)($_POST['teacher_position'] ?? ''));
        $groupId = (int)($_POST['group_id'] ?? 0);
        $isAdminForm = isset($_POST['is_admin']) ? 1 : 0;
        $isActiveForm = isset($_POST['is_active']) ? 1 : 0;
        $password = (string)($_POST['teacher_password'] ?? '');

        if ($username === '' || $fullname === '') {
            flash_set('main', 'กรุณากรอกชื่อผู้ใช้และชื่อ-สกุล', 'warning');
            header('Location: ' . url($config, '/index.php?page=admin_users&action=' . ($teacherIdForm ? 'edit&id=' . $teacherIdForm : 'new')));
            exit;
        }

        if ($teacherIdForm > 0) {
            $params = [
                ':id' => $teacherIdForm,
                ':u' => $username,
                ':f' => $fullname,
                ':g' => $groupId > 0 ? $groupId : null,
                ':pos' => $position,
                ':adm' => $isAdminForm,
                ':act' => $isActiveForm,
            ];
            if ($password !== '') {
                $params[':p'] = password_hash($password, PASSWORD_BCRYPT);
                $sql = 'UPDATE teachers SET teacher_username=:u, teacher_password=:p, teacher_fullname=:f, group_id=:g, teacher_position=:pos, is_admin=:adm, is_active=:act WHERE teacher_id=:id';
            } else {
                $sql = 'UPDATE teachers SET teacher_username=:u, teacher_fullname=:f, group_id=:g, teacher_position=:pos, is_admin=:adm, is_active=:act WHERE teacher_id=:id';
            }
            $pdo->prepare($sql)->execute($params);
            flash_set('main', 'บันทึกการแก้ไขแล้ว', 'success');
        } else {
            if ($password === '') {
                flash_set('main', 'กรุณาตั้งรหัสผ่าน', 'warning');
                header('Location: ' . url($config, '/index.php?page=admin_users&action=new'));
                exit;
            }
            $pdo->prepare('INSERT INTO teachers (teacher_username, teacher_password, teacher_fullname, group_id, teacher_position, is_admin, is_active) VALUES (:u,:p,:f,:g,:pos,:adm,:act)')->execute([
                ':u' => $username,
                ':p' => password_hash($password, PASSWORD_BCRYPT),
                ':f' => $fullname,
                ':g' => $groupId > 0 ? $groupId : null,
                ':pos' => $position,
                ':adm' => $isAdminForm,
                ':act' => $isActiveForm,
            ]);
            flash_set('main', 'เพิ่มผู้ใช้งานแล้ว', 'success');
        }
        header('Location: ' . url($config, '/index.php?page=admin_users'));
        exit;
    }

    if ($action === 'new' || $action === 'edit') {
        $row = [
            'teacher_id' => 0,
            'teacher_username' => '',
            'teacher_fullname' => '',
            'group_id' => '',
            'teacher_position' => '',
            'is_admin' => 0,
            'is_active' => 1,
        ];
        if ($action === 'edit') {
            $id = (int)($_GET['id'] ?? 0);
            $st = $pdo->prepare('SELECT teacher_id, teacher_username, teacher_fullname, group_id, teacher_position, is_admin, is_active FROM teachers WHERE teacher_id=:id');
            $st->execute([':id' => $id]);
            $r = $st->fetch();
            if (is_array($r)) {
                $row = $r;
            }
        }

        $content = '<div class="card p-4">';
        $content .= '<div class="d-flex align-items-center justify-content-between mb-3">';
        $content .= '<div><div class="fw-bold h5 mb-1">' . ($action === 'new' ? 'เพิ่มผู้ใช้งาน' : 'แก้ไขผู้ใช้งาน') . '</div><div class="text-muted small">เจ้าหน้าที่/ผู้ดูแลระบบ</div></div>';
        $content .= '<a class="btn btn-light" href="' . e(url($config, '/index.php?page=admin_users')) . '"><i class="fa-solid fa-arrow-left me-2"></i>กลับ</a>';
        $content .= '</div>';

        $content .= '<form method="post">';
        $content .= '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
        $content .= '<input type="hidden" name="teacher_id" value="' . e((string)($row['teacher_id'] ?? 0)) . '">';
        $content .= '<div class="row g-3">';
        $content .= '<div class="col-12 col-md-6"><label class="form-label">Username</label><input class="form-control" name="teacher_username" value="' . e((string)($row['teacher_username'] ?? '')) . '" required></div>';
        $content .= '<div class="col-12 col-md-6"><label class="form-label">รหัสผ่าน ' . ($action === 'edit' ? '(เว้นว่างถ้าไม่เปลี่ยน)' : '') . '</label><input class="form-control" type="password" name="teacher_password" ' . ($action === 'new' ? 'required' : '') . '></div>';
        $content .= '<div class="col-12 col-md-6"><label class="form-label">ชื่อ-สกุล</label><input class="form-control" name="teacher_fullname" value="' . e((string)($row['teacher_fullname'] ?? '')) . '" required></div>';
        $content .= '<div class="col-12 col-md-6"><label class="form-label">ตำแหน่ง</label><input class="form-control" name="teacher_position" value="' . e((string)($row['teacher_position'] ?? '')) . '"></div>';
        $content .= '<div class="col-12 col-md-6"><label class="form-label">กลุ่ม/หน่วยงาน</label><select class="form-select" name="group_id"><option value="">- เลือก -</option>';
        foreach ($groupOptions as $g) {
            $sel = ((string)$g['group_id'] === (string)($row['group_id'] ?? '')) ? 'selected' : '';
            $content .= '<option ' . $sel . ' value="' . e((string)$g['group_id']) . '">' . e((string)$g['group_name']) . '</option>';
        }
        $content .= '</select></div>';
        $content .= '<div class="col-12 col-md-6 d-flex align-items-end gap-3">';
        $content .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="is_admin" ' . (((int)($row['is_admin'] ?? 0) === 1) ? 'checked' : '') . '><label class="form-check-label">เป็น Admin</label></div>';
        $content .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" ' . (((int)($row['is_active'] ?? 1) === 1) ? 'checked' : '') . '><label class="form-check-label">เปิดใช้งาน</label></div>';
        $content .= '</div>';
        $content .= '</div>';
        $content .= '<div class="d-flex gap-2 mt-3">';
        $content .= '<button class="btn btn-gov" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>บันทึก</button>';
        $content .= '</div>';
        $content .= '</form></div>';

        render_layout($config, 'จัดการผู้ใช้งาน', $content, 'admin_users');
        exit;
    }

    $rows = $pdo->query('SELECT t.teacher_id, t.teacher_username, t.teacher_fullname, t.teacher_position, t.is_admin, t.is_active, g.group_name FROM teachers t LEFT JOIN groups g ON g.group_id=t.group_id ORDER BY t.teacher_fullname ASC')->fetchAll();
    $rows = is_array($rows) ? $rows : [];

    $content = '<div class="card p-4">';
    $content .= '<div class="d-flex align-items-center justify-content-between mb-3">';
    $content .= '<div><div class="fw-bold h5 mb-1">จัดการผู้ใช้งาน</div><div class="text-muted small">เพิ่ม/แก้ไข/ลบ (เจ้าหน้าที่/ผู้ดูแล)</div></div>';
    $content .= '<a class="btn btn-gov" href="' . e(url($config, '/index.php?page=admin_users&action=new')) . '"><i class="fa-solid fa-user-plus me-2"></i>เพิ่มผู้ใช้</a>';
    $content .= '</div>';
    $content .= '<div class="table-responsive"><table class="table align-middle">';
    $content .= '<thead><tr><th>ชื่อ-สกุล</th><th>Username</th><th>หน่วยงาน</th><th>สิทธิ์</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $content .= '<tr>';
        $content .= '<td class="fw-semibold">' . e((string)($r['teacher_fullname'] ?? '')) . '<div class="text-muted small">' . e((string)($r['teacher_position'] ?? '')) . '</div></td>';
        $content .= '<td>' . e((string)($r['teacher_username'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($r['group_name'] ?? '')) . '</td>';
        $content .= '<td>' . (((int)($r['is_admin'] ?? 0) === 1) ? '<span class="badge badge-gov">Admin</span>' : '<span class="badge bg-secondary-subtle text-secondary">Staff</span>') . '</td>';
        $content .= '<td>' . (((int)($r['is_active'] ?? 0) === 1) ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-danger-subtle text-danger">Inactive</span>') . '</td>';
        $content .= '<td class="text-end">';
        $content .= '<a class="btn btn-sm btn-light" href="' . e(url($config, '/index.php?page=admin_users&action=edit&id=' . (int)$r['teacher_id'])) . '"><i class="fa-solid fa-pen-to-square"></i></a> ';
        $content .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ยืนยันการลบ?\')">';
        $content .= '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
        $content .= '<input type="hidden" name="mode" value="delete">';
        $content .= '<input type="hidden" name="teacher_id" value="' . e((string)($r['teacher_id'] ?? 0)) . '">';
        $content .= '<button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>';
        $content .= '</form>';
        $content .= '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table></div></div>';

    render_layout($config, 'จัดการผู้ใช้งาน', $content, 'admin_users');
    exit;
}

if ($page === 'admin_import') {
    require_admin($config);
    $csrf = csrf_token();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check($_POST['_csrf'] ?? null)) {
            flash_set('main', 'CSRF ไม่ถูกต้อง', 'danger');
            header('Location: ' . url($config, '/index.php?page=admin_import'));
            exit;
        }

        try {
            if (!isset($_FILES['excel']) || !is_array($_FILES['excel'])) {
                throw new RuntimeException('ไม่พบไฟล์');
            }
            $f = $_FILES['excel'];
            if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ');
            }

            $tmp = (string)($f['tmp_name'] ?? '');
            $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                throw new RuntimeException('รองรับ xlsx/xls/csv');
            }

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmp);
            $ws = $spreadsheet->getActiveSheet();
            $rows = $ws->toArray(null, true, true, true);
            if (!$rows || count($rows) < 2) {
                throw new RuntimeException('ไฟล์ไม่มีข้อมูล');
            }

            $header = array_map(static fn($v) => strtolower(trim((string)$v)), $rows[1] ?? []);
            $map = [];
            foreach ($header as $col => $name) {
                $map[$name] = $col;
            }

            $required = ['teacher_id', 'teacher_username', 'teacher_password', 'teacher_fullname', 'group_id', 'teacher_position'];
            foreach ($required as $r) {
                if (!isset($map[$r])) {
                    throw new RuntimeException('ขาดคอลัมน์: ' . $r);
                }
            }

            $inserted = 0;
            $updated = 0;
            $skipped = 0;

            $pdo->beginTransaction();
            for ($i = 2; $i <= count($rows); $i++) {
                $r = $rows[$i] ?? null;
                if (!is_array($r)) {
                    continue;
                }
                $teacherId = (int)trim((string)($r[$map['teacher_id']] ?? '0'));
                $username = trim((string)($r[$map['teacher_username']] ?? ''));
                $password = (string)($r[$map['teacher_password']] ?? '');
                $fullname = trim((string)($r[$map['teacher_fullname']] ?? ''));
                $groupId = (int)trim((string)($r[$map['group_id']] ?? '0'));
                $position = trim((string)($r[$map['teacher_position']] ?? ''));

                if ($username === '' || $fullname === '') {
                    $skipped++;
                    continue;
                }

                $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;

                if ($teacherId > 0) {
                    $st = $pdo->prepare('SELECT teacher_id FROM teachers WHERE teacher_id=:id');
                    $st->execute([':id' => $teacherId]);
                    $exists = $st->fetch();
                    if ($exists) {
                        if ($hash) {
                            $pdo->prepare('UPDATE teachers SET teacher_username=:u, teacher_password=:p, teacher_fullname=:f, group_id=:g, teacher_position=:pos, is_active=1 WHERE teacher_id=:id')->execute([
                                ':u' => $username,
                                ':p' => $hash,
                                ':f' => $fullname,
                                ':g' => $groupId > 0 ? $groupId : null,
                                ':pos' => $position,
                                ':id' => $teacherId,
                            ]);
                        } else {
                            $pdo->prepare('UPDATE teachers SET teacher_username=:u, teacher_fullname=:f, group_id=:g, teacher_position=:pos, is_active=1 WHERE teacher_id=:id')->execute([
                                ':u' => $username,
                                ':f' => $fullname,
                                ':g' => $groupId > 0 ? $groupId : null,
                                ':pos' => $position,
                                ':id' => $teacherId,
                            ]);
                        }
                        $updated++;
                    } else {
                        if (!$hash) {
                            $skipped++;
                            continue;
                        }
                        $pdo->prepare('INSERT INTO teachers (teacher_id, teacher_username, teacher_password, teacher_fullname, group_id, teacher_position, is_admin, is_active) VALUES (:id,:u,:p,:f,:g,:pos,0,1)')->execute([
                            ':id' => $teacherId,
                            ':u' => $username,
                            ':p' => $hash,
                            ':f' => $fullname,
                            ':g' => $groupId > 0 ? $groupId : null,
                            ':pos' => $position,
                        ]);
                        $inserted++;
                    }
                } else {
                    if (!$hash) {
                        $skipped++;
                        continue;
                    }
                    $pdo->prepare('INSERT INTO teachers (teacher_username, teacher_password, teacher_fullname, group_id, teacher_position, is_admin, is_active) VALUES (:u,:p,:f,:g,:pos,0,1)')->execute([
                        ':u' => $username,
                        ':p' => $hash,
                        ':f' => $fullname,
                        ':g' => $groupId > 0 ? $groupId : null,
                        ':pos' => $position,
                    ]);
                    $inserted++;
                }
            }
            $pdo->commit();

            flash_set('main', 'นำเข้าสำเร็จ: เพิ่ม ' . $inserted . ' | อัปเดต ' . $updated . ' | ข้าม ' . $skipped, 'success');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('main', $e->getMessage(), 'danger');
        }

        header('Location: ' . url($config, '/index.php?page=admin_import'));
        exit;
    }

    $content = '<div class="row g-3">';
    $content .= '<div class="col-12 col-lg-7">';
    $content .= '<div class="card p-4">';
    $content .= '<div class="fw-bold h5 mb-1">นำเข้าครูด้วยไฟล์ Excel</div>';
    $content .= '<div class="text-muted small mb-3">ต้องมีคอลัมน์: teacher_id, teacher_username, teacher_password, teacher_fullname, Group_id, teacher_position</div>';
    $content .= '<form method="post" enctype="multipart/form-data">';
    $content .= '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
    $content .= '<div class="mb-3"><label class="form-label">ไฟล์ (xlsx/xls/csv)</label><input class="form-control" type="file" name="excel" required></div>';
    $content .= '<button class="btn btn-gov" type="submit"><i class="fa-solid fa-file-import me-2"></i>เริ่มนำเข้า</button>';
    $content .= '</form>';
    $content .= '</div></div>';

    $content .= '<div class="col-12 col-lg-5">';
    $content .= '<div class="card p-4">';
    $content .= '<div class="fw-semibold mb-2">หมายเหตุ</div>';
    $content .= '<ul class="mb-0">';
    $content .= '<li>ระบบจะเข้ารหัสรหัสผ่านก่อนบันทึกลงฐานข้อมูล</li>';
    $content .= '<li>ถ้า teacher_id ตรงกับของเดิม จะอัปเดตข้อมูล</li>';
    $content .= '<li>ถ้า teacher_password ว่างตอนอัปเดต ระบบจะคงรหัสผ่านเดิม</li>';
    $content .= '</ul>';
    $content .= '</div></div></div>';

    render_layout($config, 'นำเข้าครู (Excel)', $content, 'admin_import');
    exit;
}

http_response_code(404);
render_layout($config, 'ไม่พบหน้า', '<div class="card p-4"><div class="fw-bold">404</div><div class="text-muted">ไม่พบหน้าที่ต้องการ</div></div>');
