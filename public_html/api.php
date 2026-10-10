<?php
// The website's API door. Finds the private folder (ffc-private, next to public_html) and hands over to it.
$found = null;
foreach ([__DIR__ . '/../ffc-private', __DIR__ . '/ffc-private', __DIR__ . '/../../ffc-private'] as $p) {
    if (is_file($p . '/core.php')) { $found = realpath($p); break; }
}
if (!$found) { http_response_code(500); header('Content-Type: application/json'); echo '{"error":"The ffc-private folder was not found next to public_html."}'; exit; }
define('FFC_PRIVATE', $found);
require $found . '/core.php';
dispatch();
