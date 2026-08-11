<?php
/**
 * Verifications Weekly Productivity Report
 *
 * Runs Monday mornings. Fetches the previous Mon-Sat from MaxContact,
 * aggregates per-agent metrics, and emails an HTML report.
 *
 * Usage:
 *   php verifications-report.php                          # previous Mon-Sat
 *   php verifications-report.php 2026-04-20 2026-04-25    # specific range
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/maxcontact.php';
require __DIR__ . '/productivity.php';

if (file_exists(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
} else {
    define('SMTP_HOST',      getenv('SMTP_HOST'));
    define('SMTP_PORT',      (int) getenv('SMTP_PORT'));
    define('SMTP_USER',      getenv('SMTP_USER'));
    define('SMTP_PASS',      getenv('SMTP_PASS'));
    define('EMAIL_FROM',     getenv('EMAIL_FROM'));
    define('EMAIL_FROM_NAME', getenv('EMAIL_FROM_NAME'));
    define('EMAIL_TO',       getenv('EMAIL_TO'));
    define('EMAIL_CC',       getenv('EMAIL_CC'));
    define('MC_BASE_URL',    getenv('MC_BASE_URL'));
    define('MC_USERNAME',    getenv('MC_USERNAME'));
    define('MC_PASSWORD',    getenv('MC_PASSWORD'));
    define('MC_CAMPAIGN_ID', (int) getenv('MC_CAMPAIGN_ID'));
    define('SUPABASE_URL',         getenv('SUPABASE_URL'));
    define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY'));
    define('PAGES_URL',            getenv('PAGES_URL'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Toggle to false to also email Tom and Tina ──
const TEST_MODE = false;

// ── Preview mode (php verifications-report.php --preview) ──
// Sends only to Ryan and writes NOTHING to Supabase, so the email can be
// reviewed (e.g. after a threshold change) without progressing anyone's
// disciplinary stage or touching the audit trail.
define('PREVIEW', in_array('--preview', $argv ?? [], true));

// ── Preliminary review mode (php verifications-report.php --preliminary) ──
// The first of the two Monday runs: sends to Tina only (Ryan BCC'd) with a
// review banner, and writes NOTHING. She logs any approved short-day
// exceptions; the afternoon final run then assesses with those applied and
// sends to everyone. Keeping it write-free means stages only ever advance
// once, on the final run, after exceptions are in — no retroactive un-doing.
define('PRELIMINARY', in_array('--preliminary', $argv ?? [], true));

// Neither the preview nor the preliminary run commits anything to Supabase.
define('NO_COMMIT', PREVIEW || PRELIMINARY);

// ── Supersede banner (php verifications-report.php ... --supersede) ──
// Adds a one-off note that this re-issue corrects a version already sent —
// used when re-sending a week after a correction (e.g. a threshold change).
define('SUPERSEDE', in_array('--supersede', $argv ?? [], true));

const SUCCESS_CODES = ['DuelES', 'DuelSale', 'ElecSale', 'ESsale', 'FuelES', 'GasSale'];
const CANCEL_CODES  = ['VoidBadExp', 'VoidChgMnd', 'VoidDDDate', 'VoidDDQues', 'VoidDeadLn', 'VoidDebt', 'VoidLangBr', 'VoidNoCon', 'VoidNoDMC', 'VoidSwitch', 'VoidWrgDet', 'Vulnerable'];

function log_msg($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── Determine date range ──
if (isset($argv[1], $argv[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[2])) {
    $start = new DateTime($argv[1]);
    $end   = new DateTime($argv[2]);
} else {
    $end = new DateTime();
    $end->modify('previous saturday');
    $start = clone $end;
    $start->modify('-5 days');
}

log_msg("Range: " . $start->format('d/m/Y') . " to " . $end->format('d/m/Y'));

// ── Fetch CSVs day-by-day ──
$header = null;
$allRows = [];

for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $dateStr = $d->format('d/m/Y');
    log_msg("Fetching $dateStr...");
    [$csv, $err] = fetchMaxContactCSV($dateStr);
    if ($err) {
        log_msg("FETCH ERROR for $dateStr: $err");
        continue;
    }
    $lines = preg_split('/\r?\n/', trim($csv));
    if (empty($lines)) continue;
    $headerLine = array_shift($lines);
    if ($header === null) $header = str_getcsv($headerLine);
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $allRows[] = str_getcsv($line);
    }
}

log_msg("Total rows: " . count($allRows));

if (empty($allRows) || $header === null) {
    log_msg("No data - aborting.");
    exit(0);
}

// ── Fetch occupancy report (per-agent wrap/break time) ──
log_msg("Fetching occupancy report (weekly)...");
$occupancyStart = $start->format('Y-m-d') . 'T00:00:00';
$occupancyEnd   = (clone $end)->modify('+1 day')->format('Y-m-d') . 'T00:00:00';
[$occupancy, $occErr] = fetchOccupancyReport($occupancyStart, $occupancyEnd, [(string) MC_CAMPAIGN_ID]);
if ($occErr) {
    log_msg("OCCUPANCY WARNING: $occErr (proceeding without wrap/break data)");
    $occupancy = [];
}

// ── Fetch per-day occupancy (for the <7h-per-day trigger) and per-day
//    activity (to net Meeting time out of Break time) ──
// Meeting break time is only visible in the Activity Summary (event-level),
// and that parser is single-day only, so we fetch it per day and accumulate
// each agent's Meeting seconds to subtract from their weekly Break Time.
log_msg("Fetching per-day occupancy and activity...");
$perDayLogOn    = [];  // [agentName => [YYYY-MM-DD => seconds]]
$meetingSeconds = [];  // [agentName => total 'Meeting' break seconds for the week]
for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $dayStartIso = $d->format('Y-m-d') . 'T00:00:00';
    $dayEndIso   = (clone $d)->modify('+1 day')->format('Y-m-d') . 'T00:00:00';

    [$dayOcc, $dayErr] = fetchOccupancyReport($dayStartIso, $dayEndIso, [(string) MC_CAMPAIGN_ID]);
    if ($dayErr) {
        log_msg("Daily occupancy fetch failed for {$d->format('d/m/Y')}: $dayErr");
    } else {
        foreach ($dayOcc as $name => $data) {
            if (!empty($data['log_on'])) {
                $perDayLogOn[$name][$d->format('Y-m-d')] = parseHmsTime($data['log_on']);
            }
        }
    }

    [$dayAct, $actErr] = fetchActivitySummary($dayStartIso, $dayEndIso, [(string) MC_CAMPAIGN_ID]);
    if ($actErr) {
        log_msg("Daily activity fetch failed for {$d->format('d/m/Y')}: $actErr (Meeting time not netted out for this day)");
        continue;
    }
    foreach ($dayAct as $name => $a) {
        foreach ($a['events'] as $e) {
            if ($e['type'] === 'Meeting') {
                $meetingSeconds[$name] = ($meetingSeconds[$name] ?? 0) + parseHmsTime($e['duration']);
            }
        }
    }
}

// ── Column indices ──
$idx = [
    'fullname'              => array_search('fullname', $header),
    'resultcode'            => array_search('resultcode', $header),
    'resultcodedescription' => array_search('resultcodedescription', $header),
    'startdatetime'         => array_search('startdatetime', $header),
    'recordinglengthstr'    => array_search('recordinglengthstr', $header),
];

foreach ($idx as $k => $v) {
    if ($v === false) {
        log_msg("ERROR: Missing column '$k' in CSV");
        exit(1);
    }
}

// ── Aggregate per agent ──
$agents = [];
$cancelReasons = [];

foreach ($allRows as $row) {
    $agent   = trim($row[$idx['fullname']] ?? '');
    if ($agent === '') continue;

    $rc      = trim($row[$idx['resultcode']] ?? '');
    $rcDesc  = trim($row[$idx['resultcodedescription']] ?? '');
    $startDt = trim($row[$idx['startdatetime']] ?? '');
    $dur     = trim($row[$idx['recordinglengthstr']] ?? '');

    $datePart = '';
    if (preg_match('#^(\d{2}/\d{2}/\d{4})#', $startDt, $m)) {
        $datePart = $m[1];
    }

    if (!isset($agents[$agent])) {
        $agents[$agent] = ['days' => [], 'total' => 0, 'successes' => 0, 'cancels' => 0, 'seconds' => 0];
    }

    $agents[$agent]['total']++;
    if ($datePart) $agents[$agent]['days'][$datePart] = true;
    $agents[$agent]['seconds'] += parseDuration($dur);

    if (in_array($rc, SUCCESS_CODES, true)) {
        $agents[$agent]['successes']++;
    } elseif (in_array($rc, CANCEL_CODES, true)) {
        $agents[$agent]['cancels']++;
        $reasonLabel = $rcDesc !== '' ? $rcDesc : $rc;
        $cancelReasons[$reasonLabel] = ($cancelReasons[$reasonLabel] ?? 0) + 1;
    }
}

// ── Team totals ──
$team = ['days' => 0, 'total' => 0, 'successes' => 0, 'cancels' => 0, 'seconds' => 0, 'wrap_seconds' => 0, 'break_seconds' => 0];
foreach ($agents as $data) {
    $team['days']      += count($data['days']);
    $team['total']     += $data['total'];
    $team['successes'] += $data['successes'];
    $team['cancels']   += $data['cancels'];
    $team['seconds']   += $data['seconds'];
}

// Sort agents by total calls descending
$rows = [];
foreach ($agents as $name => $data) {
    $wrap  = isset($occupancy[$name]['wrap'])  ? parseHmsTime($occupancy[$name]['wrap'])  : 0;
    $break = isset($occupancy[$name]['break']) ? parseHmsTime($occupancy[$name]['break']) : 0;
    // Company-mandated Meeting time is excluded from the break stat.
    $break = max(0, $break - ($meetingSeconds[$name] ?? 0));
    $team['wrap_seconds']  += $wrap;
    $team['break_seconds'] += $break;
    $rows[] = [
        'name'          => $name,
        'days'          => count($data['days']),
        'total'         => $data['total'],
        'successes'     => $data['successes'],
        'cancels'       => $data['cancels'],
        'seconds'       => $data['seconds'],
        'wrap_seconds'  => $wrap,
        'break_seconds' => $break,
    ];
}
usort($rows, fn($a, $b) => $b['total'] - $a['total']);

// ── Productivity trigger assessment ──
log_msg("Assessing productivity triggers...");
$productivitySection = '';
$prevStatuses = loadProductivityStatuses();
if (isset($prevStatuses['_error'])) {
    log_msg("PRODUCTIVITY WARNING: could not load status: " . $prevStatuses['_error'] . " (skipping section)");
} else {
    $weekStartIso = $start->format('Y-m-d');
    $weekEndIso   = $end->format('Y-m-d');

    // Excused short days (approved sickness, half-days, appointments) for this
    // week, keyed [agentName => ['YYYY-MM-DD' => reason]]. Degrade gracefully if
    // the table isn't migrated yet, so the report never breaks on its absence.
    $exceptions = loadLogonExceptions($weekStartIso, $weekEndIso);
    if (isset($exceptions['_error'])) {
        log_msg("EXCEPTIONS WARNING: could not load log-on exceptions: " . $exceptions['_error'] . " (proceeding with none)");
        $exceptions = [];
    }

    $monitored = [];
    $watchlist = [];
    $resets    = [];
    $skipped   = [];

    // Build the set of agents to assess: anyone who appears in this week's occupancy data
    // OR anyone with an existing status row (so they keep progressing/resetting if absent).
    $agentNames = array_unique(array_merge(array_keys($occupancy), array_keys($prevStatuses)));

    foreach ($agentNames as $name) {
        $agentExcused = $exceptions[$name] ?? [];       // ['YYYY-MM-DD' => reason]
        $excusedDates = array_keys($agentExcused);

        if (!isset($occupancy[$name])) {
            // No occupancy data this week - treat as no trigger so stages can still reset
            $eval = ['triggered' => false, 'reasons' => [], 'not_ready_pct' => 0, 'break_pct' => 0, 'wrap_pct' => 0, 'short_login_days' => 0, 'log_on_seconds' => 0];
        } else {
            $occ = $occupancy[$name];
            // Net company-mandated Meeting time out of Break before the % test.
            $breakSecs = max(0, parseHmsTime($occ['break'] ?? '') - ($meetingSeconds[$name] ?? 0));
            $eval = evaluateTriggers([
                'log_on'    => parseHmsTime($occ['log_on']    ?? ''),
                'not_ready' => parseHmsTime($occ['not_ready'] ?? ''),
                'break'     => $breakSecs,
                'wrap'      => parseHmsTime($occ['wrap']      ?? ''),
            ], $perDayLogOn[$name] ?? [], $excusedDates);
        }

        // Short-day breakdown (date + hours + excused reason) for the email.
        $shortDays = shortDayDetails($perDayLogOn[$name] ?? [], $agentExcused);

        $prev = $prevStatuses[$name] ?? null;
        $prevStage = $prev['current_stage'] ?? 'none';

        // Stage progression is not idempotent - applying it twice for the same
        // week advances the agent twice. Re-running the report (manual dispatch,
        // a retry after a failure, or regenerating an older week) must rebuild
        // the email without moving anyone on, so reuse the stored status once
        // the agent has been assessed up to this week or beyond.
        $lastAssessed = $prev['last_assessed_week'] ?? null;
        $alreadyAssessed = $lastAssessed !== null && $lastAssessed >= $weekEndIso;
        if ($alreadyAssessed) $skipped[] = $name;

        $result = $alreadyAssessed
            ? ['status' => prodStatusFromRow($prev), 'transition' => null]
            : applyStateMachine($prev, $eval['triggered'], $end);
        $newStage = $result['status']['current_stage'];

        // Persist (best-effort). weekly_triggers just restates this week's
        // figures, so rewriting it on a re-run is harmless; the status row is
        // left alone when the stage was not progressed. Preview and the
        // preliminary review run write nothing.
        if (!NO_COMMIT) {
            $err = saveWeeklyTrigger($name, $weekStartIso, $weekEndIso, $eval, $excusedDates);
            if ($err) log_msg("WARNING: weekly_triggers save failed for $name: $err");
            if (!$alreadyAssessed) {
                $err = saveProductivityStatus($name, $result['status']);
                if ($err) log_msg("WARNING: productivity_status save failed for $name: $err");
            }
        }

        // Classify for the email section
        if ($newStage !== 'none') {
            $monitored[] = ['name' => $name, 'status' => $result['status'], 'eval' => $eval, 'short_days' => $shortDays];
        } elseif ($result['transition'] === 'reset') {
            $resets[] = ['name' => $name, 'prev_stage' => $prevStage];
        } elseif ($eval['triggered'] && $prevStage === 'none' && $result['status']['consecutive_trigger_weeks'] === 1) {
            $watchlist[] = ['name' => $name, 'eval' => $eval, 'short_days' => $shortDays];
        }
    }

    if ($skipped) {
        log_msg('Stage progression skipped for ' . count($skipped) . ' agent(s) already assessed up to '
            . "$weekEndIso (re-run): " . implode(', ', $skipped));
    }

    // Keep the exceptions-form roster in step with this week's agents.
    if (!NO_COMMIT) {
        $err = saveProductivityAgents(array_keys($occupancy));
        if ($err) log_msg("WARNING: productivity_agents upsert failed: $err");
    }

    // Sort monitored agents by stage severity (Final first)
    $stageOrder = ['final' => 0, 'second' => 1, 'first' => 2, 'informal' => 3];
    usort($monitored, fn($a, $b) => $stageOrder[$a['status']['current_stage']] <=> $stageOrder[$b['status']['current_stage']]);

    $exceptionsUrl = (defined('PAGES_URL') && PAGES_URL) ? rtrim(PAGES_URL, '/') . '/exceptions.html' : '';
    $productivitySection = renderProductivitySection($monitored, $watchlist, $resets, $exceptionsUrl);
}

// ── Build email HTML ──
$html = buildHtml($rows, $team, $cancelReasons, $productivitySection, $start, $end);

// ── Send ──
sendEmail($html, $start, $end);

log_msg("Done.");
exit(0);

// ══════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════

function buildNarrative($rows, $team, $cancelReasons) {
    $paragraphs = [];

    // Overview
    $successRate = pct($team['successes'], $team['total']);
    $cancelRate  = pct($team['cancels'],   $team['total']);
    $teamRatio   = ratio($team['successes'], $team['cancels']);
    $callTime    = formatDuration($team['seconds']);
    $agentCount  = count($rows);

    $paragraphs[] = "Across <b>$agentCount agents</b> and <b>{$team['days']} agent-days</b>, the team handled "
        . "<b>" . number_format($team['total']) . " calls</b>, totalling <b>$callTime</b> of call time. "
        . "Of these, <b>{$team['successes']}</b> were verified ($successRate) and "
        . "<b>{$team['cancels']}</b> were cancelled ($cancelRate), giving an overall "
        . "success-to-cancellation ratio of <b>$teamRatio</b>.";

    // Top performer insights (only if 2+ agents)
    if ($agentCount >= 2) {
        // Top by raw successes
        $topByCount = $rows;
        usort($topByCount, fn($a, $b) => $b['successes'] - $a['successes']);
        $topVerifier = $topByCount[0];

        // Top by conversion rate (min 20 calls to qualify)
        $eligible = array_filter($rows, fn($r) => $r['total'] >= 20);
        $topByRate = null;
        if (!empty($eligible)) {
            usort($eligible, function ($a, $b) {
                $aRate = $a['total'] > 0 ? $a['successes'] / $a['total'] : 0;
                $bRate = $b['total'] > 0 ? $b['successes'] / $b['total'] : 0;
                return $bRate <=> $aRate;
            });
            $topByRate = $eligible[0];
        }

        if ($topVerifier['successes'] > 0) {
            $insight = "<b>{$topVerifier['name']}</b> led the week with "
                . "<b>{$topVerifier['successes']} verifications</b> "
                . "(" . pct($topVerifier['successes'], $topVerifier['total']) . " of {$topVerifier['total']} calls)";

            if ($topByRate && $topByRate['name'] !== $topVerifier['name']) {
                $rate = pct($topByRate['successes'], $topByRate['total']);
                $insight .= ", while <b>{$topByRate['name']}</b> had the strongest verification rate at <b>$rate</b> "
                    . "({$topByRate['successes']} of {$topByRate['total']} calls)";
            }
            $paragraphs[] = $insight . ".";
        }
    }

    // Cancellation insight
    if (!empty($cancelReasons) && $team['cancels'] > 0) {
        arsort($cancelReasons);
        $topReason = array_key_first($cancelReasons);
        $topCount  = $cancelReasons[$topReason];
        $topPct    = pct($topCount, $team['cancels']);

        if (count($cancelReasons) === 1) {
            $paragraphs[] = "All cancellations this week were due to <b>" . htmlspecialchars($topReason) . "</b>.";
        } else {
            $paragraphs[] = "The leading cancellation reason was <b>" . htmlspecialchars($topReason) . "</b> "
                . "($topCount, $topPct of all cancellations).";
        }
    }

    return $paragraphs;
}

function parseDuration($str) {
    if ($str === '' || !preg_match_all('/(\d+)\s*([hms])/i', $str, $matches, PREG_SET_ORDER)) return 0;
    $sec = 0;
    foreach ($matches as $m) {
        $unit = strtolower($m[2]);
        if ($unit === 'h') $sec += (int)$m[1] * 3600;
        elseif ($unit === 'm') $sec += (int)$m[1] * 60;
        elseif ($unit === 's') $sec += (int)$m[1];
    }
    return $sec;
}

function formatDuration($seconds) {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return "{$h}h:{$m}m:{$s}s";
}

function pct($num, $denom) {
    if ($denom <= 0) return '0.0%';
    return number_format($num / $denom * 100, 1) . '%';
}

function ratio($s, $c) {
    if ($s === 0 && $c === 0) return '—';
    if ($c === 0) return '∞';
    return number_format($s / $c, 2) . ':1';
}

function sectionHeader($title, $accentColour) {
    return "<h3 style=\"border-left:4px solid $accentColour;padding-left:12px;margin:32px 0 16px;font-size:1.05rem;color:#1a1a2e\">$title</h3>";
}

function renderKpiCards($team) {
    $verifyRate = pct($team['successes'], $team['total']);
    $cancelRate = pct($team['cancels'],   $team['total']);
    $ratioVal   = ratio($team['successes'], $team['cancels']);

    $cards = [
        ['label' => 'Total Calls',   'value' => number_format($team['total']),     'sub' => '',           'color' => '#4a6cf7'],
        ['label' => 'Verifications', 'value' => number_format($team['successes']), 'sub' => $verifyRate, 'color' => '#27ae60'],
        ['label' => 'Cancels',       'value' => number_format($team['cancels']),   'sub' => $cancelRate, 'color' => '#e74c3c'],
        ['label' => 'S:C Ratio',     'value' => $ratioVal,                          'sub' => '',           'color' => '#1a1a2e'],
    ];

    $h  = "<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px 0;margin-bottom:8px\">";
    $h .= "<tr>";
    foreach ($cards as $c) {
        $sub = $c['sub'] !== ''
            ? "<div style=\"color:{$c['color']};font-size:0.85rem;margin-top:4px;font-weight:600\">{$c['sub']}</div>"
            : '';
        $h .= "<td valign=\"top\" style=\"width:25%\">";
        $h .= "<div style=\"background:#f8f9fa;border:1px solid #ececec;border-top:3px solid {$c['color']};border-radius:8px;padding:16px;text-align:center\">";
        $h .= "<div style=\"font-size:0.7rem;color:#666;text-transform:uppercase;letter-spacing:0.6px;font-weight:600\">{$c['label']}</div>";
        $h .= "<div style=\"font-size:1.65rem;font-weight:700;margin-top:6px;color:{$c['color']}\">{$c['value']}</div>";
        $h .= $sub;
        $h .= "</div>";
        $h .= "</td>";
    }
    $h .= "</tr></table>";
    return $h;
}

function buildHtml($rows, $team, $cancelReasons, $productivitySection, $start, $end) {
    $rangeStr = $start->format('d/m/Y') . ' to ' . $end->format('d/m/Y');

    $h  = "<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1a1a2e;max-width:1000px;margin:0 auto\">";

    // ── Header banner ──
    $h .= "<div style=\"background:#4a6cf7;background-image:linear-gradient(135deg,#4a6cf7 0%,#7c3aed 100%);color:#ffffff;padding:24px 28px;border-radius:12px 12px 0 0\">";
    $h .= "<div style=\"font-size:1.4rem;font-weight:700;letter-spacing:-0.5px\">Verifications Weekly Productivity Report</div>";
    $h .= "<div style=\"margin-top:8px;font-size:0.95rem;opacity:0.9\">Week of $rangeStr</div>";
    $h .= "</div>";

    // ── Body container ──
    $h .= "<div style=\"background:#ffffff;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 12px 12px;padding:28px\">";

    // ── Preliminary review banner (Tina's review copy, before the team send) ──
    if (PRELIMINARY) {
        $exUrl = (defined('PAGES_URL') && PAGES_URL) ? rtrim(PAGES_URL, '/') . '/exceptions.html' : '';
        $link = $exUrl ? " &nbsp;<a href=\"$exUrl\" style=\"color:#4a6cf7;font-weight:700;text-decoration:none\">Log an exception &rarr;</a>" : '';
        $h .= "<div style=\"background:#eef1fb;border-left:4px solid #4a6cf7;padding:14px 16px;border-radius:4px;margin-bottom:16px;font-size:0.9rem;color:#26365f\">"
            . "<b>Preliminary &mdash; for your review.</b> This has not gone to the team yet. Please check the short days flagged under Productivity Triggers and log any that were approved (sickness, appointment, half-day) before the final report is sent this afternoon.$link"
            . "</div>";
    }

    // ── Correction banner (one-off re-issue) ──
    if (SUPERSEDE) {
        $h .= "<div style=\"background:#fef5e7;border-left:4px solid #f39c12;padding:12px 16px;border-radius:4px;margin-bottom:8px;font-size:0.9rem;color:#7a4d00\">"
            . "<b>Please note:</b> this corrects and replaces the report sent earlier today. The daily log-on threshold has been updated from 7.5 to 7 hours, and the figures below reflect that change."
            . "</div>";
    }

    // ── KPI cards ──
    $h .= renderKpiCards($team);

    // ── Greeting + narrative ──
    $h .= "<p style=\"margin-top:24px\">Hi all,</p>";
    $h .= "<p>Please find below the verifications team's productivity for the week of <b>$rangeStr</b>.</p>";
    foreach (buildNarrative($rows, $team, $cancelReasons) as $para) {
        $h .= "<p>$para</p>";
    }

    // ── Agent Performance ──
    $h .= sectionHeader('Agent Performance', '#4a6cf7');
    $h .= "<table cellpadding=\"8\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%\">";
    $h .= "<thead><tr style=\"background:#f8f9fa;text-align:left\">";
    foreach (['Agent', 'Days', 'Calls', 'Successes', 'Cancels', 'S:C Ratio', 'Times'] as $col) {
        $h .= "<th style=\"border-bottom:2px solid #e0e0e0;padding:10px 8px;font-weight:600;color:#555;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.4px\">$col</th>";
    }
    $h .= "</tr></thead><tbody>";

    $stackTimes = function ($call, $wrap, $break) {
        $rows = [
            ['Call',  $call],
            ['Wrap',  $wrap],
            ['Break', $break],
        ];
        $out = "<div style=\"font-size:11.5px;line-height:1.55\">";
        foreach ($rows as [$label, $val]) {
            $out .= "<div><span style=\"color:#888;display:inline-block;width:38px\">$label</span>$val</div>";
        }
        $out .= "</div>";
        return $out;
    };

    foreach ($rows as $i => $r) {
        $rowBg = $i % 2 === 0 ? '#ffffff' : '#fafafa';
        $sCell = $r['successes'] . ' (' . pct($r['successes'], $r['total']) . ')';
        $cCell = $r['cancels']   . ' (' . pct($r['cancels'],   $r['total']) . ')';
        $rCell = ratio($r['successes'], $r['cancels']) . " ({$r['successes']}:{$r['cancels']})";
        $tCell = formatDuration($r['seconds']);
        $wCell = $r['wrap_seconds']  > 0 ? formatHms($r['wrap_seconds'])  : '-';
        $bCell = $r['break_seconds'] > 0 ? formatHms($r['break_seconds']) : '-';
        $timesCell = $stackTimes($tCell, $wCell, $bCell);
        $h .= "<tr style=\"background:$rowBg\">";
        $h .= "<td style=\"padding:10px 8px;vertical-align:middle\"><b>" . htmlspecialchars($r['name']) . "</b></td>";
        $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">{$r['days']}</td>";
        $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">{$r['total']}</td>";
        $h .= "<td style=\"padding:10px 8px;color:#27ae60;font-weight:600;vertical-align:middle\">$sCell</td>";
        $h .= "<td style=\"padding:10px 8px;color:#e74c3c;font-weight:600;vertical-align:middle\">$cCell</td>";
        $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">$rCell</td>";
        $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">$timesCell</td>";
        $h .= "</tr>";
    }

    // Team totals row
    $tsCell = $team['successes'] . ' (' . pct($team['successes'], $team['total']) . ')';
    $tcCell = $team['cancels']   . ' (' . pct($team['cancels'],   $team['total']) . ')';
    $trCell = ratio($team['successes'], $team['cancels']) . " ({$team['successes']}:{$team['cancels']})";
    $ttCell = formatDuration($team['seconds']);
    $twCell = $team['wrap_seconds']  > 0 ? formatHms($team['wrap_seconds'])  : '-';
    $tbCell = $team['break_seconds'] > 0 ? formatHms($team['break_seconds']) : '-';
    $teamTimesCell = $stackTimes($ttCell, $twCell, $tbCell);
    $h .= "<tr style=\"background:#f0f3ff;font-weight:700\">";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;vertical-align:middle\">Team Total</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;vertical-align:middle\">{$team['days']}</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;vertical-align:middle\">{$team['total']}</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;color:#27ae60;vertical-align:middle\">$tsCell</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;color:#e74c3c;vertical-align:middle\">$tcCell</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;vertical-align:middle\">$trCell</td>";
    $h .= "<td style=\"padding:12px 8px;border-top:2px solid #4a6cf7;vertical-align:middle\">$teamTimesCell</td>";
    $h .= "</tr>";

    $h .= "</tbody></table>";

    // ── Productivity triggers (already rendered with its own styled header) ──
    $h .= $productivitySection;

    // ── Cancellation breakdown ──
    $h .= sectionHeader('Cancellation Breakdown', '#e74c3c');
    if (empty($cancelReasons)) {
        $h .= "<p style=\"color:#666\">No cancellations recorded this week.</p>";
    } else {
        arsort($cancelReasons);
        $maxCount = max($cancelReasons);
        $totalCancels = array_sum($cancelReasons);

        $h .= "<table cellpadding=\"6\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%;max-width:760px\">";
        foreach ($cancelReasons as $reason => $count) {
            $barWidth = $maxCount > 0 ? round(($count / $maxCount) * 300) : 0;
            $label = $count . ' (' . pct($count, $totalCancels) . ')';
            $h .= "<tr>";
            $h .= "<td style=\"padding:6px 8px;width:240px;vertical-align:middle\">" . htmlspecialchars($reason) . "</td>";
            $h .= "<td style=\"padding:6px 8px;width:320px;vertical-align:middle\">";
            $h .= "<div style=\"display:inline-block;background:#e74c3c;background-image:linear-gradient(90deg,#e74c3c 0%,#c0392b 100%);height:18px;width:{$barWidth}px;border-radius:9px;vertical-align:middle\"></div>";
            $h .= "</td>";
            $h .= "<td style=\"padding:6px 8px;vertical-align:middle;white-space:nowrap;font-weight:600\">$label</td>";
            $h .= "</tr>";
        }
        $h .= "</table>";
    }

    // ── Footer divider + signature ──
    $h .= "<hr style=\"border:none;border-top:1px solid #e0e0e0;margin:32px 0 20px\">";
    $h .= "<p style=\"color:#555;font-size:0.9rem\">Kind regards,<br><br>Ryan Lancaster<br><b style=\"color:#1a1a2e\">Technical Product Manager<br>DWM Administration Services</b></p>";

    $h .= "</div>"; // body container
    $h .= "</div>"; // outer container

    return $h;
}

function sendEmail($html, $start, $end) {
    $rangeStr = $start->format('d/m/Y') . ' to ' . $end->format('d/m/Y');

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
        if (PREVIEW) {
            // Preview always wins on recipients — it never leaves Ryan's inbox,
            // even when combined with --preliminary to check that format.
            $mail->addAddress(EMAIL_FROM);  // Ryan only
        } elseif (PRELIMINARY) {
            // Preliminary review copy — Tina only, Ryan BCC'd for oversight.
            $mail->addAddress(EMAIL_TO);    // Tina
            $mail->addBCC(EMAIL_FROM);      // Ryan
        } else {
            $mail->addAddress(EMAIL_FROM);  // Ryan
            if (!TEST_MODE) {
                $mail->addCC(EMAIL_TO);     // Tina
                $mail->addCC(EMAIL_CC);     // Tom
                $mail->addCC('adam.kirk@dwmas.co.uk');
                $mail->addCC('liam.tustin@dwmas.co.uk');
                $mail->addCC('nick.mangan@dwmas.co.uk');
                $mail->addCC('hannad.barre@dwmas.co.uk');
            }
        }

        $prefix = PREVIEW ? '[PREVIEW] ' : (PRELIMINARY ? '[FOR REVIEW] ' : '');
        $mail->Subject = $prefix . "Verifications Weekly Productivity Report - $rangeStr";
        $mail->isHTML(true);
        $mail->Body = $html;

        $mail->send();
        if (PREVIEW)         $only = ' (Ryan only, preview — no data written)';
        elseif (PRELIMINARY) $only = ' (Tina only, preliminary review — no data written)';
        elseif (TEST_MODE)   $only = ' (Ryan only)';
        else                 $only = '';
        log_msg("Email sent successfully" . $only);
    } catch (Exception $e) {
        log_msg("EMAIL ERROR: " . $mail->ErrorInfo);
        exit(1);
    }
}
