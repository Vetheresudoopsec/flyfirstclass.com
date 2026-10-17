<?php
// duffel.php: the Duffel client (throttled, patient with rate limits), route cache, listings, and the daily refresh.
// Everything runs inside the web request or the cron job: there is no long-running process on shared hosting. A visitor's request
// fetches a few routes (within a short time budget), returns what it has plus "pending", and the page asks again until pending is 0.
if (!defined('FFC_PRIVATE')) { exit; }

const DHINT = [
    'access_token_not_found' => 'The token is not recognised. Copy a fresh one from the Duffel dashboard (Developers > Access tokens) into .env as DUFFEL_TOKEN.',
    'expired_access_token' => 'The token has expired. Create a new one in the Duffel dashboard and put it in .env.',
    'invalid_authorization_header' => 'The token is malformed. DUFFEL_TOKEN should be a single value starting with duffel_live_ or duffel_test_ (no quotes or spaces).',
    'insufficient_permissions' => 'This token is not allowed to do that. Check that live mode is enabled for your Duffel account and that the token is a live one.',
];
function okc($code, $cl) { $P = D()['PART']; return isset($P[$code]) && (!isset($P[$code][1]) || $P[$code][1] === $cl); }
function rkey($o, $c) { return C('mode') . ':' . $o . '-' . $c; }
function fresh($e) { return is_array($e) && ($e['s'] ?? null) === SCHEMA; }
function cooling($E, $k) { return isset($E[$k]) && now_ms() - $E[$k]['t'] < 60000; }   // a failed route waits 60 s before a retry
function err_set($k, $msg) {
    cupdate('errors.json', [], function (&$m) use ($k, $msg) { $m[$k] = ['t' => now_ms(), 'msg' => $msg, 'th' => md5(C('token'))]; foreach ($m as $kk => $v) if (now_ms() - $v['t'] > 3600000) unset($m[$kk]); });
}
function errs_now() {         // recorded failures, except ones made under an earlier token (fixing the token in .env gives routes a fresh start)
    $th = md5(C('token')); return array_filter(cload('errors.json', []), function ($e) use ($th) { return ($e['th'] ?? '') === $th; });
}
function err_del($k) { cupdate('errors.json', [], function (&$m) use ($k) { unset($m[$k]); }); }

// ---------- Duffel status, throttle and daily cap (shared by all requests through small files) ----------
function dstats_add($d) {
    cupdate('stats.json', [], function (&$s) use ($d) {
        foreach (['ok', 'fail', 'limited'] as $k) if (!empty($d[$k])) $s[$k] = ($s[$k] ?? 0) + $d[$k];
        if (isset($d['lastErr'])) $s['lastErr'] = $d['lastErr'];
        if (!empty($d['lastOk'])) { $s['lastOk'] = $d['lastOk']; $s['token'] = 'accepted'; unset($s['off']); }
        if (isset($d['off'])) { $s['off'] = $d['off']; $s['token'] = 'rejected'; }
    });
}
function doff() {            // set when Duffel rejected the token (401); cleared automatically once the token in .env changes
    $s = cload('stats.json', []); $o = $s['off'] ?? null;
    return ($o && ($o['th'] ?? '') === md5(C('token'))) ? $o['msg'] : '';
}
// Reserves start times for Duffel searches (DUFFEL_RPM a minute across all requests). All or nothing: when the queue is already too far
// ahead nothing is reserved, so a busy moment cannot use up slots that no search will ever fill.
// Returns a list of start times, null = too far ahead, 'cap' = daily cap reached.
function duffel_slots($launchBy, $n = 1) {
    return cupdate('throttle.json', [], function (&$t) use ($launchBy, $n) {
        $now = microtime(true); $d = day_et();
        if (($t['d'] ?? '') !== $d) { $t['d'] = $d; $t['n'] = 0; }
        if (C('dcap') && $t['n'] + $n > C('dcap')) return 'cap';
        $gap = 60 / C('drpm'); $at = max($now, (float)($t['next'] ?? 0), (float)($t['pause'] ?? 0));
        if ($at + ($n - 1) * $gap > $launchBy) return null;
        $out = []; for ($i = 0; $i < $n; $i++) $out[] = $at + $i * $gap;
        $t['next'] = $at + $n * $gap; $t['n'] += $n;
        return $out;
    });
}
function duffel_slot($launchBy) { $r = duffel_slots($launchBy, 1); return is_array($r) ? $r[0] : $r; }
function duffel_pause($ms) { cupdate('throttle.json', [], function (&$t) use ($ms) { $t['pause'] = max((float)($t['pause'] ?? 0), microtime(true) + $ms / 1000); }); }
function reset_ms($h) {      // how long Duffel says to wait after a 429
    $v = $h['retry-after'] ?? ($h['ratelimit-reset'] ?? null);
    if ($v === null || $v === '') return 15000;
    if (is_numeric($v)) { $n = (float)$v; $ms = $n > 1e9 ? $n * 1000 - now_ms() : $n * 1000; }
    else { $t = strtotime($v); $ms = $t === false ? NAN : $t * 1000 - now_ms(); }
    return is_finite($ms) ? min(max($ms, 1000) + 500, 65000) : 15000;
}
function duffel_headers() { return ['Authorization: Bearer ' . C('token'), 'Duffel-Version: v2', 'Accept: application/json', 'Content-Type: application/json']; }
function duffel_error($name, $status, $body) {
    $e = []; $j = json_decode($body, true);
    if (is_array($j) && !empty($j['errors'][0])) $e = $j['errors'][0];
    $code = $e['code'] ?? ''; $msg = substr(trim(preg_replace('/\s+/', ' ', (string)($e['message'] ?? $body ?? ''))), 0, 200);
    $hint = DHINT[$code] ?? ($status === 401 ? DHINT['access_token_not_found'] : ($status === 403 ? DHINT['insufficient_permissions'] : ''));
    return 'Duffel ' . $name . ' ' . $status . ($code ? ' ' . $code : '') . ': ' . $msg . ($hint ? ' ' . $hint : '');
}

// One itinerary list from a Duffel offer response: only itineraries whose every segment is in the asked cabin; carrier codes and
// stop cities only, never prices.
function parse_offers($j, $cl, &$raw) {
    $seen = [];
    foreach (($j['data']['offers'] ?? []) as $of) {
        $segs = $of['slices'][0]['segments'] ?? [];
        if (!$segs) continue;
        $good = true;
        foreach ($segs as $s) foreach (($s['passengers'] ?? []) as $p) if (($p['cabin_class'] ?? null) !== $cl) { $good = false; break 2; }
        if (!$good) continue;
        $it = [];
        foreach ($segs as $s) { $mc = $s['marketing_carrier'] ?? []; $it[] = ($mc['iata_code'] ?? '') ?: (($mc['name'] ?? '') ?: '??'); }
        if (all_ok($it, $cl)) {                  // cheapest fare before taxes (USD) among partner airlines: overall (px) and per airline when one airline flies every leg (pxa)
            $usd = to_usd($of['base_amount'] ?? null, ($of['base_currency'] ?? '') ?: ($of['total_currency'] ?? ''));
            if ($usd !== null && $usd > 0) {
                if (!isset($raw['px'][$cl]) || $usd < $raw['px'][$cl]) $raw['px'][$cl] = $usd;
                if (count(array_unique($it)) === 1 && (!isset($raw['pxa'][$cl][$it[0]]) || $usd < $raw['pxa'][$cl][$it[0]])) $raw['pxa'][$cl][$it[0]] = $usd;
            }
        }
        $k = implode('>', $it);
        if (!isset($seen[$k])) { if (count($seen) >= 40) continue; $seen[$k] = 1; $raw[$cl][] = $it; }
        $via = [];                      // where the stop(s) are, for example "DOH" or "DXB>SIN": kept per airline sequence, at most 4 different stop cities each
        foreach (array_slice($segs, 0, -1) as $s) { $x = $s['destination']['iata_code'] ?? ''; if ($x !== '') $via[] = $x; }
        $via = implode('>', $via);
        if ($via !== '') { $h = $raw['hubs'][$cl][$k] ?? []; if (!in_array($via, $h, true) && count($h) < 4) { $h[] = $via; } $raw['hubs'][$cl][$k] = $h; }
    }
}
function merge_raw($old, $raw) {
    $old = is_array($old) ? $old : [];
    $merged = ['first' => $old['first'] ?? [], 'business' => $old['business'] ?? []];
    foreach (['first', 'business'] as $cl) {
        $seen = []; foreach ($merged[$cl] as $x) $seen[implode('>', $x)] = 1;
        foreach (($raw[$cl] ?? []) as $it) if (!isset($seen[implode('>', $it)])) $merged[$cl][] = $it;
    }
    $merged['px'] = $old['px'] ?? []; $merged['pxa'] = $old['pxa'] ?? [];          // keep the lowest fare seen across look-ups
    foreach (['first', 'business'] as $cl) {
        if (isset($raw['px'][$cl]) && (!isset($merged['px'][$cl]) || $raw['px'][$cl] < $merged['px'][$cl])) $merged['px'][$cl] = $raw['px'][$cl];
        foreach (($raw['pxa'][$cl] ?? []) as $al => $v) if (!isset($merged['pxa'][$cl][$al]) || $v < $merged['pxa'][$cl][$al]) $merged['pxa'][$cl][$al] = $v;
    }
    $merged['hubs'] = ['first' => $old['hubs']['first'] ?? [], 'business' => $old['hubs']['business'] ?? []];
    foreach (['first', 'business'] as $cl) foreach (($raw['hubs'][$cl] ?? []) as $sq => $hs) {
        $h = $merged['hubs'][$cl][$sq] ?? [];
        foreach ($hs as $v) if (!in_array($v, $h, true) && count($h) < 4) $h[] = $v;
        $merged['hubs'][$cl][$sq] = $h;
    }
    return $merged;
}

// Runs a set of route tasks at once: each needs two searches (First and Business), started on their reserved slots and sent in
// parallel. A task is { type: fetch | hubs | more | refresh, o, c, key, date, tries? }. Returns key => 'ok' | 'err' | 'skip'.
// 'skip' means it was not started (throttle, rate-limit pause or time budget): nothing is stored and it is tried again later.
function run_tasks(array $tasks, $launchBy) {
    $res = [];
    if (!$tasks) return $res;
    ignore_user_abort(true);
    if (!C('token')) { foreach ($tasks as $t) { err_set($t['key'], 'Duffel token not set'); $res[$t['key']] = 'err'; } return $res; }
    $off = doff();
    if ($off) { foreach ($tasks as $t) { err_set($t['key'], $off); $res[$t['key']] = 'err'; } return $res; }
    $jobs = []; $full = false;
    foreach ($tasks as $ti => $t) {
        $slots = $full ? null : duffel_slots($launchBy, 2);                    // both searches or neither
        if ($slots === 'cap') { err_set($t['key'], 'Daily Duffel search limit reached (' . C('dcap') . '). Raise DUFFEL_DAILY_CAP in .env to continue.'); $res[$t['key']] = 'err'; continue; }
        if (!is_array($slots)) { $full = true; $res[$t['key']] = 'skip'; continue; }
        foreach (['first', 'business'] as $i => $cl) {
            $jobs[$ti . ':' . $cl] = ['url' => C('duffel_base') . '/air/offer_requests?return_offers=true&supplier_timeout=12000', 'method' => 'POST', 'headers' => duffel_headers(), 'timeout' => 25, 'at' => $slots[$i],
                'body' => json_encode(['data' => ['slices' => [['origin' => $t['o'], 'destination' => $t['c'], 'departure_date' => $t['date']]], 'passengers' => [['type' => 'adult']], 'cabin_class' => $cl]])];
        }
    }
    if (!$jobs) return $res;
    $out = http_multi($jobs);
    $retry = [];                       // network errors and 5xx replies are retried once
    foreach ($out as $id => $r) if (($r['status'] === 0 && empty($r['timeout'])) || $r['status'] >= 500) $retry[] = $id;
    if ($retry) {
        sleep(2); $j2 = [];
        foreach ($retry as $id) { $s = duffel_slot($launchBy + 12); if ($s === null || $s === 'cap') continue; $j = $jobs[$id]; $j['at'] = $s; $j2[$id] = $j; }
        if ($j2) foreach (http_multi($j2) as $id => $r) $out[$id] = $r;
    }
    $stats = ['ok' => 0, 'fail' => 0, 'limited' => 0]; $save = []; $limited = false;
    foreach ($tasks as $ti => $t) {
        if (isset($res[$t['key']])) continue;
        $name = '/air/offer_requests'; $raw = ['first' => [], 'business' => [], 'hubs' => ['first' => [], 'business' => []], 'px' => [], 'pxa' => []]; $err = null; $lim = false;
        foreach (['first', 'business'] as $cl) {
            $r = $out[$ti . ':' . $cl] ?? null;
            if (!$r) { $err = $err ?: 'Duffel ' . $name . ' no reply'; continue; }
            if ($r['error']) { $err = $err ?: 'Duffel ' . $name . ' ' . ($r['timeout'] ? 'timed out after 25 seconds' : $r['error']); $stats['fail']++; continue; }
            if ($r['status'] === 429) { $lim = true; $stats['limited']++; duffel_pause(reset_ms($r['headers'])); logm('Duffel rate limit reached: pausing all Duffel requests for ' . ceil(reset_ms($r['headers']) / 1000) . ' s'); continue; }
            if ($r['status'] < 200 || $r['status'] > 299) {
                $msg = duffel_error($name, $r['status'], $r['body']); $err = $err ?: $msg; $stats['fail']++;
                $stats['lastErr'] = ['t' => now_ms(), 'msg' => $msg];
                if ($r['status'] === 401) $stats['off'] = ['msg' => $msg, 'th' => md5(C('token'))];
                continue;
            }
            $j = json_decode($r['body'], true);
            if (!is_array($j)) { $err = $err ?: 'Duffel ' . $name . ': unreadable reply'; $stats['fail']++; continue; }
            $stats['ok']++; $stats['lastOk'] = now_ms();
            parse_offers($j, $cl, $raw);
            unset($j, $out[$ti . ':' . $cl]);
        }
        if ($lim) { $res[$t['key']] = 'skip'; $limited = true; continue; }       // a rate limit is not a failure: wait it out and retry
        if ($err) { err_set($t['key'], $err); logm($err); $res[$t['key']] = 'err'; if (empty($stats['lastErr'])) $stats['lastErr'] = ['t' => now_ms(), 'msg' => $err]; continue; }
        $save[$t['key']] = [$t, $raw]; $res[$t['key']] = 'ok';
    }
    if ($save) {
        cupdate('routes.json', [], function (&$RC) use ($save) {
            foreach ($save as $k => [$t, $raw]) {
                if ($t['type'] === 'hubs' && isset($RC[$k])) $RC[$k] = array_merge($RC[$k], ['raw' => merge_raw($RC[$k]['raw'] ?? [], $raw), 'hq' => (int)($RC[$k]['hq'] ?? 0) + 1, 't' => now_ms()]);
                elseif ($t['type'] === 'more' && isset($RC[$k])) $RC[$k] = array_merge($RC[$k], ['raw' => merge_raw($RC[$k]['raw'] ?? [], $raw), 'tries' => $t['tries'], 't' => now_ms()]);
                else $RC[$k] = ['s' => SCHEMA, 't' => now_ms(), 'raw' => $raw, 'tries' => 1];
            }
        });
        foreach ($save as $k => $x) { err_del($k); }
    }
    dstats_add($stats);
    return $res;
}
function travel_date($days = 45) { return gmdate('Y-m-d', time() + $days * 86400); }

// ---------- What the page sees ----------
function aname($c) { $a = air(); return !empty($a[$c]['city']) ? $a[$c]['city'] . ' (' . $c . ')' : $c; }
function hub_text($v) { return implode(' and ', array_map('aname', explode('>', $v))); }
function hubs_of($e, $cl, $it) { return $e['raw']['hubs'][$cl][implode('>', $it)] ?? []; }
// A route saved without stop cities, or topped up with another date (new itineraries come with stop cities, older ones may not).
// "hq" counts these look-ups (at most 2), so a connection Duffel no longer offers cannot cause endless searching.
function hubs_missing($e) {
    foreach (['first', 'business'] as $cl) foreach (($e['raw'][$cl] ?? []) as $it) if (count($it) > 1 && all_ok($it, $cl) && !hubs_of($e, $cl, $it)) return true;
    return false;
}
function needs_hubs($e) { return fresh($e) && (!isset($e['raw']['hubs']) || (hubs_missing($e) && (int)($e['hq'] ?? 0) < 2)); }
function all_ok($it, $cl) { foreach ($it as $k) if (!okc($k, $cl)) return false; return (bool)$it; }

// Turns stored itineraries into a listing row, keeping only partner airlines (and Qatar / Air France business only).
function view($o, $c, $e) {
    $P = D()['PART']; $cabins = []; $stops = 9; $hv = [];
    foreach (['first', 'business'] as $cl) {
        $air = [];
        foreach (($e['raw'][$cl] ?? []) as $it) {
            if (!all_ok($it, $cl)) continue;
            $n = count($it) - 1;
            if ($n < $stops) { $stops = $n; $hv = []; }
            if ($n === $stops) foreach (hubs_of($e, $cl, $it) as $v) $hv[$v] = 1;
            foreach ($it as $k) $air[$P[$k][0]] = 1;
        }
        if ($air) $cabins[] = ['c' => $cl === 'first' ? 'First' : 'Business', 'a' => array_map('strval', array_keys($air))];
    }
    if (!$cabins) return null;
    $row = ['iata' => $c, 'city' => city_of($c), 'stops' => $stops];
    if ($stops && $hv) $row['via'] = array_map('hub_text', array_map('strval', array_slice(array_keys($hv), 0, 3)));
    $row['cabins'] = $cabins; $row['test'] = C('test');
    return $row;
}
// One route option = one distinct airline sequence (for example "Singapore Airlines, direct" or "Emirates, 1 stop").
function view_options($o, $c, $e) {
    $P = D()['PART']; $m = [];
    foreach (['first', 'business'] as $cl) foreach (($e['raw'][$cl] ?? []) as $it) {
        if (!all_ok($it, $cl)) continue;
        $k2 = implode('>', $it);
        if (!isset($m[$k2])) { $air = []; foreach ($it as $k) $air[$P[$k][0]] = 1; $m[$k2] = ['stops' => count($it) - 1, 'air' => array_map('strval', array_keys($air)), 'cab' => [], 'via' => []]; }
        $m[$k2]['cab'][$cl === 'first' ? 'First' : 'Business'] = 1;
        foreach (hubs_of($e, $cl, $it) as $v) $m[$k2]['via'][$v] = 1;
    }
    $vals = array_values($m); $idx = 0;
    foreach ($vals as &$v) $v['i'] = $idx++;
    unset($v);
    usort($vals, function ($a, $b) { return $a['stops'] <=> $b['stops'] ?: $a['i'] <=> $b['i']; });
    $out = [];
    foreach ($vals as $i => $x) {
        $r = ['iata' => $c, 'city' => city_of($c), 'stops' => $x['stops'], 'option' => $i + 1];
        if ($x['stops'] && $x['via']) $r['via'] = array_map('hub_text', array_map('strval', array_slice(array_keys($x['via']), 0, 3)));
        $r['cabins'] = array_map(function ($cn) use ($x) { return ['c' => $cn, 'a' => $x['air']]; }, array_map('strval', array_keys($x['cab'])));
        $r['test'] = C('test');
        $out[] = $r;
    }
    return $out;
}

// New route lookups per visitor address per hour (the site is public now, so a script cannot burn through the Duffel allowance).
function quota_refund($n) {
    if ($n <= 0 || PHP_SAPI === 'cli') return;
    $key = client_ip() . ':routes';
    cupdate('limits.json', [], function (&$L) use ($key, $n) { if (isset($L[$key])) $L[$key]['n'] = max(0, $L[$key]['n'] - $n); });
}
function quota_take($n) {
    if ($n <= 0 || PHP_SAPI === 'cli') return $n;
    return limit_take(client_ip(), 'routes', $n, C('ip_routes'), 3600000);
}

// The listings for an origin: what is documented comes back at once. Missing routes are fetched now (a few per request, within the
// launch budget) and the page keeps asking until `pending` reaches 0.
function snap($o, $t0) {
    $dests = all_dests($o); $RC = cload('routes.json', []); $E = errs_now();
    $tasks = []; $date = travel_date();
    foreach ($dests as $c) {
        $k = rkey($o, $c); $e = $RC[$k] ?? null;
        if (!fresh($e)) { if (!claimed($k) && !cooling($E, $k)) $tasks[] = ['type' => 'fetch', 'o' => $o, 'c' => $c, 'key' => $k, 'date' => $date]; }
        elseif (needs_hubs($e) && !claimed($k) && !cooling($E, $k)) { $v = view($o, $c, $e); if ($v && $v['stops'] > 0) $tasks[] = ['type' => 'hubs', 'o' => $o, 'c' => $c, 'key' => $k, 'date' => $date]; }
    }
    $limited = false;
    if ($tasks) {
        $tasks = array_slice($tasks, 0, max(1, (int)env('TASKS_PER_REQUEST', 6)));
        $mine = [];
        foreach ($tasks as $t) if (claim($t['key'])) $mine[] = $t;               // claim first, so visitors racing for the same route do not use up each other's allowance
        if ($mine) {
            $n = quota_take(count($mine));
            if ($n < count($mine)) { $limited = true; foreach (array_slice($mine, $n) as $t) unclaim($t['key']); $mine = array_slice($mine, 0, $n); }
        }
        if ($mine) {
            logm('Requesting ' . count($mine) . ' route(s) from ' . $o . ' (' . (count($dests) - count(array_filter($dests, function ($c) use ($RC, $o) { return !fresh($RC[rkey($o, $c)] ?? null); }))) . ' already documented)');
            try { $res = run_tasks($mine, $t0 + C('launch')); } finally { foreach ($mine as $t) unclaim($t['key']); }
            $skipped = count(array_filter($res, function ($x) { return $x === 'skip'; }));
            if ($skipped) quota_refund($skipped);                                   // not started (busy or rate limited): not counted against the visitor
        }
    }
    $RC = cload('routes.json', []); $E = errs_now(); $ctx = price_ctx();
    $list = []; $pending = 0; $errs = [];
    foreach ($dests as $c) {
        $k = rkey($o, $c); $e = $RC[$k] ?? null;
        if (fresh($e)) { $v = view($o, $c, $e); if ($v) { $v['price'] = card_price($o, $c, $ctx); $list[] = $v; } }       // price: same "from" figure as the carousel, or null
        if (isset($E[$k])) $errs[] = $E[$k];
        $wait = !fresh($e) || (needs_hubs($e) && ($v2 = view($o, $c, $e)) && $v2['stops'] > 0);
        if ($wait && !cooling($E, $k) && (!$limited || claimed($k))) $pending++;
    }
    return ['dests' => $dests, 'list' => $list, 'pending' => $pending, 'errs' => $errs, 'limited' => $limited];
}
function routes($o, $d) {
    if (!C('token')) throw fail('Duffel token not set', 503);
    $AIR = air();
    if (!isset($AIR[$o]) || ($d && !isset($AIR[$d]))) return ['routes' => [], 'pending' => 0, 'error' => 'Unknown airport code.', 'test' => C('test')];
    $t0 = microtime(true);
    if ($d) return options_for($o, $d, $t0);
    $r = snap($o, $t0);
    // Fewer than 10 listings once everything is checked: make another call for more routes.
    if (!$r['pending'] && count($r['list']) < LISTMIN && count($r['dests']) < MAXTOTAL && !($r['errs'] && !$r['list']) && expand_once($o)) $r = snap($o, $t0);
    $error = !$r['list'] && !$r['pending'] ? ($r['limited'] ? 'Too many new route lookups from your connection. Please try again in a while.' : ($r['errs'] ? $r['errs'][0]['msg'] : '')) : '';
    return ['routes' => $r['list'], 'pending' => $r['pending'], 'error' => $error, 'test' => C('test'), 'rtw' => market_rtw($o)];
}
// A chosen destination: list every distinct airline option. Fewer than 3 options: make another call (another date).
function options_for($o, $d, $t0) {
    $k = rkey($o, $d); queue_photos([$d]);
    if (is_in($d)) { $m = META(); if (empty($m['asked'][$k])) cupdate('meta.json', ['lastRefresh' => 0], function (&$mm) use ($k) { $mm['asked'][$k] = now_ms(); }); }   // asked for by name: the daily refresh keeps it current
    $RC = cload('routes.json', []); $E = errs_now(); $e = $RC[$k] ?? null;
    $busy = function () use ($k, &$e) { $c = claimed($k); return $c === 'visitor' || ($c === 'refresh' && !fresh($e)); };
    $task = null; $limited = false;
    if (!fresh($e) && !$busy() && !cooling($E, $k)) $task = ['type' => 'fetch', 'o' => $o, 'c' => $d, 'key' => $k, 'date' => travel_date()];
    elseif (fresh($e) && !$busy() && !cooling($E, $k)) {
        $tries = (int)($e['tries'] ?? 1); $opts = view_options($o, $d, $e);
        if ($tries < MAXTRIES && count($opts) < 3) $task = ['type' => 'more', 'o' => $o, 'c' => $d, 'key' => $k, 'tries' => $tries + 1, 'date' => travel_date(45 + 30 * $tries)];
        elseif (needs_hubs($e) && array_filter($opts, function ($x) { return $x['stops'] > 0; })) $task = ['type' => 'hubs', 'o' => $o, 'c' => $d, 'key' => $k, 'date' => travel_date()];
    }
    if ($task) {
        if (quota_take(1) < 1) { $limited = true; $task = null; }
        elseif (claim($k)) { logm('Route ' . $o . '-' . $d . ' (' . $task['type'] . ')'); try { run_tasks([$task], $t0 + C('launch')); } finally { unclaim($k); } }
    }
    $RC = cload('routes.json', []); $E = errs_now(); $e = $RC[$k] ?? null;
    $opts = fresh($e) ? view_options($o, $d, $e) : [];
    $pending = 0;
    if (!$limited && !cooling($E, $k)) {
        if (!fresh($e)) $pending = 1;
        elseif (((int)($e['tries'] ?? 1) < MAXTRIES && count($opts) < 3) || (needs_hubs($e) && array_filter($opts, function ($x) { return $x['stops'] > 0; }))) $pending = 1;
        elseif ($busy()) $pending = 1;
    }
    return ['routes' => $opts, 'pending' => $pending, 'error' => !$opts && !$pending ? ($limited ? 'Too many new route lookups from your connection. Please try again in a while.' : ($E[$k]['msg'] ?? '')) : '', 'test' => C('test'), 'price' => $opts ? card_price($o, $d, price_ctx()) : null];
}

// ---------- Checks used by the diagnostics endpoints ----------
function check_duffel($search) {
    $out = ['mode' => C('mode')];
    if (!C('token')) return $out + ['ok' => false, 'error' => 'No DUFFEL_TOKEN found. Put it in the .env file in the ffc-private folder.'];
    $r = http_one(C('duffel_base') . '/air/airlines?limit=1', duffel_headers(), 25);
    if ($r['error'] || $r['status'] < 200 || $r['status'] > 299) {
        $msg = $r['error'] ? 'Duffel /air/airlines ' . $r['error'] : duffel_error('/air/airlines', $r['status'], $r['body']);
        if ($r['status'] === 401) dstats_add(['off' => ['msg' => $msg, 'th' => md5(C('token'))], 'fail' => 1, 'lastErr' => ['t' => now_ms(), 'msg' => $msg]]);
        return $out + ['ok' => false, 'token' => $r['status'] === 401 ? 'rejected' : 'unknown', 'error' => $msg];
    }
    $out['token'] = 'accepted'; dstats_add(['lastOk' => now_ms(), 'ok' => 1]);
    if ($search) {
        $date = travel_date(40);
        $r = http_one(C('duffel_base') . '/air/offer_requests?return_offers=true&supplier_timeout=10000', duffel_headers(), 30, 'POST', json_encode(['data' => ['slices' => [['origin' => 'LHR', 'destination' => 'JFK', 'departure_date' => $date]], 'passengers' => [['type' => 'adult']], 'cabin_class' => 'business']]));
        if ($r['error'] || $r['status'] < 200 || $r['status'] > 299) return $out + ['ok' => false, 'error' => $r['error'] ? 'Duffel /air/offer_requests ' . $r['error'] : duffel_error('/air/offer_requests', $r['status'], $r['body'])];
        $j = json_decode($r['body'], true);
        $out['searched'] = 'LHR-JFK business, ' . $date; $out['offersReturned'] = count($j['data']['offers'] ?? []);
    }
    return $out + ['ok' => true];
}

// ---------- Daily refresh at 12:00 PM Eastern (run from cron; resumable) ----------
// Re-requests every documented route once. Old data keeps being served until each new result replaces it. A run works for up to
// $budget seconds and saves its progress, so the next cron run carries on until every route is done.
function refresh_step($budget) {
    $t0 = microtime(true); $stop = $t0 + $budget;
    if (!C('token')) return 'no Duffel token';
    if (doff()) return 'Duffel token rejected';
    $m = META(); $ln = last_noon();
    if ((int)($m['lastRefresh'] ?? 0) >= $ln) return 'not due';
    $st = $m['refresh'] ?? null;
    if (!$st || ($st['for'] ?? 0) !== $ln) { $st = ['for' => $ln, 'done' => [], 'dd' => [], 'ok' => 0, 'bad' => 0]; logm('Daily refresh started'); }
    $saveSt = function () use (&$st) { cupdate('meta.json', ['lastRefresh' => 0], function (&$mm) use ($st) { $mm['refresh'] = $st; }); };
    // 1. new AirLabs destination lists
    foreach (array_keys(cload('dests.json', [])) as $o) {
        if (in_array($o, $st['dd'], true)) continue;
        if (microtime(true) > $stop - 20) { $saveSt(); return 'time budget used (destination lists)'; }
        $old = cload('dests.json', [])[$o] ?? null;
        cupdate('dests.json', [], function (&$mm) use ($o) { unset($mm[$o]); });
        dests_for($o);
        if (!isset(cload('dests.json', [])[$o]) && $old) cupdate('dests.json', [], function (&$mm) use ($o, $old) { $mm[$o] = $old; });
        $st['dd'][] = $o;
    }
    // 2. routes already documented in this mode (India only if someone asked for it by name)
    $RC = cload('routes.json', []); $asked = $m['asked'] ?? [];
    $keys = [];
    foreach (array_keys($RC) as $k) {
        if (!starts($k, C('mode') . ':')) continue;
        $c = explode('-', substr($k, strlen(C('mode')) + 1))[1] ?? '';
        if (!is_in($c) || !empty($asked[$k])) $keys[] = $k;
    }
    $todo = array_values(array_diff($keys, $st['done']));
    $date = travel_date();
    while ($todo && microtime(true) < $stop - 45) {
        $batch = array_splice($todo, 0, 6); $tasks = []; $mine = [];
        foreach ($batch as $k) {
            if (!claim($k, 'refresh')) { $st['done'][] = $k; continue; }          // a visitor is fetching it right now: that is fresh data already
            $mine[] = $k; [$o, $c] = explode('-', substr($k, strlen(C('mode')) + 1));
            $tasks[] = ['type' => 'refresh', 'o' => $o, 'c' => $c, 'key' => $k, 'date' => $date];
        }
        try { $res = run_tasks($tasks, microtime(true) + 25); } finally { foreach ($mine as $k) unclaim($k); }
        $skipped = 0;
        foreach ($res as $k => $r) { if ($r === 'skip') { $todo[] = $k; $skipped++; } else { $st['done'][] = $k; $r === 'ok' ? $st['ok']++ : $st['bad']++; } }
        $saveSt();
        if ($skipped && $skipped === count($tasks)) {                               // rate limit or throttle: wait a little, then carry on
            $t = cload('throttle.json', []); $wait = max(2, min(30, (float)($t['pause'] ?? 0) - microtime(true)));
            if (microtime(true) + $wait > $stop - 45) break;
            sleep((int)ceil($wait));
        }
    }
    if (!$todo) {
        cupdate('meta.json', ['lastRefresh' => 0], function (&$mm) use ($st) { $mm['lastRefresh'] = now_ms(); unset($mm['refresh']); });
        logm('Daily refresh done: ' . $st['ok'] . ' updated, ' . $st['bad'] . ' failed (kept previous data). Next: ' . et_text(next_noon()));
        return 'done: ' . $st['ok'] . ' updated, ' . $st['bad'] . ' failed';
    }
    $saveSt();
    return 'in progress: ' . count($st['done']) . ' of ' . count($keys) . ' routes';
}
