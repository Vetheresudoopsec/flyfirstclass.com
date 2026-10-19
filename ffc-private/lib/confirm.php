<?php
// confirm.php: the "Thank you for your request" email a visitor receives after sending a quote request (the hotel-edition design).
//
// When it is sent
//   Only for a NEW quote request that names a route (an origin and a destination) and has a usable email address.
//   Nothing is sent for a plain message with no route, a change to an existing request, a booking enquiry or a check-in request.
//   It goes out after the visitor's page has already been answered, so it never slows the form down, and a failure here never
//   affects the email to the team. One confirmation per reference ID, at most 3 a day to the same address, 300 an hour overall.
//
// Settings (.env)
//   CUSTOMER_CONFIRMATION=off      turns the visitor email off (default: on)
//
// Preview it without sending a real request (needs ADMIN_KEY):
//   /api/mail-check?key=ADMIN_KEY&preview=confirmation          round-trip
//   /api/mail-check?key=ADMIN_KEY&preview=confirmation-oneway   one way
//   /api/mail-check?key=ADMIN_KEY&preview=confirmation-multi    multi-city
//   add &send=1&to=you@example.com to send that sample to yourself as well.
if (!defined('FFC_PRIVATE')) { exit; }

const CM_SITE = 'https://flyfirstclass.com';
const CM_PHONE = '212-221-1860';
const CM_TEL = '+12122211860';
const CM_ADDRESS = '100 Church St, New York, NY 10007';
const CM_NAME = 'Fly First Class';

// A plain, conservative address check for the one place we write to a visitor's address: no commas, quotes, brackets or spaces.
function cm_addr($e) {
    $e = trim((string)$e);
    if ($e === '' || strlen($e) > 254 || !preg_match('/^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/', $e)) return '';
    if ($e[0] === '.' || strpos($e, '..') !== false || strpos($e, '.@') !== false) return '';
    return $e;
}
function cm_name($s) {                       // names typed all in lower or upper case are tidied ("alex" > "Alex"); anything mixed is left as typed
    $s = trim((string)$s);
    if ($s !== '' && ($s === mb_strtolower($s) || $s === mb_strtoupper($s))) $s = mb_convert_case($s, MB_CASE_TITLE);
    return mb_substr($s, 0, 40);
}
function cm_place($s) {                      // "Hong Kong (HKG)" > ['code' => 'HKG', 'city' => 'Hong Kong']
    $s = trim((string)$s);
    if (preg_match('/^(.*\S)\s*\(([A-Za-z]{3})\)$/u', $s, $m)) { $c = strtoupper($m[2]); $n = trim($m[1]); return ['code' => $c, 'city' => strcasecmp($n, $c) === 0 ? '' : $n]; }
    if (preg_match('/^[A-Za-z]{3}$/', $s)) return ['code' => strtoupper($s), 'city' => ''];
    return ['code' => '', 'city' => $s];
}
function cm_day($d, $yr) {                   // "12 Dec", or "12 Dec 2027" when it is not in the year $yr
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return '';
    $mo = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'][(int)$m[2] - 1];
    return (int)$m[3] . ' ' . $mo . ((int)$m[1] !== $yr ? ' ' . $m[1] : '');
}
function cm_when($a, $b, $flex, $yr) {       // one date, or the window when the visitor's dates are flexible
    $x = cm_day($a, $yr); if ($x === '') return '';
    $y = ($flex && $b && $b !== $a) ? cm_day($b, $yr) : '';
    return $y === '' ? $x : $x . ' - ' . $y;
}
function cm_cabin($c) { return preg_replace('/\s+class$/i', '', trim((string)$c)); }

// What would be sent for this request, or null when nothing should be. $p is the body the page sent to /api/send.
function confirm_mail($p, $ref) {
    if (in_array(strtolower(trim(env('CUSTOMER_CONFIRMATION', 'on'))), ['off', 'no', 'false', '0'], true)) return null;
    $kind = (string)($p['kind'] ?? 'new');
    if ($kind !== '' && $kind !== 'new') return null;                       // changes, booking enquiries and check-in requests are not new quote requests
    $t = qTrip($p['trip'] ?? null);
    if ($t['from'] === '' || $t['to'] === '') return null;                  // no route, no confirmation
    $c = qWho($p['contact'] ?? null); $to = cm_addr($c['email']);
    if ($to === '') return null;
    return confirm_build($t, $c, $ref, $to);
}

function confirm_build($t, $c, $ref, $to) {
    $yr = (int)gmdate('Y');
    $F = "Manrope,'Helvetica Neue',Helvetica,Arial,sans-serif";
    $first = cm_name($c['first']);
    $pts = []; foreach (array_merge([$t['from'], $t['to']], $t['via']) as $s) if ($s !== '') $pts[] = cm_place($s);
    $multi = count($pts) > 2;
    $types = ['One way' => 'One Way', 'Multi-city' => 'Multi-City', 'Round-trip' => 'Round-Trip'];
    $back = !$multi && ($t['type'] === 'Round-trip' || ($t['ret'] !== '' && $t['type'] !== 'One way'));
    $dep = cm_when($t['dep'], $t['dep2'], $t['flex'], $yr) ?: 'To confirm';
    $second = $back ? ['Return', cm_when($t['ret'], $t['ret2'], $t['flex'], $yr) ?: 'To confirm']
                    : ['Trip', $multi ? 'Multi-City' : ($types[$t['type']] ?? ($t['type'] !== '' ? $t['type'] : '-'))];
    $cabin = cm_cabin($t['cabin']) ?: '-';
    $guestsN = $t['adults'] + $t['children']; $guests = $guestsN > 0 ? (string)$guestsN : '-';
    $details = [['Depart', $dep], $second, ['Cabin', $cabin], ['Guests', $guests]];
    $phone = $c['phone'] !== '' ? $c['phone'] : 'Not given';
    $label = function ($p) { return $p['city'] !== '' ? $p['city'] : $p['code']; };
    $flights = max(1, count($pts) - 1);

    // ---- Subject ----
    $subject = $multi ? 'Your multi-city request (' . $ref . ')'
        : 'Your request: ' . mb_substr($label($pts[0]), 0, 30) . ' to ' . mb_substr($label($pts[1]), 0, 30) . ' (' . $ref . ')';

    // ---- Plain text ----
    $tx = [($first !== '' ? 'Thank you for your request, ' . $first . '.' : 'Thank you for your request.'), 'Your advisor will be with you shortly.', ''];
    if ($multi) {
        $tx[] = 'YOUR ITINERARY (' . $flights . ' flights)';
        foreach ($pts as $i => $p) $tx[] = ($i + 1) . '. ' . trim($p['code'] . ' ' . $p['city']);
    } else {
        foreach ([$pts[0], $pts[1]] as $p) $tx[] = trim($p['code'] . ' ' . $p['city']);
    }
    $tx[] = '';
    foreach ($details as [$k, $v]) $tx[] = $k . ': ' . $v;
    $tx[] = 'Email: ' . $c['email']; $tx[] = 'Phone: ' . $phone; $tx[] = '';
    $tx[] = 'Reference: ' . $ref; $tx[] = '';
    $tx[] = 'ANY QUESTIONS?'; $tx[] = 'Call: ' . CM_PHONE; $tx[] = 'Email: ' . MAIL_INTENDED; $tx[] = CM_ADDRESS; $tx[] = '';
    $tx[] = CM_SITE; $tx[] = 'Privacy: ' . CM_SITE . '/privacy-policy/'; $tx[] = 'Terms: ' . CM_SITE . '/terms/';
    $text = implode("\n", $tx);

    // ---- HTML (tables and inline styles only, so mail apps draw it the same way) ----
    $logo = function ($px, $style = '') { return '<img src="' . CM_SITE . '/email-logo.png" width="' . $px . '" height="' . $px . '" alt="" style="display:block;border:0;outline:none;' . $style . '">'; };
    $cap = "font:600 10px/1.3 $F;letter-spacing:.16em;text-transform:uppercase;color:#59616f";
    $rule = function ($cls = '', $w = 56) { return '<td class="' . $cls . '" width="' . $w . '" valign="middle" style="width:' . $w . 'px;padding:0"><div style="height:1px;line-height:1px;font-size:1px;mso-line-height-rule:exactly;background:#d9c9a2">&nbsp;</div></td>'; };
    $kv = function ($k, $v, $weight) use ($cap, $F) {
        return '<div style="' . $cap . '">' . m_esc($k) . '</div><div style="padding-top:4px;font:' . $weight . " 14px/1.4 $F;color:#14181f;word-break:break-word;overflow-wrap:anywhere\">" . m_esc($v) . '</div>';
    };
    $slot = function ($p, $right) use ($F, $cap) {
        $big = $p['code'] !== '' ? $p['code'] : mb_substr($p['city'], 0, 14);
        $small = $p['code'] !== '' ? $p['city'] : '';
        return '<td valign="middle" align="' . ($right ? 'right' : 'left') . '" style="padding:0;text-align:' . ($right ? 'right' : 'left') . '"><div class="cd" style="font:500 ' . ($p['code'] !== '' ? 38 : 20) . "px/1 $F;letter-spacing:.04em;color:#14181f\">" . m_esc($big) . '</div>'
            . ($small !== '' ? '<div style="padding-top:6px;' . $cap . ';font-size:11px">' . m_esc($small) . '</div>' : '') . '</td>';
    };

    if (!$multi) {
        $route = '<tr><td class="tk" style="padding:26px 28px 22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed"><tr>'
            . $slot($pts[0], false)
            . '<td class="mid" width="150" align="center" valign="middle" style="width:150px;padding:0 6px"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>' . $rule('hl') . '<td width="14" valign="middle" style="width:14px;padding:0 10px">' . $logo(14) . '</td>' . $rule('hl') . '</tr></table></td>'
            . $slot($pts[1], true) . '</tr></table></td></tr>';
    } else {
        $dot = function ($kind) {
            if ($kind === 'first') return '<div style="width:7px;height:7px;border:1px solid #b89a5e;border-radius:5px;background:#ffffff;line-height:7px;font-size:1px;margin:0 auto">&nbsp;</div>';
            return '<div style="width:9px;height:9px;border-radius:5px;background:' . ($kind === 'last' ? '#8a6d2f' : '#c9b27c') . ';line-height:9px;font-size:1px;margin:0 auto">&nbsp;</div>';
        };
        $seg = function ($h, $on) { return $on ? '<tr><td align="center" height="' . $h . '" style="padding:0;height:' . $h . 'px;line-height:' . $h . 'px;font-size:1px"><div style="width:1px;height:' . $h . 'px;line-height:' . $h . 'px;font-size:1px;background:#d9c9a2;margin:0 auto">&nbsp;</div></td></tr>' : '<tr><td height="' . $h . '" style="padding:0;height:' . $h . 'px;line-height:' . $h . 'px;font-size:1px">&nbsp;</td></tr>'; };
        $n = count($pts); $tl = '';
        foreach ($pts as $i => $p) {
            $kind = $i === 0 ? 'first' : ($i === $n - 1 ? 'last' : 'mid');
            $left = '<table role="presentation" width="9" cellpadding="0" cellspacing="0" border="0" align="center">' . $seg(8, $i > 0) . '<tr><td align="center" style="padding:0;line-height:9px;font-size:1px">' . $dot($kind) . '</td></tr>' . $seg(9, $i < $n - 1) . '</table>';
            $big = $p['code'] !== '' ? $p['code'] : mb_substr($p['city'], 0, 14);
            $tl .= '<tr><td width="9" valign="middle" style="width:9px;padding:0">' . $left . '</td><td valign="middle" style="padding:0 0 0 18px"><span style="font:500 24px/1.1 ' . $F . ';letter-spacing:.04em;color:#14181f">' . m_esc($big) . '</span>'
                . ($p['code'] !== '' && $p['city'] !== '' ? '<span style="padding-left:14px;' . $cap . ';font-size:11px">' . m_esc($p['city']) . '</span>' : '') . '</td></tr>';
            if ($i < $n - 1) $tl .= '<tr><td width="9" align="center" style="width:9px;padding:0"><div style="width:1px;height:14px;line-height:1px;font-size:1px;background:#d9c9a2;margin:0 auto">&nbsp;</div></td><td style="padding:0;font-size:1px;line-height:1px">&nbsp;</td></tr>';
        }
        $route = '<tr><td class="tk" style="padding:22px 28px 22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle" style="padding:0 0 18px;' . $cap . ';color:#6e5624"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle" style="padding:0 8px 0 0">' . $logo(12) . '</td><td valign="middle" style="padding:0;' . $cap . ';color:#6e5624">Your Itinerary</td></tr></table></td>'
            . '<td align="right" valign="middle" style="padding:0 0 18px;' . $cap . '">' . $flights . ' Flights</td></tr></table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $tl . '</table></td></tr>';
    }

    $cells4 = ''; foreach ($details as [$k, $v]) $cells4 .= '<td class="c4" width="25%" valign="top" style="width:25%;padding:0 12px 0 0">' . $kv($k, $v, 600) . '</td>';
    $detail = '<tr><td class="tk" style="padding:0 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #ece6d8"><tr><td style="padding:16px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells4 . '</tr></table></td></tr></table></td></tr>';
    $contact = '<tr><td class="tk" style="padding:0 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #ece6d8"><tr><td style="padding:16px 0 20px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td class="c2" width="50%" valign="top" style="width:50%;padding:0 12px 0 0">' . $kv('Email', $c['email'], 500) . '</td><td class="c2" width="50%" valign="top" style="width:50%;padding:0">' . $kv('Phone', $phone, 500) . '</td></tr></table></td></tr></table></td></tr>';
    $stub = '<tr><td class="tk" style="border-top:1px dashed #e0d6c0;background:#f8f3e7;border-radius:0 0 9px 9px;padding:14px 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle" style="padding:0;' . $cap . ';color:#6e5624">Reference</td>'
        . '<td align="right" valign="middle" style="padding:0;font:700 15px/1.3 ' . $F . ';letter-spacing:.12em;color:#14181f">' . m_esc($ref) . '</td></tr></table></td></tr>';

    $q = function ($k, $v, $href) use ($F, $cap) {
        return '<tr><td width="68" valign="middle" style="width:68px;padding:13px 0;border-bottom:1px solid #e6dfcf;' . $cap . ';color:#6e5624">' . $k . '</td><td valign="middle" style="padding:13px 0;border-bottom:1px solid #e6dfcf;font:500 14px/1.3 ' . $F . ';letter-spacing:.03em"><a href="' . $href . '" style="color:#14181f;text-decoration:none">' . $v . '</a></td></tr>';
    };
    $title = $first !== '' ? 'Thank you for your request, ' . $first . '.' : 'Thank you for your request.';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only"><title>' . m_esc($subject) . '</title>'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&amp;display=swap">'
        . '<style>a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}'
        . '@media (max-width:540px){.out{padding:16px 10px!important}.pad{padding:32px 22px 24px!important}.tk{padding-left:18px!important;padding-right:18px!important}.cd{font-size:32px!important}.hl{width:12px!important}.mid{width:76px!important}.c4{display:inline-block!important;width:50%!important;box-sizing:border-box!important;padding-bottom:12px!important}.c2{display:block!important;width:100%!important;box-sizing:border-box!important;padding:0 0 12px!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#efece6">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#efece6">Your advisor will be with you shortly. Reference ' . m_esc($ref) . '.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#efece6"><tr><td class="out" align="center" style="padding:40px 20px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#fcfaf6;border:1px solid #e6dfcf"><tr><td class="pad" align="center" style="padding:40px 44px 30px;font-family:' . $F . ';color:#14181f">'
        // brand
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td align="center" style="padding:0">' . $logo(26, 'margin:0 auto') . '</td></tr>'
        . '<tr><td align="center" style="padding:12px 0 0;font:600 11px/1.3 ' . $F . ';letter-spacing:.32em;text-transform:uppercase;color:#6e5624">Fly First Class</td></tr>'
        . '<tr><td align="center" style="padding:12px 0 0"><div style="width:40px;height:1px;line-height:1px;font-size:1px;background:#c9b27c;margin:0 auto">&nbsp;</div></td></tr></table>'
        // headline
        . '<h1 style="margin:30px 0 0;font:300 30px/1.2 ' . $F . ';letter-spacing:-.01em;color:#14181f;text-align:center">' . m_esc($title) . '</h1>'
        . '<p style="margin:8px 0 0;font:400 15px/1.6 ' . $F . ';color:#59616f;text-align:center">Your advisor will be with you shortly.</p>'
        // the ticket
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:30px;background:#ffffff;border:1px solid #ece6d8;border-radius:10px;border-collapse:separate;text-align:left">' . $route . $detail . $contact . $stub . '</table>'
        // questions
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:30px;text-align:left"><tr><td style="padding:0 0 10px;font:600 10px/1.3 ' . $F . ';letter-spacing:.24em;text-transform:uppercase;color:#6e5624">Any Questions?</td></tr>'
        . '<tr><td style="padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e6dfcf">' . $q('Call', m_esc(CM_PHONE), 'tel:' . CM_TEL) . $q('Email', m_esc(MAIL_INTENDED), 'mailto:' . MAIL_INTENDED) . '</table></td></tr>'
        . '<tr><td style="padding:10px 0 0;font:400 12px/1.5 ' . $F . ';color:#59616f">' . m_esc(CM_ADDRESS) . '</td></tr></table>'
        // footer
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:30px"><tr><td align="center" style="padding:18px 0 0;border-top:1px solid #ece6d8;font:400 11px/1.6 ' . $F . ';color:#59616f">'
        . '<a href="' . CM_SITE . '" style="color:#59616f;text-decoration:none;margin:0 8px">flyfirstclass.com</a><a href="' . CM_SITE . '/privacy-policy/" style="color:#59616f;margin:0 8px">Privacy</a><a href="' . CM_SITE . '/terms/" style="color:#59616f;margin:0 8px">Terms</a></td></tr></table>'
        . '</td></tr></table></td></tr></table></body></html>';

    return ['to' => $to, 'fromName' => CM_NAME, 'subject' => $subject, 'replyTo' => MAIL_INTENDED, 'headers' => ['Auto-Submitted: auto-generated'], 'text' => $text, 'html' => $html];
}

// Sends one confirmation (called after the visitor has been answered). Returns a short word saying what happened; never throws.
function confirm_deliver($m, $ref) {
    try {
        $took = cupdate('requests.json', [], function (&$QR) use ($ref) {       // one confirmation per reference ID
            if (empty($QR[$ref]) || !empty($QR[$ref]['conf'])) return false;
            $QR[$ref]['conf'] = now_ms(); return true;
        });
        if (!$took) return 'already sent';
        if (!limit_hit('conf:' . md5(strtolower($m['to'])), 3, 86400000) || !limit_hit('conf:all', 300, 3600000)) { logm('[mail] ' . $ref . ' confirmation not sent: limit reached'); return 'limit'; }
        try { $via = send_mail($m); }
        catch (Throwable $e) {
            cupdate('requests.json', [], function (&$QR) use ($ref) { if (isset($QR[$ref])) unset($QR[$ref]['conf']); });      // let a later send of this request try again
            logm('[mail] ' . $ref . ' confirmation failed: ' . $e->getMessage());
            return 'failed';
        }
        logm('[mail] ' . $ref . ' confirmation sent (' . $via . ')');
        return 'sent';
    } catch (Throwable $e) { logm('[mail] ' . $ref . ' confirmation error: ' . $e->getMessage()); return 'error'; }
}

// Sample requests for the admin preview.
function confirm_sample($which) {
    $base = ['kind' => 'new', 'contact' => ['first' => 'Alex', 'last' => 'Chen', 'email' => 'alex.chen@example.com', 'phone' => '+852 9123 4567']];
    $y = (int)gmdate('Y') + (gmdate('n') > 10 ? 1 : 0); $d = function ($m, $dd, $add = 0) use ($y) { return sprintf('%04d-%02d-%02d', $y + $add, $m, $dd); };
    $trips = [
        'confirmation' => ['from' => 'Hong Kong (HKG)', 'to' => 'London (LHR)', 'via' => [], 'cabin' => 'First class', 'type' => 'Round-trip', 'adults' => 2, 'children' => 0, 'dep' => $d(12, 12), 'ret' => $d(1, 3, 1)],
        'confirmation-oneway' => ['from' => 'New York (JFK)', 'to' => 'Dubai (DXB)', 'via' => [], 'cabin' => 'Business class', 'type' => 'One way', 'adults' => 1, 'children' => 0, 'dep' => $d(12, 12)],
        'confirmation-multi' => ['from' => 'Hong Kong (HKG)', 'to' => 'London (LHR)', 'via' => ['Paris (CDG)', 'Dubai (DXB)', 'Hong Kong (HKG)'], 'cabin' => 'First class', 'type' => 'Multi-city', 'adults' => 2, 'children' => 0, 'dep' => $d(12, 12)],
    ];
    if (!isset($trips[$which])) return null;
    $t = qTrip($trips[$which]); $c = qWho($base['contact']);
    return confirm_build($t, $c, 'FFC-7K2M9QXA', cm_addr($c['email']));
}
