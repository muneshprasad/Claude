<?php
declare(strict_types=1);
/**
 * Background jobs: overdue-cart alerts and scheduled report emails.
 * They run when the app is in use (a tablet asking for the carts-out list), so no cron is needed; cron/run_jobs.php can also call
 * run_scheduled_jobs() on a timer for guaranteed timing. Included by index.php; not meant to be opened directly.
 */

/** Claim a job slot: true for exactly one caller per interval, even with many tablets polling at once. */
function job_claim(string $key, int $seconds): bool {
    try {
        q("INSERT IGNORE INTO app_state (k, v, updated_at) VALUES (?, '0', '2000-01-01 00:00:00')", [$key]);
        return q('UPDATE app_state SET v=UNIX_TIMESTAMP() WHERE k=? AND updated_at < (NOW() - INTERVAL ' . (int)$seconds . ' SECOND)', [$key])->rowCount() === 1;
    } catch (Throwable $e) { return false; } // tables not created yet
}

function run_scheduled_jobs(): void {
    if (job_claim('overdue_alerts', 240)) { try { send_overdue_alerts(); } catch (Throwable $e) { error_log('bulagolf alerts: ' . $e->getMessage()); } }
    if (job_claim('scheduled_reports', 1800)) { try { send_scheduled_reports(); } catch (Throwable $e) { error_log('bulagolf reports: ' . $e->getMessage()); } }
}

/** Run the jobs after the response has gone back to the tablet, so nobody waits for them. */
function queue_jobs(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        ignore_user_abort(true); @set_time_limit(120);
        try { run_scheduled_jobs(); } catch (Throwable $e) { error_log('bulagolf jobs: ' . $e->getMessage()); }
    });
}

function split_emails(?string $s): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$s, -1, PREG_SPLIT_NO_EMPTY) as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = $e;
    return array_values($out);
}

function dur_text(int $m): string { return $m < 60 ? $m . 'm' : intdiv($m, 60) . 'h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . 'm'; }

/* ---------------- overdue-cart alerts ---------------- */

/** One alert per overdue check-in (today's), by email and/or webhook, to the people the course admin chose. */
function send_overdue_alerts(): void {
    foreach (q('SELECT s.course_id, s.recipients, s.webhook_url, co.name, co.full_name, co.late9, co.late18 FROM alert_settings s JOIN courses co ON co.id=s.course_id WHERE s.enabled=1 AND co.active=1')->fetchAll() as $s) {
        $cid = (int)$s['course_id'];
        $rows = q("SELECT c.id, c.holes, ca.name cart_name, CONCAT(g.first_name,' ',g.last_name) guest, TIMESTAMPDIFF(MINUTE, c.checked_in_at, NOW()) mins
                   FROM checkins c JOIN guests g ON g.id=c.main_guest_id JOIN assets ca ON ca.id=c.cart_id
                   WHERE c.course_id=? AND c.returned_at IS NULL AND DATE(c.checked_in_at)=CURDATE()
                     AND TIMESTAMPDIFF(MINUTE, c.checked_in_at, NOW()) > IF(c.holes=9, ?, ?) AND c.id NOT IN (SELECT checkin_id FROM alert_log) ORDER BY mins DESC",
                  [$cid, (int)$s['late9'], (int)$s['late18']])->fetchAll();
        if (!$rows) continue;
        foreach ($rows as $r) q('INSERT IGNORE INTO alert_log (checkin_id) VALUES (?)', [(int)$r['id']]); // claim first, so a slow send is never repeated
        notify_overdue($s, $rows);
        audit(null, 'overdue_alert', 'course ' . $cid . ': ' . count($rows) . ' cart' . (count($rows) === 1 ? '' : 's'));
    }
}

/** @return array{0:bool,1:string} */
function notify_overdue(array $s, array $rows, bool $test = false): array {
    $club = ($s['full_name'] ?? '') ?: ($s['name'] ?? 'the course');
    $lines = array_map(fn($r) => $r['cart_name'] . ' · ' . $r['guest'] . ' · out ' . dur_text((int)$r['mins']) . ' (' . (int)$r['holes'] . ' holes)', $rows);
    $head = ($test ? 'TEST: ' : '') . count($rows) . ' cart' . (count($rows) === 1 ? ' is' : 's are') . ' overdue at ' . $club;
    $text = $head . "\n\n" . implode("\n", $lines) . "\n\nThis alert is sent once per cart.";
    $ok = true; $msg = 'Sent';
    $to = split_emails($s['recipients'] ?? '');
    if ($to) {
        $set = mail_settings_for((int)$s['course_id']);
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px"><h3 style="color:#b3261e;margin:0 0 8px">' . htmlspecialchars($head) . '</h3><ul>' . implode('', array_map(fn($l) => '<li>' . htmlspecialchars($l) . '</li>', $lines)) . '</ul><p style="color:#68756c">This alert is sent once per cart.</p></div>';
        foreach ($to as $addr) { [$o, $m] = mail_send($addr, $head, $text, $html, '', '', $set); if (!$o) { $ok = false; $msg = $m; } }
    }
    $hook = trim((string)($s['webhook_url'] ?? ''));
    if ($hook !== '' && preg_match('#^https://#i', $hook)) {
        $payload = json_encode(['event' => 'overdue_carts', 'test' => $test, 'course' => $club, 'text' => $text, 'carts' => array_map(fn($r) => ['cart' => $r['cart_name'], 'guest' => $r['guest'], 'minutes_out' => (int)$r['mins'], 'holes' => (int)$r['holes']], $rows)], JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 8, 'ignore_errors' => true]]);
        $res = @file_get_contents($hook, false, $ctx);
        if ($res === false) { $ok = false; $msg = 'The webhook did not answer'; }
    }
    if (!$to && $hook === '') return [false, 'Add an email address or a webhook first'];
    return [$ok, $msg];
}

/* ---------------- scheduled report emails ---------------- */

function fmt_cell($v, string $t, string $cur): string {
    if ($v === null || $v === '') return '—';
    return match ($t) {
        'money' => $cur . ' ' . number_format((float)$v, 2), 'int' => number_format((float)$v), 'pct' => $v . '%', 'mins' => dur_text((int)$v),
        'date' => date('j M Y', strtotime((string)$v)), 'datetime' => date('j M, g:i a', strtotime((string)$v)), default => (string)$v,
    };
}

function report_to_csv(array $r, string $club): string {
    $f = fopen('php://temp', 'r+');
    fputcsv($f, [$club, $r['title'], $r['from'] . ' to ' . $r['to']]);
    foreach ($r['summary'] as [$l, $v]) fputcsv($f, [$l, $v]);
    fputcsv($f, []);
    foreach ($r['sections'] as $sec) {
        fputcsv($f, [$sec['title']]); fputcsv($f, array_map(fn($c) => $c[0], $sec['columns']));
        foreach ($sec['rows'] as $row) fputcsv($f, $row);
        fputcsv($f, []);
    }
    rewind($f); $csv = stream_get_contents($f); fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}

function report_to_html(array $r, string $club, string $period, string $cur, int $maxRows = 25): string {
    $th = 'style="text-align:left;padding:6px 8px;border-bottom:2px solid #d9d2c0;font-size:12px;color:#68756c"';
    $td = 'style="padding:6px 8px;border-bottom:1px solid #eee7d6;font-size:14px"';
    $h = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1b2a21;max-width:720px"><h2 style="font-family:Georgia,serif;color:#0f3d26;margin:0">' . htmlspecialchars($r['title']) . '</h2>'
       . '<p style="color:#68756c;margin:2px 0 14px">' . htmlspecialchars($club) . ' · ' . htmlspecialchars($period) . '</p>';
    if ($r['summary']) {
        $h .= '<table cellspacing="0" style="margin-bottom:16px"><tr>';
        foreach ($r['summary'] as [$l, $v, $t]) $h .= '<td style="padding:8px 16px 8px 0;vertical-align:top"><div style="font-size:22px;font-weight:bold;color:#0f3d26">' . htmlspecialchars(fmt_cell($v, (string)$t, $cur)) . '</div><div style="font-size:12px;color:#68756c">' . htmlspecialchars((string)$l) . '</div></td>';
        $h .= '</tr></table>';
    }
    foreach ($r['sections'] as $sec) {
        $h .= '<h3 style="margin:16px 0 6px;font-size:15px">' . htmlspecialchars($sec['title']) . '</h3>';
        if (!$sec['rows']) { $h .= '<p style="color:#68756c">Nothing in this period.</p>'; continue; }
        $h .= '<table cellspacing="0" width="100%"><tr>' . implode('', array_map(fn($c) => "<th $th>" . htmlspecialchars($c[0]) . '</th>', $sec['columns'])) . '</tr>';
        foreach (array_slice($sec['rows'], 0, $maxRows) as $row) {
            $h .= '<tr>'; foreach ($row as $i => $v) $h .= "<td $td>" . htmlspecialchars(fmt_cell($v, (string)$sec['columns'][$i][1], $cur)) . '</td>'; $h .= '</tr>';
        }
        $h .= '</table>' . (count($sec['rows']) > $maxRows ? '<p style="color:#68756c;font-size:12px">Showing the first ' . $maxRows . ' of ' . count($sec['rows']) . '. The full report is attached.</p>' : '');
    }
    return $h . '<p style="color:#68756c;font-size:12px;margin-top:18px">The full report is attached as a CSV file you can open in Excel.</p></div>';
}

/** The period a schedule covers when it fires today: last Mon-Sun week, or last calendar month. @return array{0:string,1:string,2:string} from, to, label */
function schedule_period(string $freq): array {
    $today = new DateTimeImmutable('today');
    if ($freq === 'weekly') { $s = $today->modify('monday this week')->modify('-7 days'); $e = $s->modify('+6 days'); return [$s->format('Y-m-d'), $e->format('Y-m-d'), 'Last week (' . $s->format('D j M') . ' to ' . $e->format('D j M') . ')']; }
    $s = $today->modify('first day of last month'); $e = $today->modify('last day of last month');
    return [$s->format('Y-m-d'), $e->format('Y-m-d'), $s->format('F Y')];
}

/** Build one schedule's report and email it. @return array{0:bool,1:string} */
function send_report_now(array $sc): array {
    $to = split_emails($sc['recipients'] ?? '');
    if (!$to) return [false, 'Add at least one email address'];
    $cid = (int)$sc['course_id'];
    [$from, $toD, $label] = schedule_period((string)$sc['frequency']);
    $r = report_run((string)$sc['report_type'], $cid, $from, $toD);
    $club = (string)(q('SELECT COALESCE(NULLIF(full_name, ""), name) FROM courses WHERE id=?', [$cid])->fetchColumn() ?: 'the course');
    $cur = (string)(cfg()['currency'] ?? 'FJD');
    $set = mail_settings_for($cid);
    $subject = $r['title'] . ' · ' . $label . ' · ' . $club;
    $text = $subject . "\n\nThe full report is attached as a CSV file.\n";
    $csv = report_to_csv($r, $club); $html = report_to_html($r, $club, $label, $cur);
    $ok = true; $msg = 'Sent';
    foreach ($to as $addr) { [$o, $m] = mail_send($addr, $subject, $text, $html, strtolower(preg_replace('/\W+/', '-', $r['title'])) . '-' . $from . '-to-' . $toD . '.csv', $csv, $set, 'text/csv'); if (!$o) { $ok = false; $msg = $m; } }
    return [$ok, $msg];
}

function send_scheduled_reports(): void {
    if ((int)date('G') < 6) return; // not in the small hours
    foreach (q('SELECT * FROM report_schedules WHERE enabled=1')->fetchAll() as $sc) {
        [, $end] = schedule_period((string)$sc['frequency']);
        if (!empty($sc['last_sent']) && $sc['last_sent'] >= $end) continue; // this period already went out
        q('UPDATE report_schedules SET last_sent=? WHERE id=?', [$end, (int)$sc['id']]); // claim first
        [$ok] = send_report_now($sc);
        audit(null, 'report_sent', 'course ' . (int)$sc['course_id'] . ' ' . $sc['report_type'] . ' ' . $sc['frequency'] . ($ok ? '' : ' (failed)'));
    }
}

/** The report types that can be emailed on a schedule. */
const SCHEDULE_TYPES = [
    'fleet' => 'Cart & equipment usage', 'cashup' => 'Daily cash-up', 'revenue' => 'Revenue: green fees & hire', 'playmix' => 'Golfers: members, resort guests, 9 vs 18',
    'rounds' => 'Rounds & peak times', 'guests' => 'Guest mix', 'incidents' => 'Incidents & audit', 'members' => 'Members: renewals and activity',
];

function timing_for(int $course): array { return q('SELECT t9, t18, late9, late18 FROM courses WHERE id=?', [$course])->fetch() ?: ['t9' => 135, 't18' => 270, 'late9' => 210, 'late18' => 360]; }
