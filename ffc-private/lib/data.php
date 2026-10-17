<?php
// data.php: airport list, distances, premium destinations, AirLabs lookups, places search, visitor geolocation.
if (!defined('FFC_PRIVATE')) { exit; }

const LISTMIN = 10, MAXTOTAL = 40, MAXTRIES = 3, TOPBUDGET = 10, DV = 4;

function D() {
    static $d = null;
    if ($d === null) {
        $d = json_decode((string)@file_get_contents(FFC_PRIVATE . '/data.json'), true);
        if (!is_array($d)) throw fail('data.json is missing next to the lib folder', 500);
    }
    return $d;
}
function META() { return cload('meta.json', ['lastRefresh' => 0]); }

// ---------- AirLabs ----------
function airlabs($p, $q) {
    $q['api_key'] = C('airlabs_key');
    $r = http_one(C('airlabs_base') . $p . '?' . http_build_query($q), ['Accept: application/json'], 25);
    if ($r['error']) throw fail('AirLabs ' . $p . ' ' . $r['error'], 502);
    if ($r['status'] < 200 || $r['status'] > 299) throw fail('AirLabs ' . $p . ' ' . $r['status'], 502);
    $j = json_decode($r['body'], true);
    if (!is_array($j)) throw fail('AirLabs ' . $p . ': unreadable reply', 502);
    if (!empty($j['error'])) { $e = $j['error']; throw fail('AirLabs ' . $p . ': ' . (is_array($e) ? ($e['message'] ?? $e['code'] ?? 'error') : $e), 502); }
    return $j['response'] ?? [];
}

// ---------- Airports: bundled list, plus every airport AirLabs returns (downloaded once, then read from disk) ----------
function air() {
    static $A = null;
    if ($A !== null) return $A;
    $AIR = [];
    foreach (D()['AP'] as $k => $v) $AIR[$k] = ['city' => $v['city'], 'lat' => (float)$v['lat'], 'lng' => (float)$v['lng']];
    $rows = cload('airports.json', null);
    if (!$rows && C('airlabs_key')) {
        $m = META();
        if (now_ms() - (int)($m['airportsErr'] ?? 0) > 600000) {         // after a failed download, wait 10 minutes before trying again
            try {
                $rows = [];
                foreach (airlabs('/airports', ['_fields' => 'iata_code,name,city,country_code,lat,lng,popularity']) as $x) {
                    if (empty($x['iata_code']) || !isset($x['lat'], $x['lng']) || !is_numeric($x['lat']) || !is_numeric($x['lng'])) continue;
                    $rows[] = [$x['iata_code'], ($x['city'] ?? '') ?: ($x['name'] ?? ''), $x['name'] ?? '', (float)$x['lat'], (float)$x['lng'], (float)($x['popularity'] ?? 0), $x['country_code'] ?? ''];
                }
                if ($rows) { csave('airports.json', $rows); logm('Saved ' . count($rows) . ' airports from AirLabs'); } else $rows = null;
            } catch (Throwable $e) {
                logm($e->getMessage());
                $rows = null;
                cupdate('meta.json', ['lastRefresh' => 0], function (&$mm) { $mm['airportsErr'] = now_ms(); });
            }
        }
    }
    foreach ((array)$rows as $r) {
        [$k, $city, $name, $lat, $lng, $pop, $cc] = $r;
        if (isset($AIR[$k])) { $AIR[$k]['name'] = $name; $AIR[$k]['pop'] = $pop; $AIR[$k]['cc'] = $cc; }
        else $AIR[$k] = ['city' => $city, 'name' => $name, 'lat' => $lat, 'lng' => $lng, 'pop' => $pop, 'cc' => $cc];
    }
    return $A = $AIR;
}
function major() {          // airports eligible as "nearest airport" for a visitor
    static $M = null;
    if ($M !== null) return $M;
    $AIR = air(); $top = []; $i = 0;
    foreach ($AIR as $k => $v) { if (!empty($v['pop'])) $top[] = [$k, $v['pop'], $i]; $i++; }
    usort($top, function ($a, $b) { return $b[1] <=> $a[1] ?: $a[2] <=> $b[2]; });
    $top = array_map(function ($x) { return $x[0]; }, array_slice($top, 0, 500));
    return $M = array_values(array_unique(array_merge(array_keys(D()['AP']), count($top) >= 50 ? $top : [])));
}
function is_in($c) { $a = air(); return !empty($a[$c]['cc']) ? $a[$c]['cc'] === 'IN' : in_array($c, D()['INX'], true); }
function auto_ok($c) { return !is_in($c); }          // India is never shown automatically
function city_of($c) { $a = air(); return $a[$c]['city'] ?? $c; }
function country_of($c) { $a = air(); return !empty($a[$c]['cc']) ? (D()['CC'][$a[$c]['cc']] ?? '') : ''; }

function km($a, $b) {
    $r = function ($x) { return $x * M_PI / 180; };
    $h = sin($r($b[0] - $a[0]) / 2) ** 2 + cos($r($a[0])) * cos($r($b[0])) * sin($r($b[1] - $a[1]) / 2) ** 2;
    return 12742 * asin(sqrt($h));
}
function nearest($lat, $lng) {
    $AIR = air(); $best = null;
    foreach (major() as $k) { if (!isset($AIR[$k])) continue; $d = km([$lat, $lng], [$AIR[$k]['lat'], $AIR[$k]['lng']]); if ($best === null || $d < $best['d']) $best = ['k' => $k, 'city' => $AIR[$k]['city'], 'd' => $d]; }
    return $best;
}

// Long-haul premium destinations from an origin (over 2,500 km), in priority order.
function candidates_all($o) {
    $AIR = air(); $f = $AIR[$o] ?? null; $out = [];
    foreach (D()['PREMIUM'] as $c) {
        if ($c === $o) continue;
        if (!$f || (isset($AIR[$c]) && km([$f['lat'], $f['lng']], [$AIR[$c]['lat'], $AIR[$c]['lng']]) > 2500)) $out[] = $c;
    }
    return $out;
}
function candidates($o) { return array_slice(candidates_all($o), 0, C('maxd')); }

// Premium destinations AirLabs says are served from the origin. Documented once, then read from disk.
function dests_for($o) {
    $DC = cload('dests.json', []);
    if (isset($DC[$o]) && is_array($DC[$o]) && ($DC[$o]['v'] ?? 0) === DV) return array_values(array_filter($DC[$o]['l'], 'auto_ok'));
    if (C('airlabs_key')) {
        $mine = claim('dests-' . $o, 'visitor', 60);
        if (!$mine) {                            // another request is asking AirLabs for this origin right now: wait for its answer
            for ($i = 0; $i < 60 && claimed('dests-' . $o, 60); $i++) usleep(200000);
            $DC = cload('dests.json', []);
            if (isset($DC[$o]) && ($DC[$o]['v'] ?? 0) === DV) return array_values(array_filter($DC[$o]['l'], 'auto_ok'));
        }
        try {
            $served = [];
            foreach (airlabs('/routes', ['dep_iata' => $o]) as $x) if (!empty($x['arr_iata'])) $served[$x['arr_iata']] = 1;
            $l = array_slice(array_values(array_filter(D()['PREMIUM'], function ($c) use ($o, $served) { return $c !== $o && isset($served[$c]); })), 0, C('maxd'));
            if ($l) { cupdate('dests.json', [], function (&$m) use ($o, $l) { $m[$o] = ['v' => DV, 'l' => $l]; }); return $l; }
        } catch (Throwable $e) { logm($e->getMessage()); }
        finally { if ($mine) unclaim('dests-' . $o); }
    }
    return candidates($o);
}
// Markets with a curated destination order (data.json > MARKETS): Singapore, Hong Kong and the US. An origin belongs to a market by its
// airport code or its country.
function market_of($o) {
    $cc = air()[$o]['cc'] ?? '';
    foreach ((D()['MARKETS'] ?? []) as $id => $m) {
        if (in_array($o, $m['from'] ?? [], true) || ($cc !== '' && in_array($cc, $m['cc'] ?? [], true))) return $id;
    }
    return null;
}
// The market's destinations in their set order, without the origin itself, India, unknown airports and anything too close to be a
// long-haul premium trip. Empty for every other origin.
function market_dests($o) {
    $id = market_of($o); if ($id === null) return [];
    $AIR = air(); $f = $AIR[$o] ?? null; $out = [];
    foreach (D()['MARKETS'][$id]['to'] as $c) {
        if ($c === $o || !isset($AIR[$c]) || !auto_ok($c) || in_array($c, $out, true)) continue;
        if ($f && km([$f['lat'], $f['lng']], [$AIR[$c]['lat'], $AIR[$c]['lng']]) < 1500) continue;
        $out[] = $c;
    }
    return $out;
}
function market_rtw($o) { $id = market_of($o); return $id !== null && !empty(D()['MARKETS'][$id]['rtw']); }
// Destinations for an origin, in display order. A market origin gets its curated list first; whatever else AirLabs says is served follows.
function all_dests($o) {
    $XS = cload('expand.json', []);
    $base = array_values(array_filter(array_unique(array_merge(dests_for($o), $XS[$o]['add'] ?? [])), 'auto_ok'));
    $mk = market_dests($o);
    return $mk ? array_slice(array_values(array_unique(array_merge($mk, $base))), 0, MAXTOTAL) : $base;
}

// A "follow-up call" for more routes from an origin: first flights arriving at the origin (the same city pairs seen from the other
// side), then other high-ticket routes a few at a time. Progress is saved, so a stage is never repeated. Returns how many it added.
function expand_once($o) {
    $XS = cload('expand.json', []);
    $st = $XS[$o] ?? ['rev' => false, 'fill' => 0, 'add' => []];
    $have = array_flip(array_merge(dests_for($o), array_filter($st['add'], 'auto_ok'), [$o]));
    $added = [];
    if (empty($st['rev'])) {
        $st['rev'] = true;
        if (C('airlabs_key')) {
            try {
                $set = [];
                foreach (airlabs('/routes', ['arr_iata' => $o]) as $x) if (!empty($x['dep_iata'])) $set[$x['dep_iata']] = 1;
                $added = array_values(array_filter(D()['PREMIUM'], function ($c) use ($set, $have) { return isset($set[$c]) && !isset($have[$c]); }));
                logm('AirLabs follow-up for ' . $o . ': ' . count($added) . ' more route(s)');
            } catch (Throwable $e) { logm($e->getMessage()); $st['rev'] = false; }
        }
    }
    if (!$added && $st['fill'] < 3) {
        $st['fill']++;
        $added = array_slice(array_values(array_filter(candidates_all($o), function ($c) use ($have) { return !isset($have[$c]); })), 0, 8);
        logm('Fallback for ' . $o . ': ' . count($added) . ' other high-ticket route(s)');
    }
    $added = array_slice($added, 0, max(0, MAXTOTAL - count($have)));
    foreach ($added as $c) $st['add'][] = $c;
    cupdate('expand.json', [], function (&$m) use ($o, $st) { $m[$o] = $st; });
    return count($added);
}

// ---------- Local search over the full airport list: no API request needed ----------
function places($q) {
    $s = lc($q); $out = []; $i = 0;
    foreach (air() as $k => $v) {
        $sc = lc($k) === $s ? 0 : (strncmp(lc($v['city']), $s, strlen($s)) === 0 ? 1 : (strpos(lc($v['name'] ?? ''), $s) !== false ? 2 : 9));
        if ($sc < 9) $out[] = [$sc, $k, $v, $i];
        $i++;
    }
    usort($out, function ($a, $b) { return $a[0] <=> $b[0] ?: (($b[2]['pop'] ?? 0) <=> ($a[2]['pop'] ?? 0)) ?: $a[3] <=> $b[3]; });
    return array_map(function ($x) { return ['iata' => $x[1], 'name' => $x[2]['city']]; }, array_slice($out, 0, 8));
}

// ---------- Nearest major airport to the caller's IP ----------
function geo() {
    $ip = geo_ip();
    if (is_private_ip($ip)) $ip = '';                 // local: look up this machine's public IP
    $r = http_one(C('geoip') . $ip, ['Accept: application/json'], 10);
    $g = $r['status'] >= 200 && $r['status'] < 300 ? json_decode($r['body'], true) : [];
    if (!is_array($g) || empty($g['success'])) throw fail('IP lookup failed (' . $r['status'] . ')', 502);
    $a = nearest((float)$g['latitude'], (float)$g['longitude']);
    return ['city' => $g['city'] ?? null, 'country' => $g['country'] ?? null, 'countryCode' => $g['country_code'] ?? null, 'airport' => $a['k'], 'airportCity' => $a['city']];
}
