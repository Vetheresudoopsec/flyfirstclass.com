<?php
// quotes.php: quote requests with reference IDs (kept 6 months), input cleaning, and rate limits.
if (!defined('FFC_PRIVATE')) { exit; }

const QTTL = 183 * 86400000;                          // about 6 months, counted from the last change
const QALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';     // no I, O, 0 or 1, so IDs are easy to read out
const QREF = '/^FFC-[A-HJ-NP-Z2-9]{8}$/';

// ---------- Input cleaning (same rules as the Node server) ----------
function qs($v, $n = 200) { return is_string($v) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $v)), 0, $n) : ''; }
function qm2($v, $n = 3000) {          // keeps line breaks
    if (!is_string($v)) return '';
    $v = preg_replace('/\r\n?/', "\n", $v);
    return mb_substr(trim(preg_replace('/[\x00-\x09\x0b-\x1f\x7f]/', ' ', $v)), 0, $n);
}
function qd($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; }
function qn($v, $lo, $hi) { return min($hi, max($lo, (int)$v)); }
function qStops($s) {                  // stops of the route the visitor chose: n (0 = direct) and up to 3 stop lists such as "Doha (DOH)"
    if (!is_array($s) || !isset($s['n']) || !(is_int($s['n']) || is_float($s['n']) || (is_string($s['n']) && is_numeric($s['n'])))) return null;
    $n = qn($s['n'], 0, 9); $via = [];
    if ($n) foreach (array_slice(is_array($s['via'] ?? null) ? $s['via'] : [], 0, 3) as $x) { $x = qs($x, 120); if ($x !== '') $via[] = $x; }
    return ['n' => $n, 'via' => $via];
}
function qStopsText($s) { return $s ? ($s['n'] ? ($s['n'] === 1 ? '1 stop' : $s['n'] . ' stops') : 'Direct') . ($s['n'] && $s['via'] ? ' via ' . implode(' or ', $s['via']) : '') : ''; }
// Cabin and trip type are choices from a list, never typed. A visitor whose browser translated the page used to send the translated words
// ("Primeira classe"). The page now sends fixed English values; this turns the common translated forms back into them so an old copy of
// the page (or any other client) cannot put another language in a quote. Anything it does not recognise is kept as sent.
function q_cabin($v) {
    $v = trim((string)$v); if ($v === '') return $v;
    $l = mb_strtolower($v);
    if ($l === 'first class' || $l === 'business class') return $l === 'first class' ? 'First class' : 'Business class';
    if (preg_match('/business|executiv|ejecutiv|affari|affaires|negoci|neg[oó]cio|gesch[aä]ft|商务|商務|ビジネス|비즈니스|бизнес/u', $l)) return 'Business class';
    if (preg_match('/first|prim(?:a|e|ei|er)|premi[eè]re|erste|一等|头等|頭等|ファースト|퍼스트|первы/u', $l)) return 'First class';
    return $v;
}
function q_ttype($v) {
    $v = trim((string)$v); if ($v === '') return $v;
    $l = mb_strtolower($v);
    foreach (['One way', 'Round-trip', 'Multi-city'] as $k) if ($l === mb_strtolower($k)) return $k;
    if (preg_match('/m[uú]lti|v[aá]rios|varios|plusieurs|mehrere|pi[uù] tratte|多程|複数|여러|несколько/u', $l)) return 'Multi-city';
    if (preg_match('/volta|vuelta|retour|ritorno|r[uü]ck|hin.?und|round|return|往返|往復|왕복|туда и обратно/u', $l)) return 'Round-trip';
    if (preg_match('/one.?way|s[oó]\s?ida|somente ida|solo ida|solo andata|aller simple|einfach|nur hin|单程|單程|片道|편도|в одну/u', $l)) return 'One way';
    return $v;
}
function qTrip($t) {
    $t = is_array($t) ? $t : [];
    $via = []; foreach (array_slice(is_array($t['via'] ?? null) ? $t['via'] : [], 0, 4) as $x) { $x = qs($x); if ($x !== '') $via[] = $x; }
    $o = ['from' => qs($t['from'] ?? null), 'to' => qs($t['to'] ?? null), 'via' => $via, 'cabin' => q_cabin(qs($t['cabin'] ?? null, 40)), 'type' => q_ttype(qs($t['type'] ?? null, 40)),
        'adults' => qn($t['adults'] ?? 0, 0, 9), 'children' => qn($t['children'] ?? 0, 0, 9), 'dep' => qd($t['dep'] ?? null), 'dep2' => qd($t['dep2'] ?? null), 'ret' => qd($t['ret'] ?? null), 'ret2' => qd($t['ret2'] ?? null), 'flex' => !empty($t['flex'])];
    $st = qStops($t['stops'] ?? null); if ($st) $o['stops'] = $st;
    return $o;
}
function qWho($c) {
    $c = is_array($c) ? $c : [];
    return ['first' => qs($c['first'] ?? null, 80), 'middle' => qs($c['middle'] ?? null, 80), 'last' => qs($c['last'] ?? null, 80), 'email' => qs($c['email'] ?? null, 254), 'phone' => qs($c['phone'] ?? null, 32)];
}
function qOut($r) { return ['ref' => $r['ref'], 'created' => $r['created'], 'updated' => $r['updated'], 'expires' => $r['exp'], 'exp' => $r['exp'], 'trip' => $r['trip'], 'contact' => $r['contact'], 'message' => $r['message']]; }
function body_json() {                 // the JSON body of a request (up to 30,000 bytes)
    $s = file_get_contents('php://input', false, null, 0, 30001);
    if ($s !== false && strlen($s) > 30000) throw fail('Request too large', 413);
    if ($s === false || trim($s) === '') return [];
    $j = json_decode($s, true);
    if (!is_array($j)) throw fail('Invalid JSON', 400);
    return $j;
}

// ---------- Rate limits (shared by all requests through limits.json) ----------
function limit_hit($key, $max, $win) {         // counts one use; false once more than $max in $win ms
    return cupdate('limits.json', [], function (&$L) use ($key, $max, $win) {
        $n = now_ms();
        if (mt_rand(1, 20) === 1) foreach ($L as $k => $e) if ($n - $e['t'] > 86400000 * 2) unset($L[$k]);   // tidy up now and then
        if (!isset($L[$key]) || $n - $L[$key]['t'] > $win) { $L[$key] = ['n' => 1, 't' => $n]; return true; }
        $L[$key]['n']++;
        return $L[$key]['n'] <= $max;
    });
}
function limit_take($ip, $kind, $want, $max, $win) {   // takes up to $want uses; returns how many were allowed
    return cupdate('limits.json', [], function (&$L) use ($ip, $kind, $want, $max, $win) {
        $key = $ip . ':' . $kind; $n = now_ms();
        if (!isset($L[$key]) || $n - $L[$key]['t'] > $win) $L[$key] = ['n' => 0, 't' => $n];
        $ok = max(0, min($want, $max - $L[$key]['n']));
        $L[$key]['n'] += $ok;
        return $ok;
    });
}
function miss_ok($ip) { $L = cload('limits.json', []); $e = $L[$ip . ':miss'] ?? null; return !$e || now_ms() - $e['t'] > 600000 || $e['n'] < 20; }   // 20 failed lookups per 10 minutes
function miss_add($ip) { limit_hit($ip . ':miss', 1000000000, 600000); }

// ---------- Requests ----------
function qpurge() {
    return cupdate('requests.json', [], function (&$QR) { $n = now_ms(); $c = 0; foreach ($QR as $k => $r) if (!$r || ($r['exp'] ?? 0) <= $n) { unset($QR[$k]); $c++; } return $c; });
}
function qnew($QR) { for (;;) { $r = 'FFC-'; for ($i = 0; $i < 8; $i++) $r .= QALPHA[random_int(0, 31)]; if (!isset($QR[$r])) return $r; } }
function q_find($ref) {                // the stored request, or null if unknown or expired
    if (!preg_match(QREF, $ref)) return null;
    $QR = cload('requests.json', []); $r = $QR[$ref] ?? null;
    return ($r && $r['exp'] > now_ms()) ? $r : null;
}
function q_create($b) {
    return cupdate('requests.json', [], function (&$QR) use ($b) {
        $n = now_ms(); $want = strtoupper(qs($b['ref'] ?? null, 20));
        $r = ($want !== '' && preg_match(QREF, $want) && !isset($QR[$want])) ? $want : qnew($QR);
        $QR[$r] = ['ref' => $r, 'created' => $n, 'updated' => $n, 'exp' => $n + QTTL, 'trip' => qTrip($b['trip'] ?? null), 'contact' => qWho($b['contact'] ?? null), 'message' => qm2($b['message'] ?? null), 'newsletter' => !empty($b['newsletter'])];
        foreach ($QR as $k => $x) if (($x['exp'] ?? 0) <= $n) unset($QR[$k]);        // expired ones are dropped as new ones arrive
        return qOut($QR[$r]);
    });
}
function q_update($ref, $b) {
    return cupdate('requests.json', [], function (&$QR) use ($ref, $b) {
        if (!isset($QR[$ref])) return null;
        $r = $QR[$ref];
        if (!empty($b['trip'])) $r['trip'] = qTrip($b['trip']);
        if (!empty($b['contact'])) $r['contact'] = qWho($b['contact']);
        if (isset($b['message']) && is_string($b['message'])) $r['message'] = qm2($b['message']);
        $r['updated'] = now_ms(); $r['exp'] = $r['updated'] + QTTL;
        $QR[$ref] = $r;
        return qOut($r);
    });
}
