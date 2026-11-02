<?php
// trips.php: the private "Completed trips" page, and the thank-you email that asks Trustpilot to invite the customer.
//
// What it does
//   You add a customer once their trip is booked: name, email, and the date they get back. A few days after that date the server
//   emails them a short thank-you and, in the same email, copies Trustpilot's Automatic Feedback Service address (Bcc). Trustpilot then
//   sends the customer its own review invitation. Nothing is sent before the return date, nobody is picked or skipped by how they
//   feel, and each trip is invited once.
//
// It also has a "Booking requests" section: for quotes done outside the website (phone, email, a Mailchimp reply) you create a request that gets the
//   same kind of reference ID as one sent through the site (FFC-XXXXXXXX), so the customer can look it up on Manage Booking, and the ID can be
//   typed in when the trip is added (Trustpilot then receives it as the reference ID of the invitation).
//
// Where it lives
//   https://yourdomain.com/api/trips : sign in with the ADMIN_KEY from .env (the page keeps you signed in for 8 hours with a cookie).
//   The page has no scripts and is never indexed (robots.txt, a noindex header and a no-store header).
//
// Privacy
//   The customer's name and email are kept on the server only until the thank-you email has gone out, then deleted. What stays for 90 days
//   is a stamp without the address (initials, booking reference, return date, send time) plus a scrambled (keyed hash) copy of the address so the same trip
//   cannot be added twice. A trip that could not be sent is dropped 45 days after its send date.
//
// .env settings
//   TRUSTPILOT_BCC=yourdomain.com+abcdef1234@invite.trustpilot.com   Trustpilot > Get reviews > Invitation methods > Automatic Invitations (AFS) > Copy email address.
//                                   Without it the email carries the plain Trustpilot review link instead (reviews are then not the "invited" kind).
//   TRUSTPILOT_DAYS=3               days after the return date the email goes out (0 to 60), at 10:00 AM Eastern
//   TRUSTPILOT_MONTHLY_CAP=50       invitations per calendar month before sending pauses until next month (Trustpilot Free allows 50; 0 = no limit)
//   TRUSTPILOT_LINK=                the plain review link used when TRUSTPILOT_BCC is not set (default https://www.trustpilot.com/evaluate/flyfirstclass.com)
//   TRIPS=off                       turns the page and the sending off
if (!defined('FFC_PRIVATE')) { exit; }

const TR_FILE = 'trips.json';
const TR_KEEP_SENT = 90 * 86400000;        // a sent entry (no address) is kept this long
const TR_KEEP_OPEN = 45 * 86400000;        // a trip still unsent this long after its send date is dropped, address and all
const TR_TRIES = 4;                        // failed attempts before a trip is marked "needs attention"
const TR_STALE = 900000;                   // a send that has been "in progress" for 15 minutes was interrupted
const TR_PAGE = '/api/trips';
const TR_REF = '/^[A-Z0-9][A-Z0-9._\/-]{2,29}$/';       // a booking reference: our FFC-XXXXXXXX IDs, or any booking number you use
const TR_COOKIE = 'ffc_adm';
const TR_SESSION = 8 * 3600;               // seconds a sign-in lasts
const TR_BACKOFF = [3600000, 21600000, 86400000];     // wait after the 1st, 2nd and 3rd failed attempt: 1 hour, 6 hours, 1 day

function tr_on() { return !in_array(strtolower(trim(env('TRIPS', 'on'))), ['off', 'no', 'false', '0'], true); }
function tr_cfg() {
    $bcc = cm_addr(env('TRUSTPILOT_BCC'));
    $link = trim(env('TRUSTPILOT_LINK', 'https://www.trustpilot.com/evaluate/flyfirstclass.com'));
    if (!preg_match('~^https://[^\s"<>]+$~', $link)) $link = 'https://www.trustpilot.com/evaluate/flyfirstclass.com';
    return ['bcc' => $bcc, 'mode' => $bcc !== '' ? 'bcc' : 'link', 'link' => $link,
        'days' => max(0, min(60, (int)env('TRUSTPILOT_DAYS', 3))), 'cap' => max(0, (int)env('TRUSTPILOT_MONTHLY_CAP', 50))];
}
function tr_eh($e) { return substr(hash_hmac('sha256', strtolower(trim($e)), guard_secret()), 0, 24); }      // a keyed hash: finds a repeat, cannot be turned back into the address
function tr_today() { return (new DateTime('now', et_tz()))->format('Y-m-d'); }
function tr_due($ret, $days) { $d = new DateTime($ret . ' 10:00:00', et_tz()); if ($days) $d->modify('+' . (int)$days . ' days'); return $d->getTimestamp() * 1000; }
function tr_day($ms) { return (new DateTime('@' . intdiv((int)$ms, 1000)))->setTimezone(et_tz())->format('j M Y'); }
function tr_initials($name) {
    $o = ''; foreach (array_slice(preg_split('/\s+/u', trim($name)), 0, 2) as $w) if ($w !== '') $o .= mb_strtoupper(mb_substr($w, 0, 1)) . '.';
    return $o !== '' ? $o : '?';
}
function tr_month_start() { return (new DateTime('first day of this month 00:00:00', et_tz()))->getTimestamp() * 1000; }
function tr_used() {         // invitations sent (or being sent) this calendar month
    $n = 0; $m = tr_month_start();
    foreach (cload(TR_FILE, []) as $r) if ((($r['st'] ?? '') === 'sent' && ($r['sent'] ?? 0) >= $m) || ($r['st'] ?? '') === 'sending') $n++;
    return $n;
}
function tr_room($cfg) { return $cfg['cap'] === 0 ? PHP_INT_MAX : $cfg['cap'] - tr_used(); }

// ---------- Storage ----------
// Adds a trip. Returns ['ok' => true, 'id' => ...] or ['ok' => false, 'code' => ..., 'msg' => ...]. code 'recent' means the same address was invited lately:
// the page then asks you to confirm it is a different trip (then $force is true).
function tr_ref($v) { $v = strtoupper(trim((string)$v)); return preg_match(TR_REF, $v) ? $v : ''; }
function tr_add($name, $email, $ret, $force, $ref = '') {
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1f\x7f<>"\\\\]+/u', ' ', (string)$name))), 0, 80);
    $e = cm_addr($email); $ret = trim((string)$ret);
    $refIn = trim((string)$ref); $ref = tr_ref($refIn);
    if ($refIn !== '' && $ref === '') return ['ok' => false, 'code' => 'ref', 'msg' => 'The booking reference can use letters, numbers and dashes only (3 to 30 characters), for example FFC-7K2M9QXA.'];
    if ($name === '') return ['ok' => false, 'code' => 'name', 'msg' => "Enter the customer's name."];
    if ($e === '') return ['ok' => false, 'code' => 'email', 'msg' => 'That email address does not look right.'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ret, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return ['ok' => false, 'code' => 'date', 'msg' => 'Pick the date the customer gets back.'];
    $diff = (strtotime($ret . ' 12:00:00 UTC') - strtotime(tr_today() . ' 12:00:00 UTC')) / 86400;
    if ($diff < -90) return ['ok' => false, 'code' => 'range', 'msg' => 'That return date is more than 90 days ago, too long ago for an invitation.'];
    if ($diff > 500) return ['ok' => false, 'code' => 'range', 'msg' => 'That return date is more than 500 days away. Check the year.'];
    $cfg = tr_cfg(); $n = now_ms(); $eh = tr_eh($e); $id = bin2hex(random_bytes(4));
    $due = max(tr_due($ret, $cfg['days']), $n);
    return cupdate(TR_FILE, [], function (&$T) use ($name, $e, $ret, $force, $n, $eh, $id, $due, $ref) {
        $open = 0; foreach ($T as $r) if (in_array($r['st'] ?? '', ['queued', 'sending', 'failed'], true)) $open++;
        if ($open >= 300) return ['ok' => false, 'code' => 'full', 'msg' => 'There are already 300 trips waiting. Remove some first.'];
        foreach ($T as $r) {
            if (($r['eh'] ?? '') !== $eh) continue;
            if (($r['r'] ?? '') === $ret) return ['ok' => false, 'code' => 'dup', 'msg' => 'This customer is already listed for that return date, so they will not be invited twice.'];
            if (!$force && $n - max($r['sent'] ?? 0, $r['at'] ?? 0) < 30 * 86400000) return ['ok' => false, 'code' => 'recent', 'msg' => 'This email address was added in the last 30 days for another trip. If this is a different trip, tick the box and add it again.'];
        }
        $T[$id] = ['id' => $id, 'n' => $name, 'e' => $e, 'r' => $ret, 'due' => $due, 'at' => $n, 'st' => 'queued', 'tries' => 0, 'next' => 0, 'err' => '', 'eh' => $eh] + ($ref !== '' ? ['ref' => $ref] : []);
        return ['ok' => true, 'id' => $id];
    });
}
function tr_remove($id) {
    return cupdate(TR_FILE, [], function (&$T) use ($id) {
        if (!isset($T[$id]) || ($T[$id]['st'] ?? '') === 'sending') return false;
        if (($T[$id]['st'] ?? '') === 'sent') return false;          // the record of what was sent stays for its 90 days
        unset($T[$id]); return true;
    });
}
function tr_retry($id) {
    return cupdate(TR_FILE, [], function (&$T) use ($id) {
        if (empty($T[$id]) || ($T[$id]['st'] ?? '') !== 'failed' || empty($T[$id]['e'])) return false;
        $T[$id]['st'] = 'queued'; $T[$id]['tries'] = 0; $T[$id]['next'] = 0; $T[$id]['err'] = ''; return true;
    });
}
// Claims a trip for sending (so two overlapping runs, or a click and the cron job, never send it twice). Returns the record or null.
function tr_take($id, $force) {
    return cupdate(TR_FILE, [], function (&$T) use ($id, $force) {
        $r = $T[$id] ?? null; $n = now_ms();
        if (!$r || ($r['st'] ?? '') !== 'queued' || empty($r['e'])) return null;
        if (!$force && (($r['due'] ?? 0) > $n || ($r['next'] ?? 0) > $n)) return null;
        $T[$id]['st'] = 'sending'; $T[$id]['sa'] = $n;
        return $T[$id];
    });
}
function tr_finish($id, $ok, $mode, $err) {
    cupdate(TR_FILE, [], function (&$T) use ($id, $ok, $mode, $err) {
        if (!isset($T[$id])) return;
        $r = $T[$id]; $n = now_ms();
        if ($ok) {         // the name and address are deleted the moment the email has gone
            $T[$id] = ['id' => $id, 'i' => tr_initials($r['n'] ?? ''), 'r' => $r['r'] ?? '', 'st' => 'sent', 'sent' => $n, 'via' => $mode, 'eh' => $r['eh'] ?? '', 'at' => $r['at'] ?? $n] + (!empty($r['ref']) ? ['ref' => $r['ref']] : []);
            return;
        }
        $tries = ($r['tries'] ?? 0) + 1;
        $T[$id]['tries'] = $tries; $T[$id]['err'] = mb_substr((string)$err, 0, 300); $T[$id]['eat'] = $n; unset($T[$id]['sa']);
        if ($tries >= TR_TRIES) $T[$id]['st'] = 'failed';
        else { $T[$id]['st'] = 'queued'; $T[$id]['next'] = $n + TR_BACKOFF[min($tries, count(TR_BACKOFF)) - 1]; }
    });
}
function tr_purge() {
    return cupdate(TR_FILE, [], function (&$T) {
        $n = now_ms(); $c = 0;
        foreach ($T as $id => $r) {
            $st = $r['st'] ?? '';
            if ($st === 'sending' && $n - ($r['sa'] ?? 0) > TR_STALE) {        // the run died partway: do not guess, ask a person to look
                $T[$id]['st'] = 'failed'; $T[$id]['err'] = 'This send was interrupted. It may or may not have gone out: check Sent mail for the customer before trying again.'; unset($T[$id]['sa']);
            } elseif (($st === 'sent' && $n - ($r['sent'] ?? 0) > TR_KEEP_SENT) || (in_array($st, ['queued', 'failed'], true) && $n - ($r['due'] ?? $n) > TR_KEEP_OPEN)) { unset($T[$id]); $c++; }
        }
        return $c;
    });
}

// ---------- Sending ----------
// Sends one trip's email. Returns 'sent', 'failed' or 'skip' (not due, claimed by another run, or already done).
function tr_deliver($id, $force, $cfg) {
    $r = tr_take($id, $force);
    if (!$r) return 'skip';
    try { $via = send_mail(tr_mail($r['n'], $r['e'], $cfg, false, $r['ref'] ?? '')); }
    catch (Throwable $e) { tr_finish($id, false, '', $e->getMessage()); logm('[trips] ' . $id . ' not sent: ' . $e->getMessage()); return 'failed'; }
    try { tr_finish($id, true, $cfg['mode'], ''); } catch (Throwable $e) { logm('[trips] ' . $id . ' was sent but could not be recorded: ' . $e->getMessage()); }
    logm('[trips] ' . $id . ' thank-you email sent (' . $cfg['mode'] . ', ' . $via . ')');
    return 'sent';
}
// The scheduled job: sends every trip that is due, oldest first, up to the monthly limit.
function tr_run($max = 10, $secs = 40) {
    $out = ['sent' => 0, 'tried' => 0, 'purged' => tr_purge()];
    if (!tr_on()) { $out['note'] = 'off'; return $out; }
    $cfg = tr_cfg(); $t0 = microtime(true); $n = now_ms(); $due = [];
    foreach (cload(TR_FILE, []) as $id => $r) if (($r['st'] ?? '') === 'queued' && ($r['due'] ?? 0) <= $n && ($r['next'] ?? 0) <= $n && !empty($r['e'])) $due[$id] = $r['due'] ?? 0;
    asort($due);
    foreach (array_keys($due) as $id) {
        if ($out['tried'] >= $max || microtime(true) - $t0 > $secs) break;
        if (tr_room($cfg) < 1) { $out['held'] = 'monthly limit of ' . $cfg['cap'] . ' reached, the rest wait for next month'; break; }
        $res = tr_deliver($id, false, $cfg); if ($res === 'skip') continue;
        $out['tried']++; if ($res === 'sent') $out['sent']++;
    }
    return $out;
}
function trips_status() {
    $cfg = tr_cfg(); $c = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'sending' => 0]; $next = 0;
    foreach (cload(TR_FILE, []) as $r) {
        $st = $r['st'] ?? ''; if (isset($c[$st])) $c[$st]++;
        if ($st === 'queued' && (!$next || ($r['due'] ?? 0) < $next)) $next = $r['due'] ?? 0;
    }
    return ['page' => tr_on() ? 'on (/api/trips)' : 'off', 'method' => $cfg['mode'] === 'bcc' ? 'thank-you email with a Bcc to Trustpilot (Trustpilot sends the invitation)' : 'thank-you email with the plain Trustpilot review link (TRUSTPILOT_BCC is not set)',
        'sendsAfterReturnDays' => $cfg['days'], 'waiting' => $c['queued'], 'needAttention' => $c['failed'], 'sentRecently' => $c['sent'], 'sentThisMonth' => tr_used(), 'monthlyLimit' => $cfg['cap'] ?: 'none',
        'nextDue' => $next ? et_text($next) : null];
}

// ---------- The email ----------
// $name and $email may be blank for a preview. Returns a message for send_mail().
function tr_mail($name, $email, $cfg, $sample = false, $ref = '') {
    $F = "Manrope,'Helvetica Neue',Helvetica,Arial,sans-serif";
    $first = cm_name(preg_split('/\s+/u', trim((string)$name))[0] ?? '');
    $bcc = $cfg['mode'] === 'bcc';
    $title = 'Thank you for flying with us' . ($first !== '' ? ', ' . $first : '') . '.';
    $subject = ($sample ? '[SAMPLE] ' : '') . 'Thank you for flying with Fly First Class';
    $p1 = "We hope your trip went smoothly. It was a pleasure arranging your travel, and we would be glad to help with your next journey.";
    $p2 = $bcc ? "You may also receive a short email from Trustpilot, an independent review service, inviting you to share your experience. It is entirely optional, and an honest review of any kind is welcome."
        : "If you would like to share your experience, you can write a review on Trustpilot, an independent review service. It is entirely optional, and an honest review of any kind is welcome.";
    $p3 = "If anything about your trip was not as it should be, reply to this email or call us and we will do our best to put it right.";
    $tx = [$title, '', $p1, '', $p2];
    if (!$bcc) { $tx[] = $cfg['link']; }
    if ($ref !== '') { $tx[] = ''; $tx[] = 'Booking reference: ' . $ref; }
    $tx = array_merge($tx, ['', $p3, '', 'Call: ' . CM_PHONE, 'Email: ' . MAIL_INTENDED, CM_ADDRESS, '', CM_SITE, 'Privacy: ' . CM_SITE . '/privacy-policy/']);
    $cap = "font:600 10px/1.3 $F;letter-spacing:.16em;text-transform:uppercase;color:#59616f";
    $row = function ($k, $v, $href) use ($F, $cap) {
        return '<tr><td width="68" valign="middle" style="width:68px;padding:13px 0;border-bottom:1px solid #e6dfcf;' . $cap . ';color:#6e5624">' . $k . '</td><td valign="middle" style="padding:13px 0;border-bottom:1px solid #e6dfcf;font:500 14px/1.3 ' . $F . ';letter-spacing:.03em"><a href="' . $href . '" style="color:#14181f;text-decoration:none">' . $v . '</a></td></tr>';
    };
    $btn = $bcc ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:22px auto 0"><tr><td style="background:#14181f;border-radius:6px"><a href="' . m_esc($cfg['link']) . '" style="display:inline-block;padding:13px 26px;font:600 12px/1 ' . $F . ';letter-spacing:.14em;text-transform:uppercase;color:#ffffff;text-decoration:none">Share Your Experience</a></td></tr></table>';
    // Trustpilot's Automatic Feedback Service reads who to invite (and an optional reference ID) from this block in the copy it receives. Mail apps do not show it.
    $meta = ($bcc && !$sample) ? '<script type="application/json+trustpilot">' . json_encode(['recipientName' => (string)$name, 'recipientEmail' => (string)$email] + ($ref !== '' ? ['referenceId' => $ref] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' : '';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . m_esc($subject) . '</title>'
        . '<style>@media (max-width:540px){.out{padding:16px 10px!important}.pad{padding:32px 22px 24px!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#efece6">' . $meta . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#efece6">We hope your trip went smoothly.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#efece6"><tr><td class="out" align="center" style="padding:40px 20px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#fcfaf6;border:1px solid #e6dfcf"><tr><td class="pad" align="center" style="padding:40px 44px 30px;font-family:' . $F . ';color:#14181f">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td align="center" style="padding:0"><img src="' . CM_SITE . '/email-logo.png" width="26" height="26" alt="" style="display:block;border:0;margin:0 auto"></td></tr>'
        . '<tr><td align="center" style="padding:12px 0 0;font:600 11px/1.3 ' . $F . ';letter-spacing:.32em;text-transform:uppercase;color:#6e5624">Fly First Class</td></tr>'
        . '<tr><td align="center" style="padding:12px 0 0"><div style="width:40px;height:1px;line-height:1px;font-size:1px;background:#c9b27c;margin:0 auto">&nbsp;</div></td></tr></table>'
        . '<h1 style="margin:30px 0 0;font:300 28px/1.25 ' . $F . ';letter-spacing:-.01em;color:#14181f;text-align:center">' . m_esc($title) . '</h1>'
        . '<p style="margin:18px 0 0;font:400 15px/1.7 ' . $F . ';color:#3b414c;text-align:left">' . m_esc($p1) . '</p>'
        . '<p style="margin:14px 0 0;font:400 15px/1.7 ' . $F . ';color:#3b414c;text-align:left">' . m_esc($p2) . '</p>' . $btn
        . ($ref !== '' ? '<p style="margin:18px 0 0;font:400 13px/1.5 ' . $F . ';color:#59616f;text-align:left">Booking reference <b style="letter-spacing:.06em;color:#14181f">' . m_esc($ref) . '</b></p>' : '')
        . '<p style="margin:22px 0 0;font:400 15px/1.7 ' . $F . ';color:#3b414c;text-align:left">' . m_esc($p3) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;text-align:left"><tr><td style="padding:0 0 10px;font:600 10px/1.3 ' . $F . ';letter-spacing:.24em;text-transform:uppercase;color:#6e5624">Any Questions?</td></tr>'
        . '<tr><td style="padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e6dfcf">' . $row('Call', m_esc(CM_PHONE), 'tel:' . CM_TEL) . $row('Email', m_esc(MAIL_INTENDED), 'mailto:' . MAIL_INTENDED) . '</table></td></tr>'
        . '<tr><td style="padding:10px 0 0;font:400 12px/1.5 ' . $F . ';color:#59616f">' . m_esc(CM_ADDRESS) . '</td></tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px"><tr><td align="center" style="padding:18px 0 0;border-top:1px solid #ece6d8;font:400 11px/1.6 ' . $F . ';color:#59616f">'
        . '<a href="' . CM_SITE . '" style="color:#59616f;text-decoration:none;margin:0 8px">flyfirstclass.com</a><a href="' . CM_SITE . '/privacy-policy/" style="color:#59616f;margin:0 8px">Privacy</a><a href="' . CM_SITE . '/terms/" style="color:#59616f;margin:0 8px">Terms</a></td></tr></table>'
        . '</td></tr></table></td></tr></table></body></html>';
    // No "Auto-Submitted" header here: Trustpilot reads this message from the Bcc copy, so it must look like an ordinary email.
    $m = ['to' => $email, 'toName' => $name, 'fromName' => CM_NAME, 'subject' => $subject, 'replyTo' => MAIL_INTENDED, 'text' => implode("\n", $tx), 'html' => $html];
    if ($bcc && !$sample) $m['bcc'] = $cfg['bcc'];
    return $m;
}

// ---------- Signing in ----------
function tr_secure() { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'; }
function tr_local() { return (string)C('admin') === '' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && empty($_SERVER['HTTP_X_FORWARDED_FOR']); }
function tr_sign($exp) { return hash_hmac('sha256', 'adm|' . $exp . '|' . C('admin'), guard_secret()); }      // changing ADMIN_KEY signs everyone out
function tr_cookie() {
    $v = (string)($_COOKIE[TR_COOKIE] ?? '');
    if (!preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', $v, $m) || (int)$m[1] < time() || (string)C('admin') === '') return '';
    return hash_equals(tr_sign($m[1]), $m[2]) ? $v : '';
}
function tr_authed() { return tr_cookie() !== '' || tr_local(); }
function tr_token() { return substr(hash_hmac('sha256', 'csrf|' . (tr_cookie() ?: 'local'), guard_secret()), 0, 32); }
function tr_set_cookie($v, $exp) { setcookie(TR_COOKIE, $v, ['expires' => $exp, 'path' => TR_PAGE, 'secure' => tr_secure(), 'httponly' => true, 'samesite' => 'Strict']); }

// ---------- Booking requests (for quotes done outside the website) ----------
// Creates a request exactly as the quote form does (same store, same FFC-XXXXXXXX reference ID, kept 6 months from its last change), marked as made by
// the team. The customer can look it up on Manage Booking. Returns ['ok' => true, 'ref' => ..., 'mail' => '' | 'sent' | 'failed'] or ['ok' => false, 'msg' => ...].
function tr_err($m) { return ['ok' => false, 'msg' => $m]; }
function tr_date($v) {        // '' when empty, the date when valid, false when it is not a date
    $v = trim((string)$v); if ($v === '') return '';
    return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) ? $v : false;
}
function tr_book($p) {
    $g = function ($k, $n = 120) use ($p) { return qs($p[$k] ?? '', $n); };
    $first = $g('first', 80); $last = $g('last', 80); $e = cm_addr($p['email'] ?? '');
    if ($first === '') return tr_err("Enter the customer's first name.");
    if ($e === '') return tr_err('That email address does not look right.');
    $dep = tr_date($p['dep'] ?? ''); $ret = tr_date($p['ret'] ?? '');
    if ($dep === false || $ret === false) return tr_err('A date does not look right.');
    if ($dep !== '' && $ret !== '' && $ret < $dep) return tr_err('The return date is before the departure date.');
    $cab = ($p['cabin'] ?? '') === 'First class' ? 'First class' : 'Business class';
    $type = in_array($p['type'] ?? '', ['One way', 'Multi-city'], true) ? $p['type'] : 'Round-trip';
    if ($type === 'One way') $ret = '';
    $from = $g('from'); $to = $g('to'); $mail = !empty($p['mail']);
    if ($mail && ($from === '' || $to === '')) return tr_err('To email the confirmation, fill in both cities.');
    $t = ['from' => $from, 'to' => $to, 'via' => [], 'cabin' => $cab, 'type' => $type, 'adults' => qn($p['adults'] ?? 1, 1, 9), 'children' => 0, 'dep' => $dep, 'ret' => $ret, 'flex' => false];
    $c = ['first' => $first, 'last' => $last, 'email' => $e, 'phone' => $g('phone', 32)];
    $made = q_create(['trip' => $t, 'contact' => $c, 'message' => qm2($p['note'] ?? '')]);
    $ref = $made['ref'];
    cupdate('requests.json', [], function (&$QR) use ($ref) { if (isset($QR[$ref])) $QR[$ref]['src'] = 'team'; });
    $out = ['ok' => true, 'ref' => $ref, 'mail' => ''];
    if ($mail) {         // the same "Thank you for your request" email a visitor gets, with the reference ID
        try { $out['mail'] = confirm_deliver(confirm_build(qTrip($t), qWho($c), $ref, $e), $ref) === 'sent' ? 'sent' : 'failed'; }
        catch (Throwable $x) { logm('[trips] confirmation for ' . $ref . ' failed: ' . $x->getMessage()); $out['mail'] = 'failed'; }
    }
    logm('[trips] booking request ' . $ref . ' created' . ($out['mail'] !== '' ? ' (confirmation ' . $out['mail'] . ')' : ''));
    return $out;
}
function tr_book_list($limit = 20) {      // the requests created here that are still kept, newest first
    $n = now_ms(); $o = [];
    foreach (cload('requests.json', []) as $r) if (($r['src'] ?? '') === 'team' && ($r['exp'] ?? 0) > $n) $o[] = $r;
    usort($o, function ($a, $b) { return ($b['created'] ?? 0) <=> ($a['created'] ?? 0); });
    return array_slice($o, 0, $limit);
}
function tr_book_delete($ref) {           // only requests created here can be deleted from this page
    if (!preg_match(QREF, $ref)) return false;
    return cupdate('requests.json', [], function (&$QR) use ($ref) { if (empty($QR[$ref]) || ($QR[$ref]['src'] ?? '') !== 'team') return false; unset($QR[$ref]); return true; });
}
function tr_prefill($ref) {               // fills the "add a completed trip" form from any stored request (made here or on the website)
    $r = q_find($ref); if (!$r) return null;
    $c = $r['contact'] ?? []; $t = $r['trip'] ?? [];
    $ret = ($t['type'] ?? '') === 'One way' ? ($t['dep'] ?? '') : (($t['ret'] ?? '') ?: ($t['dep'] ?? ''));
    return ['name' => trim(implode(' ', array_filter([$c['first'] ?? '', $c['last'] ?? '']))), 'email' => $c['email'] ?? '', 'ret' => $ret, 'ref' => $ref];
}

// ---------- The route ----------
function tr_go($code = '', $ref = '') { header('Location: ' . TR_PAGE . ($code !== '' ? '?m=' . rawurlencode($code) . ($ref !== '' ? '&r=' . rawurlencode($ref) : '') : ''), true, 303); exit; }
function trips_route($method) {
    if (!tr_on()) json_out(404, ['error' => 'Not found']);
    header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
    $cfg = tr_cfg();
    if ($method === 'POST') {
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'login') {
            if ((string)C('admin') === '') tr_page_login('Set ADMIN_KEY in the .env file first, then sign in.');
            if (!limit_hit(client_ip() . ':trips-login', 10, 3600000)) { http_response_code(429); tr_page_login('Too many tries. Please wait an hour and try again.'); }
            if (!hash_equals((string)C('admin'), (string)($_POST['key'] ?? ''))) { logm('[trips] sign-in refused'); http_response_code(403); tr_page_login('That is not the right key.'); }
            $exp = time() + TR_SESSION; tr_set_cookie($exp . '.' . tr_sign($exp), $exp); tr_go();
        }
        if (!tr_authed()) tr_go();
        if (!hash_equals(tr_token(), (string)($_POST['t'] ?? ''))) { http_response_code(403); tr_page_login('This page was open too long. Sign in again.'); }
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        switch ($act) {
            case 'logout': tr_set_cookie('', time() - 3600); tr_go('out');
            case 'add':
                if (!limit_hit('trips:add', 60, 3600000)) tr_page('bad', 'Too many trips added in the last hour. Please wait a little.');
                $r = tr_add($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['ret'] ?? '', !empty($_POST['force']), $_POST['ref'] ?? '');
                if (!empty($r['ok'])) { logm('[trips] ' . $r['id'] . ' added'); tr_go('added'); }
                http_response_code(400); tr_page($r['code'] === 'recent' ? 'warn' : 'bad', $r['msg'], ['name' => (string)($_POST['name'] ?? ''), 'email' => (string)($_POST['email'] ?? ''), 'ret' => (string)($_POST['ret'] ?? ''), 'ref' => (string)($_POST['ref'] ?? ''), 'ask' => $r['code'] === 'recent']);
            case 'book':
                if (!limit_hit('trips:book', 60, 3600000)) tr_page('bad', 'Too many booking requests in the last hour. Please wait a little.');
                $r = tr_book($_POST);
                if (empty($r['ok'])) { http_response_code(400); tr_page('bad', $r['msg'], [], $_POST); }
                tr_go($r['mail'] === 'sent' ? 'bookedm' : ($r['mail'] === '' ? 'booked' : 'bookedf'), $r['ref']);
            case 'bookdel': tr_go(tr_book_delete(strtoupper(trim((string)($_POST['ref'] ?? '')))) ? 'bookdeleted' : 'gone');
            case 'remove': tr_go(tr_remove($id) ? 'removed' : 'gone');
            case 'retry': tr_go(tr_retry($id) ? 'retried' : 'gone');
            case 'sendnow':
                if (tr_room($cfg) < 1) tr_go('cap');
                $res = tr_deliver($id, true, $cfg); tr_go($res === 'sent' ? 'sent' : ($res === 'failed' ? 'failed' : 'gone'));
            case 'sample':
                $to = cm_addr($_POST['to'] ?? '');
                if ($to === '') { http_response_code(400); tr_page('bad', 'Enter an email address to send the sample to.'); }
                if (!limit_hit('trips:sample', 6, 3600000)) tr_page('bad', 'That is enough samples for one hour.');
                try { send_mail(tr_mail('Alex Chen', $to, $cfg, true, 'FFC-7K2M9QXA')); } catch (Throwable $e) { http_response_code(502); tr_page('bad', 'The sample could not be sent: ' . $e->getMessage()); }
                tr_go('sample');
            default: tr_go();
        }
    }
    if (!tr_authed()) tr_page_login('');
    if (($_GET['preview'] ?? '') === '1') {          // the email as the customer sees it
        $mm = tr_mail('Alex Chen', 'alex@example.com', $cfg, true, 'FFC-7K2M9QXA');
        header('Content-Type: text/html; charset=UTF-8'); echo $mm['html']; exit;
    }
    $M = ['added' => ['ok', 'Added. The email goes out automatically a few days after the return date.'], 'removed' => ['ok', 'Removed.'], 'retried' => ['ok', 'Queued again. The next scheduled run will try it.'],
        'sent' => ['ok', 'Sent. The name and address have been deleted from the server.'], 'failed' => ['bad', 'It could not be sent. The reason is shown against the trip below; the server will try again later.'],
        'cap' => ['warn', 'This month\'s invitation limit has been reached, so nothing more is sent until next month.'], 'gone' => ['warn', 'That one is no longer there (it may already have been sent or removed).'],
        'sample' => ['ok', 'Sample sent. It does not copy Trustpilot.'], 'out' => ['ok', 'Signed out.'],
        'booked' => ['ok', 'Booking request created. Reference ID: %s'], 'bookedm' => ['ok', 'Booking request created and the confirmation email sent. Reference ID: %s'],
        'bookedf' => ['warn', 'Booking request created, but the confirmation email could not be sent. Reference ID: %s'], 'bookdeleted' => ['ok', 'Booking request deleted.']];
    $m = $M[(string)($_GET['m'] ?? '')] ?? null; $f = [];
    if ($m) { $rr = strtoupper((string)($_GET['r'] ?? '')); $m[1] = sprintf($m[1], preg_match(QREF, $rr) ? $rr : ''); }
    $from = strtoupper(trim((string)($_GET['from'] ?? '')));
    if ($from !== '') {             // start a completed trip from a stored request
        $f = tr_prefill($from);
        if ($f) $m = ['ok', 'Filled in from ' . $from . '. Check the return date, then add the trip.'];
        else { $f = []; $m = ['warn', 'No request found for that reference ID. Requests are kept for six months from their last change.']; }
    }
    tr_page($m ? $m[0] : '', $m ? $m[1] : '', $f);
}

// ---------- The pages ----------
function tr_head($title) {
    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    $F = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="color-scheme" content="light"><title>' . m_esc($title) . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#faf8f3;color:#14181f;font:15px/1.5 ' . $F . '}'
        . '.w{max-width:860px;margin:0 auto;padding:28px 16px 64px}h1{font-weight:300;font-size:28px;line-height:1.2;margin:0 0 4px}.sub{color:#59616f;margin:0 0 14px}'
        . '.card{background:#fff;border:1px solid #e6dfcf;border-radius:10px;padding:18px 20px;margin:0 0 18px}h2{font-size:11px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:#6e5624;margin:0 0 12px}'
        . 'label{display:block;font-size:13px;color:#59616f;margin:0 0 4px}input[type=text],input[type=email],input[type=tel],input[type=number],input[type=date],input[type=password],select,textarea{width:100%;padding:10px 12px;border:1px solid #cfc7b4;border-radius:6px;font:inherit;background:#fff;color:#14181f}textarea{resize:vertical}'
        . 'input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,summary:focus-visible,a:focus-visible{outline:2px solid #8a6d2f;outline-offset:2px}'
        . '.g{display:grid;gap:12px;margin:0 0 12px}.g2{grid-template-columns:1fr 1fr}.g3{grid-template-columns:1fr 1fr 1fr}.g4{grid-template-columns:1.2fr 1.4fr 1fr 1fr}@media(max-width:680px){.g2,.g3,.g4{grid-template-columns:1fr}}'
        . 'button{font:600 13px/1 ' . $F . ';letter-spacing:.06em;padding:11px 18px;min-height:40px;border:1px solid #14181f;background:#14181f;color:#fff;border-radius:6px;cursor:pointer;white-space:nowrap}button.s{background:#fff;color:#14181f;border-color:#cfc7b4;min-height:34px;padding:8px 12px}button.d{color:#8a2a2a}'
        . 'a.bl{display:inline-block;font:600 13px/1 ' . $F . ';letter-spacing:.06em;padding:10px 12px;border:1px solid #cfc7b4;border-radius:6px;color:#14181f;background:#fff;text-decoration:none;margin:0 6px 6px 0;white-space:nowrap}'
        . '.msg{padding:11px 14px;border-radius:8px;margin:0 0 16px;border:1px solid}.ok{background:#eef6ee;border-color:#bcd9bc}.bad{background:#fbeeee;border-color:#e3b4b4}.warn{background:#fbf5e4;border-color:#e6d49a}'
        . '.t{overflow-x:auto}table{width:100%;border-collapse:collapse}th{text-align:left;font-size:12px;font-weight:600;color:#59616f;padding:0 8px 8px 0;white-space:nowrap}td{padding:10px 8px 10px 0;border-top:1px solid #eee7d6;vertical-align:top}'
        . '.m{color:#59616f;font-size:13px}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.04em;white-space:nowrap}.a form{display:inline-block;margin:0 6px 6px 0}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.top form{margin:0}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}'
        . 'details summary{cursor:pointer;font-weight:600}a{color:#6e5624}.chk{display:flex;gap:8px;align-items:flex-start;margin-top:12px}.chk input{margin-top:4px}.chk label{margin:0;color:#14181f;font-size:14px}.nav{margin:0 0 18px;font-size:14px}.nav a{margin-right:14px}'
        . '.look{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0 0 16px;padding:0 0 16px;border-bottom:1px solid #eee7d6}.look>div{flex:1 1 220px}'
        . '@media(max-width:680px){.rt tr.hd{display:none}.rt,.rt tbody,.rt tr,.rt td{display:block;width:100%}.rt tr{padding:10px 0;border-top:1px solid #eee7d6}.rt tr:first-child{border-top:0}.rt td{border:0;padding:3px 0}.rt td[data-l]::before{content:attr(data-l);display:block;font-size:12px;font-weight:600;color:#59616f}.rt td.a{padding-top:8px}}'
        . '</style></head><body><div class="w">';
}
function tr_page_login($err) {
    echo tr_head('Bookings and trips'), '<h1>Bookings and trips</h1><p class="sub">Private page for Fly First Class International.</p>';
    if ($err !== '') echo '<div class="msg bad" role="alert">', m_esc($err), '</div>';
    if ((string)C('admin') === '') echo '<div class="card"><p>This page needs a private key. Add a line <b>ADMIN_KEY=a-long-random-phrase</b> to the .env file in ffc-private, then come back.</p></div>';
    else echo '<form class="card" method="post" action="', TR_PAGE, '"><input type="hidden" name="act" value="login"><label for="k">Admin key</label><input id="k" type="password" name="key" autocomplete="current-password" required autofocus><div class="row"><button type="submit">Sign in</button></div></form>';
    echo '</div></body></html>'; exit;
}
// $f fills the "add a completed trip" form, $b the "booking request" form (both after a mistake, or from a reference).
function tr_page($cls = '', $msg = '', $f = [], $b = []) {
    $cfg = tr_cfg(); $T = cload(TR_FILE, []); $t = tr_token(); $n = now_ms();
    $q = $s = $x = [];
    foreach ($T as $r) { $st = $r['st'] ?? ''; if ($st === 'sent') $s[] = $r; elseif ($st === 'failed') $x[] = $r; elseif (in_array($st, ['queued', 'sending'], true)) $q[] = $r; }
    usort($q, function ($a, $b) { return ($a['due'] ?? 0) <=> ($b['due'] ?? 0); });
    usort($s, function ($a, $b) { return ($b['sent'] ?? 0) <=> ($a['sent'] ?? 0); });
    usort($x, function ($a, $b) { return ($a['due'] ?? 0) <=> ($b['due'] ?? 0); });
    $used = tr_used(); $cap = $cfg['cap'];
    $btn = function ($act, $id, $label, $extra = '', $field = 'id') use ($t) {
        return '<form method="post" action="' . TR_PAGE . '"><input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="' . $field . '" value="' . m_esc($id) . '"><input type="hidden" name="t" value="' . $t . '"><button class="s ' . $extra . '" type="submit">' . $label . '</button></form>';
    };
    $bv = function ($k, $d = '') use ($b) { return m_esc($b[$k] ?? $d); };
    $sel = function ($k, $v, $d = false) use ($b) { return ((string)($b[$k] ?? '') === $v || (!isset($b[$k]) && $d)) ? ' selected' : ''; };
    echo tr_head('Bookings and trips');
    echo '<div class="top"><div><h1>Bookings and trips</h1><p class="sub">Create booking requests with a reference ID, and add each customer once their trip is booked so they are thanked and invited to review us.</p></div>',
        '<form method="post" action="', TR_PAGE, '"><input type="hidden" name="act" value="logout"><input type="hidden" name="t" value="', $t, '"><button class="s" type="submit">Sign out</button></form></div>',
        '<p class="nav"><a href="#book">Booking requests</a><a href="#trip">Completed trips</a></p>';
    if ($msg !== '') echo '<div class="msg ', m_esc($cls ?: 'ok'), '" role="status">', m_esc($msg), '</div>';

    // ---- Booking requests ----
    $bl = tr_book_list();
    echo '<form class="card" id="book" method="post" action="', TR_PAGE, '"><h2>Create a booking request</h2><p class="m" style="margin:-4px 0 14px">For quotes done outside the website (phone, email, a Mailchimp reply). It gets a reference ID like one sent through the site (FFC-XXXXXXXX), kept for six months from its last change, and the customer can look it up on Manage Booking.</p>',
        '<input type="hidden" name="act" value="book"><input type="hidden" name="t" value="', $t, '">',
        '<div class="g g3"><div><label for="bf">First name</label><input id="bf" type="text" name="first" maxlength="80" autocomplete="off" required value="', $bv('first'), '"></div>',
        '<div><label for="bl">Last name</label><input id="bl" type="text" name="last" maxlength="80" autocomplete="off" value="', $bv('last'), '"></div>',
        '<div><label for="be">Email</label><input id="be" type="email" name="email" maxlength="254" autocomplete="off" required value="', $bv('email'), '"></div></div>',
        '<div class="g g3"><div><label for="bp">Phone (optional)</label><input id="bp" type="tel" name="phone" maxlength="32" autocomplete="off" value="', $bv('phone'), '"></div>',
        '<div><label for="bfr">From (optional)</label><input id="bfr" type="text" name="from" maxlength="120" placeholder="New York (JFK)" autocomplete="off" value="', $bv('from'), '"></div>',
        '<div><label for="bt">To (optional)</label><input id="bt" type="text" name="to" maxlength="120" placeholder="London (LHR)" autocomplete="off" value="', $bv('to'), '"></div></div>',
        '<div class="g g3"><div><label for="bc">Cabin</label><select id="bc" name="cabin"><option', $sel('cabin', 'Business class', true), '>Business class</option><option', $sel('cabin', 'First class'), '>First class</option></select></div>',
        '<div><label for="by">Trip type</label><select id="by" name="type"><option', $sel('type', 'Round-trip', true), '>Round-trip</option><option', $sel('type', 'One way'), '>One way</option><option', $sel('type', 'Multi-city'), '>Multi-city</option></select></div>',
        '<div><label for="ba">Travellers</label><input id="ba" type="number" name="adults" min="1" max="9" value="', $bv('adults', '1'), '"></div></div>',
        '<div class="g g2"><div><label for="bd">Departing (optional)</label><input id="bd" type="date" name="dep" value="', $bv('dep'), '"></div>',
        '<div><label for="br">Returning (optional)</label><input id="br" type="date" name="ret" value="', $bv('ret'), '"></div></div>',
        '<label for="bn">Note (optional)</label><textarea id="bn" name="note" rows="2" maxlength="3000">', $bv('note'), '</textarea>',
        '<div class="chk"><input id="bm" type="checkbox" name="mail" value="1"', !empty($b['mail']) ? ' checked' : '', '><label for="bm">Email them the confirmation with the reference ID (needs both cities)</label></div>',
        '<div class="row"><button type="submit">Create request</button></div></form>';
    if ($bl) {
        echo '<div class="card"><h2>Created here (', count($bl), ')</h2><div class="t"><table class="rt"><tr class="hd"><th>Reference ID</th><th>Customer</th><th>Trip</th><th>Created</th><th></th></tr>';
        foreach ($bl as $r) {
            $c = $r['contact'] ?? []; $tp = $r['trip'] ?? [];
            echo '<tr><td><b class="mono">', m_esc($r['ref']), '</b></td><td data-l="Customer">', m_esc(trim(($c['first'] ?? '') . ' ' . ($c['last'] ?? ''))), '<br><span class="m">', m_esc($c['email'] ?? ''), '</span></td>',
                '<td data-l="Trip">', m_esc(implode(' to ', array_filter([$tp['from'] ?? '', $tp['to'] ?? ''])) ?: 'No route yet'), '<br><span class="m">', m_esc(implode(', ', array_filter([$tp['cabin'] ?? '', !empty($tp['dep']) ? m_date($tp['dep']) : '']))), '</span></td>',
                '<td data-l="Created">', m_esc(tr_day($r['created'] ?? 0)), '</td><td class="a"><a class="bl" href="', TR_PAGE, '?from=', rawurlencode($r['ref']), '#trip">Use for a completed trip</a>', $btn('bookdel', $r['ref'], 'Delete', 'd', 'ref'), '</td></tr>';
        }
        echo '</table></div></div>';
    }

    // ---- Completed trips ----
    echo '<div id="trip"></div>';
    if ($cfg['mode'] === 'bcc') echo '<div class="msg ok">Invitations are sent by <b>Trustpilot</b>. Each thank-you email copies your Trustpilot address, and Trustpilot then invites the customer. ',
        $cap ? '<b>' . $used . ' of ' . $cap . '</b> invitations used this month.' : '<b>' . $used . '</b> sent this month.', '</div>';
    else echo '<div class="msg warn"><b>Trustpilot address not set.</b> Until <b>TRUSTPILOT_BCC</b> is added to the .env file, the thank-you email carries the plain Trustpilot review link instead. ', $cap ? $used . ' of ' . $cap . ' sent this month.' : '', '</div>';

    echo '<div class="card"><h2>Add a completed trip</h2>',
        '<form class="look" method="get" action="', TR_PAGE, '"><div><label for="lf">Start from a booking reference ID (optional)</label><input id="lf" type="text" name="from" maxlength="30" placeholder="FFC-7K2M9QXA" autocomplete="off"></div><button class="s" type="submit">Fill in</button></form>',
        '<form method="post" action="', TR_PAGE, '"><input type="hidden" name="act" value="add"><input type="hidden" name="t" value="', $t, '"><div class="g g4">',
        '<div><label for="n">Customer name</label><input id="n" type="text" name="name" maxlength="80" autocomplete="off" required value="', m_esc($f['name'] ?? ''), '"></div>',
        '<div><label for="e">Email</label><input id="e" type="email" name="email" maxlength="254" autocomplete="off" required value="', m_esc($f['email'] ?? ''), '"></div>',
        '<div><label for="r">Date they get back</label><input id="r" type="date" name="ret" required value="', m_esc($f['ret'] ?? ''), '"></div>',
        '<div><label for="rf">Booking reference (optional)</label><input id="rf" type="text" name="ref" maxlength="30" autocomplete="off" value="', m_esc($f['ref'] ?? ''), '"></div></div>';
    if (!empty($f['ask'])) echo '<div class="chk"><input id="fc" type="checkbox" name="force" value="1"><label for="fc">This is a different trip from the earlier one. Add it anyway.</label></div>';
    echo '<div class="row"><button type="submit">Add trip</button><span class="m">Goes out ', (int)$cfg['days'], ' day', $cfg['days'] === 1 ? '' : 's', ' after the return date, at 10:00 AM Eastern. One-way trips: use the arrival date. A reference ID is sent to Trustpilot with the invitation.</span></div></form></div>';

    echo '<div class="card"><h2>Waiting to send (', count($q), ')</h2>';
    if (!$q) echo '<p class="m" style="margin:0">Nothing waiting.</p>';
    else {
        echo '<div class="t"><table class="rt"><tr class="hd"><th>Customer</th><th>Back on</th><th>Sends</th><th></th></tr>';
        foreach ($q as $r) {
            $busy = ($r['st'] ?? '') === 'sending';
            echo '<tr><td><b>', m_esc($r['n'] ?? ''), '</b><br><span class="m">', m_esc($r['e'] ?? ''), !empty($r['ref']) ? ' &middot; ' . m_esc($r['ref']) : '', '</span>';
            if (!empty($r['err']) && !$busy) echo '<br><span class="m" style="color:#8a2a2a">Last try failed: ', m_esc($r['err']), '. Trying again ', tr_day($r['next'] ?? $n), '.</span>';
            echo '</td><td data-l="Back on">', m_esc(m_date($r['r'] ?? '')), '</td><td data-l="Sends">', $busy ? 'Sending now' : m_esc(tr_day(max($r['due'] ?? 0, $r['next'] ?? 0))), '</td><td class="a">',
                $busy ? '' : $btn('sendnow', $r['id'], 'Send now') . $btn('remove', $r['id'], 'Remove', 'd'), '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';

    if ($x) {
        echo '<div class="card" style="border-color:#e3b4b4"><h2>Needs attention (', count($x), ')</h2><div class="t"><table class="rt"><tr class="hd"><th>Customer</th><th>What happened</th><th></th></tr>';
        foreach ($x as $r) echo '<tr><td><b>', m_esc($r['n'] ?? ''), '</b><br><span class="m">', m_esc($r['e'] ?? ''), '<br>Back ', m_esc(m_date($r['r'] ?? '')), '</span></td><td data-l="What happened">', m_esc($r['err'] ?? ''), '</td><td class="a">', $btn('retry', $r['id'], 'Try again') . $btn('remove', $r['id'], 'Remove', 'd'), '</td></tr>';
        echo '</table></div></div>';
    }

    echo '<div class="card"><h2>Sent</h2>';
    if (!$s) echo '<p class="m" style="margin:0">Nothing sent yet. Names and addresses are deleted as soon as the email has gone; this list keeps initials only, for 90 days.</p>';
    else {
        echo '<div class="t"><table class="rt"><tr class="hd"><th>Customer</th><th>Reference</th><th>Back on</th><th>Sent</th><th>How</th></tr>';
        foreach (array_slice($s, 0, 30) as $r) echo '<tr><td><b>', m_esc($r['i'] ?? '?'), '</b></td><td data-l="Reference" class="mono">', m_esc($r['ref'] ?? '-'), '</td><td data-l="Back on">', m_esc(m_date($r['r'] ?? '')), '</td><td data-l="Sent">', m_esc(tr_day($r['sent'] ?? 0)), '</td><td data-l="How">', ($r['via'] ?? '') === 'bcc' ? 'Trustpilot invitation' : 'Review link in email', '</td></tr>';
        echo '</table></div><p class="m" style="margin:10px 0 0">Names and addresses are deleted as soon as the email has gone. This list keeps initials only, for 90 days.</p>';
    }
    echo '</div>';

    echo '<div class="card"><details><summary>See the email, or send yourself a sample</summary><p class="m">The sample does not copy Trustpilot, so it uses none of your monthly invitations.</p>',
        '<p><a href="', TR_PAGE, '?preview=1" target="_blank" rel="noopener">Open the email in a new tab</a></p>',
        '<form method="post" action="', TR_PAGE, '"><input type="hidden" name="act" value="sample"><input type="hidden" name="t" value="', $t, '"><label for="to">Send a sample to</label><input id="to" type="email" name="to" maxlength="254" required><div class="row"><button class="s" type="submit">Send sample</button></div></form></details></div>';
    echo '</div></body></html>'; exit;
}
