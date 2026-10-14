<?php
// mail.php: quote emails. Sent through the hosting account's own mail (PHP mail()) unless SMTP_HOST is set in .env, in which case
// the message goes through that SMTP server (for example Gmail with an app password, or a cPanel mailbox).
if (!defined('FFC_PRIVATE')) { exit; }

const MAIL_INTENDED = 'sales@flyfirstclass.com';        // quote requests go to this address unless MAIL_TO is set in .env
function mail_cfg() {
    $host = env('SMTP_HOST'); $port = (int)env('SMTP_PORT', 465) ?: 465;
    $from = trim(env('MAIL_FROM') ?: (env('SMTP_USER') ?: MAIL_INTENDED));
    $cfg = ['to' => trim(env('MAIL_TO', MAIL_INTENDED)), 'from' => $from, 'smtp' => null];
    if ($host) $cfg['smtp'] = ['host' => $host, 'port' => $port, 'user' => env('SMTP_USER'), 'pass' => env('SMTP_PASS'), 'secure' => env('SMTP_SECURE') ?: ($port === 465 ? 'ssl' : ($port === 587 ? 'starttls' : 'none')), 'verify' => env('SMTP_VERIFY_TLS') !== 'off'];
    return $cfg;
}
function m_hdr($v) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$v)); }
function m_word($v) { return preg_match('/^[\x20-\x7e]*$/', $v) ? $v : '=?UTF-8?B?' . base64_encode($v) . '?='; }
function m_b64($v) { return rtrim(chunk_split(base64_encode($v), 76, "\r\n")); }
function m_esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function m_domain($addr) { $p = strrpos($addr, '@'); return $p === false ? 'flyfirstclass.com' : substr($addr, $p + 1); }
function m_body($bnd, $text, $html) {
    return implode("\r\n", ['--' . $bnd, 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64', '', m_b64($text),
        '--' . $bnd, 'Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: base64', '', m_b64($html), '--' . $bnd . '--', '']);
}

// A small SMTP client (no libraries): implicit TLS (465), STARTTLS (587) or plain, with AUTH PLAIN.
function smtp_read($s) {
    $out = '';
    while (($l = fgets($s, 2048)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; }
    if ($out === '') { $m = stream_get_meta_data($s); throw fail($m['timed_out'] ? 'The mail server timed out' : 'The mail server closed the connection', 502); }
    return $out;
}
function smtp_cmd($s, $line, $want) {
    if ($line !== null) fwrite($s, $line . "\r\n");
    $r = smtp_read($s);
    foreach ($want as $w) if (starts($r, $w)) return $r;
    $lines = explode("\n", trim($r));
    throw fail('The mail server said: ' . trim(end($lines)), 502);
}
function smtp_send($c, $from, $to, $msg) {
    $local = in_array($c['host'], ['localhost', '127.0.0.1', '::1'], true);
    $ssl = ['peer_name' => $c['host'], 'verify_peer' => $c['verify'] && !$local, 'verify_peer_name' => $c['verify'] && !$local, 'allow_self_signed' => !$c['verify'] || $local];
    $ctx = stream_context_create(['ssl' => $ssl]);
    $s = @stream_socket_client(($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'], $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw fail('Could not reach the mail server ' . $c['host'] . ':' . $c['port'] . ' (' . ($errstr ?: 'error ' . $errno) . ')', 502);
    stream_set_timeout($s, 30);
    try {
        smtp_cmd($s, null, ['220']);
        $ehlo = 'EHLO ' . m_domain($from);
        smtp_cmd($s, $ehlo, ['250']);
        if ($c['secure'] === 'starttls') {
            smtp_cmd($s, 'STARTTLS', ['220']);
            if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw fail('The mail server would not start a secure connection', 502);
            smtp_cmd($s, $ehlo, ['250']);
        }
        if ($c['user']) smtp_cmd($s, 'AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']), ['235']);
        smtp_cmd($s, 'MAIL FROM:<' . $from . '>', ['250']);
        smtp_cmd($s, 'RCPT TO:<' . $to . '>', ['250', '251']);
        smtp_cmd($s, 'DATA', ['354']);
        $msg = preg_replace('/\r?\n/', "\r\n", $msg);
        $msg = preg_replace('/\r\n\./', "\r\n..", $msg);
        if (isset($msg[0]) && $msg[0] === '.') $msg = '.' . $msg;
        fwrite($s, $msg . "\r\n.\r\n");
        smtp_cmd($s, null, ['250']);
        try { smtp_cmd($s, 'QUIT', ['221']); } catch (Throwable $e) { /* already closed */ }
    } finally { @fclose($s); }
}
// Every send attempt is remembered (last 20) so you can see in /api/status or /api/mail-check what the server did, and why a send failed.
function mail_note($ok, $info) {
    try { cupdate('mail.json', [], function (&$L) use ($ok, $info) { array_unshift($L, ['t' => now_ms(), 'ok' => $ok] + $info); $L = array_slice($L, 0, 20); }); } catch (Throwable $e) { /* the log must never stop an email */ }
}
function mail_recent($n = 5) {
    return array_map(function ($x) { return ['when' => et_text($x['t'] ?? 0), 'ok' => !empty($x['ok']), 'via' => $x['via'] ?? null, 'to' => $x['to'] ?? null, 'from' => $x['from'] ?? null, 'subject' => $x['subject'] ?? null, 'error' => $x['error'] ?? null]; }, array_slice(cload('mail.json', []), 0, $n));
}
function send_mail($m) {
    $cfg = mail_cfg(); $info = ['to' => $cfg['to'], 'from' => $cfg['from'], 'subject' => mb_substr((string)($m['subject'] ?? ''), 0, 120)];
    try { $via = send_mail_now($m); mail_note(true, ['via' => $via] + $info); return $via; }
    catch (Throwable $e) { mail_note(false, ['via' => $cfg['smtp'] ? 'smtp' : 'mail()', 'error' => mb_substr($e->getMessage(), 0, 300)] + $info); throw $e; }
}
function send_mail_now($m) {
    $cfg = mail_cfg(); $from = $cfg['from']; $to = $cfg['to'];
    $bnd = 'ffc_' . bin2hex(random_bytes(12)); $body = m_body($bnd, $m['text'], $m['html']);
    $ct = 'multipart/alternative; boundary="' . $bnd . '"';
    $fromHdr = 'From: Fly First Class Website <' . m_hdr($from) . '>';
    if ($cfg['smtp']) {
        $msg = implode("\r\n", array_filter([$fromHdr, 'To: ' . m_hdr($to), !empty($m['replyTo']) ? 'Reply-To: ' . m_hdr($m['replyTo']) : null, 'Subject: ' . m_word(m_hdr($m['subject'])),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' GMT', 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . m_domain($from) . '>', 'MIME-Version: 1.0', 'Content-Type: ' . $ct, ''], function ($x) { return $x !== null; })) . "\r\n" . $body;
        smtp_send($cfg['smtp'], $from, $to, $msg);
        return 'smtp';
    }
    $hdr = implode("\r\n", array_filter([$fromHdr, !empty($m['replyTo']) ? 'Reply-To: ' . m_hdr($m['replyTo']) : null, 'MIME-Version: 1.0', 'Content-Type: ' . $ct], function ($x) { return $x !== null; }));
    $extra = preg_match('/^[^\s@<>"\'`;|&$\\\\]+@[^\s@<>"\'`;|&$\\\\]+$/', $from) ? '-f' . $from : '';
    if (!function_exists('mail')) throw fail('Email is not available on this server (PHP mail() is disabled). Add SMTP_HOST to .env.', 503);
    if (!@mail($to, m_word(m_hdr($m['subject'])), $body, $hdr, $extra)) throw fail('The server could not hand the email to its mail system. Create the mailbox ' . $from . ' in cPanel, or add SMTP_HOST to .env.', 502);
    return 'mail()';
}

// ---------- The email itself ----------
function m_date($d) { if (!$d) return 'To be confirmed'; $t = strtotime($d . ' 00:00:00 UTC'); $mo = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'][(int)gmdate('n', $t) - 1]; return gmdate('j', $t) . ' ' . $mo . ' ' . gmdate('Y', $t); }
function m_win($a, $b) { return $a ? ($b && $b !== $a ? m_date($a) . ' to ' . m_date($b) : m_date($a)) : 'To be confirmed'; }
function m_env($e) {
    $e = is_array($e) ? $e : []; $f = function ($k, $n = 120) use ($e) { return qs($e[$k] ?? null, $n); };
    return ['browser' => $f('browser', 80), 'os' => $f('os', 80), 'ua' => $f('ua', 300), 'lang' => $f('lang', 80), 'platform' => $f('platform', 60), 'screen' => $f('screen', 60), 'viewport' => $f('viewport', 40), 'dpr' => $f('dpr', 10), 'colors' => $f('colors', 10), 'cores' => $f('cores', 10), 'memory' => $f('memory', 20), 'touch' => $f('touch', 10), 'tz' => $f('tz', 60), 'mobile' => $f('mobile', 10)];
}
function ip_location() {
    $ip = client_ip(); $priv = is_private_ip($ip);
    try {
        $r = http_one(C('geoip') . ($priv ? '' : $ip), ['Accept: application/json'], 5);
        $g = $r['status'] >= 200 && $r['status'] < 300 ? json_decode($r['body'], true) : [];
        if (is_array($g) && !empty($g['success'])) return ['ip' => $priv ? ($g['ip'] ?? '') . " (this server's public address, because you are on the same machine)" : ($g['ip'] ?? $ip), 'place' => implode(', ', array_filter([$g['city'] ?? '', $g['region'] ?? '', $g['country'] ?? '']))];
    } catch (Throwable $e) { /* location is optional */ }
    return ['ip' => $priv ? 'local' : $ip, 'place' => 'Not available'];
}
function quote_mail($p, $ref, $loc) {
    $cfg = mail_cfg();
    $KINDS = ['new' => ['New quote request', 'Quote request'], 'adv' => ['Booking enquiry', 'Booking enquiry'], 'chg' => ['Change request', 'Change request'], 'chk' => ['Check-in request', 'Check-in request']];
    $t = qTrip($p['trip'] ?? null); $c = qWho($p['contact'] ?? null); $msg = qm2($p['message'] ?? null);
    $k = $KINDS[$p['kind'] ?? ''] ?? $KINDS['new']; $consent = ($p['consent'] ?? null) === true; $env = $consent ? m_env($p['env'] ?? null) : null;
    $utc = gmdate('Y-m-d H:i:s') . ' UTC'; $local = qs($p['localTime'] ?? null, 80); $tz = qs($p['tz'] ?? null, 60);
    $name = implode(' ', array_filter([$c['first'], $c['middle'], $c['last']])) ?: 'Not given';
    $route = implode(' > ', array_filter(array_merge([$t['from'], $t['to']], $t['via']))) ?: 'To be confirmed';
    $pax = $t['adults'] ? $t['adults'] . ($t['adults'] === 1 ? ' adult' : ' adults') . ($t['children'] ? ', ' . $t['children'] . ($t['children'] === 1 ? ' child' : ' children') : '') : 'Not given';
    $req = array_merge([['Reference ID', $ref], ['Route', $route]], !empty($t['stops']) ? [['Stops', qStopsText($t['stops'])]] : [], [['Cabin', $t['cabin'] ?: 'Not given'], ['Trip type', $t['type'] ?: 'Not given'], ['Passengers', $pax], [$t['flex'] && $t['dep2'] ? 'Departure window' : 'Departing', m_win($t['dep'], $t['dep2'])]]);
    if ($t['ret'] || $t['type'] === 'Round-trip') $req[] = [$t['flex'] && $t['ret2'] ? 'Return window' : 'Returning', m_win($t['ret'], $t['ret2'])];
    $req[] = ['Flexible dates', $t['flex'] ? 'Yes' : 'No'];
    $rows = [['Request', $req], ['Contact', [['Name', $name], ['Email', $c['email'] ?: 'Not given'], ['Phone', $c['phone'] ?: 'Not given'], ['Newsletter', !empty($p['newsletter']) ? 'Yes, subscribe' : 'No']]],
        ['Message', [['', $msg ?: '(no message)']]], ['Sent', [["Visitor's time", $local ? $local . ($tz ? ' (' . $tz . ')' : '') : 'Not given'], ['Server time', $utc]]]];
    if ($env) {
        $hw = array_filter([$env['cores'] ? $env['cores'] . ' CPU cores' : '', $env['memory'] ? 'about ' . $env['memory'] . ' memory' : '', $env['screen'] ? 'screen ' . $env['screen'] . ($env['dpr'] ? ' at ' . $env['dpr'] . 'x' : '') : '', ($env['touch'] && $env['touch'] !== '0') ? $env['touch'] . ' touch points' : '', $env['mobile'] === 'true' ? 'mobile device' : '']);
        $rows[] = ["Visitor location and device (shared with the visitor's consent)", [['Location (from IP address)', $loc['place']], ['IP address', $loc['ip']], ['Browser', $env['browser'] ?: 'Not given'], ['Operating system', $env['os'] ?: 'Not given'], ['Hardware', $hw ? implode(', ', $hw) : 'Not given'], ['Viewport', $env['viewport'] ?: 'Not given'], ['Language', $env['lang'] ?: 'Not given'], ['Time zone', $env['tz'] ?: ($tz ?: 'Not given')], ['User agent', $env['ua'] ?: 'Not given']]];
    } else $rows[] = ['Visitor location and device', [['', 'Not shared: the visitor did not consent to optional data.']]];
    $rerouted = strtolower($cfg['to']) !== MAIL_INTENDED;
    $text = [strtoupper($k[0]) . '  ' . $ref];
    if ($rerouted) $text[] = '(Addressed to ' . MAIL_INTENDED . ', rerouted to ' . $cfg['to'] . ' for now)';
    $text[] = '';
    foreach ($rows as [$h, $kv]) { $text[] = strtoupper($h); foreach ($kv as [$a, $b]) $text[] = ($a ? $a . ': ' : '') . $b; $text[] = ''; }
    $text = implode("\n", $text);
    $sec = function ($r) {
        [$h, $kv] = $r; $o = '<tr><td colspan="2" style="padding:22px 0 6px;font:600 12px Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#8a6d2f;border-bottom:1px solid #e3e6eb">' . m_esc($h) . '</td></tr>';
        foreach ($kv as [$a, $b]) $o .= $a ? '<tr><td style="padding:7px 16px 7px 0;font:13px Arial,sans-serif;color:#59616f;vertical-align:top;white-space:nowrap">' . m_esc($a) . '</td><td style="padding:7px 0;font:14px Arial,sans-serif;color:#14181f;word-break:break-word">' . m_esc($b) . '</td></tr>'
            : '<tr><td colspan="2" style="padding:8px 0;font:14px/1.6 Arial,sans-serif;color:#14181f;white-space:pre-wrap;word-break:break-word">' . m_esc($b) . '</td></tr>';
        return $o;
    };
    $html = '<!doctype html><html><body style="margin:0;background:#f5f6f8"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#fff;border:1px solid #e3e6eb"><tr><td style="padding:26px 30px"><div style="font:600 12px Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#59616f">Fly First Class International</div><div style="font:300 26px Arial,sans-serif;color:#14181f;margin:6px 0 2px">' . m_esc($k[0]) . '</div><div style="font:600 14px Arial,sans-serif;color:#006564">' . m_esc($ref) . '</div>'
        . ($rerouted ? '<div style="font:12px Arial,sans-serif;color:#59616f;margin-top:8px">Addressed to ' . m_esc(MAIL_INTENDED) . ', rerouted to ' . m_esc($cfg['to']) . ' for now.</div>' : '')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px">' . implode('', array_map($sec, $rows)) . '</table></td></tr></table></td></tr></table></body></html>';
    $subject = $k[1] . ' ' . $ref . ': ' . $name . ($t['to'] ? ' (' . implode(' to ', array_map(function ($x) { return preg_replace('/\s*\(.*\)$/', '', $x); }, array_filter([$t['from'], $t['to']]))) . ')' : '');
    return ['subject' => $subject, 'replyTo' => preg_match('/^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/', $c['email']) ? $c['email'] : '', 'text' => $text, 'html' => $html];
}
