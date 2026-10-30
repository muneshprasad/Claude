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
        return ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => norm_role((string)$u['role'])];
    }
    start_session();
    $s = $_SESSION['user'] ?? null;
    if (!$s) return null;
    // role and active flag are read fresh, so an admin's change (or disabling a login) applies at once, not at next sign-in
    $row = q('SELECT id, name, role FROM users WHERE id=? AND active=1', [(int)$s['id']])->fetch();
    return $row ? ['id' => (int)$row['id'], 'name' => $row['name'], 'role' => norm_role((string)$row['role'])] : null;
}

/** Roles: admin (head office, or one course's admin), supervisor, staff. The old "manager" is an admin. */
function norm_role(string $r): string { return $r === 'manager' ? 'admin' : $r; }

/**
 * What an admin hands out per role. Team and Access management stay with admins; Terms and Courses with head office.
 *   checkin  = check guests in     returns = return carts / clubs     range = issue range balls     today = see today's check-ins
 * Roles: supervisor (everything by default), golf attendant (returns and range balls), staff (check-in only).
 */
const PERMS = ['checkin', 'returns', 'range', 'today', 'dashboard', 'reports', 'history', 'fleet', 'members', 'export'];
const ROLE_DEFAULTS = [
    'supervisor' => PERMS,
    'attendant' => ['returns', 'range'],
    'staff' => ['checkin'],
];

/** What each role may open at a course: the defaults above, adjusted by that course's admin under Settings > Access. */
function access_matrix(int $course): array {
    $m = [];
    foreach (ROLE_DEFAULTS as $role => $on) { $m[$role] = array_fill_keys(PERMS, false); foreach ($on as $p) $m[$role][$p] = true; }
    foreach (q('SELECT role, perm, allowed FROM role_perms WHERE course_id=?', [$course])->fetchAll() as $r) {
        if (isset($m[$r['role']][$r['perm']])) $m[$r['role']][$r['perm']] = (bool)(int)$r['allowed'];
    }
    return $m;
}

function can(array $u, string $perm): bool {
    if ($u['role'] === 'admin') return true;
    $course = user_course_id($u);
    return $course ? (access_matrix($course)[$u['role']][$perm] ?? false) : false;
}

function my_perms(array $u): array { return array_values(array_filter(PERMS, fn($p) => can($u, $p))); }

function require_perm(string $perm): array {
    $u = require_login();
    if (!can($u, $perm)) fail(403, 'You do not have access to this. Ask your admin.');
    return $u;
}

function require_course_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') fail(403, 'Admin access only');
    return $u;
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

/** Fail unless this course offers the hire type (shoes / racquet are per-course features; Natadola has no tennis). */
function require_hire_kind(int $course, string $kind): void {
    if ($kind !== 'shoes' && $kind !== 'racquet') return;
    $col = $kind === 'racquet' ? 'tennis' : 'shoes';
    if (!(int)q("SELECT $col FROM courses WHERE id=?", [$course])->fetchColumn()) fail(422, ($kind === 'racquet' ? 'Tennis hire' : 'Shoe hire') . ' is not set up for this course');
}

/** A real calendar date as Y-m-d, or null. */
function valid_date($d): ?string {
    if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return $d;
}

/** The member row for this course matching a member number and/or mobile (newest expiry first), or null. */
function find_member(int $course, ?string $no, string $mobile, ?string $first = null, ?string $last = null): ?array {
    $conds = []; $p = [$course];
    if ($no) { $conds[] = 'member_no=?'; $p[] = $no; }
    if (strlen($mobile) >= 5) { $conds[] = 'mobile=?'; $p[] = $mobile; }
    if (!$conds) return null;
    $sql = 'SELECT id,member_no,first_name,last_name,mobile,email,expires_on,(expires_on IS NOT NULL AND expires_on < CURDATE()) AS expired,
              DATEDIFF(expires_on, CURDATE()) AS days_left
              FROM members WHERE course_id=? AND (' . implode(' OR ', $conds) . ')';
    if ($first !== null && $last !== null) { $sql .= ' AND LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?)'; array_push($p, $first, $last); } // the golfer must BE the member
    return q($sql . ' ORDER BY (expires_on IS NULL), expires_on DESC LIMIT 1', $p)->fetch() ?: null;
}

function is_admin(array $u): bool { return $u['role'] === 'admin' && user_course_id($u) === null; }

function require_admin(): array {
    $u = require_course_admin();
    if (!is_admin($u)) fail(403, 'Head-office admin only');
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

/** Terms wording for a course: its own newest version, else the newest shared (course_id NULL), else the newest of any. */
function terms_for(int $course): array {
    return q('SELECT version, body FROM terms ORDER BY (course_id = ?) DESC, (course_id IS NULL) DESC, version DESC LIMIT 1', [$course])->fetch() ?: ['version' => 0, 'body' => ''];
}

/** Which green-fee rate applies to a golfer: a verified member, a resort/hotel guest, a Fiji resident, or any other visitor. */
function rate_category(bool $isMember, ?string $hotel, ?string $country): string {
    if ($isMember) return 'member';
    if ($hotel !== null && trim($hotel) !== '') return 'resort';
    if (in_array(strtolower(trim((string)$country)), ['fiji', 'fijian', 'fiji islands'], true)) return 'resident';
    return 'visitor';
}

/** The course's rate card as [holes][category] => amount (empty until an admin sets one). */
function rates_for(int $course): array {
    $r = [9 => [], 18 => []];
    try { foreach (q('SELECT holes, category, amount FROM rates WHERE course_id=?', [$course])->fetchAll() as $x) $r[(int)$x['holes']][$x['category']] = (float)$x['amount']; }
    catch (Throwable $e) { /* rates table not created yet */ }
    return $r;
}

/** SQL fragment: check-in "c" currently holds asset "a" (cart, clubs, or an extra hire item such as shoes/racquet). */
const HOLDS_SQL = "(c.cart_id = a.id OR c.clubs_id = a.id OR c.id IN (SELECT i.checkin_id FROM checkin_items i WHERE i.asset_id = a.id))";

/** SQL fragment: effective status for an asset row aliased "a". */
const STATUS_SQL = "CASE
    WHEN EXISTS (SELECT 1 FROM checkins c WHERE c.returned_at IS NULL AND (c.cart_id = a.id OR c.clubs_id = a.id OR c.id IN (SELECT i.checkin_id FROM checkin_items i WHERE i.asset_id = a.id))) THEN 'out'
    WHEN a.maintenance = 1 THEN 'maintenance'
    WHEN a.gps_status IS NOT NULL AND a.gps_status <> 'ready' THEN a.gps_status
    ELSE 'available' END";

/** Payment status recorded at check-in (the system never takes money): everything is paid up front, or charged to the room where the course allows it. */
const PAY_METHODS = ['paid', 'room'];

const DEFAULT_GUEST_TYPES = [
    ['member', 'Member', 0], ['resort', 'Resort / hotel guest', 0], ['resident', 'Fiji resident', 0], ['visitor', 'Visitor', 0],
];

/** The course's guest types as [code => ['code','label','proof','active']] (member / resort / resident / visitor always exist, plus whatever the admin adds). */
function guest_types_for(int $course, bool $onlyActive = true): array {
    $t = [];
    try {
        foreach (q('SELECT code, label, proof, active FROM guest_types WHERE course_id=? ORDER BY sort, id', [$course])->fetchAll() as $r)
            if (!$onlyActive || (int)$r['active']) $t[$r['code']] = ['code' => $r['code'], 'label' => $r['label'], 'proof' => (int)$r['proof'], 'active' => (int)$r['active']];
    } catch (Throwable $e) { /* guest_types not created yet */ }
    if (!$t) foreach (DEFAULT_GUEST_TYPES as [$c, $l, $p]) $t[$c] = ['code' => $c, 'label' => $l, 'proof' => $p, 'active' => 1];
    return $t;
}

/** Price of a club set for the round length (a 9-hole price when the admin set one). */
function club_fee(array $asset, int $holes): float {
    return $holes === 9 && ($asset['fee9'] ?? null) !== null && $asset['fee9'] !== '' ? (float)$asset['fee9'] : (float)$asset['fee'];
}
