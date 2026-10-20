<?php
// Loads the backend. FFC_PRIVATE is the folder this file is in; api.php (website) and cron.php (scheduled job) both start here.
if (!defined('FFC_PRIVATE')) { exit; }
if (PHP_VERSION_ID < 70400) { http_response_code(500); header('Content-Type: application/json'); echo '{"error":"This site needs PHP 7.4 or newer. Choose a newer PHP version in cPanel > MultiPHP Manager."}'; exit; }
foreach (['base', 'data', 'quotes', 'mail', 'confirm', 'history', 'newsletter', 'duffel', 'pricing', 'photos', 'guard', 'api'] as $f) require __DIR__ . '/lib/' . $f . '.php';
