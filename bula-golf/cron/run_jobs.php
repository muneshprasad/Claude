<?php
declare(strict_types=1);
/**
 * OPTIONAL. Overdue-cart alerts and scheduled report emails normally run by themselves while the app is in use.
 * If you want them on a guaranteed timer instead, add a cPanel cron job every 5 minutes:
 *   *\/5 * * * * /usr/local/bin/php /home/USER/path/to/cron/run_jobs.php
 * (adjust the paths; the cron folder should NOT be web-accessible).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach ([__DIR__ . '/../public/api/', __DIR__ . '/api/', __DIR__ . '/../api/'] as $d) {
    if (is_file($d . 'lib.php')) { require $d . 'lib.php'; foreach (['pdf', 'reports', 'jobs'] as $f) require $d . $f . '.php'; break; }
}
if (!function_exists('run_scheduled_jobs')) { fwrite(STDERR, "Cannot find api/lib.php - edit the path at the top of run_jobs.php\n"); exit(1); }
run_scheduled_jobs();
echo "jobs checked\n";
