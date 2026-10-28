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

function user(): ?array { start_session(); return $_SESSION['user'] ?? null; }

function require_login(): array {
    $u = user();
    if (!$u) fail(401, 'Please sign in');
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
