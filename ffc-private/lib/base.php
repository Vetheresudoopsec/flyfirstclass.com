<?php
// Fly First Class International: PHP backend for cPanel hosting (HostGator). Same API as server.js.
// base.php: settings, small file store with locks, parallel HTTP, logging, JSON replies.
if (!defined('FFC_PRIVATE')) { exit; }

const BUILD = '2026-10-27e-php';
const SCHEMA = 2;                    // bump when the stored data format changes: older cache entries are ignored
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
@ini_set('display_errors', '0');
@ini_set('memory_limit', '256M');

class ApiError extends Exception {
    public $status;
    public function __construct($m, $s = 502) { parent::__construct($m); $this->status = $s; }
}
function fail($m, $s = 502) { return new ApiError($m, $s); }

// ---------- Settings: .env next to this folder, real environment variables win ----------
function load_env() {
    static $done = false;
    if ($done) return;
    $done = true;
    $GLOBALS['ENV'] = [];
    $s = @file_get_contents(FFC_PRIVATE . '/.env');
    if ($s === false) return;
    foreach (preg_split('/\r?\n/', $s) as $l) {
        if (!preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $l, $m)) continue;
        $v = $m[2];
        if (preg_match('/^["\'\x{201c}\x{2018}](.*?)["\'\x{201d}\x{2019}]/u', $v, $q)) $v = $q[1];   // quoted: keep what is inside
        else $v = preg_replace('/\s+#.*$/', '', $v);                                                 // unquoted: drop a trailing # comment
        $GLOBALS['ENV'][$m[1]] = trim($v);
    }
}
function env($k, $d = '') {
    load_env();
    $r = getenv($k);
    if ($r !== false && $r !== '') return $r;                // a non-empty real environment variable wins over .env
    if (isset($GLOBALS['ENV'][$k]) && $GLOBALS['ENV'][$k] !== '') return $GLOBALS['ENV'][$k];
    return $d;
}
function C($k) {
    static $c = null;
    if ($c === null) {
        $tok = trim(preg_replace('/^\s*bearer\s+/i', '', env('DUFFEL_TOKEN')));   // tolerate a pasted "Bearer " prefix
        $c = [
            'token' => $tok, 'test' => (bool)preg_match('/^duffel_test_/', $tok), 'mode' => preg_match('/^duffel_test_/', $tok) ? 'test' : 'live',
            'duffel_base' => rtrim(env('DUFFEL_BASE', 'https://api.duffel.com'), '/'),
            'airlabs_key' => env('AIRLABS_KEY'), 'airlabs_base' => rtrim(env('AIRLABS_BASE', 'https://airlabs.co/api/v9'), '/'),
            'ukey' => trim(env('UNSPLASH_KEY') ?: env('UNSPLASH_ACCESS_KEY')), 'unsplash_base' => rtrim(env('UNSPLASH_BASE', 'https://api.unsplash.com'), '/'),
            'maxd' => (int)env('MAX_ROUTES', 20) ?: 20, 'ubudget' => (int)env('PHOTO_LOOKUPS', 6) ?: 6,
            'drpm' => max(5, (int)env('DUFFEL_RPM', 40) ?: 40), 'dcap' => (int)env('DUFFEL_DAILY_CAP', 1000),
            'geoip' => env('GEOIP_BASE', 'https://ipwho.is/'),
            'credits' => env('PHOTO_CREDITS') !== 'off',
            'launch' => (float)env('LAUNCH_BUDGET', 9),          // seconds a web request may spend starting new Duffel searches
            'ip_routes' => (int)env('NEW_ROUTES_PER_IP_HOUR', 80),
            'admin' => env('ADMIN_KEY'),
        ];
    }
    return $c[$k];
}

// ---------- Cache folder: small JSON files, written atomically, updated under a lock ----------
function cdir() {
    static $d = null;
    if ($d === null) {
        $d = rtrim(env('CACHE_DIR', FFC_PRIVATE . '/cache'), '/');
        if (!is_dir($d)) @mkdir($d, 0755, true);
        if (!is_dir($d)) throw fail('The cache folder could not be created: ' . $d . ' (check the folder permissions)', 500);
        if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $d;
}
function cload($f, $def = []) {
    $s = @file_get_contents(cdir() . '/' . $f);
    if ($s === false || $s === '') return $def;
    $j = json_decode($s, true);
    return $j === null ? $def : $j;
}
function csave($f, $o) {
    $p = cdir() . '/' . $f;
    $t = $p . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    $j = json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (@file_put_contents($t, $j) === false) throw fail('Could not write ' . $f . ' in the cache folder (check the folder permissions)', 500);
    if (!@rename($t, $p)) { @unlink($t); throw fail('Could not save ' . $f, 500); }
}
// Reads the latest copy, lets $fn change it (by reference), writes it back. Other requests wait their turn.
function cupdate($f, $def, $fn) {
    $h = @fopen(cdir() . '/' . $f . '.lock', 'c');
    $got = false;
    if ($h) { for ($i = 0; $i < 100; $i++) { if (flock($h, LOCK_EX | LOCK_NB)) { $got = true; break; } usleep(100000); } }
    try {
        $v = cload($f, $def);
        $ret = $fn($v);
        csave($f, $v);
        return $ret;
    } finally {
        if ($h) { if ($got) flock($h, LOCK_UN); fclose($h); }
    }
}
// Short-lived claims ("someone is already fetching this"), as files. A claim older than $stale seconds is ignored.
function claim($name, $kind = 'visitor', $stale = 150) {
    $d = cdir() . '/locks';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    $p = $d . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '.lock';
    for ($i = 0; $i < 2; $i++) {
        $h = @fopen($p, 'x');
        if ($h) { fwrite($h, json_encode(['k' => $kind, 't' => time()])); fclose($h); $GLOBALS['CLAIMED'][$p] = 1; return true; }
        clearstatcache(true, $p);
        $m = @filemtime($p);
        if ($m !== false && time() - $m > $stale) { @unlink($p); continue; }
        return false;
    }
    return false;
}
function claim_path($name) { return cdir() . '/locks/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '.lock'; }
function unclaim($name) { $p = claim_path($name); @unlink($p); unset($GLOBALS['CLAIMED'][$p]); }
function claimed($name, $stale = 150) {      // the kind of claim in force ("visitor" or "refresh"), or false
    $p = claim_path($name);
    clearstatcache(true, $p);
    $m = @filemtime($p);
    if ($m === false) return false;
    if (time() - $m > $stale) return false;
    $j = json_decode((string)@file_get_contents($p), true);
    return is_array($j) && isset($j['k']) ? $j['k'] : 'visitor';
}
function claims_count() {
    $n = 0;
    foreach ((array)@glob(cdir() . '/locks/*.lock') as $p) { clearstatcache(true, $p); if (time() - (int)@filemtime($p) <= 150) $n++; }
    return $n;
}
register_shutdown_function(function () { foreach (array_keys($GLOBALS['CLAIMED'] ?? []) as $p) @unlink($p); });

// ---------- Logging (cache/log.txt, kept small) ----------
function logm($msg) {
    try {
        $p = cdir() . '/log.txt';
        clearstatcache(true, $p);
        if (is_file($p) && filesize($p) > 200000) @rename($p, $p . '.old');
        @file_put_contents($p, gmdate('Y-m-d H:i:s') . ' ' . str_replace(["\r", "\n"], ' ', $msg) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) { /* logging is never critical */ }
}
function log_tail($n = 25) {
    $s = @file_get_contents(cdir() . '/log.txt');
    return $s === false ? [] : array_slice(array_values(array_filter(explode("\n", $s))), -$n);
}

// ---------- HTTP: many requests at once (curl_multi), each with an optional earliest start time ----------
// job: url, method (GET, POST, PUT or PATCH), body, headers [], timeout (25), at (earliest start, microtime), id (optional). Returns id => result.
// result: status (0 = no reply), headers (lower-case names), body, error (string or null)
function http_multi(array $jobs) {
    $mh = curl_multi_init();
    $out = []; $queue = []; $active = []; $hdrs = [];
    foreach ($jobs as $k => $j) $queue[$k] = $j;
    while ($queue || $active) {
        $now = microtime(true);
        foreach ($queue as $k => $j) {
            if (($j['at'] ?? 0) > $now) continue;
            $ch = curl_init($j['url']);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $j['timeout'] ?? 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => $j['headers'] ?? [], CURLOPT_USERAGENT => 'FlyFirstClass/' . BUILD,
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdrs, $k) { $p = strpos($line, ':'); if ($p > 0) $hdrs[$k][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); return strlen($line); }];
            if (($j['method'] ?? 'GET') === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $j['body'] ?? ''; }
            elseif (in_array($j['method'] ?? 'GET', ['PUT', 'PATCH'], true)) { $o[CURLOPT_CUSTOMREQUEST] = $j['method']; $o[CURLOPT_POSTFIELDS] = $j['body'] ?? ''; }   // Mailchimp member updates
            curl_setopt_array($ch, $o);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [$k];
            unset($queue[$k]);
        }
        do { $st = curl_multi_exec($mh, $running); } while ($st === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle']; $id = spl_object_id($ch);
            if (!isset($active[$id])) continue;
            [$k] = $active[$id];
            $errno = curl_errno($ch);
            $out[$k] = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $hdrs[$k] ?? [], 'body' => (string)curl_multi_getcontent($ch),
                'error' => $errno ? ($errno === 28 ? 'timed out' : 'network error: ' . curl_error($ch)) : null, 'timeout' => $errno === 28];
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            unset($active[$id]);
        }
        if (!$queue && !$active) break;
        $wait = 0.05;
        if (!$active && $queue) { $next = min(array_map(function ($j) { return $j['at'] ?? 0; }, $queue)); $wait = max(0.005, min(0.5, $next - microtime(true))); }
        if ($active) { if (curl_multi_select($mh, $wait) === -1) usleep(10000); } else usleep((int)($wait * 1e6));
    }
    curl_multi_close($mh);
    return $out;
}
function http_one($url, $headers = [], $timeout = 25, $method = 'GET', $body = null) {
    $r = http_multi([0 => ['url' => $url, 'headers' => $headers, 'timeout' => $timeout, 'method' => $method, 'body' => $body]]);
    return $r[0];
}

// ---------- Small helpers ----------
function now_ms() { return (int)round(microtime(true) * 1000); }
function lc($s) { return mb_strtolower((string)$s); }
function shuffle_a($a) { $a = array_values($a); for ($i = count($a) - 1; $i > 0; $i--) { $j = random_int(0, $i); $t = $a[$i]; $a[$i] = $a[$j]; $a[$j] = $t; } return $a; }
function starts($s, $p) { return strncmp($s, $p, strlen($p)) === 0; }
function json_out($code, $b) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
// Sends the reply first and only then runs $after (the slower Mailchimp call), so the visitor never waits for it.
function json_out_then($code, $b, $after) {
    ignore_user_abort(true);
    $s = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    http_response_code($code);
    header('Content-Type: application/json'); header('Cache-Control: no-store'); header('Content-Length: ' . strlen($s)); header('Connection: close');
    echo $s;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();        // LiteSpeed (used by many HostGator plans)
    else { while (ob_get_level() > 0) @ob_end_flush(); @flush(); }
    try { $after(); } catch (Throwable $e) { logm('after-reply task: ' . $e->getMessage()); }
    exit;
}
function client_ip() {      // the connecting address; proxy headers are believed only when TRUST_PROXY=on (for example behind Cloudflare)
    if (env('TRUST_PROXY') === 'on') {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '?';
}
function geo_ip() {         // for the nearest-airport suggestion only (a spoofed header just changes the visitor's own suggestion)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
function is_private_ip($ip) { return (bool)preg_match('/^(::1|127\.|::ffff:127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|fe80|fc|fd)/i', $ip); }
function admin_ok() {       // diagnostics: allowed with ?key=ADMIN_KEY, or from the server itself when no key is set
    $k = (string)C('admin');
    if ($k !== '') return isset($_GET['key']) && hash_equals($k, (string)$_GET['key']);
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && empty($_SERVER['HTTP_X_FORWARDED_FOR']);
}

// Eastern time helpers for the daily 12:00 PM Eastern refresh (EST in winter, EDT in summer)
function et_tz() { static $z = null; if (!$z) $z = new DateTimeZone('America/New_York'); return $z; }
function day_et() { return (new DateTime('now', et_tz()))->format('Y-n-j'); }
function noon_et($y, $m, $d) { return (new DateTime(sprintf('%04d-%02d-%02d 12:00:00', $y, $m, $d), et_tz()))->getTimestamp() * 1000; }
function last_noon($now = null) {
    $now = $now ?: now_ms();
    $p = (new DateTime('@' . intdiv($now, 1000)))->setTimezone(et_tz());
    $t = noon_et((int)$p->format('Y'), (int)$p->format('n'), (int)$p->format('j'));
    if ($t > $now) { $q = (clone $p)->modify('-1 day'); $t = noon_et((int)$q->format('Y'), (int)$q->format('n'), (int)$q->format('j')); }
    return $t;
}
function next_noon($now = null) {
    $now = $now ?: now_ms();
    $p = (new DateTime('@' . intdiv($now, 1000)))->setTimezone(et_tz());
    $t = noon_et((int)$p->format('Y'), (int)$p->format('n'), (int)$p->format('j'));
    if ($t <= $now) { $q = (clone $p)->modify('+1 day'); $t = noon_et((int)$q->format('Y'), (int)$q->format('n'), (int)$q->format('j')); }
    return $t;
}
function et_text($ms) { return (new DateTime('@' . intdiv($ms, 1000)))->setTimezone(et_tz())->format('M j, Y, g:i A') . ' ET'; }
