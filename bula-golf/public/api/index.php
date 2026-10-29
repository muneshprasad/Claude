<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$route = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'bula') fail(403, 'Bad request');

try {
    switch ($route) {

    // ---------- auth ----------
    case 'auth/login': {
        $b = body();
        $u = strtolower(trim((string)($b['username'] ?? '')));
        $p = (string)($b['password'] ?? '');
        $n = (int)q("SELECT COUNT(*) FROM login_attempts WHERE username=? AND at > (NOW() - INTERVAL 10 MINUTE)", [$u])->fetchColumn();
        if ($n >= 6) fail(429, 'Too many attempts. Try again in 10 minutes.');
        $row = q('SELECT * FROM users WHERE username=? AND active=1', [$u])->fetch();
        if (!$row || !password_verify($p, $row['pass_hash'])) {
            q('INSERT INTO login_attempts (username, ip) VALUES (?,?)', [$u, ip()]);
            fail(401, 'Wrong username or password');
        }
        q('DELETE FROM login_attempts WHERE username=?', [$u]);
        $me = ['id' => (int)$row['id'], 'name' => $row['name'], 'role' => $row['role']];
        audit((int)$row['id'], 'login');
        if (!empty($b['app'])) { // mobile app: issue a token instead of a cookie session
            $tok = bin2hex(random_bytes(32));
            q('DELETE FROM api_tokens WHERE last_used < (NOW() - INTERVAL 30 DAY)');
            q('INSERT INTO api_tokens (token_hash, user_id) VALUES (?,?)', [hash('sha256', $tok), $me['id']]);
            out(['user' => $me, 'token' => $tok]);
        }
        start_session();
        session_regenerate_id(true);
        $_SESSION['user'] = $me;
        out(['user' => $me]);
    }
    case 'auth/logout':
        if (!empty($_SERVER['HTTP_X_BULA_TOKEN'])) q('DELETE FROM api_tokens WHERE token_hash=?', [hash('sha256', $_SERVER['HTTP_X_BULA_TOKEN'])]);
        else { start_session(); $_SESSION = []; session_destroy(); }
        out(['ok' => true]);
    case 'auth/me': {
        $u = user();
        $c = cfg();
        out(['user' => $u, 'currency' => $c['currency'], 'course' => $c['course_name']]);
    }

    // ---------- guests ----------
    case 'guests/lookup': {
        require_login();
        $m = norm_mobile((string)($_GET['mobile'] ?? ''));
        if (strlen($m) < 5) out(['guests' => []]);
        $rows = q('SELECT * FROM guests WHERE mobile=? ORDER BY last_visit DESC LIMIT 8', [$m])->fetchAll();
        foreach ($rows as &$g) {
            $g['last_clubs'] = q("SELECT a.name FROM checkins c JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.guest_id=?
                                  JOIN assets a ON a.id=c.clubs_id ORDER BY c.id DESC LIMIT 1", [$g['id']])->fetchColumn() ?: null;
        }
        out(['guests' => $rows]);
    }
    case 'guests/search': {
        require_login();
        $s = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string)($_GET['q'] ?? ''))) . '%';
        if (strlen($s) < 4) out(['guests' => []]);
        out(['guests' => q('SELECT * FROM guests WHERE CONCAT(first_name," ",last_name) LIKE ? OR mobile LIKE ? ORDER BY last_visit DESC LIMIT 15', [$s, $s])->fetchAll()]);
    }
    case 'guests/alert': { // manager sets or clears a patron warning note
        $u = require_manager();
        $b = body();
        q('UPDATE guests SET alert_note=? WHERE id=?', [clean($b['note'] ?? null, 255), (int)$b['id']]);
        audit($u['id'], 'guest_alert', 'guest ' . (int)$b['id']);
        out(['ok' => true]);
    }

    // ---------- assets ----------
    case 'assets': {
        require_login();
        $showAll = ($_GET['all'] ?? '') === '1';
        $rows = q('SELECT a.id,a.kind,a.name,a.description,a.fee,a.sort_order,a.maintenance,a.active,a.gps_unit_id,a.gps_status,a.gps_battery,a.gps_lat,a.gps_lng,a.gps_seen, ' . STATUS_SQL . ' AS status
                   FROM assets a ' . ($showAll ? '' : 'WHERE a.active=1 ') . 'ORDER BY a.kind, a.sort_order, a.name')->fetchAll();
        out(['assets' => $rows]);
    }
    case 'assets/save': {
        $u = require_manager();
        $b = body();
        $kind = in_array($b['kind'] ?? '', ['cart', 'clubs'], true) ? $b['kind'] : fail(422, 'Bad kind');
        $name = clean($b['name'] ?? null, 80) ?? fail(422, 'Name required');
        $vals = [$kind, $name, clean($b['description'] ?? null), max(0, (float)($b['fee'] ?? 0)), (int)($b['sort_order'] ?? 0),
                 empty($b['maintenance']) ? 0 : 1, empty($b['active']) && isset($b['active']) ? 0 : 1, clean($b['gps_unit_id'] ?? null, 80)];
        if (!empty($b['id'])) {
            q('UPDATE assets SET kind=?,name=?,description=?,fee=?,sort_order=?,maintenance=?,active=?,gps_unit_id=? WHERE id=?', [...$vals, (int)$b['id']]);
        } else {
            q('INSERT INTO assets (kind,name,description,fee,sort_order,maintenance,active,gps_unit_id) VALUES (?,?,?,?,?,?,?,?)', $vals);
        }
        audit($u['id'], 'asset_save', "$kind $name");
        out(['ok' => true]);
    }

    // ---------- check-ins ----------
    case 'checkins/create': {
        $u = require_login();
        $b = body();
        $golfers = $b['golfers'] ?? [];
        if (!is_array($golfers) || !$golfers || count($golfers) > 8) fail(422, 'Add 1 to 8 golfers');
        $holes = (int)($b['holes'] ?? 18) === 9 ? 9 : 18;
        $fee = in_array($b['fee_status'] ?? '', ['paid', 'room', 'unpaid'], true) ? $b['fee_status'] : 'unpaid';
        $cartId = !empty($b['cart_id']) ? (int)$b['cart_id'] : null;
        $clubsId = !empty($b['clubs_id']) ? (int)$b['clubs_id'] : null;
        $terms = (int)q('SELECT MAX(version) FROM terms')->fetchColumn();

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // lock chosen assets, then make sure they are really free
            $hire = 0.0;
            foreach ([['cart', $cartId], ['clubs', $clubsId]] as [$kind, $id]) {
                if (!$id) continue;
                $a = q('SELECT a.*, ' . STATUS_SQL . ' AS status FROM assets a WHERE a.id=? AND a.kind=? AND a.active=1 FOR UPDATE', [$id, $kind])->fetch();
                if (!$a) fail(422, 'Unknown ' . $kind);
                if ($a['status'] !== 'available') fail(409, "{$a['name']} is not available ({$a['status']})");
                $hire += (float)$a['fee'];
            }
            $ci = q('INSERT INTO checkins (main_guest_id,holes,fee_status,tee_time,cart_id,clubs_id,hire_fee,notes,terms_version,staff_id) VALUES (NULL,?,?,?,?,?,?,?,?,?)',
                [$holes, $fee, clean($b['tee_time'] ?? null, 5), $cartId, $clubsId, $hire, clean($b['notes'] ?? null, 500), $terms, $u['id']]);
            $ciId = (int)$pdo->lastInsertId();
            $mainId = 0;
            foreach ($golfers as $i => $g) {
                $first = clean($g['first_name'] ?? null, 80); $last = clean($g['last_name'] ?? null, 80);
                $mobile = norm_mobile((string)($g['mobile'] ?? ''));
                if (!$first || !$last || strlen($mobile) < 5) fail(422, 'Golfer ' . ($i + 1) . ': name and mobile are required');
                if (!valid_png((string)($g['signature'] ?? ''))) fail(422, "$first $last has not signed");
                $dob = !empty($g['dob']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['dob']) ? $g['dob'] : null;
                $guardian = clean($g['guardian_name'] ?? null, 120);
                if ($dob && (new DateTime($dob))->diff(new DateTime())->y < 18 && !$guardian) fail(422, "$first $last is under 18: parent/guardian name required");

                $row = q('SELECT id, alert_note FROM guests WHERE mobile=? AND LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) LIMIT 1', [$mobile, $first, $last])->fetch();
                $f = [clean($g['email'] ?? null), clean($g['country'] ?? null, 80), clean($g['hotel'] ?? null), clean($g['room'] ?? null, 30), $dob,
                      ($g['guest_type'] ?? '') === 'member' ? 'member' : 'guest', empty($g['marketing_optin']) ? 0 : 1];
                if ($row) {
                    $gid = (int)$row['id'];
                    q('UPDATE guests SET email=COALESCE(?,email),country=COALESCE(?,country),hotel=?,room=?,dob=COALESCE(?,dob),guest_type=?,marketing_optin=?,visits=visits+1,last_visit=NOW() WHERE id=?', [...$f, $gid]);
                } else {
                    q('INSERT INTO guests (mobile,first_name,last_name,email,country,hotel,room,dob,guest_type,marketing_optin,visits,last_visit) VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW())', [$mobile, $first, $last, ...$f]);
                    $gid = (int)$pdo->lastInsertId();
                }
                if ($i === 0) $mainId = $gid;
                q('INSERT INTO checkin_golfers (checkin_id,guest_id,is_main,signature,signed_ip,guardian_name) VALUES (?,?,?,?,?,?)',
                    [$ciId, $gid, $i === 0 ? 1 : 0, $g['signature'], ip(), $guardian]);
            }
            q('UPDATE checkins SET main_guest_id=? WHERE id=?', [$mainId, $ciId]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        audit($u['id'], 'checkin', "id $ciId");
        out(['id' => $ciId, 'hire_fee' => $hire]);
    }

    case 'checkins/active': {
        require_login();
        out(['checkins' => checkin_rows('c.returned_at IS NULL', [])]);
    }
    case 'checkins/list': {
        $u = require_login();
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
        if ($u['role'] !== 'manager' && $d !== date('Y-m-d')) fail(403, 'Managers only');
        out(['checkins' => checkin_rows('DATE(c.checked_in_at)=?', [$d])]);
    }
    case 'checkins/get': {
        require_manager();
        $id = (int)($_GET['id'] ?? 0);
        $c = q('SELECT c.*, ca.name cart_name, cl.name clubs_name, u.name staff_name FROM checkins c LEFT JOIN assets ca ON ca.id=c.cart_id LEFT JOIN assets cl ON cl.id=c.clubs_id JOIN users u ON u.id=c.staff_id WHERE c.id=?', [$id])->fetch() ?: fail(404, 'Not found');
        $c['golfers'] = q('SELECT g.first_name,g.last_name,g.mobile,g.email,g.hotel,g.room,cg.signature,cg.signed_at,cg.guardian_name,cg.is_main FROM checkin_golfers cg JOIN guests g ON g.id=cg.guest_id WHERE cg.checkin_id=? ORDER BY cg.id', [$id])->fetchAll();
        $c['terms'] = q('SELECT body FROM terms WHERE version=?', [$c['terms_version']])->fetchColumn();
        out($c);
    }
    case 'checkins/return': {
        $u = require_login();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $cond = in_array($b['condition'] ?? '', ['ok', 'damaged', 'warning'], true) ? $b['condition'] : fail(422, 'Choose a condition');
        $photo = (string)($b['photo'] ?? '');
        if ($photo !== '' && (strpos($photo, 'data:image/jpeg;base64,') !== 0 || strlen($photo) > 1500000)) fail(422, 'Bad photo');
        $c = q('SELECT * FROM checkins WHERE id=? AND returned_at IS NULL FOR UPDATE', [$id])->fetch();
        if (!$c) fail(404, 'Already returned');
        if ($cond !== 'ok' && !clean($b['note'] ?? null)) fail(422, 'Please describe the problem');
        q('UPDATE checkins SET returned_at=NOW(), return_staff_id=?, return_condition=?, return_note=?, return_photo=? WHERE id=?',
            [$u['id'], $cond, clean($b['note'] ?? null, 500), $photo ?: null, $id]);
        if ($cond === 'damaged' && $c['cart_id']) q('UPDATE assets SET maintenance=1 WHERE id=?', [$c['cart_id']]);
        if ($cond === 'damaged' && $c['clubs_id']) q('UPDATE assets SET maintenance=1 WHERE id=?', [$c['clubs_id']]);
        if ($cond === 'warning') {
            q('UPDATE guests SET alert_note=? WHERE id=?', ['Patron warning ' . date('j M Y') . ': ' . (clean($b['note'] ?? null, 200) ?? ''), $c['main_guest_id']]);
        }
        audit($u['id'], 'return', "id $id $cond");
        out(['ok' => true]);
    }

    // ---------- terms ----------
    case 'terms/current': {
        require_login();
        $t = q('SELECT version, body FROM terms ORDER BY version DESC LIMIT 1')->fetch();
        out($t);
    }
    case 'terms/save': {
        $u = require_manager();
        $t = trim((string)(body()['body'] ?? ''));
        if (mb_strlen($t) < 50) fail(422, 'Terms look too short');
        q('INSERT INTO terms (body, created_by) VALUES (?,?)', [$t, $u['id']]);
        audit($u['id'], 'terms_save');
        out(['ok' => true]);
    }

    // ---------- dashboard / export ----------
    case 'dashboard': {
        require_manager();
        $days = q("SELECT DATE(checked_in_at) d, COUNT(*) n, SUM(hire_fee) fees FROM checkins WHERE checked_in_at >= (CURDATE() - INTERVAL 14 DAY) GROUP BY d ORDER BY d")->fetchAll();
        $t = q("SELECT COUNT(*) rounds, COALESCE(SUM(hire_fee),0) fees, SUM(returned_at IS NULL) out_now FROM checkins WHERE DATE(checked_in_at)=CURDATE()")->fetch();
        $a = q("SELECT status, COUNT(*) n FROM (SELECT a.kind, " . STATUS_SQL . " AS status FROM assets a WHERE a.active=1 AND a.kind='cart') x GROUP BY status")->fetchAll();
        $damaged = q("SELECT c.id, c.checked_in_at, c.return_note, CONCAT(g.first_name,' ',g.last_name) guest FROM checkins c JOIN guests g ON g.id=c.main_guest_id WHERE c.return_condition IN ('damaged','warning') ORDER BY c.id DESC LIMIT 10")->fetchAll();
        out(['days' => $days, 'today' => $t, 'carts' => $a, 'issues' => $damaged]);
    }
    case 'export': {
        $u = require_manager();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
        $marketing = ($_GET['type'] ?? '') === 'marketing';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bula-golf-' . ($marketing ? 'marketing-' : '') . "$from-$to.csv\"");
        $f = fopen('php://output', 'w');
        fwrite($f, "\xEF\xBB\xBF");
        if ($marketing) {
            fputcsv($f, ['first_name', 'last_name', 'email', 'mobile', 'country']);
            foreach (q('SELECT first_name,last_name,email,mobile,country FROM guests WHERE marketing_optin=1 AND email IS NOT NULL ORDER BY last_name')->fetchAll() as $r) fputcsv($f, $r);
        } else {
            fputcsv($f, ['id', 'checked_in', 'returned', 'main_guest', 'mobile', 'hotel', 'room', 'golfers', 'holes', 'fee_status', 'cart', 'clubs', 'hire_fee', 'return_condition', 'return_note', 'staff']);
            foreach (q("SELECT c.id,c.checked_in_at,c.returned_at,CONCAT(g.first_name,' ',g.last_name) guest,g.mobile,g.hotel,g.room,
                        (SELECT COUNT(*) FROM checkin_golfers x WHERE x.checkin_id=c.id) golfers,c.holes,c.fee_status,ca.name cart,cl.name clubs,c.hire_fee,c.return_condition,c.return_note,u.name staff
                        FROM checkins c JOIN guests g ON g.id=c.main_guest_id LEFT JOIN assets ca ON ca.id=c.cart_id LEFT JOIN assets cl ON cl.id=c.clubs_id JOIN users u ON u.id=c.staff_id
                        WHERE DATE(c.checked_in_at) BETWEEN ? AND ? ORDER BY c.id", [$from, $to])->fetchAll() as $r) fputcsv($f, $r);
        }
        audit($u['id'], 'export', ($marketing ? 'marketing ' : '') . "$from..$to");
        exit;
    }

    // ---------- users ----------
    case 'users/list': {
        require_manager();
        out(['users' => q('SELECT id,username,name,role,active FROM users ORDER BY name')->fetchAll()]);
    }
    case 'users/save': {
        $u = require_manager();
        $b = body();
        $un = strtolower(trim((string)($b['username'] ?? '')));
        $name = clean($b['name'] ?? null, 100);
        $role = ($b['role'] ?? '') === 'manager' ? 'manager' : 'staff';
        $pw = (string)($b['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $un) || !$name) fail(422, 'Username (3+ letters/numbers) and name required');
        if (!empty($b['id'])) {
            if ((int)$b['id'] === $u['id'] && (isset($b['active']) && !$b['active'] || $role !== 'manager')) fail(422, "You can't demote or disable yourself");
            q('UPDATE users SET username=?,name=?,role=?,active=? WHERE id=?', [$un, $name, $role, empty($b['active']) && isset($b['active']) ? 0 : 1, (int)$b['id']]);
            if ($pw !== '') { if (strlen($pw) < 8) fail(422, 'Password: 8+ characters'); q('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), (int)$b['id']]); }
        } else {
            if (strlen($pw) < 8) fail(422, 'Password: 8+ characters');
            if (q('SELECT 1 FROM users WHERE username=?', [$un])->fetch()) fail(409, 'Username taken');
            q('INSERT INTO users (username,name,role,pass_hash) VALUES (?,?,?,?)', [$un, $name, $role, password_hash($pw, PASSWORD_DEFAULT)]);
        }
        audit($u['id'], 'user_save', $un);
        out(['ok' => true]);
    }

    default: fail(404, 'Unknown route');
    }
} catch (PDOException $e) {
    error_log('bulagolf: ' . $e->getMessage());
    fail(500, 'Database error');
}

function checkin_rows(string $where, array $p): array {
    return q("SELECT c.id,c.checked_in_at,c.returned_at,c.holes,c.fee_status,c.tee_time,c.hire_fee,c.notes,c.return_condition,c.return_note,
              g.id guest_id, CONCAT(g.first_name,' ',g.last_name) guest, g.mobile, g.hotel, g.room, g.alert_note,
              (SELECT COUNT(*) FROM checkin_golfers x WHERE x.checkin_id=c.id) golfers,
              ca.name cart_name, cl.name clubs_name, u.name staff
              FROM checkins c JOIN guests g ON g.id=c.main_guest_id LEFT JOIN assets ca ON ca.id=c.cart_id LEFT JOIN assets cl ON cl.id=c.clubs_id JOIN users u ON u.id=c.staff_id
              WHERE $where ORDER BY c.id DESC LIMIT 300", $p)->fetchAll();
}
