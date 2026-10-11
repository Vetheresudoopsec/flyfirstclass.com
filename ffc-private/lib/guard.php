<?php
// guard.php: quiet bot checks. Nobody is ever asked to pick pictures or type distorted text.
//
// The page asks for a short-lived signed pass (POST /api/guard) only when something is about to call the server: a route
// lookup, finding or changing a request, or sending a quote. The home page, the carousel and the photos never start a check.
// The server weighs a few signals (is it a normal browser, was the page used by a person, is the hidden field empty and,
// if you set it up, an invisible Cloudflare Turnstile check) and hands out the pass or says no. Someone who looks automated
// sees a plain message with an email address and phone number instead.
//
// .env settings (all optional):
//   BOT_GUARD=off           turns every check off (for testing)
//   TURNSTILE_SITE_KEY / TURNSTILE_SECRET   invisible Cloudflare Turnstile (create the widget as "Invisible")
//   GUARD_TTL=1800          seconds a pass stays valid
//   SITE_HOSTS=a.com,b.com  extra host names allowed to call the API (the site's own host name is always allowed)
//   GUARD_SECRET=...        signing key (made automatically and kept in the cache folder when not set)
if (!defined('FFC_PRIVATE')) { exit; }

const GUARD_MSG = "We couldn't confirm that this request came from a person, so it wasn't processed. Please email hello@flyfirstclass.com or call +1 (555) 010-0199 and we'll help right away.";

function guard_on() { return strtolower(trim(env('BOT_GUARD', 'on'))) !== 'off'; }
function guard_ttl() { $t = (int)env('GUARD_TTL', 1800); return $t >= 5 && $t <= 86400 ? $t : 1800; }
function guard_site() { return (env('TURNSTILE_SITE_KEY') !== '' && env('TURNSTILE_SECRET') !== '') ? env('TURNSTILE_SITE_KEY') : ''; }
function guard_secret() {
    $e = env('GUARD_SECRET');
    if ($e !== '') return $e;
    static $s = null;
    if ($s !== null) return $s;
    $g = cload('guard.json', []);
    if (!empty($g['k'])) return $s = $g['k'];
    return $s = cupdate('guard.json', [], function (&$g) { if (empty($g['k'])) $g['k'] = bin2hex(random_bytes(32)); return $g['k']; });
}
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_d($s) { return base64_decode(strtr($s, '-_', '+/'), true); }
function guard_ua_tag() { return substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12); }

// The pass: base64url(json) . "." . base64url(HMAC-SHA256). It names its expiry, its level and the browser it was given to.
function guard_make($lv) {
    $p = b64u(json_encode(['e' => time() + guard_ttl(), 'l' => (int)$lv, 'u' => guard_ua_tag(), 'n' => bin2hex(random_bytes(4))]));
    return $p . '.' . b64u(hash_hmac('sha256', $p, guard_secret(), true));
}
function guard_check() {      // ['st' => 'ok', 'lv' => n] | ['st' => 'none'] (no pass sent) | ['st' => 'stale'] (bad, expired or someone else's)
    $t = (string)($_SERVER['HTTP_X_FFC_GUARD'] ?? '');
    if ($t === '') return ['st' => 'none'];
    $x = explode('.', $t);
    if (count($x) !== 2) return ['st' => 'stale'];
    if (!hash_equals(b64u(hash_hmac('sha256', $x[0], guard_secret(), true)), $x[1])) return ['st' => 'stale'];
    $j = json_decode((string)b64u_d($x[0]), true);
    if (!is_array($j) || ($j['e'] ?? 0) < time() || !hash_equals((string)($j['u'] ?? ''), guard_ua_tag())) return ['st' => 'stale'];
    return ['st' => 'ok', 'lv' => (int)($j['l'] ?? 0)];
}

function guard_hosts() {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? '')); $h = preg_replace('/:\d+$/', '', $h);
    $a = array_filter(array_map('trim', explode(',', strtolower(env('SITE_HOSTS')))));
    if ($h !== '') $a[] = $h;
    return $a;
}
// Things no normal browser does. Returns the reason, or '' when nothing is wrong.
function guard_hard() {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen(trim($ua)) < 10) return 'no browser identity';
    if (preg_match('~HeadlessChrome|PhantomJS|selenium|puppeteer|playwright|python|aiohttp|httpx|libwww|go-http|java/|okhttp|scrapy|axios|node-fetch|undici|postman|insomnia|curl/|wget|httpclient|crawler|spider|slurp|googlebot|bingbot|duckduckbot|baiduspider|yandex|ahrefs|semrush|mj12bot|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|facebookexternalhit|twitterbot|applebot~i', $ua)) return 'automation client';
    if (strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') return 'other website';
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o !== '') {
        $oh = strtolower((string)parse_url($o, PHP_URL_HOST));
        if ($oh === '' || !in_array($oh, guard_hosts(), true)) return 'other website';
    }
    return '';
}
// Smaller hints that only count together.
function guard_soft() {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); $s = 0;
    $modern = (bool)preg_match('~(Chrome|Chromium|CriOS|Edg|OPR)/(\d+)~', $ua, $m) && (int)$m[2] >= 80 || (bool)preg_match('~Firefox/(\d+)~', $ua, $m2) && (int)$m2[1] >= 90;
    if ($modern && empty($_SERVER['HTTP_SEC_FETCH_SITE'])) $s += 2;      // current Chrome, Edge and Firefox always send the Sec-Fetch headers
    if (trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) === '') $s += 1;
    return $s;
}
function ts_verify($cf) {     // true = passed, false = failed, null = Cloudflare could not be reached (do not punish the visitor for that)
    $u = env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    try {
        $r = http_one($u, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], 8, 'POST', http_build_query(['secret' => env('TURNSTILE_SECRET'), 'response' => $cf, 'remoteip' => client_ip()]));
    } catch (Throwable $e) { return null; }
    if (($r['status'] ?? 0) !== 200) return null;
    $j = json_decode((string)$r['body'], true);
    return is_array($j) ? !empty($j['success']) : null;
}
function guard_deny($why) {
    logm('[guard] blocked ' . client_ip() . ' (' . $why . ')');
    json_out(403, ['bot' => true, 'error' => GUARD_MSG]);
}

// POST /api/guard: decides whether this visitor gets a pass.
function guard_mint($b) {
    $why = guard_hard();
    if ($why !== '') guard_deny($why);
    $s = is_array($b['s'] ?? null) ? $b['s'] : [];
    if (($s['wd'] ?? false) === true) guard_deny('webdriver');
    $h = qn($s['h'] ?? 0, 0, 20); $ev = qn($s['ev'] ?? 0, 0, 1000);
    if ($h >= 2) guard_deny('headless browser');
    $score = guard_soft() + ($h === 1 ? 2 : 0);
    if (guard_site() !== '') {
        $cf = is_string($b['cf'] ?? null) ? $b['cf'] : '';
        if ($cf === '!') guard_deny('Turnstile wanted a puzzle');
        elseif ($cf === '') $score += 2;
        else { $v = ts_verify($cf); if ($v === false) guard_deny('Turnstile failed'); if ($v === null) $score += 1; }
    }
    if ($score >= 3) guard_deny('score ' . $score);
    $lv = $ev >= 1 ? 1 : 0;       // level 1: a person has moved, typed or tapped on the page
    return ['ok' => true, 'g' => guard_make($lv), 'lv' => $lv, 'ttl' => guard_ttl()];
}

// In front of the endpoints. $need: 'soft' (no pass needed: carousel and photos), 0 (route lookups), 1 (requests and sending).
function guard_gate($need) {
    if (!guard_on()) return;
    $c = guard_check();
    if ($need === 'soft') {
        if ($c['st'] === 'ok') return;
        $why = guard_hard();
        if ($why !== '') guard_deny($why);
        if (guard_soft() >= 3) guard_deny('missing browser headers');
        return;
    }
    if ($c['st'] !== 'ok' || $c['lv'] < $need) json_out(403, ['bot' => true, 'stale' => true, 'error' => GUARD_MSG]);
    $why = guard_hard();
    if ($why !== '') guard_deny($why);
}
// Quote and booking forms: the hidden field must be empty and the form must not be filled in faster than a person can.
function guard_form($b) {
    if (!guard_on()) return;
    if (is_string($b['hp'] ?? null) && trim($b['hp']) !== '') guard_deny('hidden field filled');
    if (isset($b['ft']) && is_numeric($b['ft']) && (float)$b['ft'] < 1000) guard_deny('form sent too fast');
}
