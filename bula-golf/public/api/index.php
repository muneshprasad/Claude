<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/pdf.php';
require __DIR__ . '/reports.php';
require __DIR__ . '/jobs.php';

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
            audit(null, 'login_failed', mb_substr($u, 0, 60)); fail(401, 'Wrong username or password');
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
    case 'version': { // a fingerprint of the app files, so a tablet running an old copy (an iPad home-screen app, say) knows to update
        $dir = dirname(__DIR__); $v = '';
        foreach (['index.html', 'app.js', 'style.css'] as $f) $v .= (string)@filemtime($dir . '/' . $f) . ':' . (string)@filesize($dir . '/' . $f) . ';';
        out(['v' => substr(md5($v), 0, 12)]);
    }
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
        if ($member) $member['free_claimed'] = q('SELECT 1 FROM range_orders WHERE course_id=? AND member_id=? AND free_day=?', [course_id($u), (int)$member['id'], date('Y-m-d')])->fetchColumn() ? 1 : 0; // their free bucket for today
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
        // a returning guest by name (either order, part of a name is enough) or by mobile number (spaces and dashes ignored)
        $raw = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($raw) < 2) out(['guests' => []]);
        $s = '%' . str_replace(['%', '_'], ['\%', '\_'], $raw) . '%';
        $digits = preg_replace('/[^0-9+]/', '', $raw);
        $d = strlen($digits) >= 3 ? '%' . $digits . '%' : '';
        out(['guests' => q("SELECT id, mobile, first_name, last_name, email, country, hotel, room, dob, marketing_optin, alert_note, visits, last_visit FROM guests
                            WHERE CONCAT(first_name,' ',last_name) LIKE ? OR CONCAT(last_name,' ',first_name) LIKE ? OR (? <> '' AND mobile LIKE ?)
                            ORDER BY last_visit DESC LIMIT 12", [$s, $s, $d, $d])->fetchAll()]);
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
        $rows = q('SELECT a.id,a.kind,a.name,a.description,a.fee,a.fee9,a.sort_order,a.maintenance,a.active,a.gps_unit_id,a.gps_status,a.gps_battery,a.gps_lat,a.gps_lng,a.gps_seen, ' . STATUS_SQL . ' AS status
                   FROM assets a WHERE a.course_id=? ' . ($showAll ? '' : 'AND a.active=1 ') . 'ORDER BY a.kind, a.sort_order, a.name', [course_id($u)])->fetchAll();
        out(['assets' => $rows]);
    }
    case 'assets/save': {
        $u = require_perm('fleet');
        $b = body();
        $kind = in_array($b['kind'] ?? '', ['cart', 'clubs', 'shoes', 'racquet'], true) ? $b['kind'] : fail(422, 'Bad kind');
        $name = clean($b['name'] ?? null, 80) ?? fail(422, 'Name required');
        $vals = [$kind, $name, clean($b['description'] ?? null), $kind === 'cart' ? 0.0 : max(0, (float)($b['fee'] ?? 0)), (int)($b['sort_order'] ?? 0),
                 empty($b['maintenance']) ? 0 : 1, empty($b['active']) && isset($b['active']) ? 0 : 1, clean($b['gps_unit_id'] ?? null, 80),
                 $kind === 'clubs' && isset($b['fee9']) && $b['fee9'] !== '' && is_numeric($b['fee9']) ? max(0, (float)$b['fee9']) : null];
        $course = course_id($u);
        require_hire_kind($course, $kind);
        if (!empty($b['id'])) {
            q('UPDATE assets SET kind=?,name=?,description=?,fee=?,sort_order=?,maintenance=?,active=?,gps_unit_id=?,fee9=? WHERE id=? AND course_id=?', [...$vals, (int)$b['id'], $course]);
        } else {
            q('INSERT INTO assets (kind,name,description,fee,sort_order,maintenance,active,gps_unit_id,fee9,course_id) VALUES (?,?,?,?,?,?,?,?,?,?)', [...$vals, $course]);
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
        $u = require_perm('checkin');
        $b = body();
        $golfers = $b['golfers'] ?? [];
        if (!is_array($golfers) || !$golfers || count($golfers) > 8) fail(422, 'Add 1 to 8 golfers');
        $holes = (int)($b['holes'] ?? 18) === 9 ? 9 : 18;
        $payMethod = in_array($b['pay_method'] ?? '', PAY_METHODS, true) ? $b['pay_method'] : null; // everything is paid up front
        $fee = $payMethod === 'room' ? 'room' : ($payMethod ? 'paid' : (in_array($b['fee_status'] ?? '', ['paid', 'room', 'unpaid', 'member'], true) ? $b['fee_status'] : 'unpaid')); // old tablets still send fee_status
        $cartId = !empty($b['cart_id']) ? (int)$b['cart_id'] : null;
        $clubsId = !empty($b['clubs_id']) ? (int)$b['clubs_id'] : null;
        $course = course_id($u);
        if ($fee === 'room' && !(int)q('SELECT room_charge FROM courses WHERE id=?', [$course])->fetchColumn()) fail(422, 'Charge to room is not enabled for this course');
        $terms = (int)terms_for($course)['version'];
        $itemIds = array_values(array_unique(array_map('intval', is_array($b['items'] ?? null) ? $b['items'] : [])));
        if (count($itemIds) > 12) fail(422, 'Too many hire items');
        // a check-in saved offline on the tablet and sent again (or sent twice) is only ever recorded once
        $clientRef = clean($b['client_ref'] ?? null, 40);
        if ($clientRef) {
            $dupe = q('SELECT id, hire_fee, green_total, range_total FROM checkins WHERE client_ref=? AND course_id=?', [$clientRef, $course])->fetch();
            if ($dupe) out(['id' => (int)$dupe['id'], 'hire_fee' => (float)$dupe['hire_fee'], 'green_total' => (float)$dupe['green_total'], 'range_total' => (float)$dupe['range_total'], 'emailing' => 0, 'duplicate' => true]);
        }
        $rates = rates_for($course); $greenTotal = 0.0; $types = guest_types_for($course);
        $rangeIn = is_array($b['range'] ?? null) ? array_slice($b['range'], 0, 6) : [];

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
                if ($kind === 'clubs') $hire += club_fee($a, $holes); elseif ($kind !== 'cart') $hire += (float)$a['fee']; // a cart comes with the round: never charged
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
            $ci = q('INSERT INTO checkins (course_id,main_guest_id,holes,fee_status,pay_method,tee_time,cart_id,clubs_id,hire_fee,notes,bags,terms_version,staff_id,client_ref) VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$course, $holes, $fee, $payMethod, clean($b['tee_time'] ?? null, 5), $cartId, $clubsId, $hire, clean($b['notes'] ?? null, 500), clean($b['bags'] ?? null, 40), $terms, $u['id'], $clientRef]);
            $ciId = (int)$pdo->lastInsertId();
            foreach ($items as [$aid, $afee]) q('INSERT INTO checkin_items (checkin_id,asset_id,hire_fee) VALUES (?,?,?)', [$ciId, $aid, $afee]);
            $mainId = 0; $allMembers = true; $mailJobs = []; $freeFor = []; $freeGiven = []; // golfers who gave an email get a PDF copy of what they signed
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
                $rateCat = rate_category((bool)$isMember, $f[2], $f[1]);
                $pick = (string)($g['rate_cat'] ?? ''); // staff may place a golfer in another guest type (senior, PGA member...); a type that needs proof needs the box ticked
                if (!$isMember && $pick !== '' && $pick !== 'member' && isset($types[$pick])) {
                    if ($types[$pick]['proof'] && empty($g['proof_checked'])) fail(422, "$first $last: confirm you have seen their {$types[$pick]['label']} card or ID");
                    $rateCat = $pick;
                } // green fee from the course's rate card, decided here, never by the tablet
                $greenFee = (float)($rates[$holes][$rateCat] ?? 0.0); $greenTotal += $greenFee;
                q('INSERT INTO checkin_golfers (checkin_id,guest_id,is_main,signature,signed_ip,guardian_name,guest_type,member_id,hotel,country,green_fee,rate_cat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$ciId, $gid, $i === 0 ? 1 : 0, $g['signature'], ip(), $guardian, $f[5], $memberId, $f[2], $f[1], $greenFee, $rateCat]);
                if ($f[0] && filter_var($f[0], FILTER_VALIDATE_EMAIL)) $mailJobs[(int)$pdo->lastInsertId()] = $f[0];
                if ($memberId && empty($g['skip_free'])) $freeFor[$memberId] = "$first $last";
            }
            // range balls ordered with the check-in: priced from the course's products, then handed to the golf attendant to issue
            $rangeTotal = 0.0; $gname = trim(($golfers[0]['first_name'] ?? '') . ' ' . ($golfers[0]['last_name'] ?? ''));
            foreach ($rangeIn as $ri) {
                $qty = max(1, min(20, (int)($ri['qty'] ?? 1)));
                $p = q('SELECT name, price FROM range_products WHERE id=? AND course_id=? AND active=1', [(int)($ri['id'] ?? 0), $course])->fetch();
                if (!$p) fail(422, 'Unknown range-ball option');
                $amt = round((float)$p['price'] * $qty, 2); $rangeTotal += $amt;
                q('INSERT INTO range_orders (course_id,checkin_id,guest_name,product_name,qty,amount) VALUES (?,?,?,?,?,?)', [$course, $ciId, mb_substr($gname, 0, 160), $p['name'], $qty, $amt]);
            }
            // a verified member's free bucket: one per member per day (the unique key makes a second claim impossible), extra buckets are paid above
            if ($freeFor && ($fp = q('SELECT id, name FROM range_products WHERE course_id=? AND active=1 AND member_free=1 ORDER BY sort, id LIMIT 1', [$course])->fetch())) {
                foreach ($freeFor as $mid => $mname) {
                    $n = q('INSERT IGNORE INTO range_orders (course_id,checkin_id,guest_name,product_name,qty,amount,free,member_id,free_day) VALUES (?,?,?,?,1,0,1,?,?)',
                           [$course, $ciId, mb_substr($mname, 0, 160), $fp['name'], $mid, date('Y-m-d')])->rowCount();
                    if ($n) $freeGiven[] = $mname;
                }
            }
            $hire = round($hire, 2);
            if ($payMethod && ($hire + $greenTotal + $rangeTotal) < 0.005 && $allMembers) $fee = 'member';
            if ($fee === 'member' && !$allMembers) fail(422, 'No green fee needs every golfer to be a verified, current member with their card or ID seen');
            if ($fee === 'member' && ($hire + $greenTotal + $rangeTotal) > 0.004) fail(422, 'Member (no fee) only applies when there is nothing to pay. Choose how the fees are being paid');
            q('UPDATE checkins SET main_guest_id=?, green_total=?, range_total=?, fee_status=?, hire_fee=? WHERE id=?', [$mainId, $greenTotal, $rangeTotal, $fee, $hire, $ciId]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        audit($u['id'], 'checkin', "id $ciId");
        $queued = $mailJobs && (cfg()['email_signed_copy'] ?? true) && mail_settings_for($course)['enabled'] ? count($mailJobs) : 0;
        if ($queued) { // send after the tablet has its answer, so check-in is never held up by email
            register_shutdown_function(function () use ($mailJobs) {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
                ignore_user_abort(true); @set_time_limit(90);
                foreach ($mailJobs as $cgId => $to) { try { send_signed_copy((int)$cgId, (string)$to); } catch (Throwable $e) { error_log('bulagolf mail: ' . $e->getMessage()); } }
            });
        }
        out(['id' => $ciId, 'hire_fee' => $hire, 'green_total' => $greenTotal, 'range_total' => $rangeTotal, 'free_buckets' => $freeGiven, 'emailing' => $queued]);
    }

    case 'checkins/active': {
        $u = require_login();
        queue_jobs(); // overdue alerts and scheduled reports, after the response has gone
        out(['checkins' => checkin_rows('c.course_id=? AND c.returned_at IS NULL', [course_id($u)])]);
    }
    case 'checkins/list': {
        $u = require_login();
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
        if (!can($u, 'history') && !can($u, 'today')) fail(403, 'Check-in lists are not open to you. Ask your admin.');
        if (!can($u, 'history') && $d !== date('Y-m-d')) fail(403, "Only today's check-ins are open to you. Ask your admin for history access.");
        out(['checkins' => checkin_rows('c.course_id=? AND DATE(c.checked_in_at)=?', [course_id($u), $d])]);
    }
    case 'checkins/get': {
        $u = require_perm('history');
        $id = (int)($_GET['id'] ?? 0);
        $c = q('SELECT c.*, ca.name cart_name, cl.name clubs_name, u.name staff_name FROM checkins c LEFT JOIN assets ca ON ca.id=c.cart_id LEFT JOIN assets cl ON cl.id=c.clubs_id JOIN users u ON u.id=c.staff_id WHERE c.id=? AND c.course_id=?', [$id, course_id($u)])->fetch() ?: fail(404, 'Not found');
        $c['golfers'] = q('SELECT cg.id AS golfer_id,cg.green_fee,cg.rate_cat,g.first_name,g.last_name,g.mobile,g.email,g.hotel,g.room,cg.signature,cg.signed_at,cg.guardian_name,cg.is_main,cg.guest_type,(SELECT mm.member_no FROM members mm WHERE mm.id=cg.member_id) AS member_no FROM checkin_golfers cg JOIN guests g ON g.id=cg.guest_id WHERE cg.checkin_id=? ORDER BY cg.id', [$id])->fetchAll();
        $c['terms'] = q('SELECT body FROM terms WHERE version=?', [$c['terms_version']])->fetchColumn();
        try { $c['photos'] = q('SELECT id, kind, photo FROM return_photos WHERE checkin_id=? ORDER BY id', [$id])->fetchAll(); } catch (Throwable $e) { $c['photos'] = []; }
        $c['returned_by'] = $c['return_staff_id'] ? (string)q('SELECT name FROM users WHERE id=?', [$c['return_staff_id']])->fetchColumn() : null;
        try { $c['emails'] = q('SELECT golfer_id, to_email, status, sent_at FROM email_log WHERE checkin_id=? ORDER BY id DESC', [$id])->fetchAll(); } catch (Throwable $e) { $c['emails'] = []; } // before migrate_email.sql has run
        $c['items'] = q('SELECT a.name, a.kind, a.description FROM checkin_items i JOIN assets a ON a.id=i.asset_id WHERE i.checkin_id=? ORDER BY a.kind, a.name', [$id])->fetchAll();
        out($c);
    }
    case 'checkins/pdf': { // a golfer's signed copy as a PDF (History access)
        $u = require_perm('history');
        $d = signed_pdf_for((int)($_GET['id'] ?? 0), course_id($u)) ?: fail(404, 'Not found');
        audit($u['id'], 'pdf_view', 'check-in ' . $d['ci']['id']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="signed-agreement-' . (int)$d['ci']['id'] . '.pdf"');
        header('Cache-Control: private, no-store');
        echo $d['pdf'];
        exit;
    }
    case 'checkins/email': { // email (or re-email) a signed copy, to the guest's address or one typed in
        $u = require_perm('history');
        $b = body();
        $id = (int)($b['id'] ?? 0);
        if (!signed_pdf_for($id, course_id($u))) fail(404, 'Not found');
        [$ok, $msg] = send_signed_copy($id, (string)($b['to'] ?? ''));
        audit($u['id'], 'pdf_email', "record $id " . ($ok ? 'sent' : 'failed'));
        if (!$ok) fail(502, $msg);
        out(['ok' => true]);
    }
    case 'checkins/return': {
        $u = require_perm('returns');
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $cond = in_array($b['condition'] ?? '', ['ok', 'damaged', 'warning'], true) ? $b['condition'] : fail(422, 'Choose a condition');
        $photo = (string)($b['photo'] ?? '');
        if ($photo !== '' && (strpos($photo, 'data:image/jpeg;base64,') !== 0 || strlen($photo) > 1500000)) fail(422, 'Bad photo');
        $c = q('SELECT * FROM checkins WHERE id=? AND course_id=? AND returned_at IS NULL FOR UPDATE', [$id, course_id($u)])->fetch();
        if (!$c) fail(404, 'Already returned');
        if ($cond !== 'ok' && !clean($b['note'] ?? null)) fail(422, 'Please describe the problem');
        // a hired golf set must be checked on the way back: all there, or exactly what is missing (the old mobile app, which signs in with a token, is exempt)
        $missing = null; $checked = 0;
        if ($c['clubs_id']) {
            $chk = (string)($b['clubs_check'] ?? '');
            if ($chk === 'ok') $checked = 1;
            elseif ($chk === 'missing') {
                $missing = clean($b['clubs_missing'] ?? null, 255) ?? fail(422, 'Say what is missing from the club set');
                $checked = 1;
            } elseif (empty($_SERVER['HTTP_X_BULA_TOKEN'])) fail(422, 'Please check the club set: all there, or what is missing');
        }
        q('UPDATE checkins SET returned_at=NOW(), return_staff_id=?, return_condition=?, return_note=?, return_photo=?, clubs_missing=?, clubs_checked=? WHERE id=?',
            [$u['id'], $cond, clean($b['note'] ?? null, 500), $photo ?: null, $missing, $checked, $id]);
        if ($missing && !empty($b['clubs_out_of_service']) && $c['clubs_id']) q('UPDATE assets SET maintenance=1 WHERE id=?', [$c['clubs_id']]); // take the incomplete set out of circulation
        // photos of damage, or of an incomplete / damaged golf set: up to four, kept with the check-in so an admin can open them later
        $shots = is_array($b['photos'] ?? null) ? array_slice($b['photos'], 0, 4) : [];
        foreach ($shots as $sh) {
            $data = (string)($sh['data'] ?? ''); $kind = ($sh['kind'] ?? '') === 'clubs' ? 'clubs' : 'damage';
            if (strpos($data, 'data:image/jpeg;base64,') !== 0 || strlen($data) > 1500000) fail(422, 'One of the photos could not be used. Take it again');
            q('INSERT INTO return_photos (checkin_id, kind, photo) VALUES (?,?,?)', [$id, $kind, $data]);
        }
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
                COALESCE(SUM(c.hire_fee + c.green_total + c.range_total),0) fees, COALESCE(SUM(c.green_total),0) green, COALESCE(SUM(c.hire_fee),0) hire, COALESCE(SUM(c.returned_at IS NULL),0) out_now,
                COALESCE(SUM(CASE WHEN c.fee_status='unpaid' THEN c.hire_fee + c.green_total + c.range_total END),0) unpaid,
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
            'days' => q("SELECT DATE(c.checked_in_at) d, COUNT(*) n, SUM(c.hire_fee + c.green_total + c.range_total) fees FROM checkins c WHERE c.course_id=? AND c.checked_in_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY d ORDER BY d", [$cid])->fetchAll(),
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
            'issues' => q("SELECT c.id, c.checked_in_at, c.return_condition, c.return_note, c.clubs_missing, CONCAT(g.first_name,' ',g.last_name) guest FROM checkins c JOIN guests g ON g.id=c.main_guest_id WHERE c.course_id=? AND (c.return_condition IN ('damaged','warning') OR c.clubs_missing IS NOT NULL) ORDER BY c.id DESC LIMIT 8", [$cid])->fetchAll(),
        ]);
    }
    case 'dashboard/overview': { // head office: every course side by side
        $u = require_admin();
        $rows = [];
        foreach (q('SELECT id,name FROM courses WHERE active=1 ORDER BY id')->fetchAll() as $c) {
            $cid = (int)$c['id'];
            $t = q('SELECT COUNT(*) rounds, COALESCE(SUM(hire_fee + green_total + range_total),0) fees, COALESCE(SUM(returned_at IS NULL),0) out_now FROM checkins WHERE course_id=? AND DATE(checked_in_at)=CURDATE()', [$cid])->fetch();
            $w = q('SELECT COUNT(*) rounds, COALESCE(SUM(hire_fee + green_total + range_total),0) fees FROM checkins WHERE course_id=? AND checked_in_at >= (CURDATE() - INTERVAL 6 DAY)', [$cid])->fetch();
            $m = q('SELECT COUNT(*) rounds, COALESCE(SUM(hire_fee + green_total + range_total),0) fees FROM checkins WHERE course_id=? AND checked_in_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")', [$cid])->fetch();
            $f = q("SELECT COUNT(*) total, COALESCE(SUM(status='available'),0) available, COALESCE(SUM(status='out'),0) out_now FROM (SELECT " . STATUS_SQL . " AS status FROM assets a WHERE a.course_id=? AND a.active=1 AND a.kind='cart') x", [$cid])->fetch();
            $iss = (int)q("SELECT COUNT(*) FROM checkins WHERE course_id=? AND return_condition IN ('damaged','warning') AND checked_in_at >= (CURDATE() - INTERVAL 6 DAY)", [$cid])->fetchColumn();
            $rows[] = ['id' => $cid, 'name' => $c['name'], 'today' => $t, 'week' => $w, 'month' => $m, 'carts' => $f, 'issues_week' => $iss,
                       'days' => q('SELECT DATE(checked_in_at) d, COUNT(*) n FROM checkins WHERE course_id=? AND checked_in_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY d ORDER BY d', [$cid])->fetchAll()];
        }
        out(['courses' => $rows]);
    }
    // ---------- email: system default (head office) and each course's own choice ----------
    case 'email/get': {
        $u = require_course_admin();
        $cid = course_id($u);
        $pub = static function (array $r): ?array {
            if (!$r) return null;
            unset($r['updated_at']);
            $r['has_pass'] = !empty($r['smtp_pass']); unset($r['smtp_pass']); // the password never leaves the server
            return $r;
        };
        $eff = mail_settings_for($cid);
        $promo = '';
        try { $promo = (string)q('SELECT promo FROM courses WHERE id=?', [$cid])->fetchColumn(); } catch (Throwable $e) { }
        out(['course' => $pub(email_row($cid)), 'system' => is_admin($u) ? $pub(email_row(0)) : null, 'is_head_office' => is_admin($u),
             'sending_as' => ['from' => $eff['from'], 'name' => $eff['from_name'], 'source' => $eff['source'], 'method' => $eff['method'], 'enabled' => $eff['enabled']],
             'promo' => $promo]);
    }
    case 'email/save': {
        $u = require_course_admin();
        $b = body();
        $scope = ($b['scope'] ?? '') === 'system' ? 'system' : 'course';
        if ($scope === 'system') require_admin(); // only head office sets the system sender
        $cid = $scope === 'system' ? 0 : course_id($u);
        $mail = static function ($v, string $what): ?string { $v = clean($v, 150); if ($v === null) return null; if (!filter_var($v, FILTER_VALIDATE_EMAIL)) fail(422, "$what is not a valid email address"); return $v; };
        $method = ($b['method'] ?? '') === 'smtp' ? 'smtp' : 'mail';
        $secure = in_array($b['smtp_secure'] ?? '', ['none', 'ssl', 'tls'], true) ? $b['smtp_secure'] : 'tls';
        $host = clean($b['smtp_host'] ?? null, 150);
        if ($host !== null && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) fail(422, 'The SMTP server name should look like mail.example.com');
        $port = (int)($b['smtp_port'] ?? 0); if ($port && ($port < 1 || $port > 65535)) fail(422, 'The SMTP port should be a number such as 587 or 465');
        $own = $scope === 'course' && empty($b['use_system']);
        $from = $mail($b['from_email'] ?? null, 'The sending address');
        if (($scope === 'system' || $own) && $method === 'smtp' && !$host) fail(422, 'Enter the SMTP server (for example mail.example.com)');
        if ($own && !$from) fail(422, 'Enter the address this course should send from, or choose the system email');
        q('INSERT INTO email_settings (course_id, enabled, use_system, method, from_name, from_email, reply_to, smtp_host, smtp_port, smtp_secure, smtp_user)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), use_system=VALUES(use_system), method=VALUES(method), from_name=VALUES(from_name), from_email=VALUES(from_email),
             reply_to=VALUES(reply_to), smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port), smtp_secure=VALUES(smtp_secure), smtp_user=VALUES(smtp_user)',
          [$cid, $scope === 'system' || !empty($b['enabled']) ? 1 : 0, $scope === 'system' || !$own ? 1 : 0, $method, clean($b['from_name'] ?? null, 100), $from,
           $mail($b['reply_to'] ?? null, 'The reply-to address'), $host, $port ?: null, $secure, clean($b['smtp_user'] ?? null, 150)]);
        $pw = (string)($b['smtp_pass'] ?? '');
        if ($pw !== '') q('UPDATE email_settings SET smtp_pass=? WHERE course_id=?', [secret_enc($pw), $cid]);
        elseif (!empty($b['clear_pass'])) q('UPDATE email_settings SET smtp_pass=NULL WHERE course_id=?', [$cid]);
        audit($u['id'], 'email_settings', $scope === 'system' ? 'system' : "course $cid");
        out(['ok' => true]);
    }
    case 'email/test': { // sends a short message using the saved settings, so a bad password or server shows up now, not at check-in
        $u = require_course_admin();
        $b = body();
        $system = ($b['scope'] ?? '') === 'system';
        if ($system) require_admin();
        [$ok, $msg] = send_test_email($system ? 0 : course_id($u), (string)($b['to'] ?? ''));
        audit($u['id'], 'email_test', ($system ? 'system ' : 'course ') . ($ok ? 'ok' : 'failed'));
        if (!$ok) fail(502, $msg);
        out(['ok' => true]);
    }
    case 'course/promo': { // the banner at the foot of each emailed PDF; a course admin sets their own course's
        $u = require_course_admin();
        $p = (string)(body()['promo'] ?? '');
        $cid = course_id($u);
        if ($p === '') { q('UPDATE courses SET promo=NULL, promo_at=NULL WHERE id=?', [$cid]); out(['ok' => true]); }
        if (!preg_match('#^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$#', $p) || strlen($p) > 1100000) fail(422, 'Use a JPEG or PNG under about 800 KB');
        if (!pdf_image_from_dataurl($p)) fail(422, 'That image format can\'t go in the PDF. Save it as a standard JPEG or PNG and try again');
        q('UPDATE courses SET promo=?, promo_at=NOW() WHERE id=?', [$p, $cid]);
        audit($u['id'], 'promo_set', "course $cid");
        out(['ok' => true]);
    }

    // ---------- scheduled report emails and overdue-cart alerts (course admin) ----------
    case 'schedule/list': {
        $u = require_course_admin();
        out(['schedules' => q('SELECT id, report_type, frequency, recipients, enabled, last_sent FROM report_schedules WHERE course_id=? ORDER BY id', [course_id($u)])->fetchAll(), 'types' => SCHEDULE_TYPES]);
    }
    case 'schedule/save': {
        $u = require_course_admin();
        $b = body();
        $cid = course_id($u);
        $type = (string)($b['report_type'] ?? ''); if (!isset(SCHEDULE_TYPES[$type])) fail(422, 'Choose a report');
        $freq = ($b['frequency'] ?? '') === 'monthly' ? 'monthly' : 'weekly';
        $to = split_emails((string)($b['recipients'] ?? '')); if (!$to) fail(422, 'Add at least one valid email address'); if (count($to) > 10) fail(422, 'Up to 10 addresses');
        q('INSERT INTO report_schedules (course_id, report_type, frequency, recipients, enabled) VALUES (?,?,?,?,?)
           ON DUPLICATE KEY UPDATE recipients=VALUES(recipients), enabled=VALUES(enabled)', [$cid, $type, $freq, implode(', ', $to), empty($b['enabled']) && isset($b['enabled']) ? 0 : 1]);
        audit($u['id'], 'schedule_save', "course $cid $type $freq");
        out(['ok' => true]);
    }
    case 'schedule/delete': {
        $u = require_course_admin();
        q('DELETE FROM report_schedules WHERE id=? AND course_id=?', [(int)(body()['id'] ?? 0), course_id($u)]);
        audit($u['id'], 'schedule_save', 'removed a schedule');
        out(['ok' => true]);
    }
    case 'schedule/send': { // send one schedule's report now (last week or last month), to check the recipients and layout
        $u = require_course_admin();
        $sc = q('SELECT * FROM report_schedules WHERE id=? AND course_id=?', [(int)(body()['id'] ?? 0), course_id($u)])->fetch() ?: fail(404, 'Not found');
        [$ok, $msg] = send_report_now($sc);
        if (!$ok) fail(502, $msg);
        out(['ok' => true]);
    }
    case 'alert/get': {
        $u = require_course_admin();
        $r = q('SELECT enabled, recipients, webhook_url FROM alert_settings WHERE course_id=?', [course_id($u)])->fetch() ?: ['enabled' => 0, 'recipients' => '', 'webhook_url' => ''];
        out(['alert' => $r, 'timing' => timing_for(course_id($u))]);
    }
    case 'alert/save': {
        $u = require_course_admin();
        $b = body();
        $hook = clean($b['webhook_url'] ?? null, 300);
        if ($hook !== null && !preg_match('#^https://[^\s]+$#i', $hook)) fail(422, 'The webhook address must start with https://');
        $to = split_emails((string)($b['recipients'] ?? ''));
        q('INSERT INTO alert_settings (course_id, enabled, recipients, webhook_url) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), recipients=VALUES(recipients), webhook_url=VALUES(webhook_url)', [course_id($u), empty($b['enabled']) ? 0 : 1, implode(', ', $to), $hook]);
        audit($u['id'], 'alert_save', 'course ' . course_id($u));
        out(['ok' => true]);
    }
    case 'alert/test': { // a pretend overdue cart, to prove the email / webhook reaches the right people
        $u = require_course_admin();
        $cid = course_id($u);
        $s = q('SELECT s.course_id, s.recipients, s.webhook_url, co.name, co.full_name FROM alert_settings s JOIN courses co ON co.id=s.course_id WHERE s.course_id=?', [$cid])->fetch() ?: fail(422, 'Save the alert settings first');
        [$ok, $msg] = notify_overdue($s, [['cart_name' => 'Cart 99', 'guest' => 'Test Guest', 'mins' => 400, 'holes' => 18]], true);
        if (!$ok) fail(502, $msg);
        out(['ok' => true]);
    }

    case 'audit/list': { // the activity log: who did what and when. A course admin sees their own course's team; head office sees everyone
        $u = require_course_admin();
        $bound = user_course_id($u);
        $from = valid_date($_GET['from'] ?? null) ?? date('Y-m-d', strtotime('-6 days'));
        $to = valid_date($_GET['to'] ?? null) ?? date('Y-m-d');
        $w = 'DATE(l.at) BETWEEN ? AND ?'; $p = [$from, $to];
        if ($bound) { $w .= ' AND u.course_id=?'; $p[] = $bound; }
        if (!empty($_GET['user'])) { $w .= ' AND l.user_id=?'; $p[] = (int)$_GET['user']; }
        $s = trim((string)($_GET['q'] ?? ''));
        if ($s !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%'; $w .= ' AND (l.action LIKE ? OR l.detail LIKE ? OR u.name LIKE ?)'; array_push($p, $like, $like, $like); }
        $rows = q("SELECT l.id, l.at, l.action, l.detail, l.ip, u.name, u.username, u.role, co.name AS course
                   FROM audit_log l LEFT JOIN users u ON u.id=l.user_id LEFT JOIN courses co ON co.id=u.course_id WHERE $w ORDER BY l.id DESC LIMIT 400", $p)->fetchAll();
        $people = $bound ? q("SELECT id, name FROM users WHERE course_id=? ORDER BY name", [$bound])->fetchAll() : q('SELECT id, name FROM users ORDER BY name')->fetchAll();
        out(['rows' => $rows, 'people' => $people, 'from' => $from, 'to' => $to, 'head_office' => !$bound]);
    }
    case 'rates/get': { // the course's green-fee rate card (not secret: the tablet shows guests what they will pay)
        $u = require_login();
        $cid = course_id($u);
        out(['rates' => rates_for($cid), 'types' => array_values(guest_types_for($cid, ($_GET['all'] ?? '') !== '1')), 'range' => q('SELECT id, name, price, member_free FROM range_products WHERE course_id=? AND active=1 ORDER BY sort, id', [$cid])->fetchAll()]);
    }
    case 'rates/save': {
        $u = require_course_admin();
        $cid = course_id($u);
        $in = body()['rates'] ?? [];
        foreach ([9, 18] as $h) foreach (array_keys(guest_types_for($cid, false)) as $cat) {
            $amt = $in[$h][$cat] ?? 0;
            if (!is_numeric($amt) || (float)$amt < 0 || (float)$amt > 100000) fail(422, 'Rates must be amounts from 0 upwards');
            q('INSERT INTO rates (course_id, holes, category, amount) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount)', [$cid, $h, $cat, round((float)$amt, 2)]);
        }
        audit($u['id'], 'rates_save', "course $cid");
        out(['ok' => true]);
    }
    case 'types/save': { // the course's guest types: keep member/resort/resident/visitor, add or rename the rest (senior, PGA member...)
        $u = require_course_admin();
        $cid = course_id($u);
        $list = body()['types'] ?? [];
        if (!is_array($list) || count($list) > 20) fail(422, 'Too many guest types');
        $core = array_column(DEFAULT_GUEST_TYPES, 1, 0); $seen = []; $n = 0;
        foreach ($list as $t) {
            $label = clean($t['label'] ?? null, 60); if (!$label) continue;
            $code = isset($t['code']) && preg_match('/^[a-z0-9_]{1,30}$/', (string)$t['code']) ? $t['code'] : substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_'), 0, 30);
            if ($code === '' || isset($seen[$code])) continue;
            $seen[$code] = 1;
            $isCore = isset($core[$code]);
            q('INSERT INTO guest_types (course_id,code,label,proof,sort,active) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),proof=VALUES(proof),sort=VALUES(sort),active=VALUES(active)',
              [$cid, $code, $label, $isCore || empty($t['proof']) ? 0 : 1, ++$n, $isCore || !isset($t['active']) || $t['active'] ? 1 : 0]);
        }
        foreach ($core as $c => $lbl) if (!isset($seen[$c])) q('INSERT IGNORE INTO guest_types (course_id,code,label,proof,sort,active) VALUES (?,?,?,0,?,1)', [$cid, $c, $lbl, ++$n]);
        audit($u['id'], 'types_save', "course $cid");
        out(['types' => array_values(guest_types_for($cid, false))]);
    }
    case 'range/products': { // admin list (including switched-off items)
        $u = require_course_admin();
        out(['products' => q('SELECT id, name, price, active, member_free FROM range_products WHERE course_id=? ORDER BY sort, id', [course_id($u)])->fetchAll()]);
    }
    case 'range/products/save': {
        $u = require_course_admin();
        $cid = course_id($u); $b = body();
        $name = clean($b['name'] ?? null, 60) ?? fail(422, 'Name required');
        $price = is_numeric($b['price'] ?? null) ? round((float)$b['price'], 2) : fail(422, 'Enter a price');
        if ($price < 0 || $price > 10000) fail(422, 'Enter a price from 0 upwards');
        $act = isset($b['active']) && !$b['active'] ? 0 : 1;
        $free = empty($b['member_free']) ? 0 : 1; // the one product verified members get free, once a day
        if (!empty($b['id'])) {
            if ($free) q('UPDATE range_products SET member_free=0 WHERE course_id=? AND id<>?', [$cid, (int)$b['id']]);
            q('UPDATE range_products SET name=?, price=?, active=?, member_free=? WHERE id=? AND course_id=?', [$name, $price, $act, $free, (int)$b['id'], $cid]);
        } else {
            if ($free) q('UPDATE range_products SET member_free=0 WHERE course_id=?', [$cid]);
            q('INSERT INTO range_products (course_id,name,price,active,sort,member_free) VALUES (?,?,?,?,?,?)', [$cid, $name, $price, $act, (int)q('SELECT COALESCE(MAX(sort),0)+1 FROM range_products WHERE course_id=?', [$cid])->fetchColumn(), $free]);
        }
        audit($u['id'], 'range_product', $name);
        out(['ok' => true]);
    }
    case 'range/pending': { // the golf attendant's alert list: paid range-ball orders still to be issued
        $u = require_perm('range');
        $cid = course_id($u);
        out(['orders' => q("SELECT id, checkin_id, guest_name, product_name, qty, free, created_at FROM range_orders WHERE course_id=? AND status='pending' ORDER BY id", [$cid])->fetchAll(), 'now' => date('Y-m-d H:i:s')]);
    }
    case 'range/issue': {
        $u = require_perm('range');
        $cid = course_id($u); $id = (int)(body()['id'] ?? 0);
        $n = q("UPDATE range_orders SET status='issued', issued_at=NOW(), issued_by=? WHERE id=? AND course_id=? AND status='pending'", [$u['id'], $id, $cid])->rowCount();
        if ($n) audit($u['id'], 'range_issue', "order $id");
        out(['ok' => true, 'already' => !$n]);
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
            ? q("SELECT id,username,name,role,course_id,active FROM users WHERE course_id=? AND role IN ('staff','supervisor','attendant') ORDER BY role DESC, name", [$bound])->fetchAll()
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
        $role = in_array($b['role'] ?? '', ['staff', 'attendant', 'supervisor', 'admin'], true) ? $b['role'] : 'staff';
        if ($bound && $role === 'admin') fail(403, 'Only head office can create admins');
        if ($role !== 'admin' && $courseId === null) fail(422, 'Choose a course for this login');
        $pw = (string)($b['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $un) || !$name) fail(422, 'Username (3+ letters/numbers) and name required');
        if (!empty($b['id'])) {
            if ($bound && !q("SELECT 1 FROM users WHERE id=? AND course_id=? AND role IN ('staff','supervisor','attendant')", [(int)$b['id'], $bound])->fetch()) fail(403, 'You can only manage supervisors and staff for your own course');
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
        foreach (array_keys(ROLE_DEFAULTS) as $role) foreach (PERMS as $p) {
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
