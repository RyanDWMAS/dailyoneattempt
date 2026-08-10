<?php
/**
 * One-off backfill: re-evaluate historical productivity triggers against the
 * current daily log-on threshold (PROD_MIN_DAILY_LOGON_SECONDS) and rebuild
 * each agent's stage from the weekly_triggers audit trail.
 *
 * Why this is needed: weekly_triggers stores short_login_days as a count, not
 * the per-day log-on seconds behind it, so a stored "2 short days" could mean
 * two 7h15m days (fine under the new 7h rule) or two 5h days (still short).
 * The per-day figures have to come back from MaxContact's occupancy report.
 *
 * The stage machine is path-dependent — flipping one week changes every week
 * after it — so stages are replayed from scratch per agent rather than patched.
 *
 * Fetched days are cached to disk, so re-runs and --commit after a dry run
 * cost nothing extra.
 *
 * Usage:
 *   php backfill-productivity.php                       # dry run, full history
 *   php backfill-productivity.php --from=2026-06-01     # limit the window
 *   php backfill-productivity.php --commit              # write the changes
 *   php backfill-productivity.php --refetch             # ignore the day cache
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/maxcontact.php';
require __DIR__ . '/productivity.php';
require __DIR__ . '/config.php';

// The threshold history was originally assessed under, kept only so the
// dry run can show what changed and prove the replay reproduces the past.
const PROD_OLD_MIN_DAILY_LOGON_SECONDS = 7.5 * 3600;

$opts    = getopt('', ['commit', 'refetch', 'from::', 'to::', 'cache::']);
$commit  = isset($opts['commit']);
$refetch = isset($opts['refetch']);
$cachePath = $opts['cache'] ?? sys_get_temp_dir() . '/productivity-occupancy-cache.json';

function log_msg($msg) {
    echo '[' . date('H:i:s') . "] $msg\n";
}

// ── Load the audit trail ──
$u = SUPABASE_URL;
$k = SUPABASE_SERVICE_KEY;

$wt = supabaseGet($u, $k, 'weekly_triggers', 'select=*&order=week_start.asc&limit=5000');
if ($wt['error']) { log_msg('ERROR loading weekly_triggers: ' . $wt['error']); exit(1); }
$rows = $wt['data'] ?? [];
if (!$rows) { log_msg('No weekly_triggers rows found — nothing to backfill.'); exit(0); }

$st = supabaseGet($u, $k, 'productivity_status', 'select=*');
if ($st['error']) { log_msg('ERROR loading productivity_status: ' . $st['error']); exit(1); }
$live = [];
foreach ($st['data'] ?? [] as $s) $live[$s['agent_name']] = $s;

// Optional window — rows outside it keep their stored evaluation but are still
// replayed, so stage history stays continuous.
$from = $opts['from'] ?? null;
$to   = $opts['to']   ?? null;

$inWindow = fn($r) => (!$from || $r['week_start'] >= $from) && (!$to || $r['week_end'] <= $to);

// ── Work out which days need per-day occupancy ──
$needDates = [];
foreach ($rows as $r) {
    if (!$inWindow($r)) continue;
    foreach (weekDates($r['week_start'], $r['week_end']) as $d) $needDates[$d] = true;
}
$needDates = array_keys($needDates);
sort($needDates);

log_msg(count($rows) . ' agent-weeks across ' . count(array_unique(array_column($rows, 'agent_name'))) . ' agents');
log_msg(count($needDates) . ' days of per-day occupancy required');

// ── Fetch per-day occupancy (cached) ──
$cache = [];
if (!$refetch && file_exists($cachePath)) {
    $cache = json_decode(file_get_contents($cachePath), true) ?: [];
    log_msg('Loaded ' . count($cache) . ' cached days from ' . $cachePath);
}

$failedDays = [];
foreach ($needDates as $i => $date) {
    if (isset($cache[$date])) continue;

    $n = $i + 1;
    log_msg("  Fetching $date ($n/" . count($needDates) . ')...');
    [$occ, $err] = fetchOccupancyReport(
        $date . 'T00:00:00',
        (new DateTime($date))->modify('+1 day')->format('Y-m-d') . 'T00:00:00',
        [(string) MC_CAMPAIGN_ID]
    );
    if ($err) {
        log_msg("    FETCH FAILED: $err");
        $failedDays[$date] = $err;
        continue;
    }

    $day = [];
    foreach ($occ as $name => $data) {
        if (!empty($data['log_on'])) $day[$name] = parseHmsTime($data['log_on']);
    }
    $cache[$date] = $day;
    file_put_contents($cachePath, json_encode($cache));
}
if ($failedDays) {
    log_msg(count($failedDays) . ' day(s) could not be fetched — any week containing one is left untouched.');
}

// ── Re-evaluate each agent-week ──
$byAgent    = [];
$changed    = 0;
$incomplete = 0;

foreach ($rows as $r) {
    $agent  = $r['agent_name'];
    $fired  = firedList($r);
    $others = array_values(array_diff($fired, ['short_login']));

    $row = $r;
    $row['_recomputed'] = false;
    $row['_incomplete'] = false;

    if ($inWindow($r)) {
        $dates   = weekDates($r['week_start'], $r['week_end']);
        $missing = array_filter($dates, fn($d) => !isset($cache[$d]));

        if ($missing) {
            // A day we cannot see might have been a short day, so changing this
            // week would be a guess. Leave it exactly as stored.
            $row['_incomplete'] = true;
            $incomplete++;
        } else {
            $shortNew = 0;
            $shortOld = 0;
            foreach ($dates as $d) {
                $sec = $cache[$d][$agent] ?? 0;
                if ($sec <= 0) continue;
                if ($sec < PROD_MIN_DAILY_LOGON_SECONDS)     $shortNew++;
                if ($sec < PROD_OLD_MIN_DAILY_LOGON_SECONDS) $shortOld++;
            }

            $reasonsNew = $others;
            if ($shortNew > 0) $reasonsNew[] = 'short_login';
            $reasonsNew = canonicalOrder($reasonsNew);

            $row['_recomputed']      = true;
            $row['_short_new']       = $shortNew;
            $row['_short_recheck']   = $shortOld;   // sanity: should match stored
            $row['_reasons_new']     = $reasonsNew;
            $row['_triggered_new']   = !empty($reasonsNew);

            if ((bool)$r['triggered'] !== $row['_triggered_new']
                || (int)$r['short_login_days'] !== $shortNew) {
                $changed++;
            }
        }
    }

    $byAgent[$agent][] = $row;
}
foreach ($byAgent as &$rs) usort($rs, fn($a, $b) => strcmp($a['week_start'], $b['week_start']));
unset($rs);

log_msg("$changed agent-week(s) change under the new threshold"
    . ($incomplete ? ", $incomplete left untouched (missing occupancy)" : ''));

// ── Sanity check: does the recomputation reproduce the stored short-day counts
//    when run at the OLD threshold? If not, the occupancy data no longer matches
//    what the report saw at the time and the result cannot be trusted. ──
$recheckOk = 0; $recheckBad = [];
foreach ($byAgent as $agent => $weeks) {
    foreach ($weeks as $w) {
        if (empty($w['_recomputed'])) continue;
        if ((int)$w['_short_recheck'] === (int)$w['short_login_days']) $recheckOk++;
        else $recheckBad[] = "$agent {$w['week_start']}: stored={$w['short_login_days']} recomputed={$w['_short_recheck']}";
    }
}
echo "\n=== Sanity check: recompute at the old 7.5h threshold ===\n";
echo "  matches stored count: $recheckOk\n";
if ($recheckBad) {
    echo '  MISMATCHES: ' . count($recheckBad) . " — occupancy data has shifted since assessment.\n";
    foreach (array_slice($recheckBad, 0, 10) as $m) echo "    $m\n";
    if (count($recheckBad) > 10) echo '    ... and ' . (count($recheckBad) - 10) . " more\n";
    echo "  Treat the results below as indicative only.\n";
}

// ── Replay stages ──
echo "\n=== Stage rebuild ===\n";
printf("%-24s %-16s %-16s %-16s %s\n", 'Agent', 'Live now', 'Replay @7.5h', 'Replay @7h', '');
$newStatuses = [];
$exonerated  = [];
$driftNoted  = [];

foreach ($byAgent as $agent => $weeks) {
    $old = replayStages($weeks, false);
    $new = replayStages($weeks, true);
    $newStatuses[$agent] = $new;

    $act     = $live[$agent] ?? null;
    $liveStr = $act ? stageStr($act['current_stage'], $act['awaiting_hr']) : '-';
    $oldStr  = stageStr($old['current_stage'], $old['awaiting_hr']);
    $newStr  = stageStr($new['current_stage'], $new['awaiting_hr']);

    $note = '';
    if ($newStr !== $oldStr) { $note = '<== IMPROVED'; $exonerated[] = $agent; }
    if ($act && ($liveStr !== $oldStr
        || (string)$act['stage_entered_at'] !== (string)$old['stage_entered_at'])) {
        $driftNoted[] = $agent;
        $note = trim($note . ' [live drifts from audit trail]');
    }

    printf("%-24s %-16s %-16s %-16s %s\n", $agent, $liveStr, $oldStr, $newStr, $note);
}

if ($driftNoted) {
    echo "\nNOTE: " . count($driftNoted) . " agent(s) have a live status that the audit trail\n";
    echo "does not reproduce (see stage_entered_at). Committing rebuilds them from the\n";
    echo "trail, which corrects that drift as well as applying the new threshold.\n";
}

// ── Commit ──
if (!$commit) {
    echo "\nDry run — nothing written. Re-run with --commit to apply.\n";
    exit(0);
}

echo "\nApplying changes...\n";
$errors = 0;
foreach ($byAgent as $agent => $weeks) {
    foreach ($weeks as $w) {
        if (empty($w['_recomputed'])) continue;
        if ((bool)$w['triggered'] === $w['_triggered_new']
            && (int)$w['short_login_days'] === (int)$w['_short_new']) continue;

        $err = saveWeeklyTrigger($agent, $w['week_start'], $w['week_end'], [
            'triggered'        => $w['_triggered_new'],
            'reasons'          => $w['_reasons_new'],
            'not_ready_pct'    => (float)$w['not_ready_pct'],
            'break_pct'        => (float)$w['break_pct'],
            'wrap_pct'         => (float)$w['wrap_pct'],
            'short_login_days' => $w['_short_new'],
            'log_on_seconds'   => (int)$w['log_on_seconds'],
        ]);
        if ($err) { log_msg("WARNING: weekly_triggers save failed for $agent {$w['week_start']}: $err"); $errors++; }
    }

    $err = saveProductivityStatus($agent, $newStatuses[$agent]);
    if ($err) { log_msg("WARNING: productivity_status save failed for $agent: $err"); $errors++; }
}
log_msg($errors ? "Completed with $errors error(s)." : 'Committed successfully.');
exit($errors ? 1 : 0);

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

/** Every date from week_start to week_end inclusive, as YYYY-MM-DD. */
function weekDates($startIso, $endIso) {
    $out = [];
    for ($d = new DateTime($startIso), $e = new DateTime($endIso); $d <= $e; $d->modify('+1 day')) {
        $out[] = $d->format('Y-m-d');
    }
    return $out;
}

/** Supabase returns text[] as either an array or a "{a,b}" literal. */
function firedList($r) {
    $f = $r['triggers_fired'] ?? [];
    if (is_string($f)) $f = array_values(array_filter(explode(',', trim($f, '{}'))));
    return $f;
}

/** Keep reasons in the order evaluateTriggers() produces them. */
function canonicalOrder($reasons) {
    $order = ['not_ready', 'break', 'wrap', 'short_login'];
    return array_values(array_filter($order, fn($r) => in_array($r, $reasons, true)));
}

/** Replay the stage machine from a clean slate over an agent's weeks. */
function replayStages($weeks, $useRecomputed) {
    $prev = null;
    foreach ($weeks as $w) {
        $triggered = ($useRecomputed && !empty($w['_recomputed']))
            ? $w['_triggered_new']
            : (bool) $w['triggered'];
        $prev = applyStateMachine($prev, $triggered, new DateTime($w['week_end']))['status'];
    }
    return $prev;
}

function stageStr($stage, $awaitingHr) {
    return $stage . ($awaitingHr ? '+HR' : '');
}
