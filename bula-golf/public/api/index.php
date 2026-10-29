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
        $me = ['id' => (int)$row['id'], 'name' => $row['name'], 'role' => norm_role((string)$row['role'])];
        $courses = q('SELECT id,name,full_name,tennis,shoes,room_charge,t9,t18,late9,late18,(logo IS NOT NULL) AS has_logo,UNIX_TIMESTAMP(logo_at) AS logo_v FROM courses WHERE active=1 ORDER BY id')->fetchAll();
        $meOut = $me + ['course_id' => $row['course_id'] === null ? null : (int)$row['course_id'], 'perms' => my_perms($me)];
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
        out(['user' => $u ? $u + ['course_id' => user_course_id($u), 'perms' => my_perms($u)] : null, 'currency' => $c['currency'], 'course' => $c['course_name'],
              // course names and logos are public (the sign-in screen shows them); the hire switches only go to signed-in users
              'courses' => q('SELECT id,name,full_name,' . ($u ? 'tennis,shoes,room_charge,t9,t18,late9,late18,' : '') . '(logo IS NOT NULL) AS has_logo,UNIX_TIMESTAMP(logo_at) AS logo_v FROM courses WHERE active=1 ORDER BY id')->fetchAll()]);
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
        $u = require_perm('history');
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
        $u = require_perm('fleet');
        $b = body();
        $kind = in_array($b['kind'] ?? '', ['cart', 'clubs', 'shoes', 'racquet'], true) ? $b['kind'] : fail(422, 'Bad kind');
        $name = clean($b['name'] ?? null, 80) ?? fail(422, 'Name required');
        $vals = [$kind, $name, clean($b['description'] ?? null), $kind === 'cart' ? 0.0 : max(0, (float)($b['fee'] ?? 0)), (int)($b['sort_order'] ?? 0),
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
        $u = require_perm('fleet');
        $b = body();
        $kind = in_array($b['kind'] ?? '', ['cart', 'clubs', 'shoes', 'racquet'], true) ? $b['kind'] : fail(422, 'Bad kind');
        $prefix = clean($b['prefix'] ?? null, 60) ?? fail(422, 'Name prefix required');
        $from = max(0, (int)($b['from'] ?? 1)); $count = (int)($b['count'] ?? 0);
        if ($count < 1 || $count > 200) fail(422, 'Count must be 1 to 200');
        $fee = $kind === 'cart' ? 0.0 : max(0, (float)($b['fee'] ?? 0)); $desc = clean($b['description'] ?? null);
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
        $u = require_perm('members');
        $cid = course_id($u);
        $w = 'course_id=?'; $p = [$cid];
        $s = trim((string)($_GET['q'] ?? ''));
        if ($s !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%'; $w .= ' AND (CONCAT(first_name," ",last_name) LIKE ? OR member_no LIKE ? OR mobile LIKE ?)'; array_push($p, $like, $like, $like); }
        $st = $_GET['status'] ?? 'all';
        if ($st === 'expired') $w .= ' AND expires_on < CURDATE()';
        elseif ($st === 'expiring') $w .= ' AND expires_on BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY)';
        elseif ($st === 'active') $w .= ' AND (expires_on IS NULL OR expires_on >= CURDATE())';
        out(['members' => q("SELECT id,member_no,first_name,last_name,mobile,email,starts_on,expires_on FROM members WHERE $w ORDER BY last_name, first_name LIMIT 300", $p)->fetchAll(),
             'counts' => q('SELECT COUNT(*) total, COALESCE(SUM(expires_on IS NULL OR expires_on >= CURDATE()),0) active, COALESCE(SUM(expires_on < CURDATE()),0) expired,
                            COALESCE(SUM(expires_on BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY)),0) expiring FROM members WHERE course_id=?', [$cid])->fetch()]);
    }
    case 'members/check': { // counter lookup for any signed-in user: status only, no contact details
        $u = require_login();
        $s = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($s) < 2) out(['members' => []]);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%';
        out(['members' => q("SELECT first_name, last_name, member_no, expires_on, (expires_on IS NOT NULL AND expires_on < CURDATE()) AS expired, DATEDIFF(expires_on, CURDATE()) AS days_left
                             FROM members WHERE course_id=? AND (CONCAT(first_name,' ',last_name) LIKE ? OR member_no LIKE ? OR mobile LIKE ?) ORDER BY last_name, first_name LIMIT 8",
                            [course_id($u), $like, $like, $like])->fetchAll()]);
    }
    case 'assets/maintenance': { // a supervisor marks a cart or hire item in or out of maintenance without opening the full fleet editor
        $u = require_perm('fleet');
        $b = body();
        q('UPDATE assets SET maintenance=? WHERE id=? AND course_id=?', [empty($b['maintenance']) ? 0 : 1, (int)($b['id'] ?? 0), course_id($u)]);
        audit($u['id'], 'asset_maintenance', 'id ' . (int)($b['id'] ?? 0) . (empty($b['maintenance']) ? ' in service' : ' maintenance'));
        out(['ok' => true]);
    }
    case 'members/import': { // rows parsed in the browser from the course's CSV; "replace" swaps the whole list
        $u = require_perm('members');
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
                $exp = valid_date($r['expires_on'] ?? null); $start = valid_date($r['starts_on'] ?? null);
                $row = $no ? q('SELECT id FROM members WHERE course_id=? AND member_no=?', [$cid, $no])->fetch()
                           : q('SELECT id FROM members WHERE course_id=? AND LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) AND mobile <=> ?', [$cid, $first, $last, $mobile])->fetch();
                if ($row) { q('UPDATE members SET member_no=?,first_name=?,last_name=?,mobile=?,email=?,starts_on=?,expires_on=? WHERE id=?', [$no, $first, $last, $mobile, $email, $start, $exp, $row['id']]); $updated++; }
                else { q('INSERT INTO members (course_id,member_no,first_name,last_name,mobile,email,starts_on,expires_on) VALUES (?,?,?,?,?,?,?,?)', [$cid, $no, $first, $last, $mobile, $email, $start, $exp]); $created++; }
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        audit($u['id'], 'members_import', "course $cid +$created ~$updated" . (($b['mode'] ?? '') === 'replace' ? ' replace' : ''));
        out(['created' => $created, 'updated' => $updated, 'error_count' => count($errors), 'errors' => array_slice($errors, 0, 20)]);
    }
    case 'members/save': {
        $u = require_perm('members');
        $cid = course_id($u);
        $b = body();
        $first = clean($b['first_name'] ?? null, 80) ?? fail(422, 'First name required');
        $last = clean($b['last_name'] ?? null, 80) ?? fail(422, 'Last name required');
        $no = clean($b['member_no'] ?? null, 40);
        $mobile = norm_mobile((string)($b['mobile'] ?? '')); $mobile = strlen($mobile) >= 5 ? $mobile : null;
        $vals = [$no, $first, $last, $mobile, clean($b['email'] ?? null, 150), valid_date($b['starts_on'] ?? null), valid_date($b['expires_on'] ?? null)];
        if ($no && q('SELECT 1 FROM members WHERE course_id=? AND member_no=? AND id<>?', [$cid, $no, (int)($b['id'] ?? 0)])->fetch()) fail(409, 'That member number is already used');
        if (!empty($b['id'])) q('UPDATE members SET member_no=?,first_name=?,last_name=?,mobile=?,email=?,starts_on=?,expires_on=? WHERE id=? AND course_id=?', [...$vals, (int)$b['id'], $cid]);
        else q('INSERT INTO members (member_no,first_name,last_name,mobile,email,starts_on,expires_on,course_id) VALUES (?,?,?,?,?,?,?,?)', [...$vals, $cid]);
        audit($u['id'], 'member_save', "$first $last");
        out(['ok' => true]);
    }
    case 'members/delete': {
        $u = require_perm('members');
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
        if ($fee === 'room' && !(int)q('SELECT room_charge FROM courses WHERE id=?', [$course])->fetchColumn()) fail(422, 'Charge to room is not enabled for this course');
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
                if ($kind !== 'cart') $hire += (float)$a['fee']; // a cart comes with the round: never charged
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
                // hotel and country are kept per check-in (the guest record only holds the latest), so reports stay true for past dates
                q('INSERT INTO checkin_golfers (checkin_id,guest_id,is_main,signature,signed_ip,guardian_name,guest_type,member_id,hotel,country) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$ciId, $gid, $i === 0 ? 1 : 0, $g['signature'], ip(), $guardian, $f[5], $memberId, $f[2], $f[1]]);
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
        if (!can($u, 'history') && $d !== date('Y-m-d')) fail(403, 'Only today\'s check-ins are open to you. Ask your admin for history access.');
        out(['checkins' => checkin_rows('c.course_id=? AND DATE(c.checked_in_at)=?', [course_id($u), $d])]);
    }
    case 'checkins/get': {
        $u = require_perm('history');
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
        $u = require_perm('dashboard');
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
            'origin' => q("SELECT CASE WHEN g.country IS NULL OR g.country='' THEN 'unknown' WHEN LOWER(g.country) IN ('fiji','fijian','fiji islands') THEN 'local' ELSE 'overseas' END k, COUNT(*) n
                           FROM checkins c JOIN guests g ON g.id=c.main_guest_id WHERE $since GROUP BY k")->fetchAll(),
            'countries' => q("SELECT g.country name, COUNT(*) n FROM checkins c JOIN guests g ON g.id=c.main_guest_id WHERE $since AND g.country IS NOT NULL AND g.country<>'' AND LOWER(g.country) NOT IN ('fiji','fijian','fiji islands') GROUP BY g.country ORDER BY n DESC LIMIT 5")->fetchAll(),
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
    case 'course/timing': { // a course admin sets their own course's round times (minutes); head office edits whichever course is selected
        $u = require_course_admin();
        $b = body();
        $t9 = (int)($b['t9'] ?? 0); $t18 = (int)($b['t18'] ?? 0); $l9 = (int)($b['late9'] ?? 0); $l18 = (int)($b['late18'] ?? 0);
        if ($t9 < 20 || $t9 > 400 || $t18 < 40 || $t18 > 720 || $l9 < 20 || $l9 > 600 || $l18 < 40 || $l18 > 900) fail(422, 'Enter all four times in minutes (9 holes 20-400, 18 holes 40-720, overdue up to 600 / 900)');
        if ($l9 < $t9 || $l18 < $t18) fail(422, 'The overdue time must not be shorter than the expected time');
        $cid = course_id($u);
        q('UPDATE courses SET t9=?, t18=?, late9=?, late18=? WHERE id=?', [$t9, $t18, $l9, $l18, $cid]);
        audit($u['id'], 'course_timing', "course $cid $t9/$t18/$l9/$l18");
        out(['ok' => true]);
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
        $tennis = empty($b['tennis']) ? 0 : 1; $shoes = empty($b['shoes']) ? 0 : 1; $room = empty($b['room_charge']) ? 0 : 1;
        $full = clean($b['full_name'] ?? null, 150);
        if (!empty($b['id'])) { $cid = (int)$b['id']; q('UPDATE courses SET name=?, full_name=?, tennis=?, shoes=?, room_charge=?, active=? WHERE id=?', [$name, $full, $tennis, $shoes, $room, empty($b['active']) && isset($b['active']) ? 0 : 1, $cid]); }
        else { q('INSERT INTO courses (name, full_name, tennis, shoes, room_charge) VALUES (?,?,?,?,?)', [$name, $full, $tennis, $shoes, $room]); $cid = (int)db()->lastInsertId(); }
        $logo = (string)($b['logo'] ?? '');
        if ($logo !== '') {
            if (!preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $logo) || strlen($logo) > 700000) fail(422, 'Logo must be a PNG, JPEG or WebP image under about 500 KB');
            q('UPDATE courses SET logo=?, logo_at=NOW() WHERE id=?', [$logo, $cid]);
        } elseif (!empty($b['remove_logo'])) q('UPDATE courses SET logo=NULL, logo_at=NULL WHERE id=?', [$cid]);
        audit($u['id'], 'course_save', $name);
        out(['ok' => true]);
    }
    case 'export': {
        $u = require_perm('export');
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
        $u = require_course_admin();
        $bound = user_course_id($u); // a course admin sees only their own course's supervisors and staff
        out(['users' => $bound
            ? q("SELECT id,username,name,role,course_id,active FROM users WHERE course_id=? AND role IN ('staff','supervisor') ORDER BY role DESC, name", [$bound])->fetchAll()
            : q('SELECT id,username,name,role,course_id,active FROM users ORDER BY name')->fetchAll()]);
    }
    case 'users/save': { // head office creates anyone; a course admin creates and manages supervisors and staff for their own course
        $u = require_course_admin();
        $b = body();
        $bound = user_course_id($u);
        $courseId = $bound ?: (!empty($b['course_id']) ? (int)$b['course_id'] : null);
        if ($courseId !== null && !q('SELECT 1 FROM courses WHERE id=?', [$courseId])->fetch()) fail(422, 'Unknown course');
        $un = strtolower(trim((string)($b['username'] ?? '')));
        $name = clean($b['name'] ?? null, 100);
        $role = in_array($b['role'] ?? '', ['staff', 'supervisor', 'admin'], true) ? $b['role'] : 'staff';
        if ($bound && $role === 'admin') fail(403, 'Only head office can create admins');
        if ($role !== 'admin' && $courseId === null) fail(422, 'Choose a course for this login');
        $pw = (string)($b['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $un) || !$name) fail(422, 'Username (3+ letters/numbers) and name required');
        if (!empty($b['id'])) {
            if ($bound && !q("SELECT 1 FROM users WHERE id=? AND course_id=? AND role IN ('staff','supervisor')", [(int)$b['id'], $bound])->fetch()) fail(403, 'You can only manage supervisors and staff for your own course');
            if (q('SELECT 1 FROM users WHERE username=? AND id<>?', [$un, (int)$b['id']])->fetch()) fail(409, 'Username taken');
            if ((int)$b['id'] === $u['id'] && (isset($b['active']) && !$b['active'] || $role !== 'admin' || $courseId !== null)) fail(422, "You can't demote, disable or restrict yourself");
            q('UPDATE users SET username=?,name=?,role=?,course_id=?,active=? WHERE id=?', [$un, $name, $role, $courseId, empty($b['active']) && isset($b['active']) ? 0 : 1, (int)$b['id']]);
            if ($pw !== '') { if (strlen($pw) < 8) fail(422, 'Password: 8+ characters'); q('UPDATE users SET pass_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), (int)$b['id']]); }
        } else {
            if (strlen($pw) < 8) fail(422, 'Password: 8+ characters');
            if (q('SELECT 1 FROM users WHERE username=?', [$un])->fetch()) fail(409, 'Username taken');
            q('INSERT INTO users (username,name,role,course_id,pass_hash) VALUES (?,?,?,?,?)', [$un, $name, $role, $courseId, password_hash($pw, PASSWORD_DEFAULT)]);
        }
        audit($u['id'], 'user_save', "$un ($role)");
        out(['ok' => true]);
    }

    // ---------- reports (admins can give this to supervisors and staff under Access) ----------
    case 'reports/run': {
        $u = require_perm('reports');
        $from = valid_date($_GET['from'] ?? null) ?? date('Y-m-01');
        $to = valid_date($_GET['to'] ?? null) ?? date('Y-m-d');
        if ($to < $from) [$from, $to] = [$to, $from];
        if ((strtotime($to) - strtotime($from)) > 400 * 86400) fail(422, 'Choose a period of up to 13 months');
        out(report_run((string)($_GET['type'] ?? ''), course_id($u), $from, $to, (string)($_GET['scope'] ?? 'current')));
    }

    // ---------- access: what supervisors and staff may do, per course (admins always can do everything) ----------
    case 'access/get': {
        $u = require_course_admin();
        out(['perms' => PERMS, 'matrix' => access_matrix(course_id($u))]);
    }
    case 'access/save': {
        $u = require_course_admin();
        $cid = course_id($u);
        $m = body()['matrix'] ?? [];
        foreach (['supervisor', 'staff'] as $role) foreach (PERMS as $p) {
            q('REPLACE INTO role_perms (course_id, role, perm, allowed) VALUES (?,?,?,?)', [$cid, $role, $p, empty($m[$role][$p]) ? 0 : 1]);
        }
        audit($u['id'], 'access_save', "course $cid");
        out(['ok' => true]);
    }

    default: fail(404, 'Unknown route');
    }
} catch (PDOException $e) {
    error_log('bulagolf: ' . $e->getMessage());
    // Signed-in admins get the real reason (e.g. a migration not yet run); everyone else just "Database error".
    $detail = '';
    try { $who = user(); if ($who && $who['role'] === 'admin') $detail = ': ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300); } catch (Throwable $x) { }
    fail(500, 'Database error' . $detail);
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
/**
 * Reports for planning and improvement. Every report is a list of sections (title, typed columns, rows) plus headline figures,
 * so the tablet can show it, print it and save it as CSV the same way. Column types: text, int, money, pct, mins, date, datetime.
 */
function report_run(string $type, int $cid, string $from, string $to, string $scope = 'current'): array {
    $W = 'c.course_id=? AND DATE(c.checked_in_at) BETWEEN ? AND ?';
    $P = [$cid, $from, $to];
    $golfers = '(SELECT COUNT(*) FROM checkin_golfers x WHERE x.checkin_id=c.id)';
    $local = "LOWER(cg.country) IN ('fiji','fijian','fiji islands')";
    $sections = []; $summary = [];
    $sec = function (string $title, array $cols, array $rows) use (&$sections) { $sections[] = ['title' => $title, 'columns' => $cols, 'rows' => $rows]; };
    $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 1) : 0;
    $titles = ['memberyear' => 'Member usage over the membership year', 'playmix' => 'Golfers: members, resort guests and holes', 'cashup' => 'Daily cash-up', 'rounds' => 'Rounds and peak times', 'fleet' => 'Cart and equipment usage', 'guests' => 'Guest mix', 'members' => 'Members', 'incidents' => 'Incidents and audit', 'trends' => '12-month trend'];
    if (!isset($titles[$type])) fail(422, 'Unknown report');

    if ($type === 'memberyear') {
        // Rounds per member across their membership year: from the start date (or 12 months before expiry) to expiry/today. Only verified member check-ins count.
        $S = 'COALESCE(m.starts_on, m.expires_on - INTERVAL 1 YEAR)';
        $where = ['current' => 'm.expires_on >= CURDATE()', 'expiring' => 'm.expires_on BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY)',
                  'expired' => 'm.expires_on < CURDATE() AND m.expires_on >= (CURDATE() - INTERVAL 12 MONTH)'][$scope] ?? 'm.expires_on >= CURDATE()';
        $in = "cg.member_id=m.id AND c.course_id=m.course_id AND DATE(c.checked_in_at) >= $S AND DATE(c.checked_in_at) <= m.expires_on";
        $rows = q("SELECT m.member_no, CONCAT(m.first_name,' ',m.last_name) name, m.expires_on, $S AS year_start,
                   (SELECT COUNT(*) FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $in) rounds,
                   (SELECT COUNT(*) FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $in AND c.holes=9) nine,
                   (SELECT MAX(c.checked_in_at) FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $in) last_at
                   FROM members m WHERE m.course_id=? AND m.expires_on IS NOT NULL AND $where ORDER BY rounds DESC, m.last_name, m.first_name", [$cid])->fetchAll();
        $out = []; $sum = 0; $none = 0; $best = null;
        foreach ($rows as $r) {
            $n = (int)$r['rounds']; $sum += $n; if ($n === 0) $none++; if ($best === null || $n > $best['n']) $best = ['n' => $n, 'name' => $r['name']];
            $days = max(30, (int)((min(time(), strtotime($r['expires_on'])) - strtotime($r['year_start'])) / 86400));
            $out[] = [$r['member_no'], $r['name'], $r['year_start'], $r['expires_on'], $n, (int)$r['nine'], $n - (int)$r['nine'], round($n / ($days / 30.4), 1), $r['last_at'],
                      $n === 0 ? 'No rounds recorded' : ($n < 6 ? 'Low use' : '')];
        }
        $sec('Rounds in the membership year (verified member check-ins only)', [['Member no.', 'text'], ['Name', 'text'], ['Year started', 'date'], ['Expires', 'date'], ['Rounds', 'int'], ['9 holes', 'int'], ['18 holes', 'int'], ['Per month', 'text'], ['Last played', 'datetime'], ['Note', 'text']], $out);
        $summary = [['Members in this list', count($rows), 'int'], ['Average rounds per member', $rows ? round($sum / count($rows), 1) : 0, 'text'], ['Members with no rounds', $none, 'int'],
                    ['Most rounds', $best && $best['n'] > 0 ? $best['name'] . ' (' . $best['n'] . ')' : '—', 'text']];
    }

    if ($type === 'playmix') {
        // one row per golfer (not per check-in): who they are, 9 or 18 holes, and how they are paying
        $rows = q("SELECT cat, holes, pay, COUNT(*) n FROM (
                     SELECT CASE WHEN cg.guest_type='member' THEN 'Members'
                                 WHEN cg.hotel IS NOT NULL AND cg.hotel<>'' THEN 'Resort / hotel guests'
                                 WHEN LOWER(COALESCE(cg.country,'')) IN ('fiji','fijian','fiji islands') THEN 'Fiji residents (not members)'
                                 ELSE 'Other visitors' END AS cat,
                            c.holes AS holes,
                            CASE WHEN cg.guest_type='member' THEN 'member' ELSE c.fee_status END AS pay
                     FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $W) t GROUP BY cat, holes, pay", $P)->fetchAll();
        $cats = ['Members', 'Resort / hotel guests', 'Fiji residents (not members)', 'Other visitors'];
        $byCat = array_fill_keys($cats, [9 => 0, 18 => 0]);
        $pay = [9 => ['n' => 0, 'member' => 0, 'paid' => 0, 'room' => 0, 'unpaid' => 0], 18 => ['n' => 0, 'member' => 0, 'paid' => 0, 'room' => 0, 'unpaid' => 0]];
        foreach ($rows as $r) {
            $h = (int)$r['holes'] === 9 ? 9 : 18; $n = (int)$r['n'];
            $byCat[$r['cat']][$h] += $n; $pay[$h]['n'] += $n; $pay[$h][$r['pay']] += $n;
        }
        $total = 0; foreach ($byCat as $v) $total += $v[9] + $v[18];
        $out = [];
        foreach ($byCat as $cat => $v) $out[] = [$cat, $v[9] + $v[18], $v[9], $v[18], $pct($v[9] + $v[18], $total)];
        $out[] = ['All golfers', $total, $pay[9]['n'], $pay[18]['n'], $total ? 100 : 0];
        $sec('Who checked in (golfers)', [['Group', 'text'], ['Golfers', 'int'], ['9 holes', 'int'], ['18 holes', 'int'], ['Share', 'pct']], $out);
        $po = [];
        foreach ([9, 18] as $h) { $p = $pay[$h]; $po[] = [$h . ' holes', $p['n'], $p['member'], $p['paid'], $p['room'], $p['paid'] + $p['room'], $p['unpaid']]; }
        $po[] = ['Both', $pay[9]['n'] + $pay[18]['n'], $pay[9]['member'] + $pay[18]['member'], $pay[9]['paid'] + $pay[18]['paid'], $pay[9]['room'] + $pay[18]['room'],
                 $pay[9]['paid'] + $pay[18]['paid'] + $pay[9]['room'] + $pay[18]['room'], $pay[9]['unpaid'] + $pay[18]['unpaid']];
        $sec('How golfers are paying, 9 holes and 18 holes', [['Holes', 'text'], ['Golfers', 'int'], ['Members (no fee)', 'int'], ['Paid', 'int'], ['Charged to room', 'int'], ['Paid or charged to room', 'int'], ['Still to collect', 'int']], $po);
        $summary = [['Golfers', $total, 'int'], ['Members', $byCat['Members'][9] + $byCat['Members'][18], 'int'], ['Resort / hotel guests', $byCat['Resort / hotel guests'][9] + $byCat['Resort / hotel guests'][18], 'int'],
                    ['Played 9 holes', $pay[9]['n'], 'int'], ['Played 18 holes', $pay[18]['n'], 'int']];
    }

    if ($type === 'cashup') {
        $rows = q("SELECT DATE(c.checked_in_at) d, COUNT(*) rounds, SUM($golfers) golfers, SUM(c.hire_fee) hire,
                   SUM(CASE WHEN c.fee_status='paid' THEN c.hire_fee ELSE 0 END) paid, SUM(CASE WHEN c.fee_status='room' THEN c.hire_fee ELSE 0 END) room,
                   SUM(CASE WHEN c.fee_status='unpaid' THEN c.hire_fee ELSE 0 END) unpaid, SUM(c.fee_status='member') member_rounds
                   FROM checkins c WHERE $W GROUP BY DATE(c.checked_in_at) ORDER BY d", $P)->fetchAll();
        $t = ['rounds' => 0, 'golfers' => 0, 'hire' => 0, 'paid' => 0, 'room' => 0, 'unpaid' => 0, 'm' => 0];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['d'], (int)$r['rounds'], (int)$r['golfers'], (float)$r['hire'], (float)$r['paid'], (float)$r['room'], (float)$r['unpaid'], (int)$r['member_rounds']];
            $t['rounds'] += $r['rounds']; $t['golfers'] += $r['golfers']; $t['hire'] += $r['hire']; $t['paid'] += $r['paid']; $t['room'] += $r['room']; $t['unpaid'] += $r['unpaid']; $t['m'] += $r['member_rounds'];
        }
        if ($out) $out[] = ['Total', $t['rounds'], $t['golfers'], $t['hire'], $t['paid'], $t['room'], $t['unpaid'], $t['m']];
        $sec('By day', [['Date', 'date'], ['Check-ins', 'int'], ['Golfers', 'int'], ['Hire total', 'money'], ['Paid', 'money'], ['Charged to room', 'money'], ['Still to collect', 'money'], ['Member check-ins (no fee)', 'int']], $out);
        $st = q("SELECT u.name, COUNT(*) rounds, SUM(c.hire_fee) hire, SUM(CASE WHEN c.fee_status='unpaid' THEN c.hire_fee ELSE 0 END) unpaid, SUM(c.fee_status='member') member_rounds
                 FROM checkins c JOIN users u ON u.id=c.staff_id WHERE $W GROUP BY u.id, u.name ORDER BY rounds DESC", $P)->fetchAll();
        $sec('By staff member', [['Staff', 'text'], ['Check-ins', 'int'], ['Hire total', 'money'], ['Still to collect', 'money'], ['Member check-ins (no fee)', 'int']],
             array_map(fn($r) => [$r['name'], (int)$r['rounds'], (float)$r['hire'], (float)$r['unpaid'], (int)$r['member_rounds']], $st));
        $summary = [['Check-ins', $t['rounds'], 'int'], ['Hire total', $t['hire'], 'money'], ['Charged to room', $t['room'], 'money'], ['Still to collect', $t['unpaid'], 'money']];
    }

    if ($type === 'rounds') {
        $tm = q('SELECT late9, late18 FROM courses WHERE id=?', [$cid])->fetch() ?: ['late9' => 210, 'late18' => 360]; // this course's overdue limits (minutes)
        $days = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $wk = q("SELECT DAYOFWEEK(c.checked_in_at) w, COUNT(*) rounds, SUM($golfers) golfers, COUNT(DISTINCT DATE(c.checked_in_at)) days FROM checkins c WHERE $W GROUP BY DAYOFWEEK(c.checked_in_at)", $P)->fetchAll();
        usort($wk, fn($a, $b) => (($a['w'] + 5) % 7) <=> (($b['w'] + 5) % 7)); // Monday first
        $sec('By day of week', [['Day', 'text'], ['Check-ins', 'int'], ['Golfers', 'int'], ['Average check-ins per day', 'text']],
             array_map(fn($r) => [$days[(int)$r['w']], (int)$r['rounds'], (int)$r['golfers'], $r['days'] > 0 ? number_format($r['rounds'] / $r['days'], 1) : '0'], $wk));
        $hr = q("SELECT HOUR(c.checked_in_at) h, COUNT(*) rounds, SUM($golfers) golfers FROM checkins c WHERE $W GROUP BY HOUR(c.checked_in_at) ORDER BY h", $P)->fetchAll();
        $sec('By hour of check-in', [['Hour', 'text'], ['Check-ins', 'int'], ['Golfers', 'int']], array_map(fn($r) => [sprintf('%02d:00', $r['h']), (int)$r['rounds'], (int)$r['golfers']], $hr));
        $du = q("SELECT c.holes, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, c.checked_in_at, c.returned_at)) avg_min,
                 SUM(TIMESTAMPDIFF(MINUTE, c.checked_in_at, c.returned_at) > IF(c.holes=9, {$tm['late9']}, {$tm['late18']})) late
                 FROM checkins c WHERE $W AND c.returned_at IS NOT NULL GROUP BY c.holes ORDER BY c.holes", $P)->fetchAll();
        $sec('Time out on the course (cart and clubs returned)', [['Holes', 'int'], ['Returned check-ins', 'int'], ['Average time out', 'mins'], ['Overdue rounds (over ' . $tm['late9'] . ' min for 9 holes, ' . $tm['late18'] . ' for 18)', 'int']],
             array_map(fn($r) => [(int)$r['holes'], (int)$r['n'], (int)round((float)$r['avg_min']), (int)$r['late']], $du));
        $tot = q("SELECT COUNT(*) rounds, COALESCE(SUM($golfers),0) golfers, COALESCE(SUM(c.holes=9),0) nine FROM checkins c WHERE $W", $P)->fetch();
        $summary = [['Check-ins', (int)$tot['rounds'], 'int'], ['Golfers', (int)$tot['golfers'], 'int'], ['Golfers per check-in', $tot['rounds'] ? round($tot['golfers'] / $tot['rounds'], 2) : 0, 'text'], ['9-hole share', $pct($tot['nine'], $tot['rounds']), 'pct']];
    }

    if ($type === 'fleet') {
        $rows = q("SELECT a.kind, a.name, a.fee, a.maintenance,
                   (SELECT COUNT(*) FROM checkins c WHERE c.course_id=a.course_id AND DATE(c.checked_in_at) BETWEEN ? AND ? AND " . HOLDS_SQL . ") uses,
                   (SELECT COUNT(*) FROM checkins c WHERE c.course_id=a.course_id AND DATE(c.checked_in_at) BETWEEN ? AND ? AND c.return_condition='damaged' AND " . HOLDS_SQL . ") damaged,
                   (SELECT MAX(c.checked_in_at) FROM checkins c WHERE c.course_id=a.course_id AND " . HOLDS_SQL . ") last_used
                   FROM assets a WHERE a.course_id=? AND a.active=1 ORDER BY a.kind, a.sort_order, a.name", [$from, $to, $from, $to, $cid])->fetchAll();
        $by = []; foreach ($rows as $r) $by[$r['kind']][] = $r;
        $names = ['cart' => 'Golf carts', 'clubs' => 'Club sets', 'shoes' => 'Rental shoes', 'racquet' => 'Tennis hire'];
        $cartUsed = $cartTotal = $dmg = $idle = $cartHires = 0; $top = null;
        foreach ($names as $k => $label) {
            if (empty($by[$k])) continue;
            usort($by[$k], fn($a, $b) => ((int)$b['uses'] <=> (int)$a['uses']) ?: strnatcmp($a['name'], $b['name'])); // most used first
            $sec($label, [['Name', 'text'], ['Times hired', 'int'], ['Estimated hire income', 'money'], ['Damaged returns', 'int'], ['Last used', 'datetime'], ['Note', 'text']],
                 array_map(function ($r) {
                     $note = (int)$r['maintenance'] ? 'In maintenance' : ((int)$r['uses'] === 0 ? 'Not used this period' : ((int)$r['damaged'] > 0 ? 'Had damage' : ''));
                     return [$r['name'], (int)$r['uses'], $r['kind'] === 'cart' ? null : (int)$r['uses'] * (float)$r['fee'], (int)$r['damaged'], $r['last_used'], $note];
                 }, $by[$k]));
            foreach ($by[$k] as $r) {
                $dmg += (int)$r['damaged']; if ((int)$r['uses'] === 0) $idle++;
                if ($k === 'cart') { $cartTotal++; $cartHires += (int)$r['uses']; if ((int)$r['uses'] > 0) $cartUsed++; if ($top === null || (int)$r['uses'] > $top['uses']) $top = ['name' => $r['name'], 'uses' => (int)$r['uses']]; }
            }
        }
        $summary = [['Total cart hires', $cartHires, 'int'], ['Most used cart', $top && $top['uses'] > 0 ? $top['name'] . ' (' . $top['uses'] . ' times)' : '—', 'text'],
                    ['Average hires per cart', $cartTotal ? round($cartHires / $cartTotal, 1) : 0, 'text'], ['Carts never used', $cartTotal - $cartUsed, 'int'], ['Damaged returns', $dmg, 'int']];
    }

    if ($type === 'guests') {
        $o = q("SELECT CASE WHEN cg.country IS NULL OR cg.country='' THEN 'Not recorded' WHEN $local THEN 'Fiji residents' ELSE 'Overseas visitors' END k, COUNT(*) n
                FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 WHERE $W GROUP BY k ORDER BY n DESC", $P)->fetchAll();
        $total = array_sum(array_column($o, 'n'));
        $sec('Where guests are from', [['Group', 'text'], ['Check-ins', 'int'], ['Share', 'pct']], array_map(fn($r) => [$r['k'], (int)$r['n'], $pct($r['n'], $total)], $o));
        $co = q("SELECT cg.country k, COUNT(*) n FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 WHERE $W AND cg.country IS NOT NULL AND cg.country<>'' AND NOT ($local) GROUP BY cg.country ORDER BY n DESC LIMIT 15", $P)->fetchAll();
        $sec('Top overseas countries', [['Country', 'text'], ['Check-ins', 'int']], array_map(fn($r) => [$r['k'], (int)$r['n']], $co));
        $ho = q("SELECT cg.hotel k, COUNT(*) n FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 WHERE $W AND cg.hotel IS NOT NULL AND cg.hotel<>'' GROUP BY cg.hotel ORDER BY n DESC LIMIT 15", $P)->fetchAll();
        $sec('Top hotels (for partnerships and concierge relationships)', [['Hotel', 'text'], ['Check-ins', 'int']], array_map(fn($r) => [$r['k'], (int)$r['n']], $ho));
        $rt = q("SELECT COALESCE(SUM(g.visits>1),0) again, COALESCE(SUM(g.visits<=1),0) first FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 WHERE $W", $P)->fetch();
        $sec('New and returning guests', [['Group', 'text'], ['Check-ins', 'int'], ['Share', 'pct']], [['First visit', (int)$rt['first'], $pct($rt['first'], $rt['first'] + $rt['again'])], ['Returning guests', (int)$rt['again'], $pct($rt['again'], $rt['first'] + $rt['again'])]]);
        $summary = [['Check-ins', (int)$total, 'int'], ['Returning guests', $pct($rt['again'], $rt['first'] + $rt['again']), 'pct']];
    }

    if ($type === 'members') {
        $c = q('SELECT COUNT(*) total, COALESCE(SUM(expires_on IS NULL OR expires_on >= CURDATE()),0) active, COALESCE(SUM(expires_on < CURDATE()),0) expired,
                COALESCE(SUM(expires_on BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY)),0) expiring FROM members WHERE course_id=?', [$cid])->fetch();
        $rn = q('SELECT first_name, last_name, member_no, mobile, email, expires_on, DATEDIFF(expires_on, CURDATE()) days FROM members
                 WHERE course_id=? AND expires_on IS NOT NULL AND expires_on <= (CURDATE() + INTERVAL 30 DAY) AND expires_on >= (CURDATE() - INTERVAL 90 DAY) ORDER BY expires_on', [$cid])->fetchAll();
        $sec('Renewals: expiring in 30 days or lapsed in the last 90 (call list)', [['Name', 'text'], ['Member no.', 'text'], ['Mobile', 'text'], ['Email', 'text'], ['Expires', 'date'], ['Status', 'text']],
             array_map(fn($r) => [$r['first_name'] . ' ' . $r['last_name'], $r['member_no'], $r['mobile'], $r['email'], $r['expires_on'], (int)$r['days'] < 0 ? 'Lapsed ' . abs((int)$r['days']) . ' days ago' : ((int)$r['days'] === 0 ? 'Expires today' : 'Expires in ' . $r['days'] . ' days')], $rn));
        $us = q("SELECT m.member_no, CONCAT(m.first_name,' ',m.last_name) name, COUNT(*) rounds, MAX(c.checked_in_at) last_at
                 FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id JOIN members m ON m.id=cg.member_id WHERE $W GROUP BY m.id, m.member_no, m.first_name, m.last_name ORDER BY rounds DESC LIMIT 50", $P)->fetchAll();
        $sec('Most active members this period', [['Member no.', 'text'], ['Name', 'text'], ['Rounds', 'int'], ['Last played', 'datetime']], array_map(fn($r) => [$r['member_no'], $r['name'], (int)$r['rounds'], $r['last_at']], $us));
        $in = q("SELECT m.member_no, CONCAT(m.first_name,' ',m.last_name) name, m.mobile, m.expires_on FROM members m
                 WHERE m.course_id=? AND (m.expires_on IS NULL OR m.expires_on >= CURDATE())
                 AND NOT EXISTS (SELECT 1 FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE cg.member_id=m.id AND DATE(c.checked_in_at) BETWEEN ? AND ?) ORDER BY m.last_name, m.first_name LIMIT 200", [$cid, $from, $to])->fetchAll();
        $sec('Current members who have not played this period (retention)', [['Member no.', 'text'], ['Name', 'text'], ['Mobile', 'text'], ['Expires', 'date']], array_map(fn($r) => [$r['member_no'], $r['name'], $r['mobile'], $r['expires_on']], $in));
        $mr = q("SELECT COUNT(*) n FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $W AND cg.guest_type='member'", $P)->fetchColumn();
        $gl = q("SELECT COUNT(*) n FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $W", $P)->fetchColumn();
        $summary = [['Members on the list', (int)$c['total'], 'int'], ['Current', (int)$c['active'], 'int'], ['Expiring in 30 days', (int)$c['expiring'], 'int'], ['Expired', (int)$c['expired'], 'int'], ['Member share of golfers', $pct($mr, $gl), 'pct']];
    }

    if ($type === 'incidents') {
        $iss = q("SELECT c.checked_in_at, CONCAT(g.first_name,' ',g.last_name) guest, ca.name cart, c.return_condition, c.return_note FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1
                  LEFT JOIN assets ca ON ca.id=c.cart_id WHERE $W AND c.return_condition IN ('damaged','warning') ORDER BY c.id DESC LIMIT 200", $P)->fetchAll();
        $sec('Damage and patron warnings', [['When', 'datetime'], ['Guest', 'text'], ['Cart', 'text'], ['Type', 'text'], ['Notes', 'text']], array_map(fn($r) => [$r['checked_in_at'], $r['guest'], $r['cart'], $r['return_condition'], $r['return_note']], $iss));
        $mf = q("SELECT u.name, COUNT(*) n FROM checkins c JOIN users u ON u.id=c.staff_id WHERE $W AND c.fee_status='member' GROUP BY u.id, u.name ORDER BY n DESC", $P)->fetchAll();
        $sec('Member (no-fee) check-ins by staff member: look for unusual patterns', [['Staff', 'text'], ['Member check-ins', 'int']], array_map(fn($r) => [$r['name'], (int)$r['n']], $mf));
        $op = q("SELECT c.checked_in_at, CONCAT(g.first_name,' ',g.last_name) guest, ca.name cart, u.name staff FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 JOIN users u ON u.id=c.staff_id
                 LEFT JOIN assets ca ON ca.id=c.cart_id WHERE c.course_id=? AND c.returned_at IS NULL AND DATE(c.checked_in_at) < CURDATE() ORDER BY c.checked_in_at", [$cid])->fetchAll();
        $sec('Still not returned from an earlier day (check these)', [['Checked in', 'datetime'], ['Guest', 'text'], ['Cart', 'text'], ['Checked in by', 'text']], array_map(fn($r) => [$r['checked_in_at'], $r['guest'], $r['cart'], $r['staff']], $op));
        $u18 = (int)q("SELECT COUNT(*) FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $W AND cg.guardian_name IS NOT NULL", $P)->fetchColumn();
        $summary = [['Damage / warnings', count($iss), 'int'], ['Not returned from earlier days', count($op), 'int'], ['Under-18 golfers (guardian signed)', $u18, 'int']];
    }

    if ($type === 'trends') {
        $tr = q("SELECT DATE_FORMAT(c.checked_in_at, '%Y-%m') ym, COUNT(*) rounds, SUM($golfers) golfers, SUM(c.hire_fee) hire, SUM(c.fee_status='member') member_rounds,
                 SUM(CASE WHEN cg.country IS NOT NULL AND cg.country<>'' AND NOT ($local) THEN 1 ELSE 0 END) overseas
                 FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 WHERE c.course_id=? AND c.checked_in_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
                 GROUP BY ym ORDER BY ym", [$cid])->fetchAll();
        $sec('Month by month', [['Month', 'text'], ['Check-ins', 'int'], ['Golfers', 'int'], ['Hire total', 'money'], ['Member check-ins', 'int'], ['Overseas share', 'pct']],
             array_map(fn($r) => [$r['ym'], (int)$r['rounds'], (int)$r['golfers'], (float)$r['hire'], (int)$r['member_rounds'], $pct($r['overseas'], $r['rounds'])], $tr));
        $summary = [['Months with data', count($tr), 'int']];
    }

    return ['type' => $type, 'title' => $titles[$type], 'from' => $from, 'to' => $to, 'summary' => $summary, 'sections' => $sections];
}
