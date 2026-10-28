<?php
declare(strict_types=1);
/**
 * GPS cart status sync. Run from cPanel > Cron Jobs every minute:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/golf/cron/gps_sync.php
 * (adjust the path; the cron folder should NOT be web-accessible - keep it outside public_html if you can,
 *  and change the require path below to match.)
 *
 * Providers (config.php 'gps_provider'):
 *   none       - does nothing; carts are available unless checked out or in maintenance
 *   simulated  - random battery/status, for testing the screens
 *   http       - TEMPLATE: fill in fetch_http() once we have the GPS vendor's API docs
 *
 * Each provider returns rows like:
 *   ['unit' => 'GPS-001', 'status' => 'ready|charging|offline|out_of_bounds', 'battery' => 87, 'lat' => -17.77, 'lng' => 177.38]
 * matched to carts by assets.gps_unit_id.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../public/api/lib.php';
$c = cfg();

function fetch_simulated(): array {
    $rows = [];
    foreach (q("SELECT gps_unit_id FROM assets WHERE kind='cart' AND active=1 AND gps_unit_id IS NOT NULL")->fetchAll() as $r) {
        $s = random_int(1, 20);
        $rows[] = ['unit' => $r['gps_unit_id'], 'status' => $s === 1 ? 'offline' : ($s === 2 ? 'charging' : 'ready'), 'battery' => random_int(20, 100), 'lat' => null, 'lng' => null];
    }
    return $rows;
}

function fetch_http(array $c): array {
    // TODO: replace with the real vendor call. Example shape:
    // $ch = curl_init($c['gps_api_url']); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    //     CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['gps_api_key']]]);
    // $json = json_decode((string)curl_exec($ch), true);
    // map each vehicle in $json to ['unit'=>..., 'status'=>..., 'battery'=>..., 'lat'=>..., 'lng'=>...]
    throw new RuntimeException('GPS http provider not implemented yet');
}

if (($c['gps_provider'] ?? 'none') === 'none') { echo "GPS provider is 'none'; nothing to do\n"; exit; }
$rows = match ($c['gps_provider'] ?? 'none') {
    'simulated' => fetch_simulated(),
    'http' => fetch_http($c),
    default => [],
};
$allowed = ['ready', 'charging', 'offline', 'out_of_bounds'];
foreach ($rows as $r) {
    $st = in_array($r['status'], $allowed, true) ? $r['status'] : 'offline';
    q('UPDATE assets SET gps_status=?, gps_battery=?, gps_lat=?, gps_lng=?, gps_seen=NOW() WHERE kind="cart" AND gps_unit_id=?', [$st, $r['battery'] ?? null, $r['lat'] ?? null, $r['lng'] ?? null, $r['unit']]);
}
// a unit that has not reported for 10 minutes is treated as offline
q("UPDATE assets SET gps_status='offline' WHERE gps_unit_id IS NOT NULL AND (gps_seen IS NULL OR gps_seen < NOW() - INTERVAL 10 MINUTE)");
echo count($rows) . " units updated\n";
