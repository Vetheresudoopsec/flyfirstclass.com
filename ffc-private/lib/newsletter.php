<?php
// newsletter.php: newsletter sign-ups. Same behaviour as the newsletter part of server.js.
//
// How it works
//   1. A visitor agrees to the newsletter on the site (the home page, the pop-up that can appear on any page, or the
//      tick-box on the quote form). The address is saved at once in cache/subscribers.json, so it is never lost.
//   2. The server then tells Mailchimp (PUT /lists/{list}/members/{md5 of the address}). By default Mailchimp emails the person
//      a confirmation link ("double opt-in"); they are on the list only after they click it.
//   3. If Mailchimp is slow, down or not set up yet, the address stays "waiting" and the cron job (every 15 minutes) tries again
//      with longer and longer pauses. Nothing is dropped.
//   4. Once Mailchimp has it, the address and name are wiped from this server. Only a small stamp stays (no address) for 90 days
//      so the counts in /api/status make sense. Mailchimp is the one place the list lives.
//
// Someone who unsubscribed in Mailchimp is never re-added by the website (the call only sets a status for NEW contacts).
//
// .env settings:
//   MAILCHIMP_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21     (Mailchimp > Profile > Extras > API keys; the part after the dash is your data centre)
//   MAILCHIMP_LIST_ID=a1b2c3d4e5                                  (Audience > Settings > Audience name and defaults > Audience ID)
//   MAILCHIMP_DOUBLE_OPTIN=on          "off" subscribes people straight away without the confirmation email (not recommended)
//   MAILCHIMP_TAG=Website newsletter   tag added to everyone who joins here (also "<tag> - home", "<tag> - about" ... for the page they used); "none" = no tags
//   MAILCHIMP_BASE=                    for testing only (a stand-in Mailchimp address); leave empty
if (!defined('FFC_PRIVATE')) { exit; }

const NL_FILE = 'subscribers.json';
const NL_KEEP_DONE = 90 * 86400000;        // a finished entry (no address) is kept this long, for the counts
const NL_KEEP_OPEN = 183 * 86400000;       // an address Mailchimp still has not accepted is held at most this long
const NL_TRIES = 12;                       // failed attempts before an address is given up on (about a week of retries)

function nl_clean_email($v) {
    $e = strtolower(trim(is_string($v) ? $v : ''));
    return (strlen($e) <= 254 && preg_match('/^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/', $e)) ? $e : '';
}
function nl_hash($e) { return md5(strtolower($e)); }                      // also Mailchimp's own id for a contact
function nl_src($v) { $v = strtolower(is_string($v) ? $v : ''); return in_array($v, ['home', 'about', 'services', 'listings', 'contact', 'privacy', 'terms', 'thanks', 'quote'], true) ? $v : 'other'; }

function nl_cfg() {
    $key = trim(env('MAILCHIMP_API_KEY')); $list = trim(env('MAILCHIMP_LIST_ID')); $base = rtrim(trim(env('MAILCHIMP_BASE')), '/');
    if ($base === '' && preg_match('/-([a-z]+[0-9]+)$/i', $key, $m)) $base = 'https://' . strtolower($m[1]) . '.api.mailchimp.com';
    return ['ok' => $key !== '' && $list !== '' && $base !== '', 'key' => $key, 'list' => $list, 'base' => $base,
        'double' => strtolower(trim(env('MAILCHIMP_DOUBLE_OPTIN', 'on'))) !== 'off', 'tag' => strtolower(trim(env('MAILCHIMP_TAG'))) === 'none' ? '' : (trim(env('MAILCHIMP_TAG')) ?: 'Website newsletter')];
}

// Saves an address as "waiting". Returns its id when it needs sending to Mailchimp, or '' when there is nothing to do
// (already waiting, already sent, or refused by Mailchimp earlier).
function nl_add($email, $first, $src) {
    $h = nl_hash($email);
    $go = cupdate(NL_FILE, [], function (&$S) use ($h, $email, $first, $src) {
        $r = $S[$h] ?? null;
        if ($r && ($r['st'] ?? '') !== 'failed') return false;
        $S[$h] = ['e' => $email, 'f' => $first, 'src' => $src, 'at' => now_ms(), 'st' => 'queued', 'n' => 0, 'next' => 0, 'err' => ''];
        return true;
    });
    return $go ? $h : '';
}
function nl_from_quote($c) {      // the tick-box on the quote form
    $e = nl_clean_email($c['email'] ?? ''); if ($e === '') return '';
    return nl_add($e, qs($c['first'] ?? '', 80), 'quote');
}

// One call to Mailchimp. Returns ['r' => done | skip | fail | retry | config, 'msg' => text, 'pause' => true when it is better to stop for now]
function nl_push($r, $cfg) {
    $tags = [];
    if ($cfg['tag'] !== '') { $tags[] = $cfg['tag']; $tags[] = $cfg['tag'] . ' - ' . $r['src']; }
    $body = ['email_address' => $r['e'], 'status_if_new' => $cfg['double'] ? 'pending' : 'subscribed'];
    if (($r['f'] ?? '') !== '') $body['merge_fields'] = ['FNAME' => $r['f']];
    if ($tags) $body['tags'] = $tags;
    $url = $cfg['base'] . '/3.0/lists/' . rawurlencode($cfg['list']) . '/members/' . nl_hash($r['e']);
    $res = http_one($url, ['Authorization: Basic ' . base64_encode('flyfirstclass:' . $cfg['key']), 'Content-Type: application/json', 'Accept: application/json'], 12, 'PUT', json_encode($body, JSON_UNESCAPED_UNICODE));
    $st = (int)($res['status'] ?? 0);
    $j = json_decode((string)($res['body'] ?? ''), true);
    if ($st >= 200 && $st < 300) {       // success: also note what Mailchimp says about the contact (a status word and where it came from, never the address)
        $ms = is_array($j) ? strtolower((string)($j['status'] ?? '')) : ''; $mx = is_array($j) ? (string)($j['source'] ?? '') : '';
        return ['r' => 'done', 'ms' => preg_match('/^[a-z_]{3,20}$/', $ms) ? $ms : 'other', 'mx' => mb_substr(preg_replace('/[^A-Za-z0-9 ()._-]/', '', $mx), 0, 40)];
    }
    $title = is_array($j) ? (string)($j['title'] ?? '') : ''; $det = is_array($j) ? (string)($j['detail'] ?? '') : '';
    $msg = mb_substr(trim('Mailchimp ' . $st . ($title !== '' ? ': ' . $title : '') . ($det !== '' ? ' (' . $det . ')' : '')), 0, 240);
    if ($st === 0) return ['r' => 'retry', 'msg' => 'Could not reach Mailchimp: ' . ($res['error'] ?? 'no reply'), 'pause' => true];
    if ($st === 401 || $st === 403) return ['r' => 'config', 'msg' => 'Mailchimp rejected the API key (' . $st . '). Check MAILCHIMP_API_KEY.', 'pause' => true];
    if ($st === 404) return ['r' => 'config', 'msg' => 'Mailchimp could not find the audience. Check MAILCHIMP_LIST_ID.', 'pause' => true];
    if ($st === 429 || $st >= 500) return ['r' => 'retry', 'msg' => $msg, 'pause' => true];
    if ($st === 400 && preg_match('/forgotten|compliance|fake|invalid/i', $title . ' ' . $det)) return ['r' => 'skip', 'msg' => $msg];
    return ['r' => 'fail', 'msg' => $msg];
}
function nl_delay($n) { $d = [300, 1800, 7200, 21600, 43200]; return $n >= 1 && $n <= count($d) ? $d[$n - 1] : 86400; }   // seconds to wait after the nth failure: 5 min, 30 min, 2 h, 6 h, 12 h, then daily
function nl_apply(&$r, $res) {
    $n = now_ms();
    switch ($res['r']) {
        case 'done': $r = ['st' => 'synced', 'src' => $r['src'], 'at' => $r['at'], 'sent' => $n, 'ms' => $res['ms'] ?? '', 'mx' => $res['mx'] ?? '']; break;   // address and name are wiped; Mailchimp's status word stays
        case 'skip': $r = ['st' => 'skipped', 'src' => $r['src'], 'at' => $r['at'], 'sent' => $n, 'err' => $res['msg'], 'eat' => $n]; break;
        case 'fail': $r['st'] = 'failed'; $r['err'] = $res['msg']; $r['eat'] = $n; break;
        case 'config': $r['err'] = $res['msg']; $r['eat'] = $n; $r['next'] = $n + 3600000; break;                                       // a setup problem is not the address's fault: no strike
        default:
            $r['n'] = ($r['n'] ?? 0) + 1; $r['err'] = $res['msg'] ?? ''; $r['eat'] = $n;
            if ($r['n'] >= NL_TRIES) $r['st'] = 'failed'; else $r['next'] = $n + nl_delay($r['n']) * 1000;
    }
}
// Sends one waiting address now. Returns the result of nl_push(), or ['r' => 'off'] / ['r' => 'none'].
function nl_sync_one($h, $cfg = null) {
    $cfg = $cfg ?: nl_cfg();
    if (!$cfg['ok']) return ['r' => 'off'];
    $S = cload(NL_FILE, []); $r = $S[$h] ?? null;
    if (!$r || ($r['st'] ?? '') !== 'queued' || empty($r['e'])) return ['r' => 'none'];
    $res = nl_push($r, $cfg);
    cupdate(NL_FILE, [], function (&$S) use ($h, $res) { if (isset($S[$h])) nl_apply($S[$h], $res); });
    if ($res['r'] !== 'done') logm('[newsletter] ' . $res['r'] . ': ' . ($res['msg'] ?? ''));
    elseif (!in_array($res['ms'] ?? '', ['subscribed', 'pending'], true)) logm('[newsletter] Mailchimp accepted a sign-up but the contact is "' . ($res['ms'] ?? '?') . '" there (source: ' . (($res['mx'] ?? '') ?: 'unknown') . '). The website never changes an existing contact.');
    return $res;
}
function nl_purge() {
    return cupdate(NL_FILE, [], function (&$S) {
        $n = now_ms(); $c = 0;
        foreach ($S as $h => $r) {
            $st = $r['st'] ?? ''; $age = $n - ($r['at'] ?? 0);
            if ((in_array($st, ['synced', 'skipped'], true) && $age > NL_KEEP_DONE) || (in_array($st, ['queued', 'failed'], true) && $age > NL_KEEP_OPEN)) { unset($S[$h]); $c++; }
        }
        return $c;
    });
}
// The scheduled job: sends every waiting address that is due, oldest first.
function nl_run($max = 25, $secs = 45) {
    $out = ['configured' => false, 'tried' => 0, 'sent' => 0, 'purged' => nl_purge()];
    $cfg = nl_cfg();
    if (!$cfg['ok']) { $out['note'] = 'Mailchimp is not set up yet, sign-ups are being held'; return $out; }
    $out['configured'] = true; $t0 = microtime(true); $n = now_ms(); $due = [];
    foreach (cload(NL_FILE, []) as $h => $r) if (($r['st'] ?? '') === 'queued' && ($r['next'] ?? 0) <= $n && !empty($r['e'])) $due[$h] = $r['at'] ?? 0;
    asort($due);
    foreach (array_keys($due) as $h) {
        if ($out['tried'] >= $max || microtime(true) - $t0 > $secs) break;
        $res = nl_sync_one($h, $cfg); $out['tried']++;
        if ($res['r'] === 'done') $out['sent']++;
        if (!empty($res['pause'])) { $out['stoppedBecause'] = $res['msg'] ?? ''; break; }
    }
    return $out;
}
function nl_status() {
    $cfg = nl_cfg(); $c = ['queued' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0]; $old = 0; $last = null;
    foreach (cload(NL_FILE, []) as $r) {
        $st = $r['st'] ?? ''; if (isset($c[$st])) $c[$st]++;
        if ($st === 'queued') $old = $old ? min($old, $r['at']) : $r['at'];
        if (!empty($r['err']) && (!$last || ($r['eat'] ?? 0) > $last['t'])) $last = ['t' => $r['eat'] ?? 0, 'msg' => $r['err']];
    }
    $ans = []; $from = [];
    foreach (cload(NL_FILE, []) as $r) {
        if (($r['st'] ?? '') !== 'synced') continue;
        $k = ($r['ms'] ?? '') !== '' ? $r['ms'] : 'not recorded (sent before this was added)'; $ans[$k] = ($ans[$k] ?? 0) + 1;
        if (($r['mx'] ?? '') !== '') $from[$r['mx']] = ($from[$r['mx']] ?? 0) + 1;
    }
    arsort($ans); arsort($from);
    return ['mailchimp' => $cfg['ok'] ? 'on' : 'not set up (sign-ups are held until MAILCHIMP_API_KEY and MAILCHIMP_LIST_ID are in .env)',
        'confirmation' => $cfg['double'] ? 'double opt-in (Mailchimp emails each new subscriber a confirmation link)' : 'single opt-in (subscribed straight away)',
        'waiting' => $c['queued'], 'sentToMailchimp' => $c['synced'],
        'mailchimpAnswered' => (object)$ans, 'mailchimpSource' => (object)$from,
        'refusedByMailchimp' => $c['skipped'], 'gaveUp' => $c['failed'],
        'oldestWaiting' => $old ? et_text($old) : null, 'lastProblem' => $last ? ['msg' => $last['msg'], 'when' => et_text($last['t'])] : null,
        'howToRead' => 'sentToMailchimp = Mailchimp accepted the request. mailchimpAnswered = what Mailchimp then said about each contact: subscribed = on the list now; pending = waiting for a confirmation click; unsubscribed, cleaned or archived = the address was already in the audience in that state and the website never changes it. mailchimpSource shows where Mailchimp says the contact came from: a sign-up the website just created shows as an API source, an older contact shows its original source.'];
}
// A CSV (Mailchimp's import format) of the addresses Mailchimp does not have yet: use it if you ever need to add them by hand.
function nl_export() {
    $q = function ($v) { $v = (string)$v; if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; };
    $cfg = nl_cfg(); $rows = ["Email Address,First Name,Tags,Signed up (UTC),Page"];
    foreach (cload(NL_FILE, []) as $r) {
        if (!in_array($r['st'] ?? '', ['queued', 'failed'], true) || empty($r['e'])) continue;
        $rows[] = implode(',', [$q($r['e']), $q($r['f'] ?? ''), $q($cfg['tag'] !== '' ? $cfg['tag'] . ',' . $cfg['tag'] . ' - ' . $r['src'] : ''), $q(gmdate('Y-m-d H:i', intdiv($r['at'], 1000))), $q($r['src'])]);
    }
    http_response_code(200); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="newsletter-waiting.csv"'); header('Cache-Control: no-store');
    echo implode("\r\n", $rows), "\r\n"; exit;
}

// POST /api/subscribe: the body is {email, source, consent: true, hp, ft}. Returns [status, reply, id to send or '']
function nl_signup($b) {
    if (($b['consent'] ?? null) !== true) return [400, ['error' => 'Please agree to receive the newsletter to subscribe.'], ''];
    $e = nl_clean_email($b['email'] ?? null);
    if ($e === '') return [400, ['error' => 'Please enter a valid email address.'], ''];
    $cfg = nl_cfg();
    $h = nl_add($e, qs($b['first'] ?? null, 80), nl_src($b['source'] ?? null));
    // The reply never says whether the address was already known (so the form cannot be used to check who is on the list).
    return [200, ['ok' => true, 'confirm' => $cfg['ok'] && $cfg['double']], $h];
}
