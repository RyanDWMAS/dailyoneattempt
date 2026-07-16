<?php
/**
 * Saturday Activity Report
 *
 * Runs Saturday evening once dialling has finished. For the Saturday shift it
 * reports, per agent:
 *   1. Breaks — who, when, and for how long   (Activity Summary, reportId=90)
 *   2. Shift start / end                      (Activity Summary, reportId=90)
 *   3. Calls taken                            (RecordHistory export)
 *   4. Not Ready time                         (Occupancy Summary, reportId=61)
 *
 * Not Ready is not an event in the Activity Summary — it is a status *within*
 * logged-on time — so it comes from the Occupancy Summary instead.
 *
 * Usage:
 *   php saturday-report.php               # most recent Saturday (today, if today is Saturday)
 *   php saturday-report.php 2026-07-11    # a specific Saturday
 *   php saturday-report.php 2026-07-11 --preview   # write preview.html, don't email
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
const TEST_MODE = false;

/**
 * Events that mean the agent is away from the desk. Everything the Activity
 * Summary reports that is not a "Login" counts toward MaxContact's own Break
 * Time Total, so we treat it the same way rather than hardcoding break names
 * (which are configurable per site).
 */
const LOGGED_ON_EVENT = 'Login';

function log_msg($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── Determine target Saturday ──
if (isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
    $day = new DateTime($argv[1]);
} else {
    $day = new DateTime();
    if ($day->format('N') !== '6') $day->modify('last saturday');
}
if ($day->format('N') !== '6') {
    log_msg('WARNING: ' . $day->format('d/m/Y') . ' is a ' . $day->format('l') . ', not a Saturday — continuing anyway.');
}

$dayIso     = $day->format('Y-m-d');
$dayDisplay = $day->format('d/m/Y');
$startISO   = $dayIso . 'T00:00:00';
$endISO     = (clone $day)->modify('+1 day')->format('Y-m-d') . 'T00:00:00';

log_msg("Target Saturday: $dayDisplay");

// ── 1 & 2. Activity Summary — shifts and breaks ──
log_msg('Fetching Activity Summary (reportId=90)...');
[$activity, $actErr] = fetchActivitySummary($startISO, $endISO, [(string) MC_CAMPAIGN_ID]);
if ($actErr) {
    log_msg("ACTIVITY ERROR: $actErr");
    exit(1);
}
log_msg('Activity Summary: ' . count($activity) . ' agents');

if (empty($activity)) {
    log_msg('No agent activity for this Saturday — sending "nothing to report" email.');
    sendEmail(buildEmptyHtml($dayDisplay), $dayDisplay);
    exit(0);
}

// ── 4. Occupancy Summary — Not Ready ──
log_msg('Fetching Occupancy Summary (reportId=61) for Not Ready...');
[$occupancy, $occErr] = fetchOccupancyReport($startISO, $endISO, [(string) MC_CAMPAIGN_ID]);
if ($occErr) {
    log_msg("OCCUPANCY WARNING: $occErr (Not Ready will show as -)");
    $occupancy = [];
}

// ── 3. RecordHistory — calls per agent ──
log_msg('Fetching RecordHistory for call counts...');
$callCounts = [];
[$csv, $csvErr] = fetchMaxContactCSV($dayDisplay);
if ($csvErr) {
    log_msg("RECORDHISTORY WARNING: $csvErr (calls will show as -)");
} else {
    $lines  = preg_split('/\r?\n/', trim($csv));
    $header = array_map('trim', str_getcsv(array_shift($lines)));
    $fnIdx  = array_search('fullname', $header);
    if ($fnIdx === false) {
        log_msg('RECORDHISTORY WARNING: no fullname column (calls will show as -)');
    } else {
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $row   = str_getcsv($line);
            $agent = trim($row[$fnIdx] ?? '');
            if ($agent === '') continue;
            $callCounts[$agent] = ($callCounts[$agent] ?? 0) + 1;
        }
        log_msg('Call counts for ' . count($callCounts) . ' agents');
    }
}

// ── Assemble per-agent rows ──
$rows = [];
foreach ($activity as $name => $a) {
    $breaks = [];
    foreach ($a['events'] as $e) {
        if ($e['type'] === LOGGED_ON_EVENT) continue;
        $secs = parseHmsTime($e['duration']);
        if ($secs <= 0) continue;   // zero-length status flickers, not real breaks
        $breaks[] = $e + ['seconds' => $secs];
    }
    usort($breaks, fn($x, $y) => strcmp($x['start'], $y['start']));

    $notReady = isset($occupancy[$name]['not_ready']) ? parseHmsTime($occupancy[$name]['not_ready']) : null;
    $logOn    = isset($occupancy[$name]['log_on'])    ? parseHmsTime($occupancy[$name]['log_on'])    : 0;

    $rows[] = [
        'name'           => $name,
        'shift_start'    => $a['shift_start'],
        'shift_end'      => $a['shift_end'],
        'span_seconds'   => parseHmsTime($a['duration']),
        'man_seconds'    => parseHmsTime($a['man_hours']),
        'break_seconds'  => parseHmsTime($a['break_total']),
        'breaks'         => $breaks,
        'calls'          => $callCounts[$name] ?? null,
        'not_ready'      => $notReady,
        'log_on_seconds' => $logOn,
    ];
}
usort($rows, fn($x, $y) => strcmp($x['shift_start'], $y['shift_start']));

// Flag agents present in call data but with no shift recorded
$unmatched = array_diff(array_keys($callCounts), array_keys($activity));
foreach ($unmatched as $u) {
    log_msg("NOTE: '$u' took calls but has no Activity Summary shift row.");
}

$team = [
    'agents'    => count($rows),
    'calls'     => array_sum(array_map(fn($r) => (int) $r['calls'], $rows)),
    'break'     => array_sum(array_column($rows, 'break_seconds')),
    'not_ready' => array_sum(array_map(fn($r) => (int) $r['not_ready'], $rows)),
    'man'       => array_sum(array_column($rows, 'man_seconds')),
    'log_on'    => array_sum(array_column($rows, 'log_on_seconds')),
];

// ── Send ──
sendEmail(buildHtml($rows, $team, $dayDisplay, $unmatched), $dayDisplay);
log_msg('Done.');
exit(0);

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

/** Trim "10:59:32" to "10:59". */
function fmtClock($hms) {
    return preg_match('/^(\d{1,3}):(\d{2}):\d{2}$/', trim($hms), $m) ? "$m[1]:$m[2]" : $hms;
}

function pctOf($part, $whole) {
    if ($whole <= 0) return null;
    return $part / $whole * 100;
}

function sectionHeader($title, $accent) {
    return "<h3 style=\"border-left:4px solid $accent;padding-left:12px;margin:32px 0 16px;font-size:1.05rem;color:#1a1a2e\">$title</h3>";
}

// ══════════════════════════════════════════════════════════════
// Email HTML
// ══════════════════════════════════════════════════════════════

function renderKpiCards($team) {
    $cards = [
        ['label' => 'Agents on Shift', 'value' => $team['agents'],              'sub' => '',                              'color' => '#4a6cf7'],
        ['label' => 'Calls Taken',     'value' => number_format($team['calls']), 'sub' => '',                             'color' => '#27ae60'],
        ['label' => 'Break Time',      'value' => fmtDur($team['break']),        'sub' => fmtPct(pctOf($team['break'], $team['log_on'])) . ' of log-on', 'color' => '#f39c12'],
        ['label' => 'Not Ready',       'value' => fmtDur($team['not_ready']),    'sub' => fmtPct(pctOf($team['not_ready'], $team['log_on'])) . ' of log-on', 'color' => '#e74c3c'],
    ];

    $h = "<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px 0;margin-bottom:8px\"><tr>";
    foreach ($cards as $c) {
        $sub = $c['sub'] !== '' && strpos($c['sub'], '-') !== 0
            ? "<div style=\"color:{$c['color']};font-size:0.85rem;margin-top:4px;font-weight:600\">{$c['sub']}</div>"
            : '';
        $h .= "<td valign=\"top\" style=\"width:25%\">";
        $h .= "<div style=\"background:#f8f9fa;border:1px solid #ececec;border-top:3px solid {$c['color']};border-radius:8px;padding:16px;text-align:center\">";
        $h .= "<div style=\"font-size:0.7rem;color:#666;text-transform:uppercase;letter-spacing:0.6px;font-weight:600\">{$c['label']}</div>";
        $h .= "<div style=\"font-size:1.65rem;font-weight:700;margin-top:6px;color:{$c['color']}\">{$c['value']}</div>";
        $h .= $sub . "</div></td>";
    }
    return $h . "</tr></table>";
}

function fmtPct($pct) {
    return $pct === null ? '-' : number_format($pct, 1) . '%';
}

function buildHtml($rows, $team, $dayDisplay, $unmatched) {
    $h  = "<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1a1a2e;max-width:1000px;margin:0 auto\">";

    $h .= "<div style=\"background:#4a6cf7;background-image:linear-gradient(135deg,#4a6cf7 0%,#7c3aed 100%);color:#ffffff;padding:24px 28px;border-radius:12px 12px 0 0\">";
    $h .= "<div style=\"font-size:1.4rem;font-weight:700;letter-spacing:-0.5px\">Saturday Activity Report</div>";
    $h .= "<div style=\"margin-top:8px;font-size:0.95rem;opacity:0.9\">$dayDisplay &nbsp;|&nbsp; SP Verification</div>";
    $h .= "</div>";

    $h .= "<div style=\"background:#ffffff;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 12px 12px;padding:28px\">";
    $h .= renderKpiCards($team);

    $h .= "<p style=\"margin-top:24px\">Hi both,</p>";
    $h .= "<p>Please find below the Saturday shift breakdown for <b>$dayDisplay</b>, covering shift times, breaks, calls taken and Not Ready time.</p>";

    // ── Shift overview ──
    $h .= sectionHeader('Shifts &amp; Calls', '#4a6cf7');
    $h .= "<table cellpadding=\"8\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%\">";
    $h .= "<thead><tr style=\"background:#f8f9fa;text-align:left\">";
    foreach (['Agent', 'Shift Start', 'Shift End', 'On Shift', 'Man Hours', 'Calls', 'Break', 'Not Ready'] as $col) {
        $h .= "<th style=\"border-bottom:2px solid #e0e0e0;padding:10px 8px;font-weight:600;color:#555;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.4px\">$col</th>";
    }
    $h .= "</tr></thead><tbody>";

    foreach ($rows as $i => $r) {
        $bg = $i % 2 === 0 ? '#ffffff' : '#fafafa';
        $breakPct    = fmtPct(pctOf($r['break_seconds'], $r['log_on_seconds']));
        $notReadyStr = $r['not_ready'] === null ? '-' : fmtDur($r['not_ready']);
        $notReadyPct = $r['not_ready'] === null ? '' : ' <span style="color:#999">(' . fmtPct(pctOf($r['not_ready'], $r['log_on_seconds'])) . ')</span>';

        $h .= "<tr style=\"background:$bg\">";
        $h .= "<td style=\"padding:10px 8px\"><b>" . htmlspecialchars($r['name']) . "</b></td>";
        $h .= "<td style=\"padding:10px 8px\">" . fmtClock($r['shift_start']) . "</td>";
        $h .= "<td style=\"padding:10px 8px\">" . fmtClock($r['shift_end']) . "</td>";
        $h .= "<td style=\"padding:10px 8px\">" . fmtDur($r['span_seconds']) . "</td>";
        $h .= "<td style=\"padding:10px 8px\">" . fmtDur($r['man_seconds']) . "</td>";
        $h .= "<td style=\"padding:10px 8px;font-weight:600\">" . ($r['calls'] === null ? '-' : $r['calls']) . "</td>";
        $h .= "<td style=\"padding:10px 8px;color:#f39c12;font-weight:600\">" . fmtDur($r['break_seconds']) . " <span style=\"color:#999;font-weight:400\">($breakPct)</span></td>";
        $h .= "<td style=\"padding:10px 8px;color:#e74c3c;font-weight:600\">$notReadyStr$notReadyPct</td>";
        $h .= "</tr>";
    }

    $h .= "<tr style=\"background:#f0f3ff;font-weight:700\">";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7\">Team Total</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7\">-</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7\">-</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7\">-</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7\">" . fmtDur($team['man']) . "</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7\">" . number_format($team['calls']) . "</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;color:#f39c12\">" . fmtDur($team['break']) . "</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;color:#e74c3c\">" . fmtDur($team['not_ready']) . "</td>";
    $h .= "</tr></tbody></table>";

    // ── Break detail ──
    $h .= sectionHeader('Break Detail', '#f39c12');
    $anyBreaks = array_sum(array_map(fn($r) => count($r['breaks']), $rows)) > 0;

    if (!$anyBreaks) {
        $h .= "<div style=\"background:#eafaf1;border-left:3px solid #27ae60;padding:12px 16px;border-radius:4px;color:#1d6f42;font-size:0.9rem\">No breaks recorded on this shift.</div>";
    } else {
        $h .= "<p style=\"color:#666;font-size:0.85rem;margin-top:0\">Every period an agent was away from a logged-on state, in order. "
            . "<i>Other</i>, <i>Admin</i> and <i>AgentDisconnect</i> are system statuses that MaxContact also counts toward break time.</p>";

        foreach ($rows as $r) {
            if (empty($r['breaks'])) continue;
            $h .= "<div style=\"margin:16px 0 4px;font-weight:700\">" . htmlspecialchars($r['name'])
                . " <span style=\"color:#888;font-weight:400;font-size:0.85rem\">- " . count($r['breaks'])
                . " break" . (count($r['breaks']) === 1 ? '' : 's') . ", " . fmtDur($r['break_seconds']) . " total</span></div>";
            $h .= "<table cellpadding=\"6\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:12.5px;width:100%;max-width:620px\">";
            foreach ($r['breaks'] as $b) {
                $h .= "<tr>";
                $h .= "<td style=\"padding:5px 8px;width:150px;border-bottom:1px solid #f0f0f0\">"
                    . "<span style=\"display:inline-block;background:#fef5e7;color:#7a4d00;padding:3px 10px;border-radius:10px;font-size:0.72rem;font-weight:600\">"
                    . htmlspecialchars($b['type']) . "</span></td>";
                $h .= "<td style=\"padding:5px 8px;width:150px;border-bottom:1px solid #f0f0f0;color:#555\">"
                    . fmtClock($b['start']) . " to " . fmtClock($b['end']) . "</td>";
                $h .= "<td style=\"padding:5px 8px;border-bottom:1px solid #f0f0f0;font-weight:600\">" . fmtDur($b['seconds']) . "</td>";
                $h .= "</tr>";
            }
            $h .= "</table>";
        }
    }

    if (!empty($unmatched)) {
        $h .= "<div style=\"background:#fef5e7;border-left:3px solid #f39c12;padding:12px 16px;border-radius:4px;margin-top:20px;font-size:0.85rem;color:#7a4d00\">";
        $h .= "<b>Note:</b> took calls but no shift recorded in the Activity Summary: "
            . htmlspecialchars(implode(', ', $unmatched)) . ".</div>";
    }

    $h .= "<hr style=\"border:none;border-top:1px solid #e0e0e0;margin:32px 0 20px\">";
    $h .= "<p style=\"color:#555;font-size:0.9rem\">Kind regards,<br><br>Ryan Lancaster<br><b style=\"color:#1a1a2e\">Technical Product Manager<br>DWM Administration Services</b></p>";
    $h .= "</div></div>";

    return $h;
}

function buildEmptyHtml($dayDisplay) {
    $h  = "<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1a1a2e;max-width:1000px;margin:0 auto\">";
    $h .= "<div style=\"background:#4a6cf7;background-image:linear-gradient(135deg,#4a6cf7 0%,#7c3aed 100%);color:#ffffff;padding:24px 28px;border-radius:12px 12px 0 0\">";
    $h .= "<div style=\"font-size:1.4rem;font-weight:700\">Saturday Activity Report</div>";
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

        $mail->Subject = "Saturday Activity Report - $dayDisplay";
        $mail->isHTML(true);
        $mail->Body    = $html;

        $mail->send();
        log_msg('Email sent successfully' . (TEST_MODE ? ' (test mode — Ryan only)' : ''));
    } catch (Exception $e) {
        log_msg('EMAIL ERROR: ' . $mail->ErrorInfo);
        exit(1);
    }
}
