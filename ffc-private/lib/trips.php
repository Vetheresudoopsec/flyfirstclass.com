<?php
// trips.php: the private "Bookings and trips" page (booking requests, Trustpilot invitations, email preview), and the thank-you email that asks Trustpilot to invite the customer.
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
//   The page is never indexed (robots.txt, a noindex header and a no-store header). It has one small script (tr_js) that the security policy allows by its exact
//   hash, so nothing else can run: it makes the tabs, the views and the Add trip panel switch instantly, and sends the buttons without reloading the page.
//   Without it every link and button still works, one page load at a time.
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
const TR_ADJ = 'tradj.json';               // the owner's "match Trustpilot" correction for the current month
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
function tr_month_key() { return (new DateTime('now', et_tz()))->format('Y-m'); }
// Invitations this month that went to Trustpilot: thank-you emails sent with the Bcc (plus one being sent now). An email that carried only the plain review link
// uses none of Trustpilot's invitations, so it is not counted here.
function tr_count_bcc() {
    $n = 0; $m = tr_month_start(); $bcc = tr_cfg()['mode'] === 'bcc';
    foreach (cload(TR_FILE, []) as $r) { $st = $r['st'] ?? ''; if ($st === 'sent' && ($r['sent'] ?? 0) >= $m && ($r['via'] ?? '') === 'bcc') $n++; elseif ($st === 'sending' && $bcc) $n++; }
    return $n;
}
function tr_emails_month() {      // every thank-you email sent this month, whichever way
    $n = 0; $m = tr_month_start();
    foreach (cload(TR_FILE, []) as $r) if ((($r['st'] ?? '') === 'sent' && ($r['sent'] ?? 0) >= $m) || ($r['st'] ?? '') === 'sending') $n++;
    return $n;
}
// The owner can set the count to what Trustpilot's own dashboard says (invitations sent from elsewhere, or addresses Trustpilot skipped, make the two differ).
// What is kept is the difference, for the current month only, so later sends still add to it.
function tr_adjust() { $a = cload(TR_ADJ, []); return (($a['m'] ?? '') === tr_month_key()) ? (int)($a['adj'] ?? 0) : 0; }
function tr_adjust_info() { $a = cload(TR_ADJ, []); return (($a['m'] ?? '') === tr_month_key() && isset($a['to'])) ? $a : null; }
function tr_match($n) {
    $n = max(0, min(100000, (int)$n)); $ours = tr_count_bcc();
    cupdate(TR_ADJ, [], function (&$a) use ($n, $ours) { $a = ['m' => tr_month_key(), 'adj' => $n - $ours, 'to' => $n, 'set' => now_ms()]; });
    return $n;
}
function tr_match_clear() { cupdate(TR_ADJ, [], function (&$a) { $a = []; }); }
function tr_used() { return max(0, tr_count_bcc() + tr_adjust()); }          // invitations used this month, as Trustpilot would count them
function tr_room($cfg) { return ($cfg['cap'] === 0 || $cfg['mode'] !== 'bcc') ? PHP_INT_MAX : $cfg['cap'] - tr_used(); }      // the limit only matters while emails copy Trustpilot

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
        'sendsAfterReturnDays' => $cfg['days'], 'waiting' => $c['queued'], 'needAttention' => $c['failed'], 'sentRecently' => $c['sent'], 'sentThisMonth' => $cfg['mode'] === 'bcc' ? tr_used() : tr_emails_month(), 'emailsThisMonth' => tr_emails_month(), 'matchedToTrustpilot' => tr_adjust_info() ? ('set to ' . (int)tr_adjust_info()['to'] . ' on ' . et_text(tr_adjust_info()['set'])) : null, 'monthlyLimit' => ($cfg['mode'] === 'bcc' && $cfg['cap']) ? $cfg['cap'] : 'none',
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
function tr_src($r) { return ($r['src'] ?? '') === 'team' ? 'team' : 'web'; }      // where a request came from: made on this page, or sent through the website
function tr_book_list($limit = 0) {      // every request that is still kept (sent through the website or made here), newest first; 0 = all of them
    $n = now_ms(); $o = [];
    foreach (cload('requests.json', []) as $r) if (is_array($r) && !empty($r['ref']) && ($r['exp'] ?? 0) > $n) $o[] = $r;
    usort($o, function ($a, $b) { return ($b['created'] ?? 0) <=> ($a['created'] ?? 0); });
    return $limit > 0 ? array_slice($o, 0, $limit) : $o;
}
const TR_BK_MAX = 500;      // the most requests the list shows (search looks through all of them)
function tr_has_route($r) { $t = $r['trip'] ?? []; return trim((string)($t['from'] ?? '')) !== '' && trim((string)($t['to'] ?? '')) !== ''; }      // both cities filled in
function tr_bk_text($r) {      // what the search looks through, in lower case: reference ID (with and without the dash), name and email address
    $c = $r['contact'] ?? []; $ref = (string)($r['ref'] ?? '');
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', implode(' ', [$ref, str_replace('-', '', $ref), $c['first'] ?? '', $c['last'] ?? '', $c['email'] ?? '']))));
}
function tr_bk_words($v) { $v = mb_strtolower(trim(preg_replace('/\s+/u', ' ', qs((string)$v, 80)))); return $v === '' ? [] : explode(' ', $v); }
function tr_bk_hit($text, $words) { foreach ($words as $w) if (mb_strpos($text, $w) === false) return false; return true; }      // every word must appear
function tr_bk_url($rt, $src, $q) {      // the address of the booking requests view with this list, filter and search (written for use inside an attribute)
    $u = [];
    if ($rt !== 'route') $u[] = 'rt=' . $rt;
    if ($src !== 'all') $u[] = 'src=' . $src;
    if ($q !== '') $u[] = 'q=' . rawurlencode($q);
    return TR_PAGE . '?v=bookings' . ($u ? '&amp;' . implode('&amp;', $u) : '');
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
function tr_go($code = '', $ref = '', $v = '', $tab = '') {      // back to the page: m = the message to show, r = a reference ID, v = which view, tab = which list
    $q = [];
    if ($code !== '') $q[] = 'm=' . rawurlencode($code);
    if ($ref !== '') $q[] = 'r=' . rawurlencode($ref);
    if ($v !== '' && $v !== 'trips') $q[] = 'v=' . rawurlencode($v);
    if ($tab !== '' && $tab !== 'waiting') $q[] = 'tab=' . rawurlencode($tab);
    header('Location: ' . TR_PAGE . ($q ? '?' . implode('&', $q) : ''), true, 303); exit;
}
function tr_tab($v) { $v = (string)$v; return in_array($v, ['waiting', 'attention', 'sent'], true) ? $v : 'waiting'; }
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
        $tab = tr_tab($_POST['tab'] ?? '');
        switch ($act) {
            case 'logout': tr_set_cookie('', time() - 3600); tr_go('out');
            case 'add':
                if (!limit_hit('trips:add', 60, 3600000)) tr_page('bad', 'Too many trips added in the last hour. Please wait a little.', ['open' => true]);
                $r = tr_add($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['ret'] ?? '', !empty($_POST['force']), $_POST['ref'] ?? '');
                if (!empty($r['ok'])) { logm('[trips] ' . $r['id'] . ' added'); tr_go('added'); }
                http_response_code(400); tr_page($r['code'] === 'recent' ? 'warn' : 'bad', $r['msg'], ['name' => (string)($_POST['name'] ?? ''), 'email' => (string)($_POST['email'] ?? ''), 'ret' => (string)($_POST['ret'] ?? ''), 'ref' => (string)($_POST['ref'] ?? ''), 'ask' => $r['code'] === 'recent', 'open' => true]);
            case 'book':
                if (!limit_hit('trips:book', 60, 3600000)) tr_page('bad', 'Too many booking requests in the last hour. Please wait a little.', [], [], 'bookings');
                $r = tr_book($_POST);
                if (empty($r['ok'])) { http_response_code(400); tr_page('bad', $r['msg'], [], $_POST, 'bookings'); }
                tr_go($r['mail'] === 'sent' ? 'bookedm' : ($r['mail'] === '' ? 'booked' : 'bookedf'), $r['ref'], 'bookings');
            case 'trmatch':
                if (!limit_hit('trips:match', 30, 3600000)) tr_page('bad', 'Too many changes in the last hour. Please wait a little.');
                if (!preg_match('/^\d{1,6}$/', trim((string)($_POST['n'] ?? '')))) { http_response_code(400); tr_page('bad', 'Enter the number of invitations Trustpilot shows for this month, for example 12.'); }
                logm('[trips] count matched to Trustpilot: ' . tr_match($_POST['n']) . ' this month'); tr_go('matched');
            case 'trclear': tr_match_clear(); logm('[trips] count back to our own'); tr_go('unmatched');
            case 'bookdel': tr_go(tr_book_delete(strtoupper(trim((string)($_POST['ref'] ?? '')))) ? 'bookdeleted' : 'gone', '', 'bookings');
            case 'remove': tr_go(tr_remove($id) ? 'removed' : 'gone', '', '', $tab);
            case 'retry': tr_go(tr_retry($id) ? 'retried' : 'gone', '', '', $tab);
            case 'sendnow':
                if (tr_room($cfg) < 1) tr_go('cap', '', '', $tab);
                $res = tr_deliver($id, true, $cfg); tr_go($res === 'sent' ? 'sent' : ($res === 'failed' ? 'failed' : 'gone'), '', '', $tab);
            case 'sample':
                $to = cm_addr($_POST['to'] ?? '');
                if ($to === '') { http_response_code(400); tr_page('bad', 'Enter an email address to send the sample to.', [], [], 'email'); }
                if (!limit_hit('trips:sample', 6, 3600000)) tr_page('bad', 'That is enough samples for one hour.', [], [], 'email');
                try { send_mail(tr_mail('Alex Chen', $to, $cfg, true, 'FFC-7K2M9QXA')); } catch (Throwable $e) { http_response_code(502); tr_page('bad', 'The sample could not be sent: ' . $e->getMessage(), [], [], 'email'); }
                tr_go('sample', '', 'email');
            case 'mon_duffel': case 'mon_mail': case 'mon_cron': case 'mon_photo':      // the monitoring tools (monitor.php): the result is drawn at the top of the Monitoring view
                mon_result(mon_do($act, $_POST)); tr_page('', '', [], [], 'monitoring');
            case 'mon_export':      // the sign-ups Mailchimp does not have yet, as a download
                if (!limit_hit('mon:export', 20, 3600000)) tr_page('bad', 'Too many downloads in the last hour. Please wait a little.', [], [], 'monitoring');
                logm('[admin] waiting newsletter addresses downloaded'); nl_export();
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
        'bookedf' => ['warn', 'Booking request created, but the confirmation email could not be sent. Reference ID: %s'], 'bookdeleted' => ['ok', 'Booking request deleted.'], 'matched' => ['ok', 'The meter now matches Trustpilot for this month. New invitations add to it from here.'], 'unmatched' => ['ok', 'Back to counting the invitations sent from here.']];
    $m = $M[(string)($_GET['m'] ?? '')] ?? null; $f = [];
    $view = in_array($_GET['v'] ?? '', ['bookings', 'email', 'monitoring'], true) ? $_GET['v'] : 'trips';
    if ($m) { $rr = strtoupper((string)($_GET['r'] ?? '')); $m[1] = sprintf($m[1], preg_match(QREF, $rr) ? $rr : ''); }
    $from = strtoupper(trim((string)($_GET['from'] ?? '')));
    if ($from !== '') {             // start a completed trip from a stored request: the add panel opens, filled in
        $view = 'trips'; $f = tr_prefill($from);
        if ($f) $m = ['ok', 'Filled in from ' . $from . '. Check the return date, then add the trip.'];
        else { $f = []; $m = ['warn', 'No request found for that reference ID. Requests are kept for six months from their last change.']; }
        $f['open'] = true;
    }
    tr_page($m ? $m[0] : '', $m ? $m[1] : '', $f, [], $view);
}

// ---------- The pages ----------
function tr_js() {       // the page's only script. The security policy allows exactly these bytes (by hash). It never reads or writes anything but this page.
    return <<<'JS'
(function () {
  var d = document, base = '/api/trips', opener = null;
  var titles = { trips: 'Trustpilot invitations', bookings: 'Booking requests', email: 'Email preview' };
  d.documentElement.className += ' js';
  function q(s) { return d.querySelector(s); }
  function qa(s) { return Array.prototype.slice.call(d.querySelectorAll(s)); }
  function cur(sel, attr, dflt) { var e = q(sel + '[aria-current]'); return e ? e.getAttribute(attr) : dflt; }
  function mark(sel, attr, val) { qa(sel).forEach(function (a) { if (a.getAttribute(attr) === val) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); }); }
  function path(v, t) {
    if (v === 'bookings') {
      var u = [], r = cur('.rtab', 'data-rt', 'route'), f = cur('.fl', 'data-filter', 'all'), i = q('#bq'), w = i ? i.value.replace(/^\s+|\s+$/g, '') : '';
      if (r !== 'route') u.push('rt=' + r);
      if (f !== 'all') u.push('src=' + f);
      if (w) u.push('q=' + encodeURIComponent(w));
      return base + '?v=bookings' + (u.length ? '&' + u.join('&') : '');
    }
    return base + (v !== 'trips' ? '?v=' + v : (t !== 'waiting' ? '?tab=' + t : ''));
  }
  function sift() {
    var i = q('#bq'), words = (i ? i.value : '').toLowerCase().replace(/^\s+|\s+$/g, '').split(/\s+/).filter(function (x) { return x; });
    var rt = cur('.rtab', 'data-rt', 'route'), sf = cur('.fl', 'data-filter', 'all'), n = 0;
    var cn = { 'rt:route': 0, 'rt:none': 0, 'f:all': 0, 'f:web': 0, 'f:team': 0 };
    qa('#list .lr[data-src]').forEach(function (r) {
      var g = r.getAttribute('data-rt'), s = r.getAttribute('data-src'), h = r.getAttribute('data-q') || '';
      var hit = words.every(function (x) { return h.indexOf(x) >= 0; });
      if (hit && (sf === 'all' || sf === s)) cn['rt:' + g]++;
      if (hit && g === rt) { cn['f:all']++; cn['f:' + s]++; }
      var show = hit && g === rt && (sf === 'all' || sf === s);
      r.hidden = !show; if (show) n++;
    });
    qa('#list [data-count]').forEach(function (e) { e.textContent = cn[e.getAttribute('data-count')]; });
    var em = q('#list .emp'); if (em) em.hidden = n > 0;
    history.replaceState(null, '', path('bookings', null));
  }
  function openPanel(from) { var o = q('#add'); if (!o) return; opener = from; o.classList.add('open'); var n = q('#n'); if (n) n.focus(); }
  function closePanel(quiet) {
    var o = q('#add'); if (o) o.classList.remove('open');
    if (location.hash === '#add') history.replaceState(null, '', location.pathname + location.search);
    if (!quiet && opener && opener.focus) opener.focus();
  }
  function show(v, t) {
    closePanel(true);
    t = t || cur('.tab', 'data-tab', 'waiting');
    qa('[data-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== v; });
    qa('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== t; });
    mark('.nv', 'data-view', v); mark('.tab', 'data-tab', t);
    qa('.msg[role=status]').forEach(function (m) { m.hidden = true; });
    d.title = titles[v] || d.title;
    history.replaceState(null, '', path(v, t));
    window.scrollTo(0, 0);
  }
  function note(text) {
    var m = d.createElement('div'), mn = q('.main');
    m.className = 'msg bad'; m.setAttribute('role', 'alert'); m.textContent = text;
    if (mn) mn.insertBefore(m, mn.firstChild);
    window.scrollTo(0, 0);
  }
  function swap(url, text) {
    var doc = new DOMParser().parseFromString(text, 'text/html'), nw = doc.querySelector('.app'), old = q('.app');
    if (!nw || !old) { location.assign(url || base); return; }
    old.parentNode.replaceChild(d.importNode(nw, true), old);
    d.title = doc.title; history.replaceState(null, '', url);
    if (q('#add.open')) { var n = q('#n'); if (n) n.focus(); } else window.scrollTo(0, 0);
  }
  function load(u, o, undo) {
    fetch(u, o).then(function (r) {
      return r.text().then(function (t) { try { swap(r.url, t); } catch (e) { location.assign(base); } });
    }, function () { undo(); note('Could not reach the server. Check your connection and try again.'); });
  }
  d.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a');
    if (!a || e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (a.hasAttribute('data-open')) { e.preventDefault(); openPanel(a); }
    else if (a.hasAttribute('data-close')) { e.preventDefault(); closePanel(); }
    else if (a.hasAttribute('data-tab')) { e.preventDefault(); show('trips', a.getAttribute('data-tab')); }
    else if (a.hasAttribute('data-view')) { e.preventDefault(); show(a.getAttribute('data-view'), null); }
    else if (a.hasAttribute('data-filter')) { e.preventDefault(); mark('.fl', 'data-filter', a.getAttribute('data-filter')); sift(); }
    else if (a.hasAttribute('data-rt')) { e.preventDefault(); mark('.rtab', 'data-rt', a.getAttribute('data-rt')); sift(); }
    else if (a.hasAttribute('data-swap')) { e.preventDefault(); load(a.href, { credentials: 'same-origin' }, function () {}); }
  });
  d.addEventListener('input', function (e) { if (e.target && e.target.id === 'bq') sift(); });
  d.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.keyCode !== 27) return;
    var i = q('#bq');
    if (q('#add.open')) closePanel(); else if (i && d.activeElement === i && i.value) { i.value = ''; sift(); }
  });
  d.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute('data-search')) { e.preventDefault(); sift(); return; }
    if (!f.closest || !f.closest('.app') || f.hasAttribute('data-native') || f.hasAttribute('data-busy')) return;
    e.preventDefault();
    var b = e.submitter || f.querySelector('button'), was = b ? b.innerHTML : '';
    var post = (f.getAttribute('method') || 'get').toLowerCase() === 'post', u = f.getAttribute('action') || base;
    var data = new URLSearchParams(new FormData(f)), o = { credentials: 'same-origin' };
    if (post) { o.method = 'POST'; o.body = data; } else u += (u.indexOf('?') < 0 ? '?' : '&') + data.toString();
    f.setAttribute('data-busy', '1');
    if (b) { b.disabled = true; b.setAttribute('aria-busy', 'true'); b.textContent = 'Working…'; }
    load(u, o, function () { f.removeAttribute('data-busy'); if (b) { b.disabled = false; b.removeAttribute('aria-busy'); b.innerHTML = was; } });
  });
  if (location.hash === '#add') openPanel(null);
})();
JS;
}
function tr_ic($n) {       // small line icons, drawn inline (the page has no scripts and loads nothing)
    static $P = [
        'tag' => '<path d="M4 4h7l9 9-7 7-9-9V4z"/><path d="M8.5 8.5v.01"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'pulse' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'alert' => '<path d="M12 4 3 20h18L12 4z"/><path d="M12 10v4"/><path d="M12 17.5v.01"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 9.5"/>',
        'retry' => '<path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 4v5h-5"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . ($P[$n] ?? '') . '</svg>';
}
function tr_when($ms) {      // "Today, 10:00 AM" / "Tomorrow, 10:00 AM" / "Mon 9 Nov, 10:00 AM"
    $d = (new DateTime('@' . intdiv((int)$ms, 1000)))->setTimezone(et_tz());
    $today = new DateTime('today', et_tz());
    $diff = (int)$today->diff((clone $d)->setTime(0, 0))->format('%r%a');
    $day = $diff === 0 ? 'Today' : ($diff === 1 ? 'Tomorrow' : $d->format($d->format('Y') === $today->format('Y') ? 'D j M' : 'D j M Y'));
    return $day . ', ' . $d->format('g:i A');
}
function tr_head($title, $app = false) {
    header('Content-Type: text/html; charset=UTF-8');
    $sp = $app ? " script-src 'sha256-" . base64_encode(hash('sha256', tr_js(), true)) . "'; connect-src 'self';" : '';       // the one script, by its hash; fetch to this site only
    header("Content-Security-Policy: default-src 'none';" . $sp . " style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    $F = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    $M = 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="color-scheme" content="light"><title>' . m_esc($title) . '</title><style>'
        . '*{box-sizing:border-box}[hidden]{display:none!important}.pane{display:flex;flex-direction:column;gap:22px}body{margin:0;background:#f6f5f2;color:#111418;font:14px/1.45 ' . $F . '}button,input,select,textarea{font-family:inherit}'
        . 'h1{margin:0;font-size:26px;font-weight:600;letter-spacing:-.015em;line-height:1.2}h2{margin:0;font-size:17px;font-weight:600}p{margin:0}a{color:#2a5db0}a:hover{color:#184f95}'
        . ':focus-visible{outline:2px solid #2a78d6;outline-offset:2px}.m{color:#6b6a65;font-size:13px}.mono{font-family:' . $M . ';font-size:12.5px;letter-spacing:.03em;white-space:nowrap;color:#3b3a37}.fw{font-weight:500}.ic{width:18px;height:18px;flex:none;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}'
        // forms
        . 'label{display:block;font-size:13px;font-weight:500;color:#3b3a37;margin:0 0 6px}input[type=text],input[type=email],input[type=tel],input[type=number],input[type=date],input[type=password],select,textarea{width:100%;min-height:44px;padding:0 12px;border:1px solid #cfccc1;border-radius:10px;font:inherit;font-size:14px;background:#fff;color:#111418}textarea{padding:10px 12px;resize:vertical}'
        . '.g{display:grid;gap:12px;margin:0 0 12px}.g2{grid-template-columns:1fr 1fr}.g3{grid-template-columns:1fr 1fr 1fr}.chk{display:flex;gap:8px;align-items:flex-start;margin:12px 0 0}.chk input{margin-top:3px}.chk label{margin:0;color:#111418;font-size:14px;font-weight:400}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}'
        . '.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 16px;border:1px solid #cfccc1;border-radius:10px;background:#fff;color:#111418;font:600 14px/1 ' . $F . ';text-decoration:none;cursor:pointer;white-space:nowrap}.btn:hover{background:#faf9f6}a.btn,a.btn:hover{color:#111418}'
        . '.btn.pri{background:#111418;border-color:#111418;color:#fff}.btn.pri:hover{background:#2a2f38}a.btn.pri,a.btn.pri:hover{color:#fff}.btn.q{border-color:transparent;background:transparent;color:#a12a2a;padding:0 12px}.btn.q:hover{background:#fdf0f0}'
        . '.msg{padding:12px 16px;border-radius:12px;border:1px solid}.msg.ok{background:#e8f5e8;border-color:#bcd9bc}.msg.bad{background:#fdf0f0;border-color:#f1c8c8}.msg.warn{background:#fbf5e4;border-color:#e6d49a}'
        . '.card,.panel{background:#fff;border:1px solid #e4e2da;border-radius:16px}.card{padding:20px 22px}.card>h2{margin:0 0 12px}.cardl{padding:0;overflow:hidden}'
        // login
        . '.lw{max-width:460px;margin:0 auto;padding:56px 16px}.lw h1{margin:0 0 4px}.sub{color:#52514e;max-width:620px}'
        // shell: sidebar and main area
        . '.app{display:flex;flex-wrap:wrap;align-items:flex-start;min-height:100vh}.rail{flex:0 0 232px;background:#fff;border-right:1px solid #e4e2da;padding:24px 16px;display:flex;flex-direction:column;gap:24px}.brand{padding:0 8px;display:flex;flex-direction:column}.brand b{font-size:15px}'
        . '.navs{display:flex;flex-direction:column;gap:4px}.nv{display:flex;align-items:center;gap:10px;min-height:44px;padding:0 12px;border-radius:10px;color:#3b3a37;font-weight:500;text-decoration:none}.nv:hover{background:#f3f2ee;color:#111418}.nv[aria-current=page]{background:#eef3fb;color:#111418;font-weight:600}.nv[aria-current=page] .ic{color:#1c5cab}'
        . '.out{margin-top:auto;padding:0 8px}.out form{margin:0}.out button{background:none;border:0;padding:0 4px;min-height:44px;font:500 14px/1 ' . $F . ';color:#52514e;cursor:pointer}.out button:hover{color:#111418}'
        . '.main{flex:999 1 560px;min-width:0;padding:28px 32px 48px;display:flex;flex-direction:column;gap:22px}'
        . '.ph{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:14px 24px}.ph .tt{flex:1 1 360px;min-width:0;display:flex;flex-direction:column;gap:8px}.tr{display:flex;flex-wrap:wrap;align-items:center;gap:10px 12px}.acts{display:flex;flex-wrap:wrap;gap:10px}'
        . '.chip{display:inline-flex;align-items:center;gap:8px;min-height:28px;padding:0 12px;border-radius:999px;font-size:12.5px;font-weight:600;border:1px solid transparent;text-decoration:none;color:#111418}.chip i{width:8px;height:8px;border-radius:50%}a.chip:hover{color:#111418}'
        . '.chip.ok{background:#e8f5e8}.chip.ok i{background:#0ca30c}.chip.warn{background:#fbf5e4;border-color:#e6d49a}.chip.warn i{background:#fab219}.chip.bad{background:#fdf0f0;border-color:#f1c8c8}.chip.bad i{background:#d03b3b}'
        // overview: this month, and the next 30 days
        . '.ovr{display:flex;flex-wrap:wrap;gap:16px}.panel{padding:20px 22px;display:flex;flex-direction:column;gap:14px}.hero{flex:1 1 280px}.sched{flex:2 1 420px;min-width:0;gap:8px}.lbl{font-size:13px;font-weight:500;color:#52514e}.bigrow{display:flex;align-items:baseline;gap:10px}'
        . '.big{font-size:52px;font-weight:600;line-height:1;letter-spacing:-.03em}.of{font-size:18px;color:#6b6a65}.mtr{height:8px;border-radius:999px;background:#cde2fb;overflow:hidden}.mt{font-size:13px}.mt summary{cursor:pointer;color:#3b3a37;text-decoration:underline;text-underline-offset:3px;width:max-content}.mt .m{margin:8px 0 0}.mf{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}.mf input[type=number]{width:120px}.mtr b{display:block;height:100%;border-radius:999px;background:#2a78d6}.mtr.w{background:#fdeec8}.mtr.w b{background:#fab219}.mtr.d{background:#f8d4d4}.mtr.d b{background:#d03b3b}'
        . '.sh{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:4px 12px}.tl{position:relative;height:124px;padding:0 28px}.tlin{position:relative;height:100%}.tlbase{position:absolute;left:0;right:0;top:74px;height:1px;background:#c3c2b7}'
        . '.dot{position:absolute;top:74.5px;width:12px;height:12px;margin:-6px 0 0 -6px;border-radius:50%;background:#2a78d6;box-shadow:0 0 0 2px #fff}.tll{position:absolute;top:18px;transform:translateX(-50%);text-align:center;white-space:nowrap}.tll b{display:block;font-size:13px;font-weight:600}.tll span{font-size:12px;color:#6b6a65}'
        . '.tk{position:absolute;top:88px;transform:translateX(-50%);font-size:12px;color:#6b6a65;white-space:nowrap}.tle{position:absolute;left:0;right:0;top:30px;text-align:center;font-size:13px;color:#6b6a65}'
        // tabs and lists
        . '.tabs{display:flex;flex-wrap:wrap;gap:4px;padding:6px 14px 0;border-bottom:1px solid #eceae3}.tab,.rtab{display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:0 14px;border-bottom:2px solid transparent;margin-bottom:-1px;color:#52514e;font-weight:600;text-decoration:none}.tab:hover,.rtab:hover{color:#111418}.tab[aria-current=page],.rtab[aria-current=page]{color:#111418;border-bottom-color:#111418}'
        . '.pill{min-width:24px;height:24px;padding:0 8px;border-radius:999px;font-size:12.5px;font-weight:600;display:inline-grid;place-items:center;color:#111418;background:#eceae3}.pw{background:#e6effb}.pa{background:#fbe7e7}.ps{background:#e8f5e8}'
        . '.lh,.lr{display:grid;gap:16px;align-items:center;padding:12px 20px}.lh{padding:8px 20px;background:#faf9f6;font-size:12.5px;font-weight:500;color:#6b6a65}.lr{border-top:1px solid #eceae3}.lr>div{min-width:0}.lr b{font-weight:600}.lr .m{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.cw{grid-template-columns:minmax(0,1fr) 170px 130px 190px}.cs{grid-template-columns:110px minmax(0,1fr) 150px 170px}.cb{grid-template-columns:150px minmax(0,1fr) minmax(0,1fr) 100px 300px}'
        . '.cb .m{white-space:normal;overflow-wrap:anywhere}.fs{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px 16px;padding:0 20px 14px}.fb{display:flex;flex-wrap:wrap;gap:8px}.sb{position:relative;display:flex;align-items:center;flex:1 1 260px;max-width:380px;margin:0}.sb .ic{position:absolute;left:12px;color:#6b6a65;pointer-events:none}.sb input{width:100%;min-height:40px;padding:0 12px 0 38px;border:1px solid #cfccc1;border-radius:10px;font:inherit;font-size:14px;background:#fff;color:#111418}.sb .btn{margin-left:8px}html.js .sb .btn{display:none}.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}.chip.fl{background:#fff;border-color:#d9d6cc;color:#111418}.chip.fl[aria-current]{background:#e6effb;border-color:#b6cdee}.tg{display:inline-block;margin-top:4px;padding:1px 9px;border-radius:999px;background:#eceae3;font-size:12px;font-weight:600;color:#3b3a37}.tg.w{background:#e6effb}'
        . '.ac{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:4px}.ac form,.at form{margin:0}.er{font-size:13px;color:#a12a2a;margin-top:2px}.ck{display:inline-flex;align-items:center;gap:8px}.ck .ic{color:#0ca30c}'
        . '.at{display:flex;flex-wrap:wrap;align-items:center;gap:12px 18px;padding:16px 20px}.at+.at{border-top:1px solid #eceae3}.ibx{width:36px;height:36px;border-radius:10px;background:#fbe7e7;color:#b32d2d;display:grid;place-items:center}.atx{flex:1 1 280px;min-width:0}'
        . '.note{padding:12px 20px 16px;border-top:1px solid #eceae3;font-size:12.5px;color:#6b6a65}.emp{padding:28px 20px;color:#6b6a65}.ch{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:6px 16px;padding:18px 20px 14px}.ch>div{display:flex;align-items:center;gap:10px}'
        // the add-trip panel: opens with the Add trip button (:target), or straight away when a reference was looked up or the form needs another look
        . '.ov{display:none;position:fixed;top:0;right:0;bottom:0;left:0;z-index:50;justify-content:flex-end}.ov.open,html:not(.js) .ov:target{display:flex}.scrim{position:absolute;top:0;right:0;bottom:0;left:0;background:rgba(17,20,24,.32)}'
        . '.dr{position:relative;width:400px;max-width:100%;height:100%;overflow-y:auto;background:#fff;box-shadow:-12px 0 32px rgba(17,20,24,.14);padding:24px;display:flex;flex-direction:column;gap:16px}.dh{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.dh h2{font-size:18px}'
        . '.x{flex:none;width:44px;height:44px;margin:-6px -8px 0 0;border-radius:10px;display:grid;place-items:center;color:#3b3a37}.x:hover{background:#f3f2ee}.dr hr{border:0;border-top:1px solid #eceae3;width:100%;margin:0}.dr form{margin:0}.look{display:flex;gap:8px;align-items:flex-end}.look>div{flex:1 1 auto;min-width:0}.dfoot{display:flex;gap:10px;margin-top:14px}.dfoot .pri{flex:1 1 auto}'
        . '@media(min-width:761px){.rail{position:sticky;top:0;align-self:flex-start;height:100vh}}'
        . '@media(max-width:760px){.rail{flex:1 1 100%;flex-direction:row;flex-wrap:wrap;align-items:center;justify-content:space-between;border-right:0;border-bottom:1px solid #e4e2da;padding:12px 16px;gap:4px 12px}.brand .m{display:none}.navs{order:3;flex:1 1 100%;flex-direction:row;flex-wrap:wrap}.nv{padding:0 10px}.out{order:2;margin:0;padding:0}.main{padding:20px 16px 40px}.g2,.g3{grid-template-columns:1fr}.tabs{padding:6px 6px 0}.tab,.rtab{padding:0 10px;gap:6px;font-size:13px}'
        . '.lh{display:none}.lr{grid-template-columns:1fr !important;gap:6px;padding:14px 16px}.lr [data-l]::before{content:attr(data-l);display:block;font-size:12px;font-weight:600;color:#6b6a65}.ac{justify-content:flex-start}}'
        . mon_css()
        . '</style></head><body>' . ($app ? '' : '<div class="lw">');
}
function tr_page_login($err) {
    echo tr_head('Admin sign-in'), '<h1>Admin</h1><p class="sub" style="margin:0 0 16px">Private page for Fly First Class International: bookings, Trustpilot invitations and monitoring.</p>';
    if ($err !== '') echo '<div class="msg bad" role="alert" style="margin:0 0 16px">', m_esc($err), '</div>';
    if ((string)C('admin') === '') echo '<div class="card"><p>This page needs a private key. Add a line <b>ADMIN_KEY=a-long-random-phrase</b> to the .env file in ffc-private, then come back.</p></div>';
    else echo '<form class="card" method="post" action="', TR_PAGE, '"><input type="hidden" name="act" value="login"><label for="k">Admin key</label><input id="k" type="password" name="key" autocomplete="current-password" required autofocus><div class="row"><button class="btn pri" type="submit">Sign in</button></div></form>';
    echo '</div></body></html>'; exit;
}
function tr_flash($cls, $msg) { return '<div class="msg ' . m_esc($cls ?: 'ok') . '" role="status">' . m_esc($msg) . '</div>'; }
function tr_btn($t, $act, $id, $label, $cls = '', $field = 'id', $tab = '') {      // one small button that posts to the page
    return '<form method="post" action="' . TR_PAGE . '"><input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="' . $field . '" value="' . m_esc($id) . '"><input type="hidden" name="t" value="' . $t . '">'
        . ($tab !== '' ? '<input type="hidden" name="tab" value="' . m_esc($tab) . '">' : '') . '<button class="btn ' . $cls . '" type="submit">' . $label . '</button></form>';
}
function tr_rail($view, $t) {
    $nav = function ($v, $icon, $label) use ($view) {      // Monitoring is read fresh each time it is opened (data-swap); the others are already in the page and switch instantly (data-view)
        return '<a class="nv" href="' . TR_PAGE . ($v === 'trips' ? '' : '?v=' . $v) . '" ' . ($v === 'monitoring' ? 'data-swap' : 'data-view="' . $v . '"') . ($v === $view ? ' aria-current="page"' : '') . '>' . tr_ic($icon) . $label . '</a>';
    };
    return '<nav class="rail" aria-label="Admin sections"><div class="brand"><b>Fly First Class</b><span class="m">Private admin</span></div><div class="navs">'
        . $nav('bookings', 'tag', 'Booking requests') . $nav('trips', 'star', 'Trustpilot invitations') . $nav('email', 'mail', 'Email preview') . $nav('monitoring', 'pulse', 'Monitoring') . '</div>'
        . '<div class="out"><form method="post" action="' . TR_PAGE . '" data-native><input type="hidden" name="act" value="logout"><input type="hidden" name="t" value="' . $t . '"><button type="submit">Sign out</button></form></div></nav>';
}
function tr_timeline($q) {       // a 30-day line with a dot for each day that has invitations waiting
    $et = et_tz(); $start = (new DateTime('today', $et))->getTimestamp() * 1000; $span = 30 * 86400000; $g = []; $later = 0;
    foreach ($q as $r) {
        $d = max((int)($r['due'] ?? 0), (int)($r['next'] ?? 0));
        if ($d - $start > $span) { $later++; continue; }
        $k = $d < $start ? 'now' : (new DateTime('@' . intdiv($d, 1000)))->setTimezone($et)->format('Y-m-d');
        if (!isset($g[$k])) $g[$k] = ['pct' => max(0, min(100, ($d - $start) / $span * 100)), 'ms' => $d, 'r' => []];
        $g[$k]['r'][] = $r;
    }
    usort($g, function ($a, $b) { return $a['pct'] <=> $b['pct']; });
    $tick = function ($days) use ($start, $et) { return (new DateTime('@' . intdiv($start + $days * 86400000, 1000)))->setTimezone($et)->format('j M'); };
    $days = []; foreach ($g as $x) $days[] = (new DateTime('@' . intdiv($x['ms'], 1000)))->setTimezone($et)->format('D j M');
    $nq = count($q);
    $h = '<div class="panel sched"><div class="sh"><span class="lbl">Next 30 days</span><span class="m">' . ($nq ? $nq . ' invitation' . ($nq === 1 ? '' : 's') . ' scheduled' . ($later ? ' (' . $later . ' later than 30 days)' : '') : 'Nothing scheduled') . '</span></div>'
        . '<div class="tl" role="img" aria-label="' . m_esc($g ? 'Invitations scheduled in the next 30 days on: ' . implode(', ', $days) : 'Nothing scheduled in the next 30 days') . '"><div class="tlin"><div class="tlbase"></div>';
    $last = -100;
    foreach ($g as $x) {
        $pct = number_format($x['pct'], 1, '.', ''); $names = [];
        foreach ($x['r'] as $r) $names[] = trim((string)($r['n'] ?? ''));
        $tip = m_esc(implode('; ', $names) . ', ' . tr_when($x['ms']));
        $h .= '<i class="dot" style="left:' . $pct . '%" title="' . $tip . '"></i>';
        if ($x['pct'] - $last >= 11) {          // a dot too close to the last label keeps its tooltip and skips the label
            $last = $x['pct'];
            $h .= '<div class="tll" style="left:' . $pct . '%" title="' . $tip . '"><b>' . (count($x['r']) === 1 ? m_esc(tr_initials($x['r'][0]['n'] ?? '')) : count($x['r']) . ' invites') . '</b><span>' . m_esc((new DateTime('@' . intdiv($x['ms'], 1000)))->setTimezone($et)->format('D j M')) . '</span></div>';
        }
    }
    if (!$g) $h .= '<div class="tle">Nothing scheduled in the next 30 days.</div>';
    return $h . '<div class="tk" style="left:0">Today</div><div class="tk" style="left:50%">' . $tick(15) . '</div><div class="tk" style="left:100%">' . $tick(30) . '</div></div></div></div>';
}
function tr_view_trips($X) {
    $cfg = $X['cfg']; $q = $X['q']; $s = $X['s']; $x = $X['x']; $t = $X['t']; $tab = $X['tab']; $n = $X['n'];
    $used = tr_used(); $cap = $cfg['cap']; $nq = count($q); $nx = count($x); $ns = count($s); $bcc = $cfg['mode'] === 'bcc'; $days = (int)$cfg['days'];
    echo '<header class="ph"><div class="tt"><div class="tr"><h1>Trustpilot invitations</h1>', $bcc ? '<span class="chip ok"><i></i>Automatic invitations on</span>' : '<span class="chip warn"><i></i>Review link only</span>',
        $nx ? '<a class="chip bad" href="' . TR_PAGE . '?tab=attention" data-tab="attention"><i></i>' . $nx . ' needs attention</a>' : '', '</div>',
        '<p class="sub">Thank-you emails go out ', $days, ' day', $days === 1 ? '' : 's', ' after the return date, at 10:00 AM Eastern. ', $bcc ? 'Trustpilot then sends its own invitation.' : 'The email carries a link to review us on Trustpilot.', '</p></div>',
        '<div class="acts"><a class="btn" href="', TR_PAGE, '?v=email" data-view="email">', tr_ic('mail'), 'Preview email</a><a class="btn pri" href="#add" data-open>', tr_ic('plus'), 'Add trip</a></div></header>';
    if (!$bcc) echo '<div class="msg warn"><b>Trustpilot address not set.</b> Until <b>TRUSTPILOT_BCC</b> is added to the .env file, the thank-you email carries the plain Trustpilot review link instead.</div>';

    // this month, and the next 30 days. The count is the invitations that went to Trustpilot (an email with only the review link uses none).
    $emails = tr_emails_month(); $adj = $bcc ? tr_adjust_info() : null;
    $shown = $bcc ? $used : $emails; $capv = $bcc ? $cap : 0;
    $pct = $capv ? min(100, $shown / $capv * 100) : 0; $sev = !$capv ? '' : ($shown >= $capv ? ' d' : ($shown >= 0.8 * $capv ? ' w' : ''));
    $reset = (new DateTime('first day of next month', et_tz()))->format('j M');
    echo '<section class="ovr" aria-label="Overview"><div class="panel hero"><span class="lbl">', $bcc ? 'Invitations this month' : 'Thank-you emails this month', '</span><div class="bigrow"><span class="big">', $shown, '</span>', $capv ? '<span class="of">of ' . $capv . '</span>' : '', '</div>',
        $capv ? '<div class="mtr' . $sev . '" role="progressbar" aria-label="Invitations sent this month" aria-valuemin="0" aria-valuemax="' . $capv . '" aria-valuenow="' . $shown . '"><b style="width:' . number_format($pct, 1, '.', '') . '%"></b></div>' : '',
        '<span class="m">', $bcc ? ($capv ? max(0, $capv - $shown) . ' left, resets ' . $reset : 'No monthly limit') : 'Only the review link is sent, so none of Trustpilot\'s invitations are used', '</span>';
    if ($bcc && $emails !== $shown) echo '<span class="m">', $emails, ' thank-you email', $emails === 1 ? '' : 's', ' sent this month in all.</span>';
    if ($adj) echo '<span class="m">Matched to Trustpilot: it showed ', (int)$adj['to'], ' on ', m_esc(tr_day($adj['set'] ?? 0)), '.</span>';
    if ($bcc) {
        echo '<details class="mt"', $adj ? ' open' : '', '><summary>Not the same as Trustpilot?</summary><p class="m">Trustpilot\'s own dashboard has the final count. It can skip an address it has invited before, and it also counts invitations sent from anywhere else. Enter the number it shows for this month and this meter follows it from here (it starts again each month).</p>',
            '<form class="mf" method="post" action="', TR_PAGE, '"><input type="hidden" name="act" value="trmatch"><input type="hidden" name="t" value="', $t, '"><label class="sr" for="tm">Invitations Trustpilot shows this month</label><input id="tm" type="number" name="n" min="0" max="100000" inputmode="numeric" value="', $used, '" required><button class="btn" type="submit">Match</button>',
            $adj ? '</form><form class="mf" method="post" action="' . TR_PAGE . '"><input type="hidden" name="act" value="trclear"><input type="hidden" name="t" value="' . $t . '"><button class="btn" type="submit">Go back to our own count</button>' : '', '</form></details>';
    }
    echo '</div>', tr_timeline($q), '</section>';

    // the three lists, one at a time
    $tabs = ['waiting' => ['Waiting to send', $nq, 'pw'], 'attention' => ['Needs attention', $nx, 'pa'], 'sent' => ['Sent', $ns, 'ps']];
    echo '<section class="card cardl" aria-label="Invitations"><nav class="tabs" aria-label="Invitation lists">';
    foreach ($tabs as $k => $d) echo '<a class="tab" href="', TR_PAGE, $k === 'waiting' ? '' : '?tab=' . $k, '" data-tab="', $k, '"', $k === $tab ? ' aria-current="page"' : '', '>', $d[0], '<span class="pill ', $d[1] ? $d[2] : '', '">', $d[1], '</span></a>';
    echo '</nav>';
    echo '<div data-panel="waiting"', $tab === 'waiting' ? '' : ' hidden', '>';
    {
        if (!$q) echo '<p class="emp">Nothing waiting. Add a trip once it is booked.</p>';
        else {
            echo '<div class="lh cw"><span>Customer</span><span>Sends</span><span>Reference</span><span></span></div>';
            foreach ($q as $r) {
                $busy = ($r['st'] ?? '') === 'sending'; $at = max((int)($r['due'] ?? 0), (int)($r['next'] ?? 0));
                echo '<div class="lr cw"><div><b>', m_esc($r['n'] ?? ''), '</b><div class="m">', m_esc($r['e'] ?? ''), '</div>';
                if (!empty($r['err']) && !$busy) echo '<div class="er">Last try failed: ', m_esc($r['err']), '. Trying again ', m_esc(tr_day($r['next'] ?? $n)), '.</div>';
                echo '</div><div data-l="Sends"><div class="fw">', $busy ? 'Sending now' : m_esc(tr_when($at)), '</div><div class="m">Back ', m_esc(m_date($r['r'] ?? '')), '</div></div>',
                    '<div data-l="Reference">', !empty($r['ref']) ? '<span class="mono">' . m_esc($r['ref']) . '</span>' : '<span class="m">None</span>', '</div><div class="ac">',
                    $busy ? '' : tr_btn($t, 'sendnow', $r['id'], 'Send now', '', 'id', 'waiting') . tr_btn($t, 'remove', $r['id'], 'Remove', 'q', 'id', 'waiting'), '</div></div>';
            }
        }
    }
    echo '</div><div data-panel="attention"', $tab === 'attention' ? '' : ' hidden', '>';
    {
        if (!$x) echo '<p class="emp">Nothing needs attention.</p>';
        else {
            foreach ($x as $r) echo '<div class="at"><span class="ibx">', tr_ic('alert'), '</span><div class="atx"><b>', m_esc($r['n'] ?? ''), '</b> <span class="m">', m_esc($r['e'] ?? ''), '</span><div class="m">Back ', m_esc(m_date($r['r'] ?? '')), '</div><div class="er">', m_esc($r['err'] ?? ''), '</div></div><div class="ac">',
                tr_btn($t, 'retry', $r['id'], tr_ic('retry') . 'Try again', '', 'id', 'attention') . tr_btn($t, 'remove', $r['id'], 'Remove', 'q', 'id', 'attention'), '</div></div>';
            echo '<p class="note">A failed email is tried again after 1 hour, 6 hours and 1 day before it lands here. Look in Sent mail for the customer before pressing Try again.</p>';
        }
    }
    echo '</div><div data-panel="sent"', $tab === 'sent' ? '' : ' hidden', '>';
    {
        if (!$s) echo '<p class="emp">Nothing sent yet. Names and addresses are deleted as soon as the email has gone; this list keeps initials only, for 90 days.</p>';
        else {
            echo '<div class="lh cs"><span>Customer</span><span>Reference</span><span>Back on</span><span>Sent</span></div>';
            foreach (array_slice($s, 0, 30) as $r) echo '<div class="lr cs"><div><b>', m_esc($r['i'] ?? '?'), '</b></div><div data-l="Reference">', !empty($r['ref']) ? '<span class="mono">' . m_esc($r['ref']) . '</span>' : '<span class="m">None</span>', '</div><div data-l="Back on">', m_esc(m_date($r['r'] ?? '')),
                '</div><div data-l="Sent"><span class="ck">', tr_ic('check'), m_esc(tr_day($r['sent'] ?? 0)), '</span>', ($r['via'] ?? '') === 'bcc' ? '' : '<div class="m">Review link in email</div>', '</div></div>';
            echo '<p class="note">Names and addresses are deleted as soon as the email has gone. This list keeps initials only, for 90 days.</p>';
        }
    }
    echo '</div></section>';
    tr_drawer($X);
}
function tr_drawer($X) {     // the "add a completed trip" panel
    $cfg = $X['cfg']; $f = $X['f']; $t = $X['t']; $days = (int)$cfg['days'];
    $close = TR_PAGE . ($X['tab'] !== 'waiting' ? '?tab=' . $X['tab'] : '');
    echo '<div class="ov', $X['open'] ? ' open' : '', '" id="add"><a class="scrim" href="', $close, '" data-close aria-label="Close the add trip panel"></a><aside class="dr" role="dialog" aria-modal="true" aria-labelledby="add-t">',
        '<div class="dh"><div><h2 id="add-t">Add a completed trip</h2><p class="m" style="margin-top:4px">Add each customer once their trip is booked.</p></div><a class="x" href="', $close, '" data-close aria-label="Close">', tr_ic('x'), '</a></div>';
    if ($X['open'] && $X['msg'] !== '') echo tr_flash($X['cls'], $X['msg']);
    echo '<form class="look" method="get" action="', TR_PAGE, '"><div><label for="lf">Start from a booking reference ID</label><input id="lf" type="text" name="from" maxlength="30" placeholder="FFC-7K2M9QXA" autocomplete="off"></div><button class="btn" type="submit">Fill in</button></form><hr>',
        '<form method="post" action="', TR_PAGE, '"><input type="hidden" name="act" value="add"><input type="hidden" name="t" value="', $t, '">',
        '<div class="g"><div><label for="n">Customer name</label><input id="n" type="text" name="name" maxlength="80" autocomplete="off" required value="', m_esc($f['name'] ?? ''), '"></div>',
        '<div><label for="e">Email</label><input id="e" type="email" name="email" maxlength="254" autocomplete="off" required value="', m_esc($f['email'] ?? ''), '"></div>',
        '<div><label for="r">Date they get back</label><input id="r" type="date" name="ret" required value="', m_esc($f['ret'] ?? ''), '"></div>',
        '<div><label for="rf">Booking reference (optional)</label><input id="rf" type="text" name="ref" maxlength="30" autocomplete="off" value="', m_esc($f['ref'] ?? ''), '"></div></div>';
    if (!empty($f['ask'])) echo '<div class="chk"><input id="fc" type="checkbox" name="force" value="1"><label for="fc">This is a different trip from the earlier one. Add it anyway.</label></div>';
    echo '<p class="m" style="margin-top:12px">Goes out ', $days, ' day', $days === 1 ? '' : 's', ' after the return date, at 10:00 AM Eastern. One-way trips: use the arrival date. A reference ID is sent to Trustpilot with the invitation.</p>',
        '<div class="dfoot"><button class="btn pri" type="submit">', tr_ic('plus'), 'Add trip</button><a class="btn" href="', $close, '" data-close>Cancel</a></div></form></aside></div>';
}
function tr_view_bookings($X) {      // $b fills the booking request form after a mistake
    $b = $X['b']; $t = $X['t'];
    $bv = function ($k, $d = '') use ($b) { return m_esc($b[$k] ?? $d); };
    $sel = function ($k, $v, $d = false) use ($b) { return ((string)($b[$k] ?? '') === $v || (!isset($b[$k]) && $d)) ? ' selected' : ''; };
    echo '<header class="ph"><div class="tt"><h1>Booking requests</h1><p class="sub">Every request we keep, sent through the website or made here for a quote done outside it (phone, email, a Mailchimp reply). Each has a reference ID, so the customer can find it on Manage Booking and you can use it when their trip is added.</p></div></header>';
    $all = tr_book_list(); $total = count($all); $bl = array_slice($all, 0, TR_BK_MAX); $nw = $nt = 0;
    foreach ($all as $r) { if (tr_src($r) === 'team') $nt++; else $nw++; }
    $have = [];      // references that are already on a completed trip
    foreach (cload(TR_FILE, []) as $tp) if (!empty($tp['ref'])) $have[$tp['ref']] = true;
    $both = $nw && $nt; $sf = $both ? ($X['src'] ?? 'all') : 'all'; $rt = $X['rt'] ?? 'route'; $qv = $X['bq'] ?? ''; $words = tr_bk_words($qv);
    $rows = []; $cn = ['rt:route' => 0, 'rt:none' => 0, 'f:all' => 0, 'f:web' => 0, 'f:team' => 0]; $shown = 0;
    foreach ($bl as $r) {      // each row, and what each tab and button would show: the same sums the page script makes when you type or click
        $src = tr_src($r); $g = tr_has_route($r) ? 'route' : 'none'; $dq = tr_bk_text($r); $hit = tr_bk_hit($dq, $words);
        if ($hit && ($sf === 'all' || $sf === $src)) $cn['rt:' . $g]++;
        if ($hit && $g === $rt) { $cn['f:all']++; $cn['f:' . $src]++; }
        $show = $hit && $g === $rt && ($sf === 'all' || $sf === $src); if ($show) $shown++;
        $rows[] = [$r, $src, $g, $dq, $show];
    }
    if ($total) {
        echo '<section class="card cardl" id="list" aria-label="Booking requests"><div class="ch"><div><h2>All requests (', $total, ')</h2></div><span class="m">Newest first. Kept for six months from the last change.', $total > TR_BK_MAX ? ' Showing the newest ' . TR_BK_MAX . '.' : '', '</span></div>',
            '<div class="fs"><form class="sb" method="get" action="', TR_PAGE, '" role="search" data-search><input type="hidden" name="v" value="bookings">', $rt !== 'route' ? '<input type="hidden" name="rt" value="' . $rt . '">' : '', $sf !== 'all' ? '<input type="hidden" name="src" value="' . $sf . '">' : '',
            '<label class="sr" for="bq">Search requests by reference ID or name</label>', tr_ic('search'), '<input id="bq" type="search" name="q" maxlength="80" placeholder="Search by reference ID or name" autocomplete="off" value="', m_esc($qv), '"><button class="btn" type="submit">Search</button></form>';
        if ($both) {
            echo '<div class="fb" role="group" aria-label="Where the request came from">';
            foreach (['all' => ['All', 'f:all'], 'web' => ['Website', 'f:web'], 'team' => ['Created here', 'f:team']] as $k => $c)
                echo '<a class="chip fl" data-filter="', $k, '" href="', tr_bk_url($rt, $k, $qv), '"', $sf === $k ? ' aria-current="page"' : '', '>', $c[0], ' <span class="m" data-count="', $c[1], '">', $cn[$c[1]], '</span></a>';
            echo '</div>';
        }
        echo '</div><nav class="tabs" aria-label="Requests by route">';
        foreach (['route' => ['With a route', 'rt:route'], 'none' => ['No route yet', 'rt:none']] as $k => $c)
            echo '<a class="rtab" data-rt="', $k, '" href="', tr_bk_url($k, $sf, $qv), '"', $rt === $k ? ' aria-current="page"' : '', '>', $c[0], '<span class="pill" data-count="', $c[1], '">', $cn[$c[1]], '</span></a>';
        echo '</nav><div class="lh cb"><span>Reference ID</span><span>Customer</span><span>Trip</span><span>Created</span><span></span></div>';
        foreach ($rows as $w) {
            list($r, $src, $g, $dq, $show) = $w;
            $c = $r['contact'] ?? []; $tp = $r['trip'] ?? []; $mine = $src === 'team'; $ref = (string)$r['ref'];
            echo '<div class="lr cb" data-src="', $src, '" data-rt="', $g, '" data-q="', m_esc($dq), '"', $show ? '' : ' hidden', '><div><b class="mono">', m_esc($ref), '</b><div><span class="tg', $mine ? '' : ' w', '">', $mine ? 'Created here' : 'Website', '</span></div></div>',
                '<div data-l="Customer">', m_esc(trim(($c['first'] ?? '') . ' ' . ($c['last'] ?? ''))) ?: '<span class="m">No name</span>', '<div class="m">', m_esc($c['email'] ?? ''), '</div></div>',
                '<div data-l="Trip">', m_esc(implode(' to ', array_filter([$tp['from'] ?? '', $tp['to'] ?? ''])) ?: 'No route yet'), '<div class="m">', m_esc(implode(', ', array_filter([$tp['cabin'] ?? '', !empty($tp['dep']) ? m_date($tp['dep']) : '']))), '</div></div>',
                '<div data-l="Created">', m_esc(tr_day($r['created'] ?? 0)), '</div><div class="ac">';
            if (isset($have[$ref])) echo '<span class="ck">', tr_ic('check'), 'In invitations</span>';
            else echo '<a class="btn" href="', TR_PAGE, '?from=', rawurlencode($ref), '#add" data-swap>Use for a completed trip</a>';
            if ($mine) echo tr_btn($t, 'bookdel', $ref, 'Delete', 'q', 'ref');
            echo '</div></div>';
        }
        echo '<div class="emp"', $shown ? ' hidden' : '', '>No requests to show here. Try another name or reference ID, or look in the other list.</div></section>';
    }
    echo '<form class="card" id="book" method="post" action="', TR_PAGE, '"><h2>Create a booking request</h2><p class="m" style="margin:-6px 0 14px">It gets a reference ID like one sent through the site (FFC-XXXXXXXX), kept for six months from its last change, and the customer can look it up on Manage Booking.</p>',
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
        '<div class="row"><button class="btn pri" type="submit">', tr_ic('plus'), 'Create request</button></div></form>';
}
function tr_view_email($X) {
    $cfg = $X['cfg']; $t = $X['t']; $days = (int)$cfg['days']; $cap = $cfg['cap'];
    echo '<header class="ph"><div class="tt"><h1>Email preview</h1><p class="sub">The thank-you email each customer gets after their trip. A sample does not copy Trustpilot, so it uses none of your monthly invitations.</p></div></header>',
        '<section class="card"><h2>What goes out</h2><p>Sent ', $days, ' day', $days === 1 ? '' : 's', ' after the return date, at 10:00 AM Eastern. ', $cfg['mode'] === 'bcc' ? 'Each email copies your Trustpilot address, and Trustpilot then invites the customer.' : 'Each email carries the plain Trustpilot review link, because no Trustpilot address is set.',
        $cap ? ' Sending pauses at ' . $cap . ' a month.' : ' There is no monthly limit.', '</p><div class="row"><a class="btn" href="', TR_PAGE, '?preview=1" target="_blank" rel="noopener">', tr_ic('mail'), 'Open the email in a new tab</a></div></section>',
        '<form class="card" method="post" action="', TR_PAGE, '"><h2>Send yourself a sample</h2><input type="hidden" name="act" value="sample"><input type="hidden" name="t" value="', $t, '"><label for="to">Send a sample to</label><input id="to" type="email" name="to" maxlength="254" required><div class="row"><button class="btn pri" type="submit">Send sample</button></div></form>';
}
function tr_bk_rt() {      // which list of requests is showing: "route" (both cities filled in) or "none". With no choice made it is the list the request named in the address (r=) belongs to.
    $rt = (string)($_GET['rt'] ?? '');
    if ($rt === 'route' || $rt === 'none') return $rt;
    $ref = strtoupper((string)($_GET['r'] ?? '')); $one = preg_match(QREF, $ref) ? q_find($ref) : null;
    return ($one && !tr_has_route($one)) ? 'none' : 'route';
}
// $f fills the add-a-trip panel (and opens it when it has open => true), $b the booking request form (both after a mistake, or from a reference). $view: trips, bookings or email.
function tr_page($cls = '', $msg = '', $f = [], $b = [], $view = '') {
    $view = in_array($view, ['trips', 'bookings', 'email', 'monitoring'], true) ? $view : 'trips';
    $cfg = tr_cfg(); $T = cload(TR_FILE, []); $t = tr_token(); $n = now_ms();
    $q = $s = $x = [];
    foreach ($T as $r) { $st = $r['st'] ?? ''; if ($st === 'sent') $s[] = $r; elseif ($st === 'failed') $x[] = $r; elseif (in_array($st, ['queued', 'sending'], true)) $q[] = $r; }
    usort($q, function ($a, $b) { return ($a['due'] ?? 0) <=> ($b['due'] ?? 0); });
    usort($s, function ($a, $b) { return ($b['sent'] ?? 0) <=> ($a['sent'] ?? 0); });
    usort($x, function ($a, $b) { return ($a['due'] ?? 0) <=> ($b['due'] ?? 0); });
    $X = ['cfg' => $cfg, 'q' => $q, 's' => $s, 'x' => $x, 't' => $t, 'n' => $n, 'f' => $f, 'b' => $b, 'tab' => tr_tab($_GET['tab'] ?? ''), 'src' => in_array($_GET['src'] ?? '', ['web', 'team'], true) ? $_GET['src'] : 'all', 'bq' => trim(qs($_GET['q'] ?? '', 80)), 'rt' => tr_bk_rt(), 'cls' => $cls, 'msg' => $msg, 'open' => $view === 'trips' && !empty($f['open'])];
    $titles = ['trips' => 'Trustpilot invitations', 'bookings' => 'Booking requests', 'email' => 'Email preview', 'monitoring' => 'Monitoring'];
    echo tr_head($titles[$view], true), '<div class="app">', tr_rail($view, $t), '<main class="main">';
    if ($msg !== '' && !$X['open']) echo tr_flash($cls, $msg);
    foreach ($titles as $k => $unused) {      // the first three views are all in the page so they can switch instantly; only the current one shows. Monitoring is only drawn when it is the one asked for, so its numbers are always fresh.
        if ($k === 'monitoring' && $view !== 'monitoring') continue;
        echo '<div class="pane" data-pane="', $k, '"', $k === $view ? '' : ' hidden', '>';
        if ($k === 'bookings') tr_view_bookings($X); elseif ($k === 'email') tr_view_email($X); elseif ($k === 'monitoring') mon_view($X); else tr_view_trips($X);
        echo '</div>';
    }
    echo '</main></div><script>', tr_js(), '</script></body></html>'; exit;
}
