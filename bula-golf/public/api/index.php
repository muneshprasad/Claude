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
        $courses = q('SELECT id,name,full_name,tennis,shoes,(logo IS NOT NULL) AS has_logo,UNIX_TIMESTAMP(logo_at) AS logo_v FROM courses WHERE active=1 ORDER BY id')->fetchAll();
        $meOut = $me + ['course_id' => $row['course_id'] === null ? null : (int)$row['course_id']];
        audit((int)$row['id'], 'login');
        if (!empty($b['app'])) { // mobile app: issue a token instead of a cookie session
            $tok = bin2hex(random_bytes(32));
            q('DELETE FROM api_tokens WHERE last_used < (NOW() - INTERVAL 30 DAY)');
            q('INSERT INTO api_tokens (token_hash, user_id) VALUES (?,?)', [hash('sha256', $tok), $me['id']]);
            out(['user' => $meOut, 'token' => $tok, 'courses' => $courses]);
        }
        start_session();
        session_regenerate_id(true);
        $_SESSION['user'] = $me;
        out(['user' => $meOut, 'courses' => $courses]);
    }
    case 'auth/logout':
        if (!empty($_SERVER['HTTP_X_BULA_TOKEN'])) q('DELETE FROM api_tokens WHERE token_hash=?', [hash('sha256', $_SERVER['HTTP_X_BULA_TOKEN'])]);
        else { start_session(); $_SESSION = []; session_destroy(); }
        out(['ok' => true]);
    case 'auth/me': {
        $u = user();
        $c = cfg();
        out(['user' => $u ? $u + ['course_id' => user_course_id($u)] : null, 'currency' => $c['currency'], 'course' => $c['course_name'],
              'courses' => $u ? q('SELECT id,name,full_name,tennis,shoes,(logo IS NOT NULL) AS has_logo,UNIX_TIMESTAMP(logo_at) AS logo_v FROM courses WHERE active=1 ORDER BY id')->fetchAll() : []]);
    }

    // ---------- guests ----------
    case 'guests/lookup': {
        $u = require_login();
        $m = norm_mobile((string)($_GET['mobile'] ?? ''));
        $no = clean($_GET['member_no'] ?? null, 40);
        $member = find_member(course_id($u), $no, $m); // this course's member list, by member number or mobile
        if (strlen($m) < 5) out(['guests' => [], 'member' => $member]);
        $rows = q('SELECT * FROM guests WHERE mobile=? ORDER BY last_visit DESC LIMIT 8', [$m])->fetchAll();
        foreach ($rows as &$g) {
            // membership is per course: use what they were last checked in as HERE, not at the other course
            $g['guest_type'] = q('SELECT cg.guest_type FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE cg.guest_id=? AND c.course_id=? ORDER BY cg.id DESC LIMIT 1', [$g['id'], course_id($u)])->fetchColumn() ?: 'guest';
            $g['last_clubs'] = q("SELECT a.name FROM checkins c JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.guest_id=?
                                  JOIN assets a ON a.id=c.clubs_id ORDER BY c.id DESC LIMIT 1", [$g['id']])->fetchColumn() ?: null;
        }
        out(['guests' => $rows, 'member' => $member]);
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
        $u = require_login();
        $showAll = ($_GET['all'] ?? '') === '1';
        $rows = q('SELECT a.id,a.kind,a.name,a.description,a.fee,a.sort_order,a.maintenance,a.active,a.gps_unit_id,a.gps_status,a.gps_battery,a.gps_lat,a.gps_lng,a.gps_seen, ' . STATUS_SQL . ' AS status
                   FROM assets a WHERE a.course_id=? ' . ($showAll ? '' : 'AND a.active=1 ') . 'ORDER BY a.kind, a.sort_order, a.name', [course_id($u)])->fetchAll();
        out(['assets' => $rows]);
    }
    case 'assets/save': {
        $u = require_manager();
        $b = body();
        $kind = in_array($b['kind'] ?? '', ['cart', 'clubs', 'shoes', 'racquet'], true) ? $b['kind'] : fail(422, 'Bad kind');
        $name = clean($b['name'] ?? null, 80) ?? fail(422, 'Name required');
        $vals = [$kind, $name, clean($b['description'] ?? null), max(0, (float)($b['fee'] ?? 0)), (int)($b['sort_order'] ?? 0),
                 empty($b['maintenance']) ? 0 : 1, empty($b['active']) && isset($b['active']) ? 0 : 1, clean($b['gps_unit_id'] ?? null, 80)];
        $course = course_id($u);
        require_hire_kind($course, $kind);
        if (!empty($b['id'])) {
            q('UPDATE assets SET kind=?,name=?,description=?,fee=?,sort_order=?,maintenance=?,active=?,gps_unit_id=? WHERE id=? AND course_id=?', [...$vals, (int)$b['id'], $course]);
        } else {
            q('INSERT INTO assets (kind,name,description,fee,sort_order,maintenance,active,gps_unit_id,course_id) VALUES (?,?,?,?,?,?,?,?,?)', [...$vals, $course]);
        }
        audit($u['id'], 'asset_save', "$kind $name");
        out(['ok' => true]);
    }

    case 'assets/bulk': { // create a run of carts/club sets, e.g. "Cart 1".."Cart 40"; names that already exist are skipped
        $u = require_manager();
        $b = body();
        $kind = in_array($b['kind'] ?? '', ['cart', 'clubs', 'shoes', 'racquet'], true) ? $b['kind'] : fail(422, 'Bad kind');
        $prefix = clean($b['prefix'] ?? null, 60) ?? fail(422, 'Name prefix required');
        $from = max(0, (int)($b['from'] ?? 1)); $count = (int)($b['count'] ?? 0);
        if ($count < 1 || $count > 200) fail(422, 'Count must be 1 to 200');
        $fee = max(0, (float)($b['fee'] ?? 0)); $desc = clean($b['description'] ?? null);
        $course = course_id($u); $made = 0;
        require_hire_kind($course, $kind);
        for ($i = $from; $i < $from + $count; $i++) {
            $made += q('INSERT IGNORE INTO assets (course_id,kind,name,description,fee,sort_order) VALUES (?,?,?,?,?,?)', [$course, $kind, "$prefix $i", $desc, $fee, $i])->rowCount();
        }
        audit($u['id'], 'asset_bulk', "course $course $kind $prefix x$made");
        out(['created' => $made, 'skipped' => $count - $made]);
    }

    // ---------- members (each course keeps its own list) ----------
    case 'members/list': {
        $u = require_manager();
        $cid = course_id($u);
        $w = 'course_id=?'; $p = [$cid];
        $s = trim((string)($_GET['q'] ?? ''));
        if ($s !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%'; $w .= ' AND (CONCAT(first_name," ",last_name) LIKE ? OR member_no LIKE ? OR mobile LIKE ?)'; array_push($p, $like, $like, $like); }
        $st = $_GET['status'] ?? 'all';
        if ($st === 'expired') $w .= ' AND expires_on < CURDATE()';
        elseif ($st === 'expiring') $w .= ' AND expires_on BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY)';
        elseif ($st === 'active') $w .= ' AND (expires_on IS NULL OR expires_on >= CURDATE())';
        out(['members' => q("SELECT id,member_no,first_name,last_name,mobile,email,expires_on FROM members WHERE $w ORDER BY last_name, first_name LIMIT 300", $p)->fetchAll(),
             'counts' => q('SELECT COUNT(*) total, COALESCE(SUM(expires_on IS NULL OR expires_on >= CURDATE()),0) active, COALESCE(SUM(expires_on < CURDATE()),0) expired,
                            COALESCE(SUM(expires_on BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY)),0) expiring FROM members WHERE course_id=?', [$cid])->fetch()]);
    }
    case 'members/import': { // rows parsed in the browser from the course's CSV; "replace" swaps the whole list
        $u = require_manager();
        $cid = course_id($u);
        $b = body();
        $rows = $b['rows'] ?? [];
        if (!is_array($rows) || !$rows) fail(422, 'No members to import');
        if (count($rows) > 5000) fail(422, 'Up to 5000 members per upload');
        $pdo = db();
        $created = $updated = 0; $errors = [];
        $pdo->beginTransaction();
        try {
            if (($b['mode'] ?? '') === 'replace') q('DELETE FROM members WHERE course_id=?', [$cid]);
            foreach ($rows as $i => $r) {
                $first = clean($r['first_name'] ?? null, 80); $last = clean($r['last_name'] ?? null, 80);
                if (!$first || !$last) { $errors[] = 'Row ' . ($i + 1) . ': first and last name needed'; continue; }
                $no = clean($r['member_no'] ?? null, 40);
                $mobile = norm_mobile((string)($r['mobile'] ?? '')); $mobile = strlen($mobile) >= 5 ? $mobile : null;
                $email = clean($r['email'] ?? null, 150);
                $exp = valid_date($r['expires_on'] ?? null);
                $row = $no ? q('SELECT id FROM members WHERE course_id=? AND member_no=?', [$cid, $no])->fetch()
                           : q('SELECT id FROM members WHERE course_id=? AND LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) AND mobile <=> ?', [$cid, $first, $last, $mobile])->fetch();
                if ($row) { q('UPDATE members SET member_no=?,first_name=?,last_name=?,mobile=?,email=?,expires_on=? WHERE id=?', [$no, $first, $last, $mobile, $email, $exp, $row['id']]); $updated++; }
                else { q('INSERT INTO members (course_id,member_no,first_name,last_name,mobile,email,expires_on) VALUES (?,?,?,?,?,?,?)', [$cid, $no, $first, $last, $mobile, $email, $exp]); $created++; }
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        audit($u['id'], 'members_import', "course $cid +$created ~$updated" . (($b['mode'] ?? '') === 'replace' ? ' replace' : ''));
        out(['created' => $created, 'updated' => $updated, 'error_count' => count($errors), 'errors' => array_slice($errors, 0, 20)]);
    }
    case 'members/save': {
        $u = require_manager();
        $cid = course_id($u);
        $b = body();
        $first = clean($b['first_name'] ?? null, 80) ?? fail(422, 'First name required');
        $last = clean($b['last_name'] ?? null, 80) ?? fail(422, 'Last name required');
        $no = clean($b['member_no'] ?? null, 40);
        $mobile = norm_mobile((string)($b['mobile'] ?? '')); $mobile = strlen($mobile) >= 5 ? $mobile : null;
        $vals = [$no, $first, $last, $mobile, clean($b['email'] ?? null, 150), valid_date($b['expires_on'] ?? null)];
        if ($no && q('SELECT 1 FROM members WHERE course_id=? AND member_no=? AND id<>?', [$cid, $no, (int)($b['id'] ?? 0)])->fetch()) fail(409, 'That member number is already used');
        if (!empty($b['id'])) q('UPDATE members SET member_no=?,first_name=?,last_name=?,mobile=?,email=?,expires_on=? WHERE id=? AND course_id=?', [...$vals, (int)$b['id'], $cid]);
        else q('INSERT INTO members (member_no,first_name,last_name,mobile,email,expires_on,course_id) VALUES (?,?,?,?,?,?,?)', [...$vals, $cid]);
        audit($u['id'], 'member_save', "$first $last");
        out(['ok' => true]);
    }
    case 'members/delete': {
        $u = require_manager();
        q('DELETE FROM members WHERE id=? AND course_id=?', [(int)(body()['id'] ?? 0), course_id($u)]);
        audit($u['id'], 'member_delete', 'id ' . (int)(body()['id'] ?? 0));
        out(['ok' => true]);
    }

    // ---------- check-ins ----------
    case 'checkins/create': {
        $u = require_login();
        $b = body();
        $golfers = $b['golfers'] ?? [];
        if (!is_array($golfers) || !$golfers || count($golfers) > 8) fail(422, 'Add 1 to 8 golfers');
        $holes = (int)($b['holes'] ?? 18) === 9 ? 9 : 18;
        $fee = in_array($b['fee_status'] ?? '', ['paid', 'room', 'unpaid', 'member'], true) ? $b['fee_status'] : 'unpaid';
        $cartId = !empty($b['cart_id']) ? (int)$b['cart_id'] : null;
        $clubsId = !empty($b['clubs_id']) ? (int)$b['clubs_id'] : null;
        $course = course_id($u);
        $terms = (int)terms_for($course)['version'];
        $itemIds = array_values(array_unique(array_map('intval', is_array($b['items'] ?? null) ? $b['items'] : [])));
        if (count($itemIds) > 12) fail(422, 'Too many hire items');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // lock chosen assets, then make sure they are really free
            $hire = 0.0;
            foreach ([['cart', $cartId], ['clubs', $clubsId]] as [$kind, $id]) {
                if (!$id) continue;
                $a = q('SELECT a.*, ' . STATUS_SQL . ' AS status FROM assets a WHERE a.id=? AND a.kind=? AND a.active=1 AND a.course_id=? FOR UPDATE', [$id, $kind, $course])->fetch();
                if (!$a) fail(422, 'Unknown ' . $kind);
                if ($a['status'] !== 'available') fail(409, "{$a['name']} is not available ({$a['status']})");
                $hire += (float)$a['fee'];
            }
            $items = []; // rental shoes / tennis racquets
            foreach ($itemIds as $id) {
                $a = q('SELECT a.*, ' . STATUS_SQL . " AS status FROM assets a WHERE a.id=? AND a.kind IN ('shoes','racquet') AND a.active=1 AND a.course_id=? FOR UPDATE", [$id, $course])->fetch();
                if (!$a) fail(422, 'Unknown hire item');
                require_hire_kind($course, $a['kind']);
                if ($a['status'] !== 'available') fail(409, "{$a['name']} is not available ({$a['status']})");
                $hire += (float)$a['fee'];
                $items[] = [$id, (float)$a['fee']];
            }
            $ci = q('INSERT INTO checkins (course_id,main_guest_id,holes,fee_status,tee_time,cart_id,clubs_id,hire_fee,notes,bags,terms_version,staff_id) VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?)',
                [$course, $holes, $fee, clean($b['tee_time'] ?? null, 5), $cartId, $clubsId, $hire, clean($b['notes'] ?? null, 500), clean($b['bags'] ?? null, 40), $terms, $u['id']]);
            $ciId = (int)$pdo->lastInsertId();
            foreach ($items as [$aid, $afee]) q('INSERT INTO checkin_items (checkin_id,asset_id,hire_fee) VALUES (?,?,?)', [$ciId, $aid, $afee]);
            $mainId = 0; $allMembers = true;
            foreach ($golfers as $i => $g) {
                $first = clean($g['first_name'] ?? null, 80); $last = clean($g['last_name'] ?? null, 80);
                $mobile = norm_mobile((string)($g['mobile'] ?? ''));
                if (!$first || !$last || strlen($mobile) < 5) fail(422, 'Golfer ' . ($i + 1) . ': name and mobile are required');
                if (!valid_png((string)($g['signature'] ?? ''))) fail(422, "$first $last has not signed");
                $dob = !empty($g['dob']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['dob']) ? $g['dob'] : null;
                $guardian = clean($g['guardian_name'] ?? null, 120);
                if ($dob && (new DateTime($dob))->diff(new DateTime())->y < 18 && !$guardian) fail(422, "$first $last is under 18: parent/guardian name required");

                // Member status is never taken from the tablet: it must match this course's member list (name + number/mobile),
                // be unexpired, and staff must have confirmed they saw the member card / photo ID.
                $mem = find_member($course, clean($g['member_no'] ?? null, 40), $mobile, $first, $last);
                $isMember = $mem && !(int)$mem['expired'] && !empty($g['card_checked']);
                $memberId = $isMember ? (int)$mem['id'] : null;
                if (!$isMember) $allMembers = false;
                $row = q('SELECT id, alert_note FROM guests WHERE mobile=? AND LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) LIMIT 1', [$mobile, $first, $last])->fetch();
                $f = [clean($g['email'] ?? null), clean($g['country'] ?? null, 80), clean($g['hotel'] ?? null), clean($g['room'] ?? null, 30), $dob,
                      $isMember ? 'member' : 'guest', empty($g['marketing_optin']) ? 0 : 1];
                if ($row) {
                    $gid = (int)$row['id'];
                    q('UPDATE guests SET email=COALESCE(?,email),country=COALESCE(?,country),hotel=?,room=?,dob=COALESCE(?,dob),guest_type=?,marketing_optin=?,visits=visits+1,last_visit=NOW() WHERE id=?', [...$f, $gid]);
                } else {
                    q('INSERT INTO guests (mobile,first_name,last_name,email,country,hotel,room,dob,guest_type,marketing_optin,visits,last_visit) VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW())', [$mobile, $first, $last, ...$f]);
                    $gid = (int)$pdo->lastInsertId();
                }
                if ($i === 0) $mainId = $gid;
                q('INSERT INTO checkin_golfers (checkin_id,guest_id,is_main,signature,signed_ip,guardian_name,guest_type,member_id) VALUES (?,?,?,?,?,?,?,?)',
                    [$ciId, $gid, $i === 0 ? 1 : 0, $g['signature'], ip(), $guardian, $f[5], $memberId]);
            }
            if ($fee === 'member' && !$allMembers) fail(422, 'No green fee needs every golfer to be a verified, current member with their card or ID seen');
            q('UPDATE checkins SET main_guest_id=? WHERE id=?', [$mainId, $ciId]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        audit($u['id'], 'checkin', "id $ciId");
        out(['id' => $ciId, 'hire_fee' => $hire]);
    }

    case 'checkins/active': {
        $u = require_login();
        out(['checkins' => checkin_rows('c.course_id=? AND c.returned_at IS NULL', [course_id($u)])]);
    }
    case 'checkins/list': {
        $u = require_login();
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
        if ($u['role'] !== 'manager' && $d !== date('Y-m-d')) fail(403, 'Managers only');
        out(['checkins' => checkin_rows('c.course_id=? AND DATE(c.checked_in_at)=?', [course_id($u), $d])]);
    }
    case 'checkins/get': {
        $u = require_manager();
        $id = (int)($_GET['id'] ?? 0);
        $c = q('SELECT c.*, ca.name cart_name, cl.name clubs_name, u.name staff_name FROM checkins c LEFT JOIN assets ca ON ca.id=c.cart_id LEFT JOIN assets cl ON cl.id=c.clubs_id JOIN users u ON u.id=c.staff_id WHERE c.id=? AND c.course_id=?', [$id, course_id($u)])->fetch() ?: fail(404, 'Not found');
        $c['golfers'] = q('SELECT g.first_name,g.last_name,g.mobile,g.email,g.hotel,g.room,cg.signature,cg.signed_at,cg.guardian_name,cg.is_main,cg.guest_type,(SELECT mm.member_no FROM members mm WHERE mm.id=cg.member_id) AS member_no FROM checkin_golfers cg JOIN guests g ON g.id=cg.guest_id WHERE cg.checkin_id=? ORDER BY cg.id', [$id])->fetchAll();
        $c['terms'] = q('SELECT body FROM terms WHERE version=?', [$c['terms_version']])->fetchColumn();
        $c['items'] = q('SELECT a.name, a.kind, a.description FROM checkin_items i JOIN assets a ON a.id=i.asset_id WHERE i.checkin_id=? ORDER BY a.kind, a.name', [$id])->fetchAll();
        out($c);
    }
    case 'checkins/return': {
        $u = require_login();
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $cond = in_array($b['condition'] ?? '', ['ok', 'damaged', 'warning'], true) ? $b['condition'] : fail(422, 'Choose a condition');
        $photo = (string)($b['photo'] ?? '');
        if ($photo !== '' && (strpos($photo, 'data:image/jpeg;base64,') !== 0 || strlen($photo) > 1500000)) fail(422, 'Bad photo');
        $c = q('SELECT * FROM checkins WHERE id=? AND course_id=? AND returned_at IS NULL FOR UPDATE', [$id, course_id($u)])->fetch();
        if (!$c) fail(404, 'Already returned');
        if ($cond !== 'ok' && !clean($b['note'] ?? null)) fail(422, 'Please describe the problem');
        q('UPDATE checkins SET returned_at=NOW(), return_staff_id=?, return_condition=?, return_note=?, return_photo=? WHERE id=?',
            [$u['id'], $cond, clean($b['note'] ?? null, 500), $photo ?: null, $id]);
        if ($cond === 'damaged' && $c['cart_id']) q('UPDATE assets SET maintenance=1 WHERE id=?', [$c['cart_id']]);
        if ($cond === 'damaged' && $c['clubs_id']) q('UPDATE assets SET maintenance=1 WHERE id=?', [$c['clubs_id']]);
        if ($cond === 'damaged') q('UPDATE assets SET maintenance=1 WHERE id IN (SELECT asset_id FROM checkin_items WHERE checkin_id=?)', [$id]);
        if ($cond === 'warning') {
            q('UPDATE guests SET alert_note=? WHERE id=?', ['Patron warning ' . date('j M Y') . ': ' . (clean($b['note'] ?? null, 200) ?? ''), $c['main_guest_id']]);
        }
        audit($u['id'], 'return', "id $id $cond");
        out(['ok' => true]);
    }

    // ---------- terms ----------
    case 'terms/current': {
        require_login();
        $u = require_login();
        out(terms_for(course_id($u)));
    }
    case 'terms/save': {
        $u = require_admin();
        $t = trim((string)(body()['body'] ?? ''));
        if (mb_strlen($t) < 50) fail(422, 'Terms look too short');
        q('INSERT INTO terms (course_id, body, created_by) VALUES (?,?,?)', [course_id($u), $t, $u['id']]);
        audit($u['id'], 'terms_save', 'course ' . course_id($u));
        out(['ok' => true]);
    }

    // ---------- dashboard / export ----------
    case 'dashboard': {
        $u = require_manager();
        $cid = course_id($u);
        $range = in_array((int)($_GET['range'] ?? 7), [1, 7, 30], true) ? (int)$_GET['range'] : 7;
        $since = "c.course_id=$cid AND c.checked_in_at >= (CURDATE() - INTERVAL " . ($range - 1) . " DAY)";
        $sum = fn(string $where) => q("SELECT COUNT(*) rounds, COALESCE(SUM((SELECT COUNT(*) FROM checkin_golfers x WHERE x.checkin_id=c.id)),0) golfers,
                COALESCE(SUM(c.hire_fee),0) fees, COALESCE(SUM(c.returned_at IS NULL),0) out_now,
                COALESCE(SUM(CASE WHEN c.fee_status='unpaid' THEN c.hire_fee END),0) unpaid,
                COALESCE(SUM(c.return_condition IN ('damaged','warning')),0) issues,
                COALESCE(SUM((SELECT COUNT(*) FROM checkin_golfers m WHERE m.checkin_id=c.id AND m.is_main=1 AND m.guest_type='member')),0) members
                FROM checkins c WHERE $where")->fetch();
        $fleet = q("SELECT a.id, a.kind, a.name, a.gps_battery, " . STATUS_SQL . " AS status,
                (SELECT CONCAT(g.first_name,' ',g.last_name) FROM checkins c JOIN guests g ON g.id=c.main_guest_id WHERE c.returned_at IS NULL AND " . HOLDS_SQL . " LIMIT 1) guest,
                (SELECT c.checked_in_at FROM checkins c WHERE c.returned_at IS NULL AND " . HOLDS_SQL . " LIMIT 1) since
                FROM assets a WHERE a.course_id=? AND a.active=1 ORDER BY a.kind, a.sort_order, a.name", [$cid])->fetchAll();
        out([
            'course_id' => $cid, 'range' => $range,
            'today' => $sum("c.course_id=$cid AND DATE(c.checked_in_at)=CURDATE()"),
            'period' => $sum($since),
            'days' => q("SELECT DATE(c.checked_in_at) d, COUNT(*) n, SUM(c.hire_fee) fees FROM checkins c WHERE c.course_id=? AND c.checked_in_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY d ORDER BY d", [$cid])->fetchAll(),
            'hours' => q("SELECT HOUR(c.checked_in_at) h, COUNT(*) n FROM checkins c WHERE $since GROUP BY h ORDER BY h")->fetchAll(),
            'mix' => [
                'holes' => q("SELECT c.holes k, COUNT(*) n FROM checkins c WHERE $since GROUP BY c.holes")->fetchAll(),
                'fee' => q("SELECT c.fee_status k, COUNT(*) n, SUM(c.hire_fee) amt FROM checkins c WHERE $since GROUP BY c.fee_status")->fetchAll(),
            ],
            'hotels' => q("SELECT g.hotel name, COUNT(*) n FROM checkins c JOIN guests g ON g.id=c.main_guest_id WHERE $since AND g.hotel IS NOT NULL AND g.hotel<>'' GROUP BY g.hotel ORDER BY n DESC LIMIT 5")->fetchAll(),
            'fleet' => $fleet,
            'out' => checkin_rows('c.course_id=? AND c.returned_at IS NULL', [$cid]),
            'issues' => q("SELECT c.id, c.checked_in_at, c.return_condition, c.return_note, CONCAT(g.first_name,' ',g.last_name) guest FROM checkins c JOIN guests g ON g.id=c.main_guest_id WHERE c.course_id=? AND c.return_condition IN ('damaged','warning') ORDER BY c.id DESC LIMIT 8", [$cid])->fetchAll(),
        ]);
    }
    case 'dashboard/overview': { // head office: every course side by side
        $u = require_admin();
        $rows = [];
        foreach (q('SELECT id,name FROM courses WHERE active=1 ORDER BY id')->fetchAll() as $c) {
            $cid = (int)$c['id'];
            $t = q('SELECT COUNT(*) rounds, COALESCE(SUM(hire_fee),0) fees, COALESCE(SUM(returned_at IS NULL),0) out_now FROM checkins WHERE course_id=? AND DATE(checked_in_at)=CURDATE()', [$cid])->fetch();
            $w = q('SELECT COUNT(*) rounds, COALESCE(SUM(hire_fee),0) fees FROM checkins WHERE course_id=? AND checked_in_at >= (CURDATE() - INTERVAL 6 DAY)', [$cid])->fetch();
            $m = q('SELECT COUNT(*) rounds, COALESCE(SUM(hire_fee),0) fees FROM checkins WHERE course_id=? AND checked_in_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")', [$cid])->fetch();
            $f = q("SELECT COUNT(*) total, COALESCE(SUM(status='available'),0) available, COALESCE(SUM(status='out'),0) out_now FROM (SELECT " . STATUS_SQL . " AS status FROM assets a WHERE a.course_id=? AND a.active=1 AND a.kind='cart') x", [$cid])->fetch();
            $iss = (int)q("SELECT COUNT(*) FROM checkins WHERE course_id=? AND return_condition IN ('damaged','warning') AND checked_in_at >= (CURDATE() - INTERVAL 6 DAY)", [$cid])->fetchColumn();
            $rows[] = ['id' => $cid, 'name' => $c['name'], 'today' => $t, 'week' => $w, 'month' => $m, 'carts' => $f, 'issues_week' => $iss,
                       'days' => q('SELECT DATE(checked_in_at) d, COUNT(*) n FROM checkins WHERE course_id=? AND checked_in_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY d ORDER BY d', [$cid])->fetchAll()];
        }
        out(['courses' => $rows]);
    }
    case 'course/logo': { // public on purpose: the logo is shown in the app header
        $d = (string)q('SELECT logo FROM courses WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetchColumn();
        if (!preg_match('#^data:image/(png|jpeg|webp);base64,#', $d, $mt)) { http_response_code(404); exit; }
        header('Content-Type: image/' . $mt[1]);
        header('Cache-Control: public, max-age=86400');
        echo base64_decode(substr($d, strpos($d, ',') + 1));
        exit;
    }
    case 'courses/save': {
        $u = require_admin();
        $b = body();
        $name = clean($b['name'] ?? null, 80) ?? fail(422, 'Name required');
        $tennis = empty($b['tennis']) ? 0 : 1; $shoes = empty($b['shoes']) ? 0 : 1;
        $full = clean($b['full_name'] ?? null, 150);
        if (!empty($b['id'])) { $cid = (int)$b['id']; q('UPDATE courses SET name=?, full_name=?, tennis=?, shoes=?, active=? WHERE id=?', [$name, $full, $tennis, $shoes, empty($b['active']) && isset($b['active']) ? 0 : 1, $cid]); }
        else { q('INSERT INTO courses (name, full_name, tennis, shoes) VALUES (?,?,?,?)', [$name, $full, $tennis, $shoes]); $cid = (int)db()->lastInsertId(); }
        $logo = (string)($b['logo'] ?? '');
        if ($logo !== '') {
            if (!preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $logo) || strlen($logo) > 700000) fail(422, 'Logo must be a PNG, JPEG or WebP image under about 500 KB');
            q('UPDATE courses SET logo=?, logo_at=NOW() WHERE id=?', [$logo, $cid]);
        } elseif (!empty($b['remove_logo'])) q('UPDATE courses SET logo=NULL, logo_at=NULL WHERE id=?', [$cid]);
        audit($u['id'], 'course_save', $name);
        out(['ok' => true]);
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
            fputcsv($f, ['course', 'id', 'checked_in', 'returned', 'main_guest', 'mobile', 'hotel', 'room', 'golfers', 'holes', 'fee_status', 'cart', 'clubs', 'bags', 'other_hire', 'hire_fee', 'return_condition', 'return_note', 'staff']);
            $all = ($_GET['course'] ?? '') === 'all' && is_admin($u);
            foreach (q("SELECT co.name course,c.id,c.checked_in_at,c.returned_at,CONCAT(g.first_name,' ',g.last_name) guest,g.mobile,g.hotel,g.room,
                        (SELECT COUNT(*) FROM checkin_golfers x WHERE x.checkin_id=c.id) golfers,c.holes,c.fee_status,ca.name cart,cl.name clubs,c.bags,
                        (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM checkin_items i JOIN assets a ON a.id=i.asset_id WHERE i.checkin_id=c.id) other_hire,
                        c.hire_fee,c.return_condition,c.return_note,u.name staff
                        FROM checkins c JOIN courses co ON co.id=c.course_id JOIN guests g ON g.id=c.main_guest_id LEFT JOIN assets ca ON ca.id=c.cart_id LEFT JOIN assets cl ON cl.id=c.clubs_id JOIN users u ON u.id=c.staff_id
                        WHERE DATE(c.checked_in_at) BETWEEN ? AND ?" . ($all ? '' : ' AND c.course_id=?') . " ORDER BY c.id", $all ? [$from, $to] : [$from, $to, course_id($u)])->fetchAll() as $r) fputcsv($f, $r);
        }
        audit($u['id'], 'export', ($marketing ? 'marketing ' : '') . "$from..$to");
        exit;
    }

    // ---------- users ----------
    case 'users/list': {
        $u = require_manager();
        $bound = user_course_id($u); // a course admin only sees their own course's check-in staff
        out(['users' => $bound
            ? q("SELECT id,username,name,role,course_id,active FROM users WHERE course_id=? AND role='staff' ORDER BY name", [$bound])->fetchAll()
            : q('SELECT id,username,name,role,course_id,active FROM users ORDER BY name')->fetchAll()]);
    }
    case 'users/save': {
        $u = require_manager();
        $b = body();
        $bound = user_course_id($u);
        $courseId = $bound ?: (!empty($b['course_id']) ? (int)$b['course_id'] : null);
        if ($courseId !== null && !q('SELECT 1 FROM courses WHERE id=?', [$courseId])->fetch()) fail(422, 'Unknown course');
        $un = strtolower(trim((string)($b['username'] ?? '')));
        $name = clean($b['name'] ?? null, 100);
        $role = !$bound && ($b['role'] ?? '') === 'manager' ? 'manager' : 'staff';
        $pw = (string)($b['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $un) || !$name) fail(422, 'Username (3+ letters/numbers) and name required');
        if (!empty($b['id'])) {
            if ($bound && !q("SELECT 1 FROM users WHERE id=? AND course_id=? AND role='staff'", [(int)$b['id'], $bound])->fetch()) fail(403, 'You can only manage check-in staff for your own course');
            if (q('SELECT 1 FROM users WHERE username=? AND id<>?', [$un, (int)$b['id']])->fetch()) fail(409, 'Username taken');
            if ((int)$b['id'] === $u['id'] && (isset($b['active']) && !$b['active'] || $role !== 'manager' || $courseId !== null)) fail(422, "You can't demote, disable or restrict yourself");
            q('UPDATE users SET username=?,name=?,role=?,course_id=?,active=? WHERE id=?', [$un, $name, $role, $courseId, empty($b['active']) && isset($b['active']) ? 0 : 1, (int)$b['id']]);
            if ($pw !== '') { if (strlen($pw) < 8) fail(422, 'Password: 8+ characters'); q('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), (int)$b['id']]); }
        } else {
            if (strlen($pw) < 8) fail(422, 'Password: 8+ characters');
            if (q('SELECT 1 FROM users WHERE username=?', [$un])->fetch()) fail(409, 'Username taken');
            q('INSERT INTO users (username,name,role,course_id,pass_hash) VALUES (?,?,?,?,?)', [$un, $name, $role, $courseId, password_hash($pw, PASSWORD_DEFAULT)]);
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
              ca.name cart_name, cl.name clubs_name, c.bags,
              (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM checkin_items i JOIN assets a ON a.id=i.asset_id WHERE i.checkin_id=c.id) items,
              u.name staff
              FROM checkins c JOIN guests g ON g.id=c.main_guest_id LEFT JOIN assets ca ON ca.id=c.cart_id LEFT JOIN assets cl ON cl.id=c.clubs_id JOIN users u ON u.id=c.staff_id
              WHERE $where ORDER BY c.id DESC LIMIT 300", $p)->fetchAll();
}
