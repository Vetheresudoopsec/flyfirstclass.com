<?php
// photos.php: destination photos from Unsplash (looked up once per destination, then read from disk), the carousel, background filling.
if (!defined('FFC_PRIVATE')) { exit; }

const MSG401 = 'Unsplash rejected the key (401). Use the Access Key from your Unsplash app (Your apps > the app > Keys), not the Secret Key or Application ID. Photo lookups are off until then.';

function uh() { return ['Authorization: Client-ID ' . C('ukey'), 'Accept-Version: v1', 'Accept: application/json']; }
function ust() {                       // shared Unsplash state: requests left this hour, pause, rejected key
    $s = cload('unsplash.json', []);
    if (!empty($s['off']) && ($s['offTh'] ?? '') !== md5(C('ukey'))) unset($s['off']);       // a new key clears the "rejected" flag
    return $s;
}
function uset($arr) { cupdate('unsplash.json', [], function (&$s) use ($arr) { foreach ($arr as $k => $v) { if ($v === null) unset($s[$k]); else $s[$k] = $v; } }); }
function u_blocked() {
    $s = ust();
    if (!empty($s['pause']) && now_ms() >= $s['pause']) { uset(['pause' => null, 'left' => null]); $s = ust(); }   // an hour has passed: the allowance is back
    return !C('ukey') || !empty($s['off']) || (!empty($s['pause']) && now_ms() < $s['pause']);
}

// IATA -> photo { url, credit, link } (null = none found; an old "none" is retried after 7 days)
function img_all($reload = false) {
    static $c = null;
    if ($c !== null && !$reload) return $c;
    $IMG = cload('images.json', []); $NOPH = cload('nophoto.json', []);
    foreach ($IMG as $k => $v) if ($v === null && !(isset($NOPH[$k]) && now_ms() - $NOPH[$k] < 7 * 864e5)) unset($IMG[$k]);
    return $c = $IMG;
}
function pub($v) { return ($v && !C('credits')) ? ['url' => $v['url']] : $v; }   // the photographer credit is only sent when PHOTO_CREDITS is not "off"
function photo_queries($c) {
    $city = city_of($c); $country = country_of($c); $cc = $city . ($country ? ' ' . $country : '');
    $q = [[$cc . ' landmark cityscape', 'high'], [$cc, 'low'], [$city, 'low']]; $out = []; $seen = [];
    foreach ($q as $x) if (!isset($seen[$x[0]])) { $seen[$x[0]] = 1; $out[] = $x; }
    return $out;
}
function u_search_url($q, $f) { return C('unsplash_base') . '/search/photos?' . http_build_query(['query' => $q, 'orientation' => 'landscape', 'content_filter' => $f, 'per_page' => '1']); }

// Photos for a list of destinations, looked up together (up to three searches each, widening every time). Returns code => photo or
// null for every destination that is now known; one that could not be looked up (limit, error) is left out and tried again later.
function images_lookup(array $codes) {
    $IMG = img_all(true); $res = []; $todo = [];
    foreach (array_unique($codes) as $c) { if (array_key_exists($c, $IMG)) $res[$c] = $IMG[$c]; else $todo[] = $c; }
    if (!$todo || u_blocked()) return $res;
    $mine = [];
    foreach ($todo as $c) if (claim('img-' . $c, 'visitor', 40)) $mine[] = $c;
    if (!$mine) return $res;
    try {
        $s = ust(); $left = $s['left'] ?? null; $queries = []; $st = []; $photo = []; $used = [];
        foreach ($mine as $c) { $queries[$c] = photo_queries($c); $st[$c] = 'pending'; }
        $halt = false;
        for ($stage = 0; $stage < 3 && !$halt; $stage++) {
            $jobs = [];
            foreach ($mine as $c) {
                if ($st[$c] !== 'pending') continue;
                if ($stage >= count($queries[$c])) { $st[$c] = 'none'; continue; }
                [$q, $f] = $queries[$c][$stage]; $used[$c] = $q;
                $jobs[$c] = ['url' => u_search_url($q, $f), 'headers' => uh(), 'timeout' => 15];
            }
            if (!$jobs) break;
            foreach (http_multi($jobs) as $c => $r) {
                if (isset($r['headers']['x-ratelimit-remaining'])) $left = (int)$r['headers']['x-ratelimit-remaining'];
                if ($r['status'] === 401) { uset(['off' => '401', 'offTh' => md5(C('ukey'))]); logm(MSG401); $halt = true; $st[$c] = 'abort'; continue; }
                if ($r['status'] === 403) { uset(['pause' => now_ms() + 36e5]); logm('Unsplash rate limit reached (demo apps allow 50 requests an hour). Pausing photo lookups for an hour. Photos already found stay cached.'); $halt = true; $st[$c] = 'abort'; continue; }
                if ($r['error'] || $r['status'] < 200 || $r['status'] > 299) { logm('Unsplash ' . ($r['error'] ?: $r['status'])); $st[$c] = 'abort'; continue; }   // not cached, so it can be retried
                $j = json_decode($r['body'], true); $p = $j['results'][0] ?? null;
                if ($p) { $st[$c] = 'found'; $photo[$c] = $p; }
            }
            if ($halt) break;
            if ($left !== null && $left <= 1 && in_array('pending', $st, true)) { uset(['pause' => now_ms() + 36e5]); logm('Unsplash: hourly limit used up. Pausing photo lookups for an hour.'); $halt = true; }
        }
        if (!$halt) foreach ($st as $c => $x) if ($x === 'pending') $st[$c] = 'none';
        $new = []; $noph = []; $reg = [];
        foreach ($st as $c => $x) {
            if ($x === 'found') {
                $p = $photo[$c]; $raw = $p['urls']['raw'] ?? '';
                $v = ['url' => $raw . (strpos($raw, '?') !== false ? '&' : '?') . 'auto=format&fit=crop&w=1400&q=80', 'credit' => $p['user']['name'] ?? '', 'link' => ($p['links']['html'] ?? '') . '?utm_source=fly_first_class&utm_medium=referral'];
                $new[$c] = $v; $res[$c] = $v;
                if (!empty($p['links']['download_location'])) $reg[] = ['url' => $p['links']['download_location'], 'headers' => uh(), 'timeout' => 5];   // Unsplash asks apps to register a use
                logm('Unsplash: photo saved for ' . city_of($c) . ' (matched "' . ($used[$c] ?? '') . '"' . ($left !== null ? ', ' . $left . ' requests left this hour' : '') . ')');
            } elseif ($x === 'none') {
                $new[$c] = null; $noph[$c] = now_ms(); $res[$c] = null;
                logm('Unsplash: no photo found for ' . city_of($c) . ' after ' . count($queries[$c]) . ' searches (will retry in 7 days)');
            }
        }
        if ($new) cupdate('images.json', [], function (&$m) use ($new) { foreach ($new as $c => $v) $m[$c] = $v; });
        if ($noph) cupdate('nophoto.json', [], function (&$m) use ($noph) { foreach ($noph as $c => $t) $m[$c] = $t; });
        if ($left !== null) { $up = ['left' => $left, 'leftAt' => now_ms()]; if ($left <= 1 && empty(ust()['pause'])) $up['pause'] = now_ms() + 36e5; uset($up); }
        if ($reg) http_multi($reg);
        img_all(true);
    } finally { foreach ($mine as $c) unclaim('img-' . $c); }
    return $res;
}

// Photos for these destinations go to the front of the background queue.
function queue_photos($codes) {
    if (!C('ukey')) return;
    $s = ust(); if (!empty($s['off'])) return;
    $IMG = img_all(); $prio = cload('prio.json', []); $add = [];
    foreach ($codes as $c) if (!array_key_exists($c, $IMG) && !in_array($c, $prio, true) && !in_array($c, $add, true)) $add[] = $c;
    if ($add) cupdate('prio.json', [], function (&$p) use ($add) { foreach ($add as $c) $p[] = $c; $p = array_slice(array_values(array_unique($p)), -300); });
}

// Carousel: always 10 routes. The origin's AirLabs routes come first; if fewer than 5 appear, a second AirLabs call looks for more;
// if there are still fewer than 10, other high-ticket routes fill the rest. Shuffled on every request.
function top_list($o) {
    $all = all_dests($o);
    if (count($all) < 5) { expand_once($o); $all = all_dests($o); }
    if (count($all) < 10) { $have = array_flip($all); $all = array_slice(array_merge($all, array_values(array_filter(candidates_all($o), function ($c) use ($have) { return !isset($have[$c]); }))), 0, 10); }
    queue_photos($all);
    $IMG = img_all(true); $ordered = shuffle_a($all);
    $withPhoto = array_values(array_filter($ordered, function ($c) use ($IMG) { return !empty($IMG[$c]); }));
    $lookups = array_slice(array_values(array_filter($ordered, function ($c) use ($IMG) { return !array_key_exists($c, $IMG); })), 0, TOPBUDGET);
    $l = array_slice(shuffle_a(array_merge($withPhoto, $lookups)), 0, 10);
    if (count($l) < 10) $l = array_slice(array_merge($l, array_values(array_diff($ordered, $l))), 0, 10);
    $imgs = images_lookup($l);
    $with = []; $without = [];
    foreach ($l as $c) {
        $v = $imgs[$c] ?? null;
        $item = ['iata' => $c, 'city' => city_of($c), 'image' => pub($v)];     // image is null when no photo yet, same as server.js
        if ($v) $with[] = $item; else $without[] = $item;
    }
    return array_merge($with, $without);
}
// Photos for a list of destinations (Listings rows and carousel cards). Saved ones come back at once; a few new ones are looked up
// per request and the rest are reported as pending so the page can ask again shortly.
function photos_for($codes) {
    $codes = array_slice(array_values(array_unique($codes)), 0, 24);
    $IMG = img_all(true); $out = []; $todo = [];
    foreach ($codes as $c) { if (array_key_exists($c, $IMG)) $out[$c] = pub($IMG[$c]); else $todo[] = $c; }
    $now = array_slice($todo, 0, C('ubudget')); $later = array_slice($todo, C('ubudget'));
    $got = images_lookup($now);
    foreach ($now as $c) { if (array_key_exists($c, $got)) $out[$c] = pub($got[$c]); else $later[] = $c; }
    return ['photos' => (object)$out, 'pending' => (C('ukey') && !u_blocked()) ? array_values($later) : []];
}

// Background filling (run from cron): a few photos per run, most important destinations first. It leaves the last requests of
// each hour for visitors.
function prefetch_photos($budget) {
    if (!C('ukey') || env('PHOTO_PREFETCH') === 'off') return 'off';
    $stop = microtime(true) + $budget; $gap = max(1, (int)env('PHOTO_PREFETCH_MS', 15000) / 1000); $n = 0; $max = (int)env('PHOTOS_PER_RUN', 6) ?: 6;
    while ($n < $max && microtime(true) < $stop - 20) {
        if (u_blocked()) return 'paused or key rejected';
        $s = ust();
        if (($s['left'] ?? null) !== null && $s['left'] <= 8) { if (now_ms() - ($s['leftAt'] ?? 0) < 62 * 60000) return 'keeping the last requests of this hour for visitors'; uset(['left' => null]); }
        $AIR = air(); $IMG = img_all(true); $next = null;
        foreach (cload('prio.json', []) as $c) if (isset($AIR[$c]) && !array_key_exists($c, $IMG)) { $next = $c; break; }
        if (!$next) foreach (D()['PREMIUM'] as $c) if (isset($AIR[$c]) && !array_key_exists($c, $IMG)) { $next = $c; break; }
        if (!$next) return 'every destination has a photo (or was checked)';
        images_lookup([$next]); $n++;
        if (!array_key_exists($next, img_all(true))) return 'lookup failed, will retry next run';
        sleep((int)$gap);
    }
    return $n . ' photo lookup(s)';
}

// Direct test of one destination, bypassing the saved photos.
function photo_test($c) {
    if (!C('ukey')) return ['ok' => false, 'reason' => 'no UNSPLASH_KEY set'];
    $tried = []; $photo = null;
    foreach (photo_queries($c) as [$q, $f]) {
        $r = http_one(u_search_url($q, $f), uh(), 15);
        $j = $r['status'] >= 200 && $r['status'] < 300 ? json_decode($r['body'], true) : null; $p = $j['results'][0] ?? null;
        $tried[] = ['query' => $q, 'contentFilter' => $f, 'status' => $r['status'], 'photos' => $j ? ($j['total'] ?? count($j['results'] ?? [])) : 0, 'requestsLeft' => $r['headers']['x-ratelimit-remaining'] ?? null];
        if ($p) { $photo = $p['urls']['raw']; break; }
        if ($r['status'] < 200 || $r['status'] > 299) break;
    }
    $IMG = img_all(true);
    return ['ok' => (bool)$photo, 'photo' => $photo, 'tried' => $tried, 'savedInCache' => !array_key_exists($c, $IMG) ? 'not looked up yet' : ($IMG[$c] ? 'yes' : 'no photo (recorded)')];
}
