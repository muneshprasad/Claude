<?php
declare(strict_types=1);
/** Query helpers shared by the web API and the background jobs (scheduled reports, alerts). Included by index.php; not meant to be opened directly. */

function checkin_rows(string $where, array $p): array {
    return q("SELECT c.id,c.checked_in_at,c.returned_at,c.holes,c.fee_status,c.tee_time,c.hire_fee,c.green_total,c.clubs_missing,c.notes,c.return_condition,c.return_note,
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
    $sec = function (string $title, array $cols, array $rows, ?array $ids = null) use (&$sections) { $sections[] = ['title' => $title, 'columns' => $cols, 'rows' => $rows, 'ids' => $ids]; }; // ids: the check-in behind each row, so a row can be opened
    $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 1) : 0;
    $titles = ['revenue' => 'Revenue: green fees and hire', 'memberyear' => 'Member usage over the membership year', 'playmix' => 'Golfers: members, resort guests and holes', 'cashup' => 'Daily cash-up', 'rounds' => 'Rounds and peak times', 'fleet' => 'Cart and equipment usage', 'guests' => 'Guest mix', 'members' => 'Members', 'incidents' => 'Incidents and audit', 'trends' => '12-month trend'];
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
        $due = '(c.hire_fee + c.green_total + c.range_total)'; // everything owed on a check-in: green fees plus hire
        $rows = q("SELECT DATE(c.checked_in_at) d, COUNT(*) rounds, SUM($golfers) golfers, SUM(c.green_total) green, SUM(c.hire_fee) hire, SUM($due) due,
                   SUM(CASE WHEN c.fee_status='paid' THEN $due ELSE 0 END) paid, SUM(CASE WHEN c.fee_status='room' THEN $due ELSE 0 END) room,
                   SUM(CASE WHEN c.fee_status='unpaid' THEN $due ELSE 0 END) unpaid, SUM(c.fee_status='member') member_rounds
                   FROM checkins c WHERE $W GROUP BY DATE(c.checked_in_at) ORDER BY d", $P)->fetchAll();
        $t = ['rounds' => 0, 'golfers' => 0, 'green' => 0, 'hire' => 0, 'due' => 0, 'paid' => 0, 'room' => 0, 'unpaid' => 0, 'm' => 0];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['d'], (int)$r['rounds'], (int)$r['golfers'], (float)$r['green'], (float)$r['hire'], (float)$r['due'], (float)$r['paid'], (float)$r['room'], (float)$r['unpaid'], (int)$r['member_rounds']];
            foreach (['rounds', 'golfers', 'green', 'hire', 'due', 'paid', 'room', 'unpaid'] as $k) $t[$k] += $r[$k];
            $t['m'] += $r['member_rounds'];
        }
        if ($out) $out[] = ['Total', $t['rounds'], $t['golfers'], $t['green'], $t['hire'], $t['due'], $t['paid'], $t['room'], $t['unpaid'], $t['m']];
        $sec('By day', [['Date', 'date'], ['Check-ins', 'int'], ['Golfers', 'int'], ['Green fees', 'money'], ['Hire', 'money'], ['Total due', 'money'], ['Paid', 'money'], ['Charged to room', 'money'], ['Still to collect', 'money'], ['Member check-ins (nothing to pay)', 'int']], $out);
        $st = q("SELECT u.name, COUNT(*) rounds, SUM($due) due, SUM(CASE WHEN c.fee_status='unpaid' THEN $due ELSE 0 END) unpaid, SUM(c.fee_status='member') member_rounds
                 FROM checkins c JOIN users u ON u.id=c.staff_id WHERE $W GROUP BY u.id, u.name ORDER BY rounds DESC", $P)->fetchAll();
        $sec('By staff member', [['Staff', 'text'], ['Check-ins', 'int'], ['Total due', 'money'], ['Still to collect', 'money'], ['Member check-ins (nothing to pay)', 'int']],
             array_map(fn($r) => [$r['name'], (int)$r['rounds'], (float)$r['due'], (float)$r['unpaid'], (int)$r['member_rounds']], $st));
        $summary = [['Check-ins', $t['rounds'], 'int'], ['Green fees', $t['green'], 'money'], ['Hire', $t['hire'], 'money'], ['Total due', $t['due'], 'money'], ['Charged to room', $t['room'], 'money'], ['Still to collect', $t['unpaid'], 'money']];
    }

    if ($type === 'revenue') {
        // green fees by golfer type and round length, from the amounts priced at check-in
        $names = ['member' => 'Members', 'resort' => 'Resort / hotel guests', 'resident' => 'Fiji residents', 'visitor' => 'Other visitors', 'unpriced' => 'Earlier check-ins (before the rate card)'];
        $rows = q("SELECT COALESCE(cg.rate_cat, 'unpriced') cat, c.holes, COUNT(*) n, SUM(cg.green_fee) amt FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id
                   WHERE $W GROUP BY COALESCE(cg.rate_cat, 'unpriced'), c.holes", $P)->fetchAll();
        $unp = $names['unpriced']; unset($names['unpriced']); // the course's own guest types (senior, PGA member...) join the standard four
        foreach (q('SELECT code, MIN(label) l FROM guest_types GROUP BY code')->fetchAll() as $t) if (!isset($names[$t['code']])) $names[$t['code']] = $t['l'];
        $names['unpriced'] = $unp;
        $by = []; foreach ($rows as $r) $by[$r['cat']][(int)$r['holes']] = [(int)$r['n'], (float)$r['amt']];
        $out = []; $gn = 0; $ga = 0.0;
        foreach ($names as $k => $label) foreach ([18, 9] as $h) {
            if (empty($by[$k][$h])) continue;
            [$n, $amt] = $by[$k][$h]; $gn += $n; $ga += $amt;
            $out[] = [$label, $h . ' holes', $n, $amt, $n ? round($amt / $n, 2) : 0];
        }
        if ($out) $out[] = ['All golfers', '', $gn, $ga, $gn ? round($ga / $gn, 2) : 0];
        $sec('Green fees by golfer type', [['Group', 'text'], ['Round', 'text'], ['Golfers', 'int'], ['Green fees', 'money'], ['Average per golfer', 'money']], $out);
        $dy = q("SELECT DATE(c.checked_in_at) d, SUM(c.green_total) green, SUM(c.hire_fee) hire FROM checkins c WHERE $W GROUP BY DATE(c.checked_in_at) ORDER BY d", $P)->fetchAll();
        $sec('By day', [['Date', 'date'], ['Green fees', 'money'], ['Hire', 'money'], ['Total', 'money']], array_map(fn($r) => [$r['d'], (float)$r['green'], (float)$r['hire'], (float)$r['green'] + (float)$r['hire']], $dy));
        $hire = array_sum(array_column($dy, 'hire'));
        $summary = [['Green fees', $ga, 'money'], ['Hire', $hire, 'money'], ['Total takings', $ga + $hire, 'money'], ['Average green fee per golfer', $gn ? round($ga / $gn, 2) : 0, 'money']];
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
        $iss = q("SELECT c.id, c.checked_in_at, CONCAT(g.first_name,' ',g.last_name) guest, ca.name cart, c.return_condition, c.return_note FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1
                  LEFT JOIN assets ca ON ca.id=c.cart_id WHERE $W AND c.return_condition IN ('damaged','warning') ORDER BY c.id DESC LIMIT 200", $P)->fetchAll();
        $sec('Damage and patron warnings', [['When', 'datetime'], ['Guest', 'text'], ['Cart', 'text'], ['Type', 'text'], ['Notes', 'text']], array_map(fn($r) => [$r['checked_in_at'], $r['guest'], $r['cart'], $r['return_condition'], $r['return_note']], $iss), array_map('intval', array_column($iss, 'id')));
        $ms = q("SELECT c.id, c.returned_at, CONCAT(g.first_name,' ',g.last_name) guest, cl.name clubs, c.clubs_missing, u.name staff FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN assets cl ON cl.id=c.clubs_id
                 LEFT JOIN users u ON u.id=c.return_staff_id WHERE $W AND c.clubs_missing IS NOT NULL ORDER BY c.id DESC LIMIT 200", $P)->fetchAll();
        $sec('Golf sets returned with items missing', [['Returned', 'datetime'], ['Guest', 'text'], ['Set', 'text'], ['What was missing', 'text'], ['Checked by', 'text']], array_map(fn($r) => [$r['returned_at'], $r['guest'], $r['clubs'], $r['clubs_missing'], $r['staff']], $ms), array_map('intval', array_column($ms, 'id')));
        $mf = q("SELECT u.name, COUNT(*) n FROM checkins c JOIN users u ON u.id=c.staff_id WHERE $W AND c.fee_status='member' GROUP BY u.id, u.name ORDER BY n DESC", $P)->fetchAll();
        $sec('Member (no-fee) check-ins by staff member: look for unusual patterns', [['Staff', 'text'], ['Member check-ins', 'int']], array_map(fn($r) => [$r['name'], (int)$r['n']], $mf));
        $op = q("SELECT c.id, c.checked_in_at, CONCAT(g.first_name,' ',g.last_name) guest, ca.name cart, u.name staff FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 JOIN users u ON u.id=c.staff_id
                 LEFT JOIN assets ca ON ca.id=c.cart_id WHERE c.course_id=? AND c.returned_at IS NULL AND DATE(c.checked_in_at) < CURDATE() ORDER BY c.checked_in_at", [$cid])->fetchAll();
        $sec('Still not returned from an earlier day (check these)', [['Checked in', 'datetime'], ['Guest', 'text'], ['Cart', 'text'], ['Checked in by', 'text']], array_map(fn($r) => [$r['checked_in_at'], $r['guest'], $r['cart'], $r['staff']], $op), array_map('intval', array_column($op, 'id')));
        $u18 = (int)q("SELECT COUNT(*) FROM checkin_golfers cg JOIN checkins c ON c.id=cg.checkin_id WHERE $W AND cg.guardian_name IS NOT NULL", $P)->fetchColumn();
        $summary = [['Damage / warnings', count($iss), 'int'], ['Golf sets with items missing', count($ms), 'int'], ['Not returned from earlier days', count($op), 'int'], ['Under-18 golfers (guardian signed)', $u18, 'int']];
    }

    if ($type === 'trends') {
        $tr = q("SELECT DATE_FORMAT(c.checked_in_at, '%Y-%m') ym, COUNT(*) rounds, SUM($golfers) golfers, SUM(c.hire_fee + c.green_total + c.range_total) hire, SUM(c.fee_status='member') member_rounds,
                 SUM(CASE WHEN cg.country IS NOT NULL AND cg.country<>'' AND NOT ($local) THEN 1 ELSE 0 END) overseas
                 FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN checkin_golfers cg ON cg.checkin_id=c.id AND cg.is_main=1 WHERE c.course_id=? AND c.checked_in_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
                 GROUP BY ym ORDER BY ym", [$cid])->fetchAll();
        $sec('Month by month', [['Month', 'text'], ['Check-ins', 'int'], ['Golfers', 'int'], ['Takings (green fees + hire)', 'money'], ['Member check-ins', 'int'], ['Overseas share', 'pct']],
             array_map(fn($r) => [$r['ym'], (int)$r['rounds'], (int)$r['golfers'], (float)$r['hire'], (int)$r['member_rounds'], $pct($r['overseas'], $r['rounds'])], $tr));
        $summary = [['Months with data', count($tr), 'int']];
    }

    return ['type' => $type, 'title' => $titles[$type], 'from' => $from, 'to' => $to, 'summary' => $summary, 'sections' => $sections];
}
