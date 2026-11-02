<?php
// monitor.php: the Monitoring view of the private admin page (/api/trips, see trips.php).
// One place for everything that used to need ?key=... in the address: /api/status (health and numbers), /api/duffel-check, /api/mail-check
// (a test email), /api/photo-test, /api/cron (run the job now) and /api/newsletter-export (the addresses Mailchimp does not have yet).
// Those addresses still work. This view reads the same checks (status_full() and friends) and shows them in plain words, with a button for each tool.
// It is only ever shown to a signed-in admin, and the buttons are posts that carry the page token. Nothing here prints a key, a token or a password.
if (!defined('FFC_PRIVATE')) { exit; }

const MON_LABELS = ['ok' => 'Working', 'warn' => 'Check', 'bad' => 'Needs attention', 'off' => 'Not set up'];

function mon_ago($ms) {
    $s = max(0, intdiv(now_ms() - (int)$ms, 1000));
    if ($s < 60) return 'less than a minute ago';
    if ($s < 3600) { $m = intdiv($s, 60); return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
    if ($s < 86400) { $h = intdiv($s, 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    $d = intdiv($s, 86400); return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}
function mon_label($k) {           // "sentToMailchimp" becomes "Sent to Mailchimp"
    $k = strtolower(str_replace(['_', '-'], ' ', preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', (string)$k)));
    $k = str_ireplace(['mailchimp', 'duffel', 'unsplash', 'trustpilot', 'smtp'], ['Mailchimp', 'Duffel', 'Unsplash', 'Trustpilot', 'SMTP'], $k);
    return ucfirst($k);
}
function mon_val($v, $depth = 0) {      // one value, written for people: yes/no, a list on one line, a nested list indented
    if ($v === null || $v === '') return '<span class="m">None</span>';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_object($v)) $v = (array)$v;
    if (is_array($v)) {
        if (!$v) return '<span class="m">None</span>';
        $flat = array_keys($v) === range(0, count($v) - 1);
        foreach ($v as $x) if (is_array($x) || is_object($x)) $flat = false;
        if ($flat) return m_esc(implode(', ', array_map(function ($x) { return is_bool($x) ? ($x ? 'Yes' : 'No') : (string)$x; }, $v)));
        if ($depth > 3) return '<code>' . m_esc(json_encode($v)) . '</code>';
        return mon_kv($v, $depth + 1);
    }
    $s = (string)$v;
    if (preg_match('~^https://[^\s<>"]+$~', $s)) return '<a href="' . m_esc($s) . '" target="_blank" rel="noopener noreferrer">Open the link</a>';
    return m_esc($s);
}
function mon_kv($a, $depth = 0, $skip = []) {
    $h = '<dl class="kv' . ($depth ? ' in' : '') . '">';
    foreach ((array)$a as $k => $v) { if (in_array($k, $skip, true)) continue; $h .= '<dt>' . m_esc(is_int($k) ? '#' . ($k + 1) : mon_label($k)) . '</dt><dd>' . mon_val($v, $depth) . '</dd>'; }
    return $h . '</dl>';
}
function mon_result($set = null) {      // the result of the tool just used, kept for the page that is about to be drawn
    static $r = null; if ($set !== null) $r = $set; return $r;
}
function mon_mailcfg() {      // how email is sent, in words (never the password)
    $c = mail_cfg(); $sm = $c['smtp'];
    return ['Sent through' => $sm ? 'SMTP (' . ($sm['host'] ?? '') . ':' . ($sm['port'] ?? '') . ')' : "the server's own mail system (PHP mail)", 'Team inbox (MAIL_TO)' => $c['to'], 'Sent from' => $c['from'],
        'SMTP password' => env('SMTP_PASS') ? 'set' : (($sm && !empty($sm['user'])) ? 'missing' : 'not needed')];
}

// ---------- Health: one card for each thing the site depends on ----------
// st: ok, warn, bad or off (not set up / not used yet). label overrides the word on the chip. Every card says what it saw, in a sentence or two.
function mon_cards($S, $mail) {
    $now = now_ms(); $c = [];
    // Background job (the cPanel cron job, every 15 minutes)
    $last = (int)(META()['cronLast'] ?? 0);
    if (!$last) $c[] = ['k' => 'job', 'title' => 'Background job', 'st' => 'bad', 'head' => 'Has never run', 'lines' => ['The cron job is not set up yet. Fares, price lookups, photos, newsletter sign-ups and Trustpilot emails all depend on it (see the setup guide).']];
    else {
        $age = $now - $last; $st = $age <= 2400000 ? 'ok' : ($age <= 10800000 ? 'warn' : 'bad');
        $c[] = ['k' => 'job', 'title' => 'Background job', 'st' => $st, 'head' => 'Ran ' . mon_ago($last), 'lines' => array_filter([$st === 'ok' ? 'It runs every 15 minutes.' : 'It should run every 15 minutes. Check the cron job in cPanel.', 'Next daily fare refresh: ' . $S['nextRefresh'], !empty($S['refreshInProgress']) ? 'Refreshing now: ' . $S['refreshInProgress'] . '.' : null])];
    }
    // Fares (Duffel)
    $d = $S['duffel']; $tok = (string)$d['token']; $le = cload('stats.json', [])['lastErr'] ?? null;
    $cap = $d['dailyCap']; $n = (int)$d['searchesToday'];
    $lines = ['Searches today: ' . $n . (is_numeric($cap) ? ' of ' . $cap : '') . '.', 'Requests: ' . (int)$d['requestsOk'] . ' accepted, ' . (int)$d['requestsFailed'] . ' failed, ' . (int)$d['rateLimited'] . ' slowed down.'];
    if ($tok === 'not set') $c[] = ['k' => 'fares', 'title' => 'Fares (Duffel)', 'st' => 'off', 'head' => 'No Duffel token', 'lines' => ['Live fares are off. Add DUFFEL_TOKEN to the .env file.']];
    elseif ($tok === 'rejected') $c[] = ['k' => 'fares', 'title' => 'Fares (Duffel)', 'st' => 'bad', 'head' => 'Duffel refused the token', 'lines' => ['Fix DUFFEL_TOKEN in the .env file, then use Check connection below.']];
    elseif ((int)$d['pausedForSeconds'] > 0) $c[] = ['k' => 'fares', 'title' => 'Fares (Duffel)', 'st' => 'warn', 'head' => 'Paused for ' . (int)$d['pausedForSeconds'] . ' more seconds', 'lines' => array_merge(['Duffel asked us to slow down.'], $lines)];
    elseif (is_numeric($cap) && $n >= (int)$cap) $c[] = ['k' => 'fares', 'title' => 'Fares (Duffel)', 'st' => 'warn', 'head' => 'Daily search limit reached', 'lines' => $lines];
    elseif (is_array($le) && $now - (int)($le['t'] ?? 0) < 86400000) $c[] = ['k' => 'fares', 'title' => 'Fares (Duffel)', 'st' => 'warn', 'head' => 'A request failed ' . mon_ago($le['t']), 'lines' => array_merge([mb_substr((string)($le['msg'] ?? ''), 0, 200)], $lines)];
    elseif ($tok === 'accepted') $c[] = ['k' => 'fares', 'title' => 'Fares (Duffel)', 'st' => 'ok', 'head' => 'Token accepted', 'lines' => $lines];
    else $c[] = ['k' => 'fares', 'title' => 'Fares (Duffel)', 'st' => 'off', 'label' => 'Not checked yet', 'head' => 'Not checked yet', 'lines' => array_merge(['Use Check connection below to test the token.'], $lines)];
    // Email
    $f = $mail[0] ?? null; $failed = count(array_filter($mail, function ($x) { return empty($x['ok']); }));
    if (!$f) $c[] = ['k' => 'email', 'title' => 'Email sending', 'st' => 'off', 'label' => 'Nothing sent yet', 'head' => 'No emails sent yet', 'lines' => ['Use Send a test email below to check that delivery works.']];
    elseif (empty($f['ok'])) $c[] = ['k' => 'email', 'title' => 'Email sending', 'st' => 'bad', 'head' => 'The last email failed', 'lines' => array_filter([mb_substr((string)($f['error'] ?? ''), 0, 200), $f['when'] ?? null])];
    else $c[] = ['k' => 'email', 'title' => 'Email sending', 'st' => $failed ? 'warn' : 'ok', 'head' => 'The last email was accepted', 'lines' => array_filter([($f['when'] ?? '') . ($f['via'] ? ', through ' . $f['via'] : ''), $failed ? $failed . ' of the last ' . count($mail) . ' emails failed.' : null])];
    // Newsletter (Mailchimp)
    $nl = $S['newsletter']; $on = strpos((string)$nl['mailchimp'], 'on') === 0;
    $nlines = [(int)$nl['waiting'] . ' waiting, ' . (int)$nl['sentToMailchimp'] . ' sent to Mailchimp, ' . (int)$nl['refusedByMailchimp'] . ' refused by Mailchimp.'];
    if (!$on) $c[] = ['k' => 'news', 'title' => 'Newsletter (Mailchimp)', 'st' => 'off', 'head' => 'Not set up', 'lines' => array_merge(['Sign-ups are kept safely until the Mailchimp key and list ID are in the .env file.'], $nlines)];
    elseif ((int)$nl['gaveUp'] > 0) $c[] = ['k' => 'news', 'title' => 'Newsletter (Mailchimp)', 'st' => 'bad', 'head' => (int)$nl['gaveUp'] . ' sign-up' . ((int)$nl['gaveUp'] === 1 ? '' : 's') . ' could not be sent', 'lines' => array_merge(['Download the waiting addresses below and add them in Mailchimp.'], $nlines)];
    elseif (!empty($nl['lastProblem']) && (int)$nl['waiting'] > 0) $c[] = ['k' => 'news', 'title' => 'Newsletter (Mailchimp)', 'st' => 'warn', 'head' => 'Mailchimp is slow to answer', 'lines' => array_merge([mb_substr((string)$nl['lastProblem']['msg'], 0, 200)], $nlines)];
    else $c[] = ['k' => 'news', 'title' => 'Newsletter (Mailchimp)', 'st' => 'ok', 'head' => 'Connected', 'lines' => $nlines];
    // Photos (Unsplash)
    $u = (string)$S['unsplash']; $pl = [(int)$S['photosSaved'] . ' destination photos saved.'] ;
    if ($u === 'on') $c[] = ['k' => 'photos', 'title' => 'Photos (Unsplash)', 'st' => 'ok', 'head' => 'On', 'lines' => array_merge($pl, $S['photoRequestsLeft'] !== null ? ['Requests left this hour: ' . (int)$S['photoRequestsLeft'] . '.'] : [])];
    elseif ($u === 'no key') $c[] = ['k' => 'photos', 'title' => 'Photos (Unsplash)', 'st' => 'off', 'head' => 'No Unsplash key', 'lines' => $pl];
    elseif (strpos($u, 'rejected') !== false) $c[] = ['k' => 'photos', 'title' => 'Photos (Unsplash)', 'st' => 'bad', 'head' => 'Unsplash refused the key', 'lines' => array_merge(['Fix UNSPLASH_KEY in the .env file.'], $pl)];
    else $c[] = ['k' => 'photos', 'title' => 'Photos (Unsplash)', 'st' => 'warn', 'head' => 'Paused until the hourly limit resets', 'lines' => $pl];
    // Trustpilot invitations
    $t = $S['trips']; $bcc = strpos((string)$t['method'], 'Bcc') !== false; $tcap = $t['monthlyLimit'];
    $tl = [(int)$t['waiting'] . ' waiting, ' . (int)$t['sentThisMonth'] . ' sent this month' . (is_numeric($tcap) ? ' of ' . $tcap : '') . '.'];
    if (!empty($t['nextDue'])) $tl[] = 'Next one is due ' . $t['nextDue'] . '.';
    if ((int)$t['needAttention'] > 0) $c[] = ['k' => 'tp', 'title' => 'Trustpilot invitations', 'st' => 'bad', 'head' => (int)$t['needAttention'] . ' need' . ((int)$t['needAttention'] === 1 ? 's' : '') . ' attention', 'lines' => $tl, 'link' => ['See them', TR_PAGE . '?tab=attention']];
    elseif (is_numeric($tcap) && (int)$t['sentThisMonth'] >= (int)$tcap) $c[] = ['k' => 'tp', 'title' => 'Trustpilot invitations', 'st' => 'warn', 'head' => 'Monthly limit reached', 'lines' => $tl, 'link' => ['Open the list', TR_PAGE]];
    elseif (!$bcc) $c[] = ['k' => 'tp', 'title' => 'Trustpilot invitations', 'st' => 'warn', 'head' => 'Review link only', 'lines' => array_merge(['TRUSTPILOT_BCC is not set, so the email carries the plain review link.'], $tl), 'link' => ['Open the list', TR_PAGE]];
    else $c[] = ['k' => 'tp', 'title' => 'Trustpilot invitations', 'st' => 'ok', 'head' => 'Sending automatically', 'lines' => $tl, 'link' => ['Open the list', TR_PAGE]];
    return $c;
}

// ---------- The tools (what the old key addresses did). Each is a post with the page token; the result is shown at the top of the view. ----------
function mon_done($title, $st, $head, $data) { return ['title' => $title, 'st' => $st, 'head' => $head, 'data' => $data]; }
function mon_do($act, $p) {
    switch ($act) {
        case 'mon_duffel':
            $search = !empty($p['search']);
            if (!limit_hit($search ? 'mon:duffels' : 'mon:duffel', $search ? 6 : 30, 3600000)) return mon_done('Duffel', 'warn', 'That is enough checks for one hour. Try again later.', []);
            try { $r = check_duffel($search); } catch (Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
            logm('[admin] Duffel check' . ($search ? ' with a fare search' : '') . ': ' . (!empty($r['ok']) ? 'ok' : 'failed'));
            return mon_done($search ? 'Duffel connection and a test fare search' : 'Duffel connection', !empty($r['ok']) ? 'ok' : 'bad',
                !empty($r['ok']) ? 'Duffel accepted the token' . ($search ? ' and returned ' . (int)($r['offersReturned'] ?? 0) . ' fares' : '') : 'The Duffel check failed', $r);
        case 'mon_mail':
            if (!limit_hit('mon:mail', 10, 3600000)) return mon_done('Test email', 'warn', 'That is enough test emails for one hour. Try again later.', []);
            $cfg = mail_cfg();
            try {
                $via = send_mail(['subject' => 'Test email from your Fly First Class site', 'text' => 'This is a test from the Monitoring page. If you can read it, quote requests will arrive here.', 'html' => '<p>This is a test from the Monitoring page. If you can read it, quote requests will arrive here.</p>', 'replyTo' => '']);
                logm('[admin] test email sent (' . $via . ')');
                return mon_done('Test email', 'ok', 'The server accepted the test email', ['Sent to' => $cfg['to'], 'Through' => $via,
                    'Note' => $via === 'smtp' ? 'Sent through your SMTP account.' : '"Accepted" only means the server took the message. If it never arrives, check the spam folder, then cPanel > Email > Track Delivery.']);
            } catch (Throwable $e) { logm('[admin] test email failed: ' . $e->getMessage()); return mon_done('Test email', 'bad', 'The test email could not be sent', ['Reason' => $e->getMessage()]); }
        case 'mon_cron':
            if (!limit_hit('mon:cron', 6, 3600000)) return mon_done('Background job', 'warn', 'That is enough manual runs for one hour. The job also runs by itself every 15 minutes.', []);
            try { $r = cron_run(100); } catch (Throwable $e) { logm('[admin] background job failed: ' . $e->getMessage()); return mon_done('Background job', 'bad', 'The job stopped with an error', ['Reason' => $e->getMessage()]); }
            logm('[admin] background job run by hand');
            return mon_done('Background job', 'ok', 'The job ran', $r);
        case 'mon_photo':
            $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($p['code'] ?? '')), 0, 3));
            if (strlen($code) !== 3) return mon_done('Photo test', 'bad', 'Enter a 3-letter airport code, for example JFK.', []);
            if (!limit_hit('mon:photo', 20, 3600000)) return mon_done('Photo test', 'warn', 'That is enough photo tests for one hour (Unsplash allows a limited number of requests).', []);
            try { $r = photo_test($code); } catch (Throwable $e) { $r = ['ok' => false, 'reason' => $e->getMessage()]; }
            logm('[admin] photo test for ' . $code . ': ' . (!empty($r['ok']) ? 'ok' : 'no photo'));
            return mon_done('Photo test for ' . $code, !empty($r['ok']) ? 'ok' : 'warn', !empty($r['ok']) ? 'Unsplash found a photo' : 'No photo was found', $r);
    }
    return null;
}

// ---------- The view ----------
function mon_form($t, $act, $label, $cls = '', $extra = '', $native = false) {
    return '<form method="post" action="' . TR_PAGE . '"' . ($native ? ' data-native' : '') . '><input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="t" value="' . $t . '">' . $extra . '<button class="btn ' . $cls . '" type="submit">' . $label . '</button></form>';
}
function mon_view($X) {
    $t = $X['t']; $S = status_full(); $mail = mail_recent(8); $cards = mon_cards($S, $mail); $R = mon_result();
    $nb = $nw = 0; foreach ($cards as $c) { if ($c['st'] === 'bad') $nb++; elseif ($c['st'] === 'warn') $nw++; }
    $sum = $nb ? ['bad', $nb . ' need' . ($nb === 1 ? 's' : '') . ' attention'] : ($nw ? ['warn', $nw . ' to check'] : ['ok', 'Everything set up is working']);
    echo '<header class="ph"><div class="tt"><div class="tr"><h1>Monitoring</h1><span class="chip ', $sum[0], '"><i></i>', m_esc($sum[1]), '</span></div>',
        '<p class="sub">The health of everything the site depends on, in one place: fares, email, the newsletter, photos, the background job and Trustpilot. Checked ', m_esc(et_text(now_ms())), '. Build ', m_esc((string)$S['build']), '.</p></div>',
        '<div class="acts"><a class="btn" href="', TR_PAGE, '?v=monitoring" data-swap>Refresh</a></div></header>';
    if (is_array($R)) {
        echo '<section class="card" id="result" role="status"><div class="rh"><h2>', m_esc($R['title']), '</h2><span class="chip ', m_esc($R['st']), '"><i></i>', m_esc($R['head']), '</span></div>', $R['data'] ? mon_kv($R['data']) : '', '</section>';
    }
    echo '<div class="hg">';
    foreach ($cards as $c) {
        echo '<section class="card hc" aria-label="', m_esc($c['title']), '"><div class="top"><h3>', m_esc($c['title']), '</h3><span class="chip ', $c['st'], '"><i></i>', m_esc($c['label'] ?? MON_LABELS[$c['st']]), '</span></div><div class="hh">', m_esc($c['head']), '</div><ul>';
        foreach ($c['lines'] as $l) echo '<li>', m_esc($l), '</li>';
        echo '</ul>', !empty($c['link']) ? '<a class="btn" href="' . $c['link'][1] . '" data-swap>' . m_esc($c['link'][0]) . '</a>' : '', '</section>';
    }
    echo '</div>';
    // tools
    echo '<section class="card cardl" id="tools"><div class="ch"><div><h2>Tools</h2></div><span class="m">What the old /api/... addresses with a key did. The result shows at the top.</span></div>',
        '<div class="tool"><div class="tx"><b>Fares: check the Duffel connection</b><span class="m">Asks Duffel whether the token is accepted. The second button also runs one real fare search (London to New York).</span></div><div class="ac">', mon_form($t, 'mon_duffel', 'Check connection'), mon_form($t, 'mon_duffel', 'Test a fare search', '', '<input type="hidden" name="search" value="1">'), '</div></div>',
        '<div class="tool"><div class="tx"><b>Email: send a test email</b><span class="m">Sends one to the team inbox, ', m_esc(mail_cfg()['to']), '.</span></div><div class="ac">', mon_form($t, 'mon_mail', 'Send test email'), '</div></div>',
        '<div class="tool"><div class="tx"><b>Background job: run it now</b><span class="m">Does what the 15-minute job does: fares, price lookups, photos, newsletter sign-ups and Trustpilot emails that are due. It can take up to two minutes.</span></div><div class="ac">', mon_form($t, 'mon_cron', 'Run now'), '</div></div>',
        '<div class="tool"><div class="tx"><b>Photos: test an airport</b><span class="m">Looks up a destination photo on Unsplash, for example JFK.</span></div><div class="ac">', mon_form($t, 'mon_photo', 'Test photos', '', '<label class="sr" for="pc">Airport code</label><input id="pc" class="pcode" type="text" name="code" maxlength="3" placeholder="JFK" autocomplete="off" required>'), '</div></div>',
        '<div class="tool"><div class="tx"><b>Newsletter: download the waiting addresses</b><span class="m">A CSV (Mailchimp import format) of the sign-ups Mailchimp does not have yet. It contains email addresses, so keep the file private.</span></div><div class="ac">', mon_form($t, 'mon_export', 'Download CSV', '', '', true), '</div></div></section>';
    // details
    $d = $S['duffel']; $mc = mon_mailcfg();
    echo '<div class="g g2"><section class="card"><h2>Fares and route data</h2>', mon_kv(['Mode' => $d['mode'], 'Duffel token' => $d['token'], 'Requests accepted' => $d['requestsOk'], 'Requests failed' => $d['requestsFailed'], 'Slowed down by Duffel' => $d['rateLimited'], 'Searches today' => $d['searchesToday'], 'Daily search limit' => $d['dailyCap'],
        'Requests per minute' => $d['requestsPerMinute'], 'Last Duffel error' => $d['lastError'] ? $d['lastError']['msg'] . ' (' . $d['lastError']['when'] . ')' : null, 'Airports known' => $S['airports'], 'Routes documented' => $S['routesDocumented'], 'Fetching now' => $S['fetching'],
        'Last refresh' => $S['lastRefresh'], 'Next refresh' => $S['nextRefresh'], 'Background job last ran' => $S['cronLastRun'], 'Photos saved' => $S['photosSaved'], 'Unsplash' => $S['unsplash']]), '</section>',
        '<section class="card"><h2>Price sheet and newsletter</h2>', mon_kv(['Price-sheet routes' => $S['pricing']['sheetLanes'], 'Queued for a Duffel lookup' => $S['pricing']['queuedForLookup'], 'Duffel business calibration' => $S['pricing']['duffelCalibration']['business'] ?? null, 'Duffel first calibration' => $S['pricing']['duffelCalibration']['first'] ?? null]),
        '<h3 style="margin:18px 0 10px">Newsletter</h3>', mon_kv($S['newsletter'], 0, ['howToRead']), '<h3 style="margin:18px 0 10px">Email</h3>', mon_kv($mc), '</section></div>';
    // recent emails
    echo '<section class="card cardl"><div class="ch"><div><h2>Recent emails</h2></div><span class="m">The last ', count($mail), ' send attempts. Addresses are shortened.</span></div>';
    if (!$mail) echo '<div class="emp">Nothing has been sent yet.</div>';
    else {
        echo '<div class="lh cm"><span>When</span><span>Result</span><span>Subject</span><span>To</span></div>';
        foreach ($mail as $x) echo '<div class="lr cm"><div data-l="When">', m_esc($x['when']), '</div><div data-l="Result"><span class="chip ', !empty($x['ok']) ? 'ok' : 'bad', '"><i></i>', !empty($x['ok']) ? 'Accepted' : 'Failed', '</span></div>',
            '<div data-l="Subject">', m_esc($x['subject'] ?? ''), !empty($x['error']) ? '<div class="er">' . m_esc($x['error']) . '</div>' : '', '</div><div data-l="To">', m_esc($x['to'] ?? ''), '</div></div>';
    }
    echo '</section>';
    // log
    $log = log_tail(40);
    echo '<section class="card cardl"><div class="ch"><div><h2>Recent log</h2></div><span class="m">The last ', count($log), ' lines. Lines that mention a failure are marked.</span></div><div class="lg" role="log" tabindex="0" aria-label="Recent log lines">';
    if (!$log) echo '<div class="ll">Nothing logged yet.</div>';
    foreach ($log as $l) { $e = (bool)preg_match('/error|fatal|fail|refus|reject|exception/i', $l); echo '<div class="ll', $e ? ' e' : '', '">', $e ? '<span class="sr">Problem: </span>' : '', m_esc($l), '</div>'; }
    echo '</div></section>';
    echo '<details class="card raw"><summary>Raw status (the same data the old /api/status address gave)</summary><pre class="lg">', m_esc(json_encode($S, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '</pre></details>';
}
function mon_css() {
    return '.chip.off{background:#f1f0ec}.chip.off i{background:#9a988f}.hg{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px}.hc{padding:18px 20px;display:flex;flex-direction:column;gap:8px}'
        . '.hc .top{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:6px 10px}.hc h3{margin:0;font-size:14px;font-weight:600;color:#3b3a37}.hc .hh{font-size:18px;font-weight:600;letter-spacing:-.01em}'
        . '.hc ul{margin:0;padding:0;list-style:none;color:#6b6a65;font-size:13px;display:flex;flex-direction:column;gap:4px}.hc .btn{align-self:flex-start;margin-top:4px}'
        . '.rh{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 16px;margin-bottom:14px}.rh h2{margin:0}'
        . '.kv{display:grid;grid-template-columns:minmax(120px,230px) minmax(0,1fr);gap:8px 18px;margin:0;align-items:baseline}.kv dt{color:#6b6a65;font-size:13px}.kv dd{margin:0;overflow-wrap:anywhere}.kv.in{grid-template-columns:minmax(0,1fr);gap:0;padding-left:12px;border-left:2px solid #eceae3}.kv.in dt{font-size:12.5px;margin-top:6px}.kv.in dt:first-child{margin-top:0}.kv.in dd{margin-bottom:0}'
        . '.tool{display:flex;flex-wrap:wrap;align-items:center;gap:10px 20px;padding:16px 20px;border-top:1px solid #eceae3}.tool .tx{flex:1 1 300px;min-width:0;display:flex;flex-direction:column;gap:2px}.tool .ac{justify-content:flex-start}.tool form{margin:0;display:flex;gap:8px;align-items:center}'
        . '.pcode{width:84px;min-height:40px;padding:0 10px;border:1px solid #cfccc1;border-radius:10px;font:inherit;font-size:14px;letter-spacing:.08em;text-transform:uppercase;background:#fff;color:#111418}'
        . '.cm{grid-template-columns:190px 120px minmax(0,1fr) 150px}.lg{max-height:360px;overflow:auto;padding:10px 20px 16px;border-top:1px solid #eceae3;background:#faf9f6;font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere}'
        . '.ll{padding:1px 0}.ll.e{color:#a12a2a;font-weight:600}pre.lg{margin:12px 0 0;border:1px solid #eceae3;border-radius:10px}.raw summary{cursor:pointer;font-weight:600}.raw summary:focus-visible{outline:2px solid #2a78d6;outline-offset:2px}'
        . '@media(max-width:760px){.kv{grid-template-columns:1fr;gap:2px}.kv dd{margin-bottom:8px}.cm{grid-template-columns:1fr !important}}';
}
