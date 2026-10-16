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

// What a carousel card shows: business class "from" price, plus first class when the documented routes for this pair include a
// first class partner flight. null = no number (the card says to request a quote).
function card_price($o, $c, $RC = null) {
    $b = fare_estimate($o, $c, 'J');
    if (!$b || empty($b['from'])) return null;
    $p = ['business' => $b['from']];
    if ($RC === null) $RC = cload('routes.json', []);
    $e = $RC[rkey($o, $c)] ?? null;
    if (fresh($e) && ($v = view($o, $c, $e))) {
        foreach ($v['cabins'] as $cb) {
            if ($cb['c'] !== 'First') continue;
            $f = fare_estimate($o, $c, 'F');
            if ($f && !empty($f['from']) && $f['from'] > $b['from']) $p['first'] = $f['from'];
        }
    }
    return $p;
}
