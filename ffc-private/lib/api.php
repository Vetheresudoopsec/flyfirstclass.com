<?php
// api.php: the endpoints. Same addresses and replies as server.js:
//   /api/geo   /api/routes   /api/top   /api/photos   /api/places   /api/requests   /api/send   /api/subscribe
// Diagnostics (/api/status, /api/duffel-check, /api/mail-check, /api/photo-test, /api/cron, /api/newsletter-export) need ?key=ADMIN_KEY from .env.
if (!defined('FFC_PRIVATE')) { exit; }

function cron_run($budget) {         // used by cron.php and /api/cron
    $out = [];
    // Newsletter sign-ups go first, under their own short claim, so a long route refresh or photo run never holds them up
    // (and a manual /api/cron call can send a waiting address even while the main job is busy).
    if (claim('newsletter-run', 'cron', 120)) {
        try { $out['newsletter'] = nl_run(); } catch (Throwable $e) { $out['newsletter'] = 'error: ' . $e->getMessage(); logm('cron newsletter: ' . $e->getMessage()); }
        finally { unclaim('newsletter-run'); }
    } else { $out['newsletter'] = 'skipped: another newsletter run is working'; }
    if (!claim('cron', 'cron', max(300, (int)$budget + 120))) { $out['skipped'] = 'another run is still working'; return $out; }
    try {
        $t0 = microtime(true);
        $out['purged'] = qpurge();
        try { air(); } catch (Throwable $e) { $out['airports'] = $e->getMessage(); }
        try { $out['refresh'] = refresh_step($budget); } catch (Throwable $e) { $out['refresh'] = 'error: ' . $e->getMessage(); logm('cron refresh: ' . $e->getMessage()); }
        $left = $budget - (microtime(true) - $t0);
        if ($left > 60) { try { $out['prices'] = warm_step($left - 30); } catch (Throwable $e) { $out['prices'] = 'error: ' . $e->getMessage(); logm('cron prices: ' . $e->getMessage()); } }
        $left = $budget - (microtime(true) - $t0);
        if ($left > 30) { try { $out['photos'] = prefetch_photos($left); } catch (Throwable $e) { $out['photos'] = 'error: ' . $e->getMessage(); logm('cron photos: ' . $e->getMessage()); } }
        cupdate('meta.json', ['lastRefresh' => 0], function (&$m) { $m['cronLast'] = now_ms(); });
    } finally { unclaim('cron'); }
    logm('cron: ' . json_encode($out));
    return $out;
}

function status_full() {
    $s = cload('stats.json', []); $m = META(); $t = cload('throttle.json', []); $u = ust(); $IMG = img_all(true); $mode = C('mode');
    $routes = 0; foreach (array_keys(cload('routes.json', [])) as $k) if (starts($k, $mode . ':')) $routes++;
    $off = doff();
    return ['build' => BUILD, 'mode' => $mode, 'host' => 'php',
        'duffel' => ['mode' => $mode, 'token' => !C('token') ? 'not set' : ($off ? 'rejected' : (($s['token'] ?? '') === 'rejected' ? 'not checked yet' : ($s['token'] ?? 'not checked yet'))), 'requestsOk' => $s['ok'] ?? 0, 'requestsFailed' => $s['fail'] ?? 0, 'rateLimited' => $s['limited'] ?? 0,
            'pausedForSeconds' => max(0, (int)ceil(($t['pause'] ?? 0) - microtime(true))), 'searchesToday' => (($t['d'] ?? '') === day_et()) ? ($t['n'] ?? 0) : 0, 'dailyCap' => C('dcap') ?: 'off', 'requestsPerMinute' => C('drpm'),
            'lastError' => !empty($s['lastErr']) ? ['msg' => $s['lastErr']['msg'], 'when' => et_text($s['lastErr']['t'])] : null],
        'airports' => count(air()), 'routesDocumented' => $routes, 'fetching' => claims_count(),
        'unsplash' => !C('ukey') ? 'no key' : (!empty($u['off']) ? 'key rejected (401)' : (!empty($u['pause']) && now_ms() < $u['pause'] ? 'paused (hourly limit)' : 'on')),
        'photosSaved' => count(array_filter($IMG)), 'photoRequestsLeft' => $u['left'] ?? null,
        'lastRefresh' => !empty($m['lastRefresh']) ? et_text($m['lastRefresh']) : 'never', 'nextRefresh' => et_text(next_noon()),
        'refreshInProgress' => isset($m['refresh']) ? count($m['refresh']['done'] ?? []) . ' routes done' : null,
        'cronLastRun' => !empty($m['cronLast']) ? et_text($m['cronLast']) : 'never (add the cron job, see the setup guide)',
        'newsletter' => nl_status(),
        'pricing' => pricing_status(),
        'recentEmails' => mail_recent(5),
        'recentLog' => log_tail(15)];
}

function dispatch() {
    $route = $_GET['route'] ?? ''; $ref = $_GET['_ref'] ?? '';
    if ($route === '') {                               // reached without the rewrite rule (api.php/routes or /api/routes)
        $p = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (preg_match('~/(?:api|api\.php)/([A-Za-z-]+)(?:/([A-Za-z0-9-]+))?/?$~', $p, $m)) { $route = $m[1]; $ref = $m[2] ?? ''; }
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    set_exception_handler(function ($e) {
        $st = $e instanceof ApiError ? $e->status : 500;
        logm(get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        json_out($st, ['error' => $e instanceof ApiError ? $e->getMessage() : 'Server error. Details are in cache/log.txt']);
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            logm('FATAL ' . $e['message'] . ' @' . basename($e['file']) . ':' . $e['line']);
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); echo '{"error":"Server error. Details are in cache/log.txt"}'; }
        }
    });
    set_time_limit(120);
    $q = $_GET;

    switch ($route) {
        case 'geo': json_out(200, geo());
        case 'guard':                                  // the page asks for a pass here just before it calls the server
            if (!guard_on()) json_out(200, ['on' => false]);
            if ($method !== 'POST') json_out(200, ['on' => true, 'site' => guard_site()]);
            if (!limit_hit(client_ip() . ':guard', 120, 3600000)) json_out(429, ['error' => 'Too many requests. Please try again later.']);
            json_out(200, guard_mint(body_json()));
        case 'routes':
            guard_gate(0);
            $o = strtoupper((string)($q['origin'] ?? '')); $d = strtoupper((string)($q['dest'] ?? ''));
            if (!preg_match('/^[A-Z]{3}$/', $o) || ($d !== '' && !preg_match('/^[A-Z]{3}$/', $d))) json_out(400, ['error' => 'origin and dest must be 3-letter IATA codes']);
            json_out(200, routes($o, $d ?: null));
        case 'top':
            guard_gate('soft');
            $o = strtoupper((string)($q['origin'] ?? ''));
            if (!preg_match('/^[A-Z]{3}$/', $o)) json_out(400, ['error' => 'origin must be a 3-letter IATA code']);
            json_out(200, top_list($o));
        case 'photos':
            guard_gate('soft');
            if (($q['any'] ?? '') === '1') json_out(200, photo_any());
            $codes = array_values(array_filter(explode(',', strtoupper((string)($q['codes'] ?? ''))), function ($c) { return preg_match('/^[A-Z]{3}$/', $c); }));
            json_out(200, photos_for($codes));
        case 'places':
            $s = mb_substr((string)($q['q'] ?? ''), 0, 40);
            json_out(200, mb_strlen($s) < 2 ? [] : places($s));
        case 'status':
            if (!admin_ok()) json_out(200, ['build' => BUILD]);
            json_out(200, status_full());
        case 'duffel-check':
            if (!admin_ok()) json_out(403, ['error' => 'Add ?key=your ADMIN_KEY (set it in the .env file in ffc-private).']);
            json_out(200, check_duffel(($q['search'] ?? '') === '1'));
        case 'photo-test':
            if (!admin_ok()) json_out(403, ['error' => 'Add ?key=your ADMIN_KEY (set it in the .env file in ffc-private).']);
            json_out(200, photo_test(substr(strtoupper((string)($q['code'] ?? '')), 0, 3)));
        case 'cron':
            if (!admin_ok()) json_out(403, ['error' => 'Add ?key=your ADMIN_KEY (set it in the .env file in ffc-private).']);
            json_out(200, cron_run(min(110, max(60, (int)($q['budget'] ?? 100)))));   // web version: about 100 seconds per call (the command-line cron uses CRON_BUDGET)
        case 'mail-check':
            if (!admin_ok()) json_out(403, ['error' => 'Add ?key=your ADMIN_KEY (set it in the .env file in ffc-private).']);
            $pv = (string)($q['preview'] ?? '');
            if ($pv !== '') {            // shows the visitor's confirmation email in the browser; add &send=1&to=you@example.com to send that sample to yourself as well
                $cm = confirm_sample($pv);
                if (!$cm) json_out(400, ['error' => 'preview must be confirmation, confirmation-oneway or confirmation-multi']);
                if (($q['send'] ?? '') === '1') {
                    $cm['to'] = cm_addr($q['to'] ?? '');
                    if ($cm['to'] === '') json_out(400, ['error' => 'Add &to=you@example.com to send the sample.']);
                    try { $out = ['sent' => true, 'via' => send_mail($cm), 'to' => m_mask($cm['to'])]; } catch (Throwable $e) { $out = ['sent' => false, 'error' => $e->getMessage()]; }
                    $out['recent'] = mail_recent(3);
                    json_out(200, $out);
                }
                header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
                echo $cm['html']; exit;
            }
            $cfg = mail_cfg(); $sm = $cfg['smtp'];
            $out = ['configured' => (bool)($sm || function_exists('mail')), 'method' => $sm ? 'smtp' : 'mail()', 'host' => $sm['host'] ?? null, 'port' => $sm['port'] ?? null, 'secure' => $sm['secure'] ?? null, 'user' => env('SMTP_USER') ?: null,
                'password' => env('SMTP_PASS') ? 'set' : ($sm && $sm['user'] ? 'missing' : 'not needed'), 'from' => $cfg['from'], 'to' => $cfg['to'], 'intendedFor' => MAIL_INTENDED];
            if (($q['send'] ?? '') === '1') {
                try { $out['via'] = send_mail(['subject' => 'Test email from your Fly First Class site', 'text' => 'This is a test. If you can read it, quote requests will arrive here.', 'html' => '<p>This is a test. If you can read it, quote requests will arrive here.</p>', 'replyTo' => '']); $out['sent'] = true; }
                catch (Throwable $e) { $out['sent'] = false; $out['error'] = $e->getMessage(); }
            }
            $out['recent'] = mail_recent(8);
            $out['note'] = $sm ? 'Sent through your SMTP account.' : "Sent through the server's own mail system. \"sent\" only means the server accepted the message for delivery. If it never arrives, check the spam folder, then cPanel > Email > Track Delivery (search for the recipient) and cPanel > Email > Email Deliverability.";
            json_out(200, $out);
        case 'send':
            if ($method !== 'POST') json_out(405, ['error' => 'Method not allowed']);
            guard_gate(1);
            $ip = client_ip();
            if (!limit_hit($ip . ':mail', 10, 3600000)) json_out(429, ['error' => 'Too many requests. Please try again later.']);
            $b = body_json(); guard_form($b); $ref = strtoupper(qs($b['ref'] ?? null, 20));
            if (!q_find($ref)) json_out(400, ['error' => 'Unknown reference ID.']);
            $c = qWho($b['contact'] ?? null);
            if (!preg_match('/^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/', $c['email'])) json_out(400, ['error' => 'A valid email address is required.']);
            $sk = 'send:' . $ref . ':' . gmdate('Y-m-d'); $L = cload('limits.json', []);
            if (($L[$sk]['n'] ?? 0) >= 10 && now_ms() - $L[$sk]['t'] <= 86400000) json_out(429, ['error' => "This reference ID has reached today's limit. Please call us."]);
            $loc = ($b['consent'] ?? null) === true ? ip_location() : null;
            $via = send_mail(quote_mail($b, $ref, $loc));
            limit_hit($sk, 1000000000, 86400000);
            logm('[mail] ' . $ref . ' sent (' . $via . ')');
            // The thank-you email to the visitor (confirm.php): only for a new quote request that names a route. It is sent after the reply below,
            // and nothing that goes wrong with it can change what the visitor sees.
            try { $cm = confirm_mail($b, $ref); } catch (Throwable $e) { $cm = null; logm('[mail] ' . $ref . ' confirmation skipped: ' . $e->getMessage()); }
            if ($cm) json_out_then(200, ['sent' => true, 'ref' => $ref], function () use ($cm, $ref) { confirm_deliver($cm, $ref); });
            json_out(200, ['sent' => true, 'ref' => $ref]);
        case 'subscribe':
            if ($method !== 'POST') json_out(405, ['error' => 'Method not allowed']);
            guard_gate(1);
            if (!limit_hit(client_ip() . ':nl', 8, 3600000) || !limit_hit('all:nl', 300, 3600000)) json_out(429, ['error' => 'Too many requests. Please try again later.']);
            $nb = body_json(); guard_form($nb);
            [$ncode, $nrep, $nh] = nl_signup($nb);
            if ($nh !== '') json_out_then($ncode, $nrep, function () use ($nh) { nl_sync_one($nh); });
            json_out($ncode, $nrep);
        case 'newsletter-export':
            if (!admin_ok()) json_out(403, ['error' => 'Add ?key=your ADMIN_KEY (set it in the .env file in ffc-private).']);
            nl_export();
        case 'history':                                // Request History on the Manage Booking page (history.php): a code emailed to the address proves it is theirs
            if ($method !== 'POST') json_out(405, ['error' => 'Method not allowed']);
            if (!hs_on()) json_out(404, ['error' => 'Not found']);
            guard_gate(1);
            $hb = body_json(); guard_form($hb); $act = (string)($hb['act'] ?? '');
            if ($act === 'send') {
                [$hr, $hm] = hs_start($hb);
                if (!$hm) json_out(200, $hr);
                json_out_then(200, $hr, function () use ($hm) {
                    foreach ($hm as $m) { try { $via = send_mail($m); logm('[history] code sent to ' . m_mask($m['to']) . ' (' . $via . ')'); } catch (Throwable $e) { logm('[history] code not sent to ' . m_mask($m['to']) . ': ' . $e->getMessage()); } }
                });
            }
            if ($act === 'check') json_out(200, hs_check($hb));
            if ($act === 'list') json_out(200, hs_list($hb));
            json_out(400, ['error' => 'Unknown action']);
        case 'requests':
            if (in_array($method, ['GET', 'PUT', 'POST'], true)) guard_gate(1);
            $ip = client_ip(); $ref = strtoupper($ref);
            if ($ref === '' && $method === 'POST') {
                if (!limit_hit($ip . ':new', 30, 3600000)) json_out(429, ['error' => 'Too many requests. Please try again later.']);
                $nb = body_json(); guard_form($nb);
                $made = q_create($nb);
                $nh = !empty($nb['newsletter']) ? nl_from_quote($made['contact']) : '';       // the "subscribe me" tick-box on the quote form
                if ($nh !== '') json_out_then(200, $made, function () use ($nh) { nl_sync_one($nh); });
                json_out(200, $made);
            }
            if ($ref !== '' && in_array($method, ['GET', 'PUT', 'POST'], true)) {      // POST is accepted for changes too (some hosting firewalls refuse PUT)
                if (!miss_ok($ip)) json_out(429, ['error' => 'Too many attempts. Please try again in a few minutes.']);
                if (!q_find($ref)) { miss_add($ip); json_out(404, ['error' => 'No request found for that reference ID.']); }
                if ($method === 'GET') { $r = q_find($ref); json_out(200, qOut($r)); }
                $r = q_update($ref, body_json());
                if (!$r) { miss_add($ip); json_out(404, ['error' => 'No request found for that reference ID.']); }
                json_out(200, $r);
            }
            json_out(405, ['error' => 'Method not allowed']);
        default:
            json_out(404, ['error' => 'Not found']);
    }
}
