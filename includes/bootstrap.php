<?php
/**
 * Shared bootstrap: session + DB + helpers
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Dhaka');

if (!empty($config['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** App URL with base_url prefix (e.g. /login.php). */
function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = rtrim((string) ((require __DIR__ . '/../config/app.php')['base_url'] ?? ''), '/');
    }
    $path = str_replace('\\', '/', $path);
    if ($path !== '' && (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))) {
        return $path;
    }
    $path = ltrim($path, '/');
    if ($base !== '' && str_starts_with('/' . $path, $base . '/')) {
        return '/' . $path;
    }
    if ($base !== '' && '/' . $path === $base) {
        return $base;
    }
    if ($path === '') {
        return $base !== '' ? $base . '/' : '/';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

function redirect(string $path): void
{
    if (!str_starts_with($path, 'http')) {
        $path = url($path);
    }
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    if (empty($_SESSION['_flash'])) {
        return null;
    }
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (Throwable $e) {
            $pdo = null; // reconnect after "MySQL server has gone away"
        }
    }

    $config = require __DIR__ . '/../config/app.php';
    $db = $config['db'];
    ini_set('default_socket_timeout', '5');

    $hosts = array_unique([$db['host'], '127.0.0.1', 'localhost']);
    $last = null;

    foreach ($hosts as $host) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $db['port'],
                $db['name'],
                $db['charset']
            );
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            // Keep connection healthy on XAMPP
            $pdo->exec("SET SESSION wait_timeout = 28800, interactive_timeout = 28800");
            return $pdo;
        } catch (Throwable $e) {
            $last = $e;
            $pdo = null;
        }
    }

    throw new RuntimeException(
        'Database connect failed. Start MySQL in XAMPP, then open setup.php if needed. ' .
        ($last ? $last->getMessage() : '')
    );
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT id, email, role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') {
            unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_email']);
            return null;
        }
        return $user;
    } catch (Throwable $e) {
        return null;
    }
}

function require_login(?array $roles = null): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please sign in first.');
        redirect('login.php');
    }
    if ($roles !== null && !in_array($user['role'], $roles, true)) {
        flash('error', 'You do not have access to that page.');
        redirect(role_home($user['role']));
    }
    return $user;
}

function role_home(string $role): string
{
    return match ($role) {
        'teacher' => url('teacher/dashboard.php'),
        'branch_manager' => url('manager/dashboard.php'),
        'admin' => url('admin/dashboard.php'),
        'super_admin' => url('super/dashboard.php'),
        default => url('login.php'),
    };
}

function login_user(array $user): void
{
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_email'] = $user['email'];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 6371000.0;
    $φ1 = deg2rad($lat1);
    $φ2 = deg2rad($lat2);
    $Δφ = deg2rad($lat2 - $lat1);
    $Δλ = deg2rad($lng2 - $lng1);
    $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
    return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function format_time(?string $t): string
{
    if (!$t) {
        return '—';
    }
    $parts = explode(':', substr($t, 0, 5));
    $h = (int) $parts[0];
    $m = $parts[1] ?? '00';
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h = $h % 12 ?: 12;
    return $h . ':' . $m . ' ' . $ampm;
}

function badge(string $status): string
{
    $s = str_replace(' ', '_', $status);
    return '<span class="badge badge-' . h($s) . '">' . h(str_replace('_', ' ', $status)) . '</span>';
}

/** Ensure dual-entry session columns (Teacher vs Manager start/end + review counts). */
function ensure_review_counts_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM class_sessions')->fetchAll(PDO::FETCH_COLUMN);
        $add = [
            'count_best' => 'INT UNSIGNED NULL AFTER student_count_teacher',
            'count_good' => 'INT UNSIGNED NULL AFTER count_best',
            'count_bad' => 'INT UNSIGNED NULL AFTER count_good',
            'count_repeat' => 'INT UNSIGNED NULL AFTER count_bad',
            'teacher_class_start_at' => 'DATETIME NULL AFTER check_out_at',
            'teacher_class_end_at' => 'DATETIME NULL AFTER teacher_class_start_at',
            'teacher_times_saved_at' => 'DATETIME NULL AFTER teacher_class_end_at',
            'manager_class_start_at' => 'DATETIME NULL AFTER teacher_times_saved_at',
            'manager_class_end_at' => 'DATETIME NULL AFTER manager_class_start_at',
            'manager_times_saved_at' => 'DATETIME NULL AFTER manager_class_end_at',
            'manager_review_saved_at' => 'DATETIME NULL AFTER count_repeat',
        ];
        foreach ($add as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE class_sessions ADD COLUMN {$col} {$def}");
            }
        }
        // Backfill teacher end from legacy check_out_at
        $pdo->exec(
            'UPDATE class_sessions SET teacher_class_end_at = check_out_at
             WHERE teacher_class_end_at IS NULL AND check_out_at IS NOT NULL'
        );
        $pdo->exec(
            "CREATE OR REPLACE VIEW v_teacher_stats AS
             SELECT
               t.id AS teacher_id,
               t.full_name,
               COUNT(DISTINCT c.id) AS total_scheduled,
               SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
               SUM(CASE WHEN cs.check_in_at IS NOT NULL THEN 1 ELSE 0 END) AS total_checkins,
               COALESCE(SUM(cs.count_best), 0) AS rating_best,
               COALESCE(SUM(cs.count_good), 0) AS rating_good,
               COALESCE(SUM(cs.count_bad), 0) AS rating_average,
               COALESCE(SUM(cs.count_repeat), 0) AS rating_repeat
             FROM teachers t
             LEFT JOIN classes c ON c.teacher_id = t.id
             LEFT JOIN class_sessions cs ON cs.class_id = c.id
             GROUP BY t.id, t.full_name"
        );
        $revCols = $pdo->query('SHOW COLUMNS FROM class_reviews')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('evidence_photos', $revCols, true)) {
            $pdo->exec('ALTER TABLE class_reviews ADD COLUMN evidence_photos TEXT NULL AFTER additional_comments');
        }
        if (!in_array('issue_notes', $revCols, true)) {
            $pdo->exec('ALTER TABLE class_reviews ADD COLUMN issue_notes TEXT NULL AFTER evidence_photos');
        }
        if (!in_array('edit_count', $revCols, true)) {
            $pdo->exec('ALTER TABLE class_reviews ADD COLUMN edit_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER has_issue_flag');
        }
        if (!in_array('last_edited_at', $revCols, true)) {
            $pdo->exec('ALTER TABLE class_reviews ADD COLUMN last_edited_at DATETIME NULL AFTER edit_count');
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS class_review_edits (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              class_review_id BIGINT UNSIGNED NOT NULL,
              class_id BIGINT UNSIGNED NOT NULL,
              edit_no INT UNSIGNED NOT NULL,
              snapshot_json LONGTEXT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_cre_review (class_review_id),
              KEY idx_cre_class (class_id),
              CONSTRAINT fk_cre_review FOREIGN KEY (class_review_id) REFERENCES class_reviews(id) ON DELETE CASCADE,
              CONSTRAINT fk_cre_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // ignore — older installs may still use quality_rating only
    }
}

/** Snapshot of a class_reviews row for edit history */
function class_review_snapshot(array $review): array
{
    $keys = [
        'cleanliness_staircase', 'cleanliness_classroom', 'cleanliness_teachers_room',
        'facility_fan', 'facility_ac', 'facility_sound_system', 'facility_projector',
        'staff_dress_code', 'staff_grooming', 'staff_behavior',
        'reminder_call_received', 'transport_applicable', 'transport_rating', 'transport_comments',
        'additional_comments', 'evidence_photos', 'issue_notes', 'has_issue_flag',
        'submitted_at', 'edit_count', 'last_edited_at',
    ];
    $out = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $review)) {
            $out[$k] = $review[$k];
        }
    }
    return $out;
}

function parse_local_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function session_row_for_class(PDO $pdo, int $classId): array
{
    $st = $pdo->prepare('SELECT * FROM class_sessions WHERE class_id = ?');
    $st->execute([$classId]);
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    $pdo->prepare('INSERT INTO class_sessions (class_id) VALUES (?)')->execute([$classId]);
    $st->execute([$classId]);
    return $st->fetch() ?: ['id' => (int) $pdo->lastInsertId(), 'class_id' => $classId];
}

function format_clock(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    return date('h:i A', strtotime($datetime));
}

function datetime_local_value(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    return date('Y-m-d\TH:i', strtotime($datetime));
}

/** HSC Science — medical admission exam subjects */
function medico_subjects(): array
{
    return [
        'Physics',
        'Chemistry',
        'Biology',
        'English',
        'General Knowledge',
        'Higher Math',
        'ICT',
        'Bangla',
    ];
}

function medico_time_slots(): array
{
    return [
        '07:00:00' => 'সকাল ৭:০০',
        '10:00:00' => 'সকাল ১০:০০',
        '13:00:00' => 'দুপুর ১:০০',
        '16:00:00' => 'বিকাল ৪:০০',
    ];
}

function ensure_class_curriculum_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM classes')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('subject', $cols, true)) {
            $pdo->exec("ALTER TABLE classes ADD COLUMN subject VARCHAR(120) NULL AFTER course_category");
        }
        if (!in_array('lecture_no', $cols, true)) {
            $pdo->exec('ALTER TABLE classes ADD COLUMN lecture_no SMALLINT UNSIGNED NULL AFTER subject');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function class_label(array $c): string
{
    $parts = [];
    if (!empty($c['subject'])) {
        $parts[] = $c['subject'];
    }
    $parts[] = ($c['course_category'] ?? '') === '2nd_timer' ? '2nd Timer' : '1st Timer';
    if (!empty($c['lecture_no'])) {
        $parts[] = 'Lec ' . (int) $c['lecture_no'];
    }
    return implode(' · ', $parts);
}

function teacher_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $letters .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $letters !== '' ? $letters : '?';
}

function teacher_photo_src(?string $avatarPath): ?string
{
    if (!$avatarPath) {
        return null;
    }
    $rel = ltrim(str_replace('\\', '/', $avatarPath), '/');
    $full = __DIR__ . '/../' . $rel;
    if (!is_file($full)) {
        return null;
    }
    return url($rel);
}

function save_teacher_avatar(array $file, int $teacherId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('Photo must be JPG, PNG or WebP.');
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Photo max size 3MB.');
    }
    $dir = __DIR__ . '/../storage/uploads/avatars';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 't' . $teacherId . '_' . time() . '.' . $map[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save photo.');
    }
    return 'storage/uploads/avatars/' . $name;
}

/** Store issue evidence photos for branch review (max 4 images). */
function store_review_evidence_photos(int $classId, array $filesField): array
{
    if (empty($filesField['name']) || !is_array($filesField['name'])) {
        return [];
    }
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $dir = __DIR__ . '/../storage/uploads/reviews';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $saved = [];
    $count = count($filesField['name']);
    for ($i = 0; $i < $count && count($saved) < 4; $i++) {
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = (string) ($filesField['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset($map[$mime])) {
            continue;
        }
        if (($filesField['size'][$i] ?? 0) > 4 * 1024 * 1024) {
            continue;
        }
        $name = 'c' . $classId . '_' . time() . '_' . $i . '.' . $map[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            continue;
        }
        $saved[] = 'storage/uploads/reviews/' . $name;
    }
    return $saved;
}

function ensure_branch_contact_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM branches')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone', $cols, true)) {
            $pdo->exec("ALTER TABLE branches ADD COLUMN phone VARCHAR(30) NULL AFTER address");
        }
        if (!in_array('email', $cols, true)) {
            $pdo->exec("ALTER TABLE branches ADD COLUMN email VARCHAR(150) NULL AFTER phone");
        }
    } catch (Throwable $e) {
        // soft-fail
    }
}

function google_maps_api_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $key = '';
    try {
        $st = db()->query("SELECT setting_value FROM system_settings WHERE setting_key = 'google_maps_api_key'");
        $row = $st ? $st->fetchColumn() : false;
        if (is_string($row) && trim($row) !== '') {
            $key = trim($row);
            return $key;
        }
    } catch (Throwable $e) {
        // fall through to config
    }
    $cfg = require __DIR__ . '/../config/app.php';
    $key = trim((string) ($cfg['google_maps_api_key'] ?? ''));
    return $key;
}

function ensure_email_auth_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('email_verified_at', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER status');
            $pdo->exec("UPDATE users SET email_verified_at = COALESCE(created_at, NOW()) WHERE status = 'active'");
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS email_codes (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              purpose ENUM('verify','reset') NOT NULL,
              code_hash CHAR(64) NOT NULL,
              expires_at DATETIME NOT NULL,
              used_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_email_codes_lookup (code_hash, purpose),
              KEY idx_email_codes_user (user_id, purpose),
              CONSTRAINT fk_email_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        );
    } catch (Throwable $e) {
        // soft-fail
    }
}

/** Branch visit check-in/out + class start notes */
function ensure_teacher_flow_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM class_sessions')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('teacher_start_notes', $cols, true)) {
            $pdo->exec('ALTER TABLE class_sessions ADD COLUMN teacher_start_notes TEXT NULL AFTER teacher_notes');
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS teacher_branch_days (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              teacher_id BIGINT UNSIGNED NOT NULL,
              branch_id BIGINT UNSIGNED NOT NULL,
              visit_date DATE NOT NULL,
              check_in_at DATETIME NULL,
              check_in_latitude DECIMAL(10,7) NULL,
              check_in_longitude DECIMAL(10,7) NULL,
              check_in_distance_m DECIMAL(8,2) NULL,
              last_check_in_at DATETIME NULL,
              check_out_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_teacher_branch_day (teacher_id, branch_id, visit_date),
              KEY idx_tbd_branch_day (branch_id, visit_date),
              CONSTRAINT fk_tbd_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
              CONSTRAINT fk_tbd_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $tbdCols = $pdo->query('SHOW COLUMNS FROM teacher_branch_days')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_check_in_at', $tbdCols, true)) {
            $pdo->exec('ALTER TABLE teacher_branch_days ADD COLUMN last_check_in_at DATETIME NULL AFTER check_in_distance_m');
            $pdo->exec('UPDATE teacher_branch_days SET last_check_in_at = check_in_at WHERE last_check_in_at IS NULL AND check_in_at IS NOT NULL');
        }
    } catch (Throwable $e) {
        // soft-fail
    }
}

/** Brand asset URLs (favicon_io package) */
function brand_asset(string $file): string
{
    return url('favicon_io/' . ltrim($file, '/'));
}

function brand_favicon_tags(): string
{
    $ico = h(brand_asset('favicon.ico'));
    $f32 = h(brand_asset('favicon-32x32.png'));
    $f16 = h(brand_asset('favicon-16x16.png'));
    $apple = h(brand_asset('apple-touch-icon.png'));
    return <<<HTML
  <link rel="icon" href="{$ico}" sizes="any" />
  <link rel="icon" type="image/png" sizes="32x32" href="{$f32}" />
  <link rel="icon" type="image/png" sizes="16x16" href="{$f16}" />
  <link rel="apple-touch-icon" href="{$apple}" />
HTML;
}

function brand_logo_url(): string
{
    $jpg = __DIR__ . '/../favicon_io/logo-medico.jpg';
    if (is_file($jpg)) {
        return brand_asset('logo-medico.jpg') . '?v=' . filemtime($jpg);
    }
    $png = __DIR__ . '/../favicon_io/logo-medico.png';
    if (is_file($png)) {
        return brand_asset('logo-medico.png') . '?v=' . filemtime($png);
    }
    return brand_asset('android-chrome-512x512.png');
}

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/pagination.php';
