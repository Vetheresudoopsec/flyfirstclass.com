<?php
// history.php: "Request History" on the Manage Booking page.
//
// A visitor asks for the requests linked to an email address or a phone number. Anyone can type any email or phone number into a quote
// form, so a matching address is NOT proof that the person asking owns it. Nothing is shown until the visitor proves they can read the
// mailbox:
//   1. POST /api/history {act:"send", q}    q is an email address or a phone number. If requests exist for it, a 6-digit code is emailed to
//                                           the email address(es) on those requests (up to 3). The reply is always the same, so it never
//                                           tells a stranger whether an address or number is known.
//   2. POST /api/history {act:"check", q, code}   the right code unlocks the requests that carry THAT email address, and returns a signed
//                                           30-minute session so the page can show them again without another code.
//   3. POST /api/history {act:"list", tok}  the same list again, from the session.
// A phone number is only used to find the mailbox that gets the code. Requests are never matched or listed by phone alone, because a phone
// number cannot be proven from here (there is no text-message service), and someone could put another person's number on their own request.
//
// Limits: 6 code requests an hour per visitor address, 3 an hour per email or phone number, 300 an hour overall; a code works for 15 minutes,
// once, and 6 wrong tries cancel it.
//
// Setting (.env)
//   REQUEST_HISTORY=off     turns the feature off (default: on)
if (!defined('FFC_PRIVATE')) { exit; }

const HS_TTL = 900000;              // a code works for 15 minutes
const HS_SESSION = 1800;            // a verified session lasts 30 minutes
const HS_TRIES = 6;
const HS_MAX = 25;                  // requests listed

function hs_on() { return !in_array(strtolower(trim(env('REQUEST_HISTORY', 'on'))), ['off', 'no', 'false', '0'], true); }

// What the visitor typed: ['kind' => 'email', 'val' => 'a@b.com'] | ['kind' => 'phone', 'val' => '85291234567'] | null
function hs_parse($q) {
    $q = trim((string)$q);
    if ($q === '' || strlen($q) > 254) return null;
    if (strpos($q, '@') !== false) { $e = cm_addr($q); return $e === '' ? null : ['kind' => 'email', 'val' => strtolower($e)]; }
    if (!preg_match('/^[+()\d\s.\-]+$/', $q)) return null;
    $d = preg_replace('/\D/', '', $q); $d = preg_replace('/^00/', '', $d);
    return strlen($d) >= 7 && strlen($d) <= 15 ? ['kind' => 'phone', 'val' => $d] : null;
}
function hs_same_phone($a, $b) {      // the same number written two ways ("+1 212 221 1860" and "2122211860"): one is the end of the other
    $a = preg_replace('/^00/', '', preg_replace('/\D/', '', (string)$a)); $b = preg_replace('/^00/', '', preg_replace('/\D/', '', (string)$b));
    if ($a === '' || $b === '') return false;
    $s = strlen($a) <= strlen($b) ? $a : $b; $l = $s === $a ? $b : $a;
    return strlen($s) >= 7 && substr($l, -strlen($s)) === $s;
}
function hs_live() { $QR = cload('requests.json', []); $n = now_ms(); return array_filter($QR, function ($r) use ($n) { return $r && ($r['exp'] ?? 0) > $n; }); }

// The email addresses that should get a code for this lookup.
function hs_mailboxes($p) {
    $out = [];
    foreach (hs_live() as $r) {
        $c = $r['contact'] ?? []; $e = strtolower(cm_addr($c['email'] ?? ''));
        if ($e === '') continue;
        if ($p['kind'] === 'email' ? $e === $p['val'] : hs_same_phone($c['phone'] ?? '', $p['val'])) $out[$e] = 1;
    }
    return array_slice(array_keys($out), 0, 3);
}
function hs_hash($code, $email) { return hash_hmac('sha256', 'code|' . $code . '|' . strtolower($email), guard_secret()); }
function hs_key($p) { return $p['kind'] . ':' . $p['val']; }

// The requests that carry this email address, newest first. Only a summary of the trip: the reference ID is what opens one.
function hs_items($email) {
    $rows = [];
    foreach (hs_live() as $r) {
        $c = $r['contact'] ?? [];
        if (strtolower(trim((string)($c['email'] ?? ''))) !== strtolower($email)) continue;
        $t = $r['trip'] ?? [];
        $rows[] = ['ref' => $r['ref'], 'created' => $r['created'] ?? 0, 'updated' => $r['updated'] ?? 0, 'exp' => $r['exp'] ?? 0,
            'trip' => ['from' => $t['from'] ?? '', 'to' => $t['to'] ?? '', 'via' => $t['via'] ?? [], 'type' => $t['type'] ?? '', 'cabin' => $t['cabin'] ?? '',
                'dep' => $t['dep'] ?? '', 'ret' => $t['ret'] ?? '', 'adults' => $t['adults'] ?? 0, 'children' => $t['children'] ?? 0]];
    }
    usort($rows, function ($a, $b) { return $b['created'] <=> $a['created']; });
    return array_slice($rows, 0, HS_MAX);
}

// The signed session: base64url(json) . "." . base64url(HMAC). It names the email address that was proven and when it stops working.
function hs_token($email) {
    $p = b64u(json_encode(['k' => 'hist', 'e' => strtolower($email), 'x' => time() + HS_SESSION]));
    return $p . '.' . b64u(hash_hmac('sha256', 'hist|' . $p, guard_secret(), true));
}
function hs_token_email($t) {
    $x = explode('.', (string)$t);
    if (count($x) !== 2 || !hash_equals(b64u(hash_hmac('sha256', 'hist|' . $x[0], guard_secret(), true)), $x[1])) return '';
    $j = json_decode((string)b64u_d($x[0]), true);
    return is_array($j) && ($j['k'] ?? '') === 'hist' && ($j['x'] ?? 0) >= time() ? (string)($j['e'] ?? '') : '';
}

// ---------- The email with the code ----------
function hs_mail($code, $to) {
    $F = "Manrope,'Helvetica Neue',Helvetica,Arial,sans-serif";
    $subject = $code . ' is your Fly First Class code';
    $text = implode("\n", ['Your code is ' . $code, '', 'Enter it on the Manage Booking page to see the requests linked to this email address. It works once and expires in 15 minutes.', '',
        'If you did not ask for it, you can ignore this email. Nobody can see your requests without the code.', '', 'Questions? Call ' . CM_PHONE . ' or email ' . MAIL_INTENDED . '.', '', CM_SITE]);
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only"><title>' . m_esc($subject) . '</title>'
        . '<style>@media (max-width:540px){.out{padding:16px 10px!important}.pad{padding:30px 22px!important}.big{font-size:34px!important;letter-spacing:.2em!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#efece6">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#efece6">Your code is ' . m_esc($code) . '. It expires in 15 minutes.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#efece6"><tr><td class="out" align="center" style="padding:40px 20px">'
        . '<table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:520px;background:#fcfaf6;border:1px solid #e6dfcf"><tr><td class="pad" align="center" style="padding:38px 40px 28px;font-family:' . $F . ';color:#14181f">'
        . '<img src="' . CM_SITE . '/email-logo.png" width="26" height="26" alt="" style="display:block;border:0;margin:0 auto">'
        . '<div style="padding:12px 0 0;font:600 11px/1.3 ' . $F . ';letter-spacing:.32em;text-transform:uppercase;color:#6e5624">Fly First Class</div>'
        . '<div style="width:40px;height:1px;line-height:1px;font-size:1px;background:#c9b27c;margin:12px auto 0">&nbsp;</div>'
        . '<h1 style="margin:26px 0 0;font:300 26px/1.25 ' . $F . ';letter-spacing:-.01em;color:#14181f">Your code to see your requests</h1>'
        . '<p style="margin:8px 0 0;font:400 15px/1.6 ' . $F . ';color:#59616f">Enter this on the Manage Booking page.</p>'
        . '<div class="big" style="margin:24px 0 0;padding:18px 12px;background:#ffffff;border:1px solid #ece6d8;border-radius:10px;font:600 40px/1 ' . $F . ';letter-spacing:.28em;text-indent:.28em;color:#14181f">' . m_esc($code) . '</div>'
        . '<p style="margin:18px 0 0;font:400 13px/1.6 ' . $F . ';color:#59616f">It works once and expires in 15 minutes.<br>If you did not ask for it, ignore this email. Nobody can see your requests without the code.</p>'
        . '<div style="margin:26px 0 0;padding:16px 0 0;border-top:1px solid #ece6d8;font:400 12px/1.6 ' . $F . ';color:#59616f">Questions? Call <a href="tel:' . CM_TEL . '" style="color:#59616f">' . m_esc(CM_PHONE) . '</a> or email <a href="mailto:' . MAIL_INTENDED . '" style="color:#59616f">' . m_esc(MAIL_INTENDED) . '</a><br>'
        . '<a href="' . CM_SITE . '" style="color:#59616f;text-decoration:none">flyfirstclass.com</a></div>'
        . '</td></tr></table></td></tr></table></body></html>';
    return ['to' => $to, 'fromName' => CM_NAME, 'subject' => $subject, 'replyTo' => MAIL_INTENDED, 'headers' => ['Auto-Submitted: auto-generated'], 'text' => $text, 'html' => $html];
}

// ---------- Steps ----------
// Step 1. Returns [the reply, the mails to send once the reply is out].
function hs_start($b) {
    $p = hs_parse($b['q'] ?? '');
    if (!$p) throw fail('Enter the email address or phone number you used for your request.', 400);
    $ip = client_ip(); $k = hs_key($p);
    if (!limit_hit($ip . ':hist', 10, 3600000) || !limit_hit('hist:' . md5($k), 3, 3600000) || !limit_hit('hist:all', 300, 3600000)) throw fail('Too many tries. Please wait a while, or call us.', 429);
    $reply = ['ok' => true, 'sent' => true];                    // the same answer whether or not anything matched
    // A mailbox gets at most 3 codes an hour however it was found (by email, or by the phone number written different ways).
    // Over the cap it is skipped quietly, so the reply still says nothing about what exists.
    $boxes = array_values(array_filter(hs_mailboxes($p), function ($e) { return limit_hit('hist:to:' . md5($e), 3, 3600000); }));
    if (!$boxes) return [$reply, []];
    $mails = []; $entries = [];
    foreach ($boxes as $e) {
        $code = sprintf('%06d', random_int(0, 999999));
        $entries[] = ['e' => $e, 'h' => hs_hash($code, $e)];
        $mails[] = hs_mail($code, $e);
    }
    cupdate('hist.json', [], function (&$H) use ($k, $entries) {
        $n = now_ms();
        foreach ($H as $x => $v) if ($n - ($v['t'] ?? 0) > HS_TTL * 2) unset($H[$x]);
        $H[$k] = ['t' => $n, 'n' => 0, 'c' => $entries];          // a new request replaces any code sent before
    });
    return [$reply, $mails];
}
// Step 2. Returns the reply (a wrong code throws a 400 with a plain message).
function hs_check($b) {
    $p = hs_parse($b['q'] ?? ''); $code = preg_replace('/\D/', '', (string)($b['code'] ?? ''));
    if (!$p || strlen($code) !== 6) throw fail('Enter the 6-digit code from the email.', 400);
    if (!limit_hit(client_ip() . ':histc', 30, 3600000)) throw fail('Too many tries. Please wait a while, or call us.', 429);
    $k = hs_key($p);
    $res = cupdate('hist.json', [], function (&$H) use ($k, $code) {
        $v = $H[$k] ?? null;
        if (!$v || now_ms() - ($v['t'] ?? 0) > HS_TTL) { unset($H[$k]); return 'expired'; }
        foreach ($v['c'] as $c) {
            if (hash_equals($c['h'], hs_hash($code, $c['e']))) { unset($H[$k]); return ['email' => $c['e']]; }
        }
        $H[$k]['n'] = ($v['n'] ?? 0) + 1;
        if ($H[$k]['n'] >= HS_TRIES) { unset($H[$k]); return 'locked'; }
        return 'wrong';
    });
    if ($res === 'expired') throw fail('That code has expired. Ask for a new one.', 400);
    if ($res === 'locked') throw fail('Too many wrong tries. Ask for a new code.', 400);
    if ($res === 'wrong') throw fail('That code is not right. Check the email and try again.', 400);
    logm('[history] unlocked for ' . m_mask($res['email']));
    return ['ok' => true, 'tok' => hs_token($res['email']), 'items' => hs_items($res['email']), 'masked' => m_mask($res['email'])];
}
// Step 3.
function hs_list($b) {
    $e = hs_token_email($b['tok'] ?? '');
    if ($e === '') throw fail('Please ask for a new code.', 401);
    return ['ok' => true, 'items' => hs_items($e), 'masked' => m_mask($e)];
}
