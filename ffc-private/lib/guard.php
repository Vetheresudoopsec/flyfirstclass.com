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
//
// AI assistants (an AI agent filling in the booking block or contact form for a traveller) are let through on purpose, so their quote
// requests arrive through the forms with a reference ID instead of as loose emails. "Well behaved" means: a real browser running the page
// on this site (not a script calling the API directly, not another website), the hidden field left empty, and within the hourly caps below.
// An assistant that signs its visits with Web Bot Auth (ChatGPT agent does) and whose signature checks out is marked "verified".
// Quote emails sent by an assistant say so in the subject ("[AI assistant]") and in a "Sent by" line.
//   AI_AGENTS=off           treat AI assistants like any other bot again (blocked and shown the email address)
//   AGENT_HOURLY=40         quote requests (and, separately, messages) an hour from AI assistants across the whole site
//   AGENT_IP_HOURLY=10      the same, per visitor address, for assistants that are not verified
//   AGENT_DIRECTORIES=https://chatgpt.com   operators whose Web Bot Auth signatures are checked (comma-separated). Their public keys are read
//                           from <operator>/.well-known/http-message-signatures-directory and kept for an hour.
if (!defined('FFC_PRIVATE')) { exit; }

const GUARD_MSG = "We couldn't confirm that this request came from a person, so it wasn't processed. Please email quote@flyfirstclass.com or call 212-221-1860 (US local) and we'll help right away.";

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
function guard_make($lv, $agent = 0, $op = '') {      // $agent: 0 a person, 1 an AI assistant, 2 a verified AI assistant ($op = its operator)
    $p = b64u(json_encode(['e' => time() + guard_ttl(), 'l' => (int)$lv, 'u' => guard_ua_tag(), 'n' => bin2hex(random_bytes(4))] + ($agent ? ['a' => (int)$agent, 'o' => (string)$op] : [])));
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
    return ['st' => 'ok', 'lv' => (int)($j['l'] ?? 0), 'a' => (int)($j['a'] ?? 0), 'o' => (string)($j['o'] ?? '')];
}
// The pass of the current request, as checked by guard_gate(): ['a' => 0|1|2, 'o' => operator]
function guard_who() { return $GLOBALS['GUARD_PASS'] ?? ['a' => 0, 'o' => '']; }
function guard_agent_text() {        // for the quote email, or '' for a person
    $w = guard_who();
    if (!$w['a']) return '';
    return $w['a'] === 2 ? 'An AI assistant acting for the traveller (verified: ' . $w['o'] . ')' : 'An AI assistant acting for the traveller (not verified)';
}
// Hourly caps for AI assistants ($kind: 'new' quote requests, 'send' messages). Called by the endpoints that reach the team inbox.
function guard_agent_cap($kind) {
    $w = guard_who();
    if (!$w['a']) return;
    $all = max(1, (int)env('AGENT_HOURLY', 40)); $per = max(1, (int)env('AGENT_IP_HOURLY', 10));
    $ok = limit_hit('agents:' . $kind, $all, 3600000) && ($w['a'] === 2 || limit_hit(client_ip() . ':agent-' . $kind, $per, 3600000));
    if (!$ok) { logm('[guard] AI assistant over the hourly cap (' . $kind . ')'); json_out(429, ['error' => 'Too many requests from AI assistants right now. Please try again in an hour, or email quote@flyfirstclass.com.']); }
}

// ---------- Web Bot Auth: AI assistants that sign their requests (draft-ietf-webbotauth, RFC 9421 HTTP Message Signatures) ----------
function wba_hdr($name) { $v = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null; return $v === null ? null : trim(preg_replace('/\s*\r?\n\s*/', ' ', (string)$v)); }
// Splits a structured-field list or dictionary at its top-level commas (not inside quotes or brackets).
function wba_split($s) {
    $out = []; $cur = ''; $q = false; $d = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($q) { $cur .= $c; if ($c === '\\' && $i + 1 < $n) { $cur .= $s[++$i]; } elseif ($c === '"') $q = false; continue; }
        if ($c === '"') $q = true; elseif ($c === '(') $d++; elseif ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}
function wba_dict($s) {      // label => raw value
    $o = [];
    foreach (wba_split((string)$s) as $m) { $p = strpos($m, '='); if ($p !== false) $o[trim(substr($m, 0, $p))] = trim(substr($m, $p + 1)); }
    return $o;
}
function wba_params($s) {    // ;a=1;b="x" > ['a' => '1', 'b' => 'x']
    $o = [];
    if (preg_match_all('/;\s*([a-z0-9_.*-]+)(?:=("(?:[^"\\\\]|\\\\.)*"|[^;]*))?/i', $s, $mm, PREG_SET_ORDER)) foreach ($mm as $m) { $v = $m[2] ?? '1'; $o[strtolower($m[1])] = (strlen($v) > 1 && $v[0] === '"') ? stripcslashes(substr($v, 1, -1)) : trim($v); }
    return $o;
}
function wba_b64u($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function wba_origins() {     // operator origin => its host
    $o = [];
    foreach (explode(',', env('AGENT_DIRECTORIES', 'https://chatgpt.com')) as $u) { $u = rtrim(trim($u), '/'); $h = strtolower((string)parse_url($u, PHP_URL_HOST)); if ($h !== '' && preg_match('~^https?://~i', $u)) $o[strtolower($u)] = $h; }
    return $o;
}
function wba_keys($origin) { // the operator's Ed25519 public keys (raw bytes), from its key directory, cached for an hour (10 minutes after a failure)
    $C = cload('wba-keys.json', []); $now = time();
    if (isset($C[$origin]) && ($C[$origin]['x'] ?? 0) > $now) return array_values(array_filter(array_map('wba_b64u', $C[$origin]['k'] ?? [])));
    $keys = [];
    try {
        $r = http_one($origin . '/.well-known/http-message-signatures-directory', ['Accept: application/http-message-signatures-directory+json, application/json'], 8);
        $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
        foreach ((array)($j['keys'] ?? []) as $k) if (($k['kty'] ?? '') === 'OKP' && ($k['crv'] ?? '') === 'Ed25519' && is_string($k['x'] ?? null)) $keys[] = $k['x'];
    } catch (Throwable $e) { /* treated as no keys */ }
    if (!$keys) logm('[guard] could not read the AI assistant key directory of ' . $origin);
    cupdate('wba-keys.json', [], function (&$m) use ($origin, $keys, $now) { $m[$origin] = ['k' => $keys, 'x' => $now + ($keys ? 3600 : 600)]; });
    return array_values(array_filter(array_map('wba_b64u', $keys)));
}
function wba_component($id, $agentHdr) {   // the value of one covered component, or null when it cannot be produced
    if (!preg_match('/^"([^"]+)"(.*)$/', $id, $m)) return null;
    $name = strtolower($m[1]); $prm = wba_params($m[2]);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)wba_hdr('X-Forwarded-Proto')) === 'https';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? '')); $host = preg_replace($https ? '/:443$/' : '/:80$/', '', $host);
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/'); $path = (string)parse_url($uri, PHP_URL_PATH); $qs = parse_url($uri, PHP_URL_QUERY);
    switch ($name) {
        case '@authority': return $host;
        case '@method': return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        case '@scheme': return $https ? 'https' : 'http';
        case '@path': return $path === '' ? '/' : $path;
        case '@query': return '?' . (string)$qs;
        case '@target-uri': return ($https ? 'https' : 'http') . '://' . $host . $uri;
    }
    if ($name[0] === '@') return null;
    $v = $name === 'signature-agent' ? $agentHdr : wba_hdr($name);
    if ($v === null) return null;
    if (isset($prm['key'])) { $d = wba_dict($v); return $d[$prm['key']] ?? null; }      // one member of a dictionary header
    return $v;
}
// Checks the request's Web Bot Auth signature. Returns the verified operator's host (for example "chatgpt.com"), or ''.
function wba_verify() {
    if (!function_exists('sodium_crypto_sign_verify_detached')) return '';
    $in = wba_hdr('Signature-Input'); $sg = wba_hdr('Signature'); $ag = wba_hdr('Signature-Agent');
    if (!$in || !$sg || !$ag) return '';
    $allowed = wba_origins(); $sigs = wba_dict($sg); $agents = wba_dict($ag);
    foreach (wba_dict($in) as $label => $raw) {
        if (!preg_match('/^\((.*?)\)(.*)$/s', $raw, $m)) continue;
        $p = wba_params($m[2]);
        if (($p['tag'] ?? '') !== 'web-bot-auth' || (isset($p['alg']) && strtolower($p['alg']) !== 'ed25519')) continue;
        $now = time(); $cr = (int)($p['created'] ?? 0); $ex = isset($p['expires']) ? (int)$p['expires'] : $cr + 300;
        if ($cr > $now + 60 || $ex < $now || $ex - $cr > 86400) continue;
        // which operator: Signature-Agent is either one quoted URL or a dictionary keyed by signature label
        $au = isset($agents[$label]) ? $agents[$label] : $ag;
        $au = strtolower(rtrim(trim($au, " \t\""), '/'));
        if (!isset($allowed[$au]) || !isset($sigs[$label]) || !preg_match('/^:([A-Za-z0-9+\/=]+):$/', $sigs[$label], $sm)) continue;
        $sig = base64_decode($sm[1], true);
        if ($sig === false || strlen($sig) !== 64) continue;
        $lines = []; $okc = true;
        preg_match_all('/"[^"]*"(?:;[^\s"=;]+(?:="[^"]*"|=[^\s";]+)?)*/', $m[1], $items);
        foreach ($items[0] as $id) { $v = wba_component($id, $ag); if ($v === null) { $okc = false; break; } $lines[] = $id . ': ' . $v; }
        if (!$okc || !$items[0]) continue;
        $lines[] = '"@signature-params": ' . $raw;
        $base = implode("\n", $lines);
        foreach (wba_keys($au) as $pk) { if (strlen($pk) === 32 && @sodium_crypto_sign_verify_detached($sig, $base, $pk)) return $allowed[$au]; }
        logm('[guard] AI assistant signature from ' . $allowed[$au] . ' did not verify');
    }
    return '';
}

function guard_hosts() {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? '')); $h = preg_replace('/:\d+$/', '', $h);
    $a = array_filter(array_map('trim', explode(',', strtolower(env('SITE_HOSTS')))));
    if ($h !== '') $a[] = $h;
    return $a;
}
function agents_on() { return strtolower(trim(env('AI_AGENTS', 'on'))) !== 'off'; }
// A browser driven by automation software (how most AI assistants browse). Not proof of anything bad on its own.
function guard_auto_ua() { return (bool)preg_match('~HeadlessChrome|PhantomJS|selenium|puppeteer|playwright~i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')); }
// Things no normal browser does. Returns the reason, or '' when nothing is wrong. $agent: an automated browser is acceptable here.
function guard_hard($agent = false) {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen(trim($ua)) < 10) return 'no browser identity';
    if (!$agent && guard_auto_ua()) return 'automation client';
    if (preg_match('~python|aiohttp|httpx|libwww|go-http|java/|okhttp|scrapy|axios|node-fetch|undici|postman|insomnia|curl/|wget|httpclient|crawler|spider|slurp|googlebot|bingbot|duckduckbot|baiduspider|yandex|ahrefs|semrush|mj12bot|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|facebookexternalhit|twitterbot|applebot~i', $ua)) return 'automation client';
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
    $s = is_array($b['s'] ?? null) ? $b['s'] : [];
    $h = qn($s['h'] ?? 0, 0, 20); $ev = qn($s['ev'] ?? 0, 0, 1000);
    if (agents_on()) {
        // An AI assistant: verified by its signature, or a browser driven by automation that is running this site's own page.
        $op = wba_verify(); $auto = guard_auto_ua() || ($s['wd'] ?? false) === true || $h >= 2;
        if ($op !== '' || $auto) {
            $why = guard_hard(true);
            if ($why !== '') guard_deny($why);
            if ($op === '' && strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) !== 'same-origin') guard_deny('automated, not running the page');
            logm('[guard] AI assistant let in' . ($op !== '' ? ' (verified: ' . $op . ')' : ' (not verified)'));
            return ['ok' => true, 'g' => guard_make(1, $op !== '' ? 2 : 1, $op), 'lv' => 1, 'ttl' => guard_ttl()];
        }
    }
    $why = guard_hard();
    if ($why !== '') guard_deny($why);
    if (($s['wd'] ?? false) === true) guard_deny('webdriver');
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
    if ($c['a'] && !agents_on()) guard_deny('AI assistants are turned off');
    $why = guard_hard($c['a'] > 0);
    if ($why !== '') guard_deny($why);
    $GLOBALS['GUARD_PASS'] = ['a' => $c['a'], 'o' => $c['o']];
}
// Quote and booking forms: the hidden field must be empty and the form must not be filled in faster than a person can.
function guard_form($b) {
    if (!guard_on()) return;
    if (is_string($b['hp'] ?? null) && trim($b['hp']) !== '') guard_deny('hidden field filled');
    if (!guard_who()['a'] && isset($b['ft']) && is_numeric($b['ft']) && (float)$b['ft'] < 1000) guard_deny('form sent too fast');      // AI assistants fill forms faster than people
}
