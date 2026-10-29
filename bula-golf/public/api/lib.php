<?php
declare(strict_types=1);

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        if (!is_file($f)) { fail(500, 'Server not configured: api/config.php is missing'); }
        $c = require $f;
        date_default_timezone_set($c['timezone'] ?? 'Pacific/Fiji');
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // keep PHP and MySQL on the same clock (Fiji is UTC+12, no DST handling needed here)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

function out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $msg): void { out(['error' => $msg], $code); }

function body(): array {
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw ? (json_decode($raw, true) ?? []) : [];
    }
    return $b;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_name('bulagolf');
    session_start();
}

/** Signed-in user: app clients send X-Bula-Token (custom header: cPanel/Apache can strip Authorization), browsers use the session cookie. */
function user(): ?array {
    $t = $_SERVER['HTTP_X_BULA_TOKEN'] ?? '';
    if ($t !== '') {
        if (!preg_match('/^[a-f0-9]{64}$/', $t)) return null;
        $u = q("SELECT u.id, u.name, u.role FROM api_tokens t JOIN users u ON u.id=t.user_id
                WHERE t.token_hash=? AND u.active=1 AND t.last_used > (NOW() - INTERVAL 30 DAY)", [hash('sha256', $t)])->fetch();
        if (!$u) return null;
        q('UPDATE api_tokens SET last_used=NOW() WHERE token_hash=?', [hash('sha256', $t)]);
        return ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role']];
    }
    start_session();
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $u = user();
    if (!$u) fail(401, 'Please sign in');
    return $u;
}

/** users.course_id: the one course a login is locked to, or null for head office (all courses). Read fresh so edits apply at once. */
function user_course_id(array $u): ?int {
    $v = q('SELECT course_id FROM users WHERE id=?', [$u['id']])->fetchColumn();
    return $v === false || $v === null ? null : (int)$v;
}

/** The course this request is about: a locked login always gets its own; head office picks via X-Bula-Course header or ?course=. */
function course_id(array $u): int {
    static $c = null;
    if ($c !== null) return $c;
    $bound = user_course_id($u);
    if ($bound) return $c = $bound;
    $ids = array_map('intval', q('SELECT id FROM courses WHERE active=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) fail(500, 'No courses set up');
    $want = (int)($_SERVER['HTTP_X_BULA_COURSE'] ?? $_GET['course'] ?? 0);
    return $c = in_array($want, $ids, true) ? $want : $ids[0];
}

function is_admin(array $u): bool { return $u['role'] === 'manager' && user_course_id($u) === null; }

function require_admin(): array {
    $u = require_manager();
    if (!is_admin($u)) fail(403, 'Head-office admin only');
    return $u;
}

function require_manager(): array {
    $u = require_login();
    if ($u['role'] !== 'manager') fail(403, 'Manager access only');
    return $u;
}

function ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45); }

function audit(?int $uid, string $action, string $detail = ''): void {
    q('INSERT INTO audit_log (user_id, action, detail, ip) VALUES (?,?,?,?)', [$uid, $action, mb_substr($detail, 0, 255), ip()]);
}

function clean(?string $s, int $max = 150): ?string {
    if ($s === null) return null;
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return $s === '' ? null : mb_substr($s, 0, $max);
}

function norm_mobile(string $m): string { return preg_replace('/[^0-9+]/', '', $m); }

function valid_png(string $d, int $maxBytes = 600000): bool {
    if (strpos($d, 'data:image/png;base64,') !== 0) return false;
    $bin = base64_decode(substr($d, 22), true);
    return $bin !== false && strlen($bin) > 400 && strlen($bin) <= $maxBytes;
}

/** SQL fragment: effective status for an asset row aliased "a". */
const STATUS_SQL = "CASE
    WHEN EXISTS (SELECT 1 FROM checkins c WHERE c.returned_at IS NULL AND (c.cart_id = a.id OR c.clubs_id = a.id)) THEN 'out'
    WHEN a.maintenance = 1 THEN 'maintenance'
    WHEN a.gps_status IS NOT NULL AND a.gps_status <> 'ready' THEN a.gps_status
    ELSE 'available' END";
