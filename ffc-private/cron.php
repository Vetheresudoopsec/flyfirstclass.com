<?php
// Scheduled job (cPanel > Cron Jobs, every 15 minutes):
//   */15 * * * * /usr/local/bin/php /home/YOURUSER/ffc-private/cron.php >/dev/null 2>&1
// It does the daily 12:00 PM Eastern route refresh (in steps, so a slow day just takes a few runs), fills destination photos a few at
// a time, and clears out requests older than 6 months. Safe to run as often as you like: it only does work when work is due.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from cPanel Cron Jobs.'); }
define('FFC_PRIVATE', __DIR__);
require __DIR__ . '/core.php';
set_time_limit(0);
$budget = (int)env('CRON_BUDGET', 240) ?: 240;
echo json_encode(cron_run($budget), JSON_UNESCAPED_SLASHES), "\n";
