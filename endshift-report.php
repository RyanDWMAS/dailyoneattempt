<?php
/**
 * End-of-Shift Break Report
 *
 * Flags agents taking a toilet break right before clocking off — a way to
 * dodge the last stretch of the shift. For each agent per day it finds any
 * "Bathroom" break whose START falls within 20 minutes of their final logout
 * (shift end), and shows how much they actually worked after it.
 *
 * Also reports per-agent Not Ready time for the day, another form of
 * avoided-but-logged-on time.
 *
 * Data sources:
 *   - Activity Summary (reportId=90) — shift end + Bathroom events
 *   - Occupancy Summary (reportId=61) — Not Ready + log-on time
 *   - RecordHistory export            — each agent's last connected call
 *
 * "Shift end" is the agent's actual final logout (latest event end) — we have
 * no scheduled-rota data, and for this behaviour the actual clock-off is the
 * right reference anyway.
 *
 * Usage:
 *   php endshift-report.php               # today
 *   php endshift-report.php 2026-07-11    # a specific day
 *   php endshift-report.php 2026-07-11 --preview   # write preview.html, don't email
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/maxcontact.php';

if (file_exists(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
} else {
    define('SMTP_HOST',       getenv('SMTP_HOST'));
    define('SMTP_PORT',       (int) getenv('SMTP_PORT'));
    define('SMTP_USER',       getenv('SMTP_USER'));
    define('SMTP_PASS',       getenv('SMTP_PASS'));
    define('EMAIL_FROM',      getenv('EMAIL_FROM'));
    define('EMAIL_FROM_NAME', getenv('EMAIL_FROM_NAME'));
    define('EMAIL_TO',        getenv('EMAIL_TO'));
    define('EMAIL_CC',        getenv('EMAIL_CC'));
    define('MC_BASE_URL',     getenv('MC_BASE_URL'));
    define('MC_USERNAME',     getenv('MC_USERNAME'));
    define('MC_PASSWORD',     getenv('MC_PASSWORD'));
    define('MC_CAMPAIGN_ID',  (int) getenv('MC_CAMPAIGN_ID'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Set to true to email Ryan only ──
const TEST_MODE = true;

// A Bathroom break starting this close to final logout (or nearer) is flagged.
const END_SHIFT_WINDOW_SECONDS = 20 * 60;
// Which event type counts as a toilet break.
const TRIGGER_EVENT = 'Bathroom';
// Not Ready above this share of log-on is highlighted (matches productivity.php).
const NOT_READY_HIGHLIGHT = 0.03;

function log_msg($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── Determine target day ──
if (isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
    $day = new DateTime($argv[1]);
} else {
    $day = new DateTime();
}
$dayIso     = $day->format('Y-m-d');
$dayDisplay = $day->format('d/m/Y');
$startISO   = $dayIso . 'T00:00:00';
$endISO     = (clone $day)->modify('+1 day')->format('Y-m-d') . 'T00:00:00';

log_msg("Target day: $dayDisplay");

// ── Activity Summary — shift ends + bathroom events ──
log_msg('Fetching Activity Summary (reportId=90)...');
[$activity, $actErr] = fetchActivitySummary($startISO, $endISO, [(string) MC_CAMPAIGN_ID]);
if ($actErr) {
    log_msg("ACTIVITY ERROR: $actErr");
    exit(1);
}
log_msg('Activity Summary: ' . count($activity) . ' agents');

if (empty($activity)) {
    log_msg('No agent activity for this day — sending "nothing to report" email.');
    sendEmail(buildEmptyHtml($dayDisplay), $dayDisplay);
    exit(0);
}

// ── Occupancy Summary — Not Ready + log-on ──
log_msg('Fetching Occupancy Summary (reportId=61)...');
[$occupancy, $occErr] = fetchOccupancyReport($startISO, $endISO, [(string) MC_CAMPAIGN_ID]);
if ($occErr) {
    log_msg("OCCUPANCY WARNING: $occErr (Not Ready will show as -)");
    $occupancy = [];
}

// ── RecordHistory — each agent's last actual call ──
// "Last call" is the latest *connected* call (talktime > 0). Trailing
// unanswered dials are ignored on purpose: an agent who leaves their desk
// but stays Ready may get nuisance dials afterwards, and those would
// otherwise mask how early they really stopped taking calls.
log_msg('Fetching RecordHistory for last-call times...');
$lastCalls = [];
[$csv, $csvErr] = fetchMaxContactCSV($dayDisplay);
if ($csvErr) {
    log_msg("RECORDHISTORY WARNING: $csvErr (last call will show as -)");
} else {
    $lastCalls = extractLastCalls($csv);
    log_msg('Last-call times for ' . count($lastCalls) . ' agents');
}

// ── Detect end-of-shift bathroom breaks ──
$flags = [];
foreach ($activity as $name => $a) {
    $shiftEnd = parseHmsTime($a['shift_end']);
    if ($shiftEnd <= 0) continue;

    foreach ($a['events'] as $i => $e) {
        if ($e['type'] !== TRIGGER_EVENT) continue;
        $dur = parseHmsTime($e['duration']);
        if ($dur <= 0) continue;   // zero-length status flicker, not a real break

        $startSecs = parseHmsTime($e['start']);
        $beforeEnd = $shiftEnd - $startSecs;   // secs from break start to final logout
        if ($beforeEnd < 0 || $beforeEnd > END_SHIFT_WINDOW_SECONDS) continue;

        // How much the agent was actually logged on (working) after this break ended
        $workedAfter = 0;
        foreach ($a['events'] as $j => $f) {
            if ($j <= $i) continue;
            if ($f['type'] === 'Login') $workedAfter += parseHmsTime($f['duration']);
        }

        $flags[] = [
            'name'         => $name,
            'break_start'  => $e['start'],
            'break_end'    => $e['end'],
            'break_dur'    => $dur,
            'shift_end'    => $a['shift_end'],
            'before_end'   => $beforeEnd,
            'worked_after' => $workedAfter,
            'last_call'    => $lastCalls[$name] ?? null,
            'not_ready'    => isset($occupancy[$name]['not_ready']) ? parseHmsTime($occupancy[$name]['not_ready']) : null,
        ];
    }
}
// Most blatant first: least worked-after, then closest to shift end
usort($flags, function ($x, $y) {
    return $x['worked_after'] <=> $y['worked_after'] ?: $x['before_end'] <=> $y['before_end'];
});
log_msg('Flagged end-of-shift bathroom breaks: ' . count($flags));

// ── Per-agent end-of-shift summary (last call + Not Ready) ──
$summaryRows = [];
foreach ($activity as $name => $a) {
    $nr    = isset($occupancy[$name]['not_ready']) ? parseHmsTime($occupancy[$name]['not_ready']) : null;
    $logOn = isset($occupancy[$name]['log_on'])    ? parseHmsTime($occupancy[$name]['log_on'])    : 0;
    $summaryRows[] = [
        'name'      => $name,
        'shift_end' => $a['shift_end'],
        'last_call' => $lastCalls[$name] ?? null,
        'not_ready' => $nr,
        'log_on'    => $logOn,
    ];
}
usort($summaryRows, fn($x, $y) => (int) $y['not_ready'] <=> (int) $x['not_ready']);

// ── Send ──
sendEmail(buildHtml($flags, $summaryRows, $dayDisplay), $dayDisplay);
log_msg('Done.');
exit(0);

// ══════════════════════════════════════════════════════════════
// Data helpers
// ══════════════════════════════════════════════════════════════

/**
 * From a raw RecordHistory CSV, find each agent's last *connected* call.
 *
 * @return array [agentName => ['start' => 'HH:MM', 'end' => 'HH:MM', 'end_secs' => int]]
 */
function extractLastCalls($csv) {
    $lines  = preg_split('/\r?\n/', trim($csv));
    if (empty($lines)) return [];
    $header = array_map('trim', str_getcsv(array_shift($lines)));

    $fn = array_search('fullname', $header);
    $sd = array_search('startdatetime', $header);
    $ed = array_search('enddatetime', $header);
    $tt = array_search('talktime', $header);
    if ($fn === false || $sd === false || $ed === false) return [];

    $best = [];   // agent => ['start_secs','start','end','end_secs']
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $r     = str_getcsv($line);
        $agent = trim($r[$fn] ?? '');
        if ($agent === '') continue;

        // Only calls the agent actually connected on (some talk time).
        $talk = $tt !== false ? (int) trim($r[$tt] ?? '0') : 1;
        if ($talk <= 0) continue;

        $startSecs = clockSecs($r[$sd] ?? '');
        $endSecs   = clockSecs($r[$ed] ?? '');
        if ($startSecs === null) continue;

        // Keep the latest-starting connected call for this agent.
        if (!isset($best[$agent]) || $startSecs > $best[$agent]['start_secs']) {
            $best[$agent] = [
                'start_secs' => $startSecs,
                'start'      => timeOfDay($r[$sd] ?? ''),
                'end'        => timeOfDay($r[$ed] ?? ''),
                'end_secs'   => $endSecs ?? $startSecs,
            ];
        }
    }
    return $best;
}

/** Seconds-since-midnight from a "dd/mm/yyyy HH:MM(:SS)" string, or null. */
function clockSecs($s) {
    if (!preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', trim($s), $m)) return null;
    return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
}

/** The "HH:MM" time-of-day portion of a datetime string. */
function timeOfDay($s) {
    return preg_match('/(\d{1,2}:\d{2})/', trim($s), $m) ? $m[1] : '-';
}

/** Render a last-call record as "HH:MM to HH:MM", or "-" when absent. */
function fmtLastCall($lc) {
    return $lc === null ? '-' : $lc['start'] . ' to ' . $lc['end'];
}

// ══════════════════════════════════════════════════════════════
// Formatting helpers
// ══════════════════════════════════════════════════════════════

function fmtDur($seconds) {
    if ($seconds === null) return '-';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) return sprintf('%dh %02dm', $h, $m);
    if ($m > 0) return sprintf('%dm %02ds', $m, $s);
    return sprintf('%ds', $s);
}

/** Trim "17:53:35" to "17:53". */
function fmtClock($hms) {
    return preg_match('/^(\d{1,3}):(\d{2}):\d{2}$/', trim($hms), $m) ? "$m[1]:$m[2]" : $hms;
}

function fmtPct($pct) {
    return $pct === null ? '-' : number_format($pct, 1) . '%';
}

function pctOf($part, $whole) {
    return $whole <= 0 ? null : $part / $whole * 100;
}

function sectionHeader($title, $accent) {
    return "<h3 style=\"border-left:4px solid $accent;padding-left:12px;margin:32px 0 16px;font-size:1.05rem;color:#1a1a2e\">$title</h3>";
}

// ══════════════════════════════════════════════════════════════
// Email HTML
// ══════════════════════════════════════════════════════════════

function buildHtml($flags, $summaryRows, $dayDisplay) {
    $winMin = intdiv(END_SHIFT_WINDOW_SECONDS, 60);

    $h  = "<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1a1a2e;max-width:1000px;margin:0 auto\">";

    $h .= "<div style=\"background:#4a6cf7;background-image:linear-gradient(135deg,#4a6cf7 0%,#7c3aed 100%);color:#ffffff;padding:24px 28px;border-radius:12px 12px 0 0\">";
    $h .= "<div style=\"font-size:1.4rem;font-weight:700;letter-spacing:-0.5px\">End-of-Shift Break Report</div>";
    $h .= "<div style=\"margin-top:8px;font-size:0.95rem;opacity:0.9\">$dayDisplay &nbsp;|&nbsp; SP Verification</div>";
    $h .= "</div>";

    $h .= "<div style=\"background:#ffffff;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 12px 12px;padding:28px\">";
    $h .= "<p>Hi both,</p>";
    $h .= "<p>Agents who took a toilet break within <b>$winMin minutes</b> of clocking off on <b>$dayDisplay</b> are listed below. "
        . "<i>Worked after</i> is how long they were logged on between that break and their final logout &mdash; a small figure suggests the last stretch of shift was avoided.</p>";

    // ── Flagged bathroom breaks ──
    $h .= sectionHeader('Toilet Breaks Near Clock-Off', '#e74c3c');
    if (empty($flags)) {
        $h .= "<div style=\"background:#eafaf1;border-left:3px solid #27ae60;padding:12px 16px;border-radius:4px;color:#1d6f42;font-size:0.9rem\">No agents took a toilet break in the final $winMin minutes of their shift today.</div>";
    } else {
        $h .= "<table cellpadding=\"8\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%\">";
        $h .= "<thead><tr style=\"background:#f8f9fa;text-align:left\">";
        foreach (['Agent', 'Break', 'Length', 'Final Call', 'Shift End', 'Break Before End', 'Worked After', 'Not Ready (day)'] as $col) {
            $h .= "<th style=\"border-bottom:2px solid #e0e0e0;padding:10px 8px;font-weight:600;color:#555;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.4px\">$col</th>";
        }
        $h .= "</tr></thead><tbody>";

        foreach ($flags as $i => $f) {
            $bg = $i % 2 === 0 ? '#ffffff' : '#fafafa';
            // Worked-after under 2 min reads as clear avoidance
            $waColor = $f['worked_after'] < 120 ? '#e74c3c' : '#555';
            $h .= "<tr style=\"background:$bg\">";
            $h .= "<td style=\"padding:10px 8px\"><b>" . htmlspecialchars($f['name']) . "</b></td>";
            $h .= "<td style=\"padding:10px 8px\">" . fmtClock($f['break_start']) . " to " . fmtClock($f['break_end']) . "</td>";
            $h .= "<td style=\"padding:10px 8px;font-weight:600\">" . fmtDur($f['break_dur']) . "</td>";
            $h .= "<td style=\"padding:10px 8px\">" . fmtLastCall($f['last_call']) . "</td>";
            $h .= "<td style=\"padding:10px 8px\">" . fmtClock($f['shift_end']) . "</td>";
            $h .= "<td style=\"padding:10px 8px;color:#e67e22;font-weight:600\">" . fmtDur($f['before_end']) . " before</td>";
            $h .= "<td style=\"padding:10px 8px;color:$waColor;font-weight:600\">" . fmtDur($f['worked_after']) . "</td>";
            $h .= "<td style=\"padding:10px 8px\">" . ($f['not_ready'] === null ? '-' : fmtDur($f['not_ready'])) . "</td>";
            $h .= "</tr>";
        }
        $h .= "</tbody></table>";
        $h .= "<p style=\"color:#888;font-size:0.8rem;margin-top:10px\"><i>Break Before End</i> = time from the break starting to the agent's final logout.</p>";
    }

    // ── Per-agent end-of-shift summary ──
    $h .= sectionHeader('End-of-Shift Summary (all agents)', '#f39c12');
    if (empty($summaryRows)) {
        $h .= "<div style=\"color:#666;font-size:0.9rem\">No agent data available for today.</div>";
    } else {
        $h .= "<p style=\"color:#666;font-size:0.85rem;margin-top:0\">Every agent's last connected call against their final logout, plus Not Ready across the day. "
            . "A last call well before shift end catches agents who stop taking calls but stay logged on without going Not Ready. "
            . "Highlighted Not Ready exceeds " . number_format(NOT_READY_HIGHLIGHT * 100, 0) . "% of log-on.</p>";
        $h .= "<table cellpadding=\"8\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%;max-width:760px\">";
        $h .= "<thead><tr style=\"background:#f8f9fa;text-align:left\">";
        foreach (['Agent', 'Final Call', 'Shift End', 'Not Ready', '% of Log-On', 'Log-On'] as $col) {
            $h .= "<th style=\"border-bottom:2px solid #e0e0e0;padding:10px 8px;font-weight:600;color:#555;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.4px\">$col</th>";
        }
        $h .= "</tr></thead><tbody>";
        foreach ($summaryRows as $i => $r) {
            $share = ($r['not_ready'] !== null && $r['log_on'] > 0) ? $r['not_ready'] / $r['log_on'] : 0;
            $over  = $share > NOT_READY_HIGHLIGHT;
            $bg    = $over ? '#fdecea' : ($i % 2 === 0 ? '#ffffff' : '#fafafa');
            $col   = $over ? '#c0392b' : '#1a1a2e';
            $h .= "<tr style=\"background:$bg\">";
            $h .= "<td style=\"padding:9px 8px;color:$col\"><b>" . htmlspecialchars($r['name']) . "</b></td>";
            $h .= "<td style=\"padding:9px 8px;color:$col\">" . fmtLastCall($r['last_call']) . "</td>";
            $h .= "<td style=\"padding:9px 8px;color:$col\">" . fmtClock($r['shift_end']) . "</td>";
            $h .= "<td style=\"padding:9px 8px;color:$col;font-weight:600\">" . ($r['not_ready'] === null ? '-' : fmtDur($r['not_ready'])) . "</td>";
            $h .= "<td style=\"padding:9px 8px;color:$col\">" . fmtPct(pctOf($r['not_ready'], $r['log_on'])) . "</td>";
            $h .= "<td style=\"padding:9px 8px;color:#777\">" . fmtDur($r['log_on']) . "</td>";
            $h .= "</tr>";
        }
        $h .= "</tbody></table>";
    }

    $h .= "<hr style=\"border:none;border-top:1px solid #e0e0e0;margin:32px 0 20px\">";
    $h .= "<p style=\"color:#555;font-size:0.9rem\">Kind regards,<br><br>Ryan Lancaster<br><b style=\"color:#1a1a2e\">Technical Product Manager<br>DWM Administration Services</b></p>";
    $h .= "</div></div>";

    return $h;
}

function buildEmptyHtml($dayDisplay) {
    $h  = "<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1a1a2e;max-width:1000px;margin:0 auto\">";
    $h .= "<div style=\"background:#4a6cf7;background-image:linear-gradient(135deg,#4a6cf7 0%,#7c3aed 100%);color:#ffffff;padding:24px 28px;border-radius:12px 12px 0 0\">";
    $h .= "<div style=\"font-size:1.4rem;font-weight:700\">End-of-Shift Break Report</div>";
    $h .= "<div style=\"margin-top:8px;font-size:0.95rem;opacity:0.9\">$dayDisplay &nbsp;|&nbsp; SP Verification</div></div>";
    $h .= "<div style=\"background:#ffffff;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 12px 12px;padding:28px\">";
    $h .= "<p>Hi both,</p><p>No agent activity was recorded on the SP Verification campaign for <b>$dayDisplay</b>. Nothing to report.</p>";
    $h .= "<hr style=\"border:none;border-top:1px solid #e0e0e0;margin:32px 0 20px\">";
    $h .= "<p style=\"color:#555;font-size:0.9rem\">Kind regards,<br><br>Ryan Lancaster<br><b style=\"color:#1a1a2e\">Technical Product Manager<br>DWM Administration Services</b></p>";
    $h .= "</div></div>";
    return $h;
}

function sendEmail($html, $dayDisplay) {
    if (in_array('--preview', $GLOBALS['argv'] ?? [], true)) {
        $path = __DIR__ . '/preview.html';
        file_put_contents($path, $html);
        log_msg("Preview written to $path (no email sent)");
        return;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';   // default is ISO-8859-1, which mangles non-ASCII
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
        $mail->addAddress(EMAIL_FROM);   // Ryan
        if (!TEST_MODE) {
            $mail->addCC(EMAIL_TO);      // Tina
            $mail->addCC(EMAIL_CC);      // Tom
        }

        $mail->Subject = "End-of-Shift Break Report - $dayDisplay";
        $mail->isHTML(true);
        $mail->Body    = $html;

        $mail->send();
        log_msg('Email sent successfully' . (TEST_MODE ? ' (test mode — Ryan only)' : ''));
    } catch (Exception $e) {
        log_msg('EMAIL ERROR: ' . $mail->ErrorInfo);
        exit(1);
    }
}
