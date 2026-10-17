<?php
// pricing.php: estimated "from" fares for the home page carousel.
//
// The business supplies reference prices (data.json > FARES: airline, cabin, from, to, USD round trip before taxes and fees). For any
// other route the fare is estimated from them:
//   1. Curve:      log-log regression over every reference price: ln(price) = a + cabin + b*ln(km/9000) + origin region (ridge on regions).
//   2. Neighbours: each reference price in the same cabin is rescaled to the new distance, price * (km_new/km_ref)^0.4, and weighted by
//                  how close its lane is: exp(-lane_gap_km / 4000). A lane and its reverse are the same market.
//   3. Blend:      (sum(w * scaled price) + 0.15 * curve) / (sum(w) + 0.15). Near a known lane the neighbours decide; far from every
//                  known lane the curve does.
//   4. Guards:     nothing outside 4,000-17,500 km (the range the reference prices cover), for unknown airports, or for first class on
//                  a business-only airline. A route that is already in the price sheet returns the sheet price.
//   5. Display:    "from" = estimate + 15%, rounded up to $100, and only when a comparable lane is close ("high" confidence).
//                  Otherwise no number is shown and the card invites a quote request.
// Same maths as the reviewed prototype (price_estimator.py); held-out reference prices were within 25% of the estimate every time.
if (!defined('FFC_PRIVATE')) { exit; }

const PR_MIN_KM = 4000, PR_MAX_KM = 17500, PR_H = 4000.0, PR_B = 0.4, PR_PRIOR = 0.15, PR_SAME = 1.5, PR_CLOSE = 0.35, PR_BUFFER = 1.15;
const PR_BUSINESS_ONLY = ['QR', 'AF'];            // partner airlines the site lists as business class only
const PR_REGIONS = ['AM', 'EU', 'ME', 'AS', 'AF'];

// Reference prices that can be placed on the map (both airports known), as [airline, cabin, from, to, usd].
function pr_refs() {
    static $R = null;
    if ($R !== null) return $R;
    $A = air(); $R = [];
    foreach ((D()['FARES'] ?? []) as $f) {
        if (is_array($f) && count($f) === 5 && isset($A[$f[2]], $A[$f[3]]) && $f[4] > 0 && ($f[1] === 'F' || $f[1] === 'J')) $R[] = $f;
    }
    return $R;
}
function pr_region($c) {
    $a = air()[$c]; $lat = $a['lat']; $lng = $a['lng'];
    if ($lng < -30) return 'AM';
    if ($lng < 40) return $lat > 30 ? 'EU' : 'AF';
    if ($lng < 65) return 'ME';
    return 'AS';
}
function pr_km($a, $b) {
    static $K = [];
    $k = $a . '>' . $b;
    if (!isset($K[$k])) { $A = air(); $K[$k] = km([$A[$a]['lat'], $A[$a]['lng']], [$A[$b]['lat'], $A[$b]['lng']]); }
    return $K[$k];
}
function pr_gap($o, $d, $o2, $d2) { return min(pr_km($o, $o2) + pr_km($d, $d2), pr_km($o, $d2) + pr_km($d, $o2)); }
function pr_row($cabin, $o, $d) {
    $r = [1.0, $cabin === 'F' ? 1.0 : 0.0, log(pr_km($o, $d) / 9000)];
    foreach (PR_REGIONS as $g) $r[] = pr_region($o) === $g ? 1.0 : 0.0;
    return $r;
}
// Least squares by Gaussian elimination, with a ridge penalty on the region terms (columns 3 and up).
function pr_solve(array $A, array $y) {
    $n = count($A[0]); $M = []; $v = [];
    for ($i = 0; $i < $n; $i++) {
        $v[$i] = 0.0;
        for ($j = 0; $j < $n; $j++) { $s = 0.0; foreach ($A as $r) $s += $r[$i] * $r[$j]; $M[$i][$j] = $s; }
        foreach ($A as $k => $r) $v[$i] += $r[$i] * $y[$k];
    }
    for ($i = 3; $i < $n; $i++) $M[$i][$i] += 1.0;
    for ($i = 0; $i < $n; $i++) {
        $p = $i;
        for ($r = $i + 1; $r < $n; $r++) if (abs($M[$r][$i]) > abs($M[$p][$i])) $p = $r;
        $t = $M[$i]; $M[$i] = $M[$p]; $M[$p] = $t; $t = $v[$i]; $v[$i] = $v[$p]; $v[$p] = $t;
        for ($r = $i + 1; $r < $n; $r++) {
            $f = $M[$r][$i] / $M[$i][$i]; $v[$r] -= $f * $v[$i];
            for ($c = $i; $c < $n; $c++) $M[$r][$c] -= $f * $M[$i][$c];
        }
    }
    $w = array_fill(0, $n, 0.0);
    for ($i = $n - 1; $i >= 0; $i--) {
        $s = $v[$i];
        for ($c = $i + 1; $c < $n; $c++) $s -= $M[$i][$c] * $w[$c];
        $w[$i] = $s / $M[$i][$i];
    }
    return $w;
}
function pr_curve($cabin, $o, $d) {
    static $W = null;
    if ($W === null) {
        $ref = pr_refs(); $A = []; $y = [];
        foreach ($ref as $f) { $A[] = pr_row($f[1], $f[2], $f[3]); $y[] = log($f[4]); }
        $W = $A ? pr_solve($A, $y) : [];
    }
    if (!$W) return null;
    $s = 0.0;
    foreach (pr_row($cabin, $o, $d) as $i => $x) $s += $x * $W[$i];
    return exp($s);
}

// Estimate for one route and cabin ("F" first, "J" business). Returns null when there is nothing sensible to say, otherwise
// ['est' => USD, 'from' => USD to display or null, 'conf' => 'sheet' | 'high' | 'low', 'km' => distance].
function fare_estimate($o, $d, $cabin, $airline = null) {
    $A = air();
    if ($o === $d || !isset($A[$o], $A[$d]) || ($cabin !== 'F' && $cabin !== 'J')) return null;
    if ($cabin === 'F' && $airline !== null && in_array($airline, PR_BUSINESS_ONLY, true)) return null;
    $ref = pr_refs(); if (!$ref) return null;
    $dist = pr_km($o, $d); $sheet = null;
    foreach ($ref as $f) {                                                   // already in the price sheet: use it as it is
        if ($f[1] === $cabin && $f[2] === $o && $f[3] === $d && ($airline === null || $f[0] === $airline)) $sheet = $sheet === null ? $f[4] : min($sheet, $f[4]);
    }
    if ($sheet !== null) return ['est' => $sheet, 'from' => $sheet, 'conf' => 'sheet', 'km' => (int)round($dist)];
    if ($dist < PR_MIN_KM || $dist > PR_MAX_KM) return null;
    $curve = pr_curve($cabin, $o, $d); if ($curve === null) return null;
    $num = 0.0; $den = 0.0;
    foreach ($ref as $f) {
        if ($f[1] !== $cabin) continue;
        $w = exp(-pr_gap($o, $d, $f[2], $f[3]) / PR_H) * ($airline !== null && $f[0] === $airline ? PR_SAME : 1.0);
        $num += $w * $f[4] * pow($dist / pr_km($f[2], $f[3]), PR_B); $den += $w;
    }
    $est = ($num + PR_PRIOR * $curve) / ($den + PR_PRIOR);
    $close = $den >= PR_CLOSE;
    return ['est' => (int)round($est), 'from' => $close ? (int)(ceil($est * PR_BUFFER / 100) * 100) : null, 'conf' => $close ? 'high' : 'low', 'km' => (int)round($dist)];
}

// ---------- Prices from Duffel ----------
// Every Duffel search keeps the cheapest partner-airline fare before taxes (USD, one way) in the route's saved record (px: overall, pxa:
// per airline). Our own price is lower than that public fare by a fairly steady factor, which the price sheet lets us measure: for each
// sheet lane that Duffel has also priced, sheet price / Duffel fare. The median is the ratio; it is only trusted when predicting each
// lane from the others stays close (median miss 15% or less, worst 30% or less). A route the estimator above cannot price is then priced as
// Duffel fare x ratio, if the two methods do not disagree wildly and the route is documented.
function to_usd($amt, $cur) {
    if ($amt === null || !is_numeric($amt)) return null;
    $cur = strtoupper((string)$cur); $fx = D()['FX'] ?? [];
    $r = $cur === 'USD' ? 1.0 : ($fx[$cur] ?? null);
    return $r === null ? null : (int)round((float)$amt * $r);
}
function pr_median(array $a) { sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }
function fare_calib($cl, array $RC) {                 // $cl: 'business' | 'first'
    $cab = $cl === 'first' ? 'F' : 'J'; $pts = [];
    foreach (pr_refs() as $f) {
        if ($f[1] !== $cab) continue;
        $e = $RC[rkey($f[2], $f[3])] ?? null;
        $p = fresh($e) ? ($e['raw']['pxa'][$cl][$f[0]] ?? null) : null;
        if ($p > 0) $pts[] = [$f[4] / $p, $f[4], $p];
    }
    $need = $cl === 'first' ? 3 : 4;
    if (count($pts) < $need) return ['ok' => false, 'lanes' => count($pts), 'why' => 'needs ' . $need . ' sheet lanes with a live Duffel fare'];
    $R = pr_median(array_column($pts, 0)); $errs = [];
    foreach ($pts as $i => $pt) {                      // predict each lane from the others
        $o = $pts; unset($o[$i]);
        $errs[] = abs($pt[2] * pr_median(array_column($o, 0)) - $pt[1]) / $pt[1];
    }
    $med = pr_median($errs); $max = max($errs);
    return ['ok' => $med <= 0.15 && $max <= 0.30, 'lanes' => count($pts), 'ratio' => round($R, 3), 'medianMiss' => round($med, 3), 'worstMiss' => round($max, 3)];
}
function price_ctx() {
    $RC = cload('routes.json', []);
    return ['RC' => $RC, 'cal' => ['business' => fare_calib('business', $RC), 'first' => fare_calib('first', $RC)]];
}
function duffel_fill($o, $c, $cl, array $ctx, $est = null) {
    $cal = $ctx['cal'][$cl] ?? null;
    if (!$cal || empty($cal['ok'])) return null;
    $A = air(); if (!isset($A[$o], $A[$c])) return null;
    $e = $ctx['RC'][rkey($o, $c)] ?? null; $px = fresh($e) ? ($e['raw']['px'][$cl] ?? null) : null;
    if (!($px > 0)) return null;
    $d = pr_km($o, $c); if ($d < 3500 || $d > 18500) return null;
    $v = $px * $cal['ratio'];
    if ($est && ($est['est'] ?? 0) > 0 && abs($v - $est['est']) / $est['est'] > 0.5) return null;
    return (int)(ceil($v * PR_BUFFER / 100) * 100);
}

// What a carousel card shows: the business class "from" price, plus first class when the documented routes for this pair include a first
// class partner flight. null = no price we trust (the carousel then uses another route). Order of trust: the price sheet, the estimate
// when a comparable lane is close, then Duffel x ratio. A route Duffel has documented with no partner-airline flight in either cabin is
// not priced at all, so the card never links to an empty listing.
function card_price($o, $c, array $ctx) {
    $e = $ctx['RC'][rkey($o, $c)] ?? null; $v = null;
    if (fresh($e)) { $v = view($o, $c, $e); if (!$v) return null; }
    $est = fare_estimate($o, $c, 'J');
    $b = !empty($est['from']) ? $est['from'] : duffel_fill($o, $c, 'business', $ctx, $est);
    if (!$b) return null;
    $p = ['business' => $b];
    if ($v) foreach ($v['cabins'] as $cb) {
        if ($cb['c'] !== 'First') continue;
        $fe = fare_estimate($o, $c, 'F');
        $f = !empty($fe['from']) ? $fe['from'] : duffel_fill($o, $c, 'first', $ctx, $fe);
        if ($f && $f > $b) $p['first'] = $f;
    }
    return $p;
}

// ---------- Routes the carousel could not price yet: looked up in the background ----------
function queue_lanes($o, array $codes) {
    if (!C('token') || !$codes) return;
    $RC = cload('routes.json', []); $add = [];
    foreach ($codes as $c) if (!fresh($RC[rkey($o, $c)] ?? null)) $add[] = $o . '-' . $c;
    if ($add) cupdate('warm.json', [], function (&$q) use ($add) { foreach ($add as $k) if (!in_array($k, $q, true)) $q[] = $k; $q = array_slice(array_values($q), -300); });
}
// Run from cron: first the price sheet's own lanes (they calibrate the Duffel prices), then routes the carousel asked for. A few per run.
function warm_step($budget) {
    if (!C('token')) return 'no Duffel token';
    if (doff()) return 'Duffel token rejected';
    $RC = cload('routes.json', []); $E = errs_now(); $m = META(); $today = day_et(); $keys = [];
    $want = function ($k) use (&$keys, $E, $m, $today) {
        if (in_array($k, $keys, true) || cooling($E, $k) || claimed($k)) return;
        $w = $m['warm'][$k] ?? null; if ($w && ($w['d'] ?? '') === $today && ($w['n'] ?? 0) >= 2) return;      // twice a day at most per route
        $keys[] = $k;
    };
    foreach (pr_refs() as $f) { $k = rkey($f[2], $f[3]); $e = $RC[$k] ?? null; if (!fresh($e) || !isset($e['raw']['px'])) $want($k); }
    $q = cload('warm.json', []);
    foreach ($q as $p) { $k = C('mode') . ':' . $p; if (!fresh($RC[$k] ?? null)) $want($k); }
    $keys = array_slice($keys, 0, (int)env('WARM_PER_RUN', 6) ?: 6);
    if (!$keys) { if ($q) cupdate('warm.json', [], function (&$x) use ($RC) { $x = array_values(array_filter($x, function ($p) use ($RC) { return !fresh($RC[C('mode') . ':' . $p] ?? null); })); }); return 'nothing to look up'; }
    $date = travel_date(); $tasks = []; $mine = [];
    foreach ($keys as $k) {
        if (!claim($k, 'refresh')) continue;
        [$o, $c] = explode('-', substr($k, strlen(C('mode')) + 1)); $mine[] = $k;
        $tasks[] = ['type' => 'refresh', 'o' => $o, 'c' => $c, 'key' => $k, 'date' => $date];
    }
    if (!$tasks) return 'busy';
    try { $res = run_tasks($tasks, microtime(true) + max(10, min(40, $budget))); } finally { foreach ($mine as $k) unclaim($k); }
    $done = [];
    foreach ($res as $k => $r) if ($r !== 'skip') $done[] = $k;
    cupdate('meta.json', ['lastRefresh' => 0], function (&$mm) use ($done, $today) { foreach ($done as $k) { $w = $mm['warm'][$k] ?? null; $mm['warm'][$k] = ['d' => $today, 'n' => ($w && ($w['d'] ?? '') === $today ? $w['n'] : 0) + 1]; } });
    if ($done) cupdate('warm.json', [], function (&$x) use ($done) { $pre = C('mode') . ':'; $x = array_values(array_filter($x, function ($p) use ($done, $pre) { return !in_array($pre . $p, $done, true); })); });
    $ok = count(array_filter($res, function ($r) { return $r === 'ok'; }));
    return $ok . ' route(s) priced from Duffel, ' . (count($res) - $ok) . ' skipped or failed';
}
// For the status page: is the Duffel fill switched on, and how well does it predict your own prices?
function pricing_status() {
    $RC = cload('routes.json', []); $q = cload('warm.json', []);
    return ['sheetLanes' => count(pr_refs()), 'duffelCalibration' => ['business' => fare_calib('business', $RC), 'first' => fare_calib('first', $RC)], 'queuedForLookup' => count($q)];
}
