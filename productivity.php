<?php
/**
 * Productivity trigger tracking and stage progression.
 *
 * Triggers (any of these in a week breaches productivity):
 *   - Not Ready > 3% of log-on time
 *   - Break    > 8% of log-on time
 *   - Wrap     > 2% of log-on time
 *   - Log-on   < 7h on any worked day
 *
 * Stage progression:
 *   None      -> Informal  (2 consecutive triggered weeks)
 *   Informal  -> First     (any trigger within 4 weeks of entry)
 *   First     -> Second    (any trigger within 2 weeks of entry)
 *   Second    -> Final     (any trigger within 2 weeks of entry)
 *   Final     stays Final  + awaiting_hr flag set on re-trigger
 *
 * Stage reset (to None):
 *   - No trigger by end of stage window
 *
 * Window per stage (days):
 *   Informal = 28, First = 14, Second = 14, Final = 14
 */

require_once __DIR__ . '/supabase.php';

const PROD_THRESH_NOT_READY = 0.03;
const PROD_THRESH_BREAK     = 0.08;
const PROD_THRESH_WRAP      = 0.02;
const PROD_MIN_DAILY_LOGON_SECONDS = 7 * 3600;

const PROD_STAGE_WINDOWS = [
    'informal' => 28,
    'first'    => 14,
    'second'   => 14,
    'final'    => 14,
];

/**
 * Evaluate this week's triggers for a single agent.
 *
 * @param array $weekData     Per-agent week aggregate: ['log_on','not_ready','break','wrap'] as seconds
 * @param array $perDayLogOn  Map of YYYY-MM-DD => log_on_seconds for this agent
 * @return array  ['triggered' => bool, 'reasons' => string[], 'not_ready_pct' => float, ...]
 */
function evaluateTriggers($weekData, $perDayLogOn) {
    $logOn = $weekData['log_on'] ?? 0;
    $notReadyPct = $logOn > 0 ? ($weekData['not_ready'] ?? 0) / $logOn : 0;
    $breakPct    = $logOn > 0 ? ($weekData['break']     ?? 0) / $logOn : 0;
    $wrapPct     = $logOn > 0 ? ($weekData['wrap']      ?? 0) / $logOn : 0;

    $shortDays = 0;
    foreach ($perDayLogOn as $sec) {
        if ($sec > 0 && $sec < PROD_MIN_DAILY_LOGON_SECONDS) $shortDays++;
    }

    $reasons = [];
    if ($notReadyPct > PROD_THRESH_NOT_READY) $reasons[] = 'not_ready';
    if ($breakPct    > PROD_THRESH_BREAK)     $reasons[] = 'break';
    if ($wrapPct     > PROD_THRESH_WRAP)      $reasons[] = 'wrap';
    if ($shortDays   > 0)                     $reasons[] = 'short_login';

    return [
        'triggered'        => !empty($reasons),
        'reasons'          => $reasons,
        'not_ready_pct'    => $notReadyPct,
        'break_pct'        => $breakPct,
        'wrap_pct'         => $wrapPct,
        'short_login_days' => $shortDays,
        'log_on_seconds'   => $logOn,
    ];
}

/**
 * Apply the stage transition logic given the previous status and this
 * week's trigger result. Returns the new status plus a transition note
 * describing what (if anything) changed.
 *
 * @param array|null $prev   Existing row from productivity_status, or null for new agents
 * @param bool       $triggered  Whether this week's evaluation breached
 * @param DateTime   $weekEnd    Saturday of the week being assessed
 * @return array ['status' => [...], 'transition' => 'entered'|'advanced'|'reset'|'flagged'|null]
 */
function applyStateMachine($prev, $triggered, $weekEnd) {
    $stage      = $prev['current_stage']             ?? 'none';
    $enteredAt  = $prev['stage_entered_at']          ?? null;
    $consec     = (int)($prev['consecutive_trigger_weeks'] ?? 0);
    $awaitingHr = !empty($prev['awaiting_hr']);

    $weekEndStr = $weekEnd->format('Y-m-d');
    $transition = null;

    if ($triggered) {
        if ($stage === 'none') {
            $consec++;
            if ($consec >= 2) {
                $stage = 'informal';
                $enteredAt = $weekEndStr;
                $consec = 0;
                $transition = 'entered';
            }
        } elseif ($stage === 'informal') {
            $stage = 'first';   $enteredAt = $weekEndStr; $consec = 0;
            $transition = 'advanced';
        } elseif ($stage === 'first') {
            $stage = 'second';  $enteredAt = $weekEndStr; $consec = 0;
            $transition = 'advanced';
        } elseif ($stage === 'second') {
            $stage = 'final';   $enteredAt = $weekEndStr; $consec = 0;
            $transition = 'advanced';
        } elseif ($stage === 'final') {
            $awaitingHr = true;
            $consec = 0;
            $transition = 'flagged';
        }
    } else {
        // No trigger this week
        if ($stage === 'none') {
            $consec = 0;
        } else {
            $window = PROD_STAGE_WINDOWS[$stage];
            $entered = new DateTime($enteredAt);
            $daysElapsed = (int) $entered->diff($weekEnd)->format('%r%a');
            if ($daysElapsed >= $window) {
                $stage = 'none';
                $enteredAt = null;
                $consec = 0;
                $awaitingHr = false;
                $transition = 'reset';
            }
        }
    }

    return [
        'status' => [
            'current_stage'             => $stage,
            'stage_entered_at'          => $enteredAt,
            'consecutive_trigger_weeks' => $consec,
            'awaiting_hr'               => $awaitingHr,
            'last_assessed_week'        => $weekEndStr,
        ],
        'transition' => $transition,
    ];
}

/**
 * Rebuild the status shape applyStateMachine() returns from a stored row,
 * for when this week must be reported without progressing anyone.
 */
function prodStatusFromRow($row) {
    return [
        'current_stage'             => $row['current_stage'] ?? 'none',
        'stage_entered_at'          => $row['stage_entered_at'] ?? null,
        'consecutive_trigger_weeks' => (int) ($row['consecutive_trigger_weeks'] ?? 0),
        'awaiting_hr'               => !empty($row['awaiting_hr']),
        'last_assessed_week'        => $row['last_assessed_week'] ?? null,
    ];
}

/**
 * Load all productivity_status rows from Supabase.
 *
 * @return array [agentName => row]
 */
function loadProductivityStatuses() {
    $supabaseUrl = SUPABASE_URL;
    $serviceKey  = SUPABASE_SERVICE_KEY;
    $result = supabaseGet($supabaseUrl, $serviceKey, 'productivity_status', 'select=*');
    if ($result['error']) {
        return ['_error' => $result['error']];
    }
    $byName = [];
    foreach ($result['data'] ?? [] as $row) {
        $byName[$row['agent_name']] = $row;
    }
    return $byName;
}

/**
 * Upsert a productivity_status row. Uses Supabase's UPSERT (Prefer: resolution=merge-duplicates).
 */
function saveProductivityStatus($agentName, $status) {
    $supabaseUrl = SUPABASE_URL;
    $serviceKey  = SUPABASE_SERVICE_KEY;
    $payload = array_merge(['agent_name' => $agentName], $status, ['updated_at' => date('c')]);

    $url = rtrim($supabaseUrl, '/') . '/rest/v1/productivity_status?on_conflict=agent_name';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Prefer: resolution=merge-duplicates',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        return "HTTP $httpCode: $response";
    }
    return null;
}

/**
 * Upsert a weekly_triggers row.
 */
function saveWeeklyTrigger($agentName, $weekStart, $weekEnd, $eval) {
    $supabaseUrl = SUPABASE_URL;
    $serviceKey  = SUPABASE_SERVICE_KEY;
    $payload = [
        'agent_name'       => $agentName,
        'week_start'       => $weekStart,
        'week_end'         => $weekEnd,
        'triggered'        => $eval['triggered'],
        'triggers_fired'   => $eval['reasons'],
        'not_ready_pct'    => round($eval['not_ready_pct'], 5),
        'break_pct'        => round($eval['break_pct'], 5),
        'wrap_pct'         => round($eval['wrap_pct'], 5),
        'short_login_days' => $eval['short_login_days'],
        'log_on_seconds'   => $eval['log_on_seconds'],
    ];

    $url = rtrim($supabaseUrl, '/') . '/rest/v1/weekly_triggers?on_conflict=agent_name,week_start';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Prefer: resolution=merge-duplicates',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        return "HTTP $httpCode: $response";
    }
    return null;
}

// ─────────────────────────────────────────────────────────────
// Email rendering helpers
// ─────────────────────────────────────────────────────────────

function prodStageLabel($stage) {
    return match ($stage) {
        'informal' => 'Informal Stage',
        'first'    => 'First Stage',
        'second'   => 'Second Stage',
        'final'    => 'Final Stage',
        default    => $stage,
    };
}

function prodStageColour($stage) {
    return match ($stage) {
        'informal' => '#f39c12',
        'first'    => '#e67e22',
        'second'   => '#e74c3c',
        'final'    => '#c0392b',
        default    => '#666',
    };
}

function prodTriggerLabel($code) {
    return match ($code) {
        'not_ready'   => 'Not Ready > 3%',
        'break'       => 'Break > 8%',
        'wrap'        => 'Wrap > 2%',
        'short_login' => 'Log-on < 7h on at least one day',
        default       => $code,
    };
}

function prodStageAction($stage, $awaitingHr) {
    if ($awaitingHr) {
        return '<b style="color:#c0392b">Triggered again at Final Stage - awaiting HR action (suspension/termination may be warranted).</b>';
    }
    return match ($stage) {
        'informal' => 'Informal recorded conversation required. Monitor for the next 4 weeks.',
        'first'    => 'Formal investigation required; possible disciplinary and formal warning.',
        'second'   => 'Disciplinary meeting with manager and HR representative recommended.',
        'final'    => 'Further disciplinary action recommended; potential suspension or termination.',
        default    => '',
    };
}

/**
 * Render the "Productivity Triggers" section for the weekly email.
 *
 * @param array $monitored  Agents currently at Informal+ stages, with this week's eval
 * @param array $watchlist  Agents at None who triggered this week (1 step from Informal)
 * @param array $resets     Agents who reset to None this week (from a monitored stage)
 */
function renderProductivitySection($monitored, $watchlist, $resets) {
    $h = "<h3 style=\"border-left:4px solid #f39c12;padding-left:12px;margin:32px 0 16px;font-size:1.05rem;color:#1a1a2e\">Productivity Triggers</h3>";

    if (empty($monitored) && empty($watchlist) && empty($resets)) {
        $h .= "<div style=\"background:#eafaf1;border-left:3px solid #27ae60;padding:12px 16px;border-radius:4px;color:#1d6f42;font-size:0.9rem\">No agents currently on the productivity process. Nothing to action.</div>";
        return $h;
    }

    if (!empty($monitored)) {
        $h .= "<table cellpadding=\"8\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%;margin-bottom:16px\">";
        $h .= "<thead><tr style=\"background:#f8f9fa;text-align:left\">";
        foreach (['Agent', 'Stage', 'Entered', 'Window expires', 'This week', 'Action'] as $col) {
            $h .= "<th style=\"border-bottom:2px solid #e0e0e0;padding:10px 8px;font-weight:600;color:#555;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.4px\">$col</th>";
        }
        $h .= "</tr></thead><tbody>";

        foreach ($monitored as $i => $m) {
            $rowBg      = $i % 2 === 0 ? '#ffffff' : '#fafafa';
            $stage      = $m['status']['current_stage'];
            $enteredAt  = $m['status']['stage_entered_at'];
            $awaitingHr = !empty($m['status']['awaiting_hr']);
            $window     = PROD_STAGE_WINDOWS[$stage] ?? 0;
            $expires    = $enteredAt ? (new DateTime($enteredAt))->modify("+$window days")->format('d/m/Y') : '-';
            $enteredFmt = $enteredAt ? (new DateTime($enteredAt))->format('d/m/Y') : '-';

            $thisWeek = $m['eval']['triggered']
                ? prodReasonPills($m['eval']['reasons'])
                : '<span style="display:inline-block;background:#eafaf1;color:#1d6f42;padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600">Clean</span>';

            $stageHtml = prodStagePill($stage);
            if ($awaitingHr) {
                $stageHtml .= ' <span style="display:inline-block;background:#fdecea;color:#c0392b;padding:3px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;margin-left:4px">Awaiting HR</span>';
            }

            $h .= "<tr style=\"background:$rowBg\">";
            $h .= "<td style=\"padding:10px 8px;vertical-align:middle\"><b>" . htmlspecialchars($m['name']) . "</b></td>";
            $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">$stageHtml</td>";
            $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">$enteredFmt</td>";
            $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">$expires</td>";
            $h .= "<td style=\"padding:10px 8px;vertical-align:middle\">$thisWeek</td>";
            $h .= "<td style=\"padding:10px 8px;font-size:12px;color:#555;vertical-align:middle\">" . prodStageAction($stage, $awaitingHr) . "</td>";
            $h .= "</tr>";
        }
        $h .= "</tbody></table>";
    }

    if (!empty($watchlist)) {
        $h .= "<div style=\"background:#fef5e7;border-left:3px solid #f39c12;padding:12px 16px;border-radius:4px;margin-bottom:12px\">";
        $h .= "<div style=\"font-weight:700;color:#7a4d00;margin-bottom:6px\">Watchlist</div>";
        $h .= "<div style=\"color:#555;font-size:0.85rem;margin-bottom:8px\">Triggered this week - another trigger next week will move them to the Informal Stage.</div>";
        foreach ($watchlist as $w) {
            $reasonPills = prodReasonPills($w['eval']['reasons']);
            $h .= "<div style=\"margin-top:6px\"><b>" . htmlspecialchars($w['name']) . "</b> &nbsp;$reasonPills</div>";
        }
        $h .= "</div>";
    }

    if (!empty($resets)) {
        $h .= "<div style=\"background:#eafaf1;border-left:3px solid #27ae60;padding:12px 16px;border-radius:4px\">";
        $h .= "<div style=\"font-weight:700;color:#1d6f42;margin-bottom:6px\">Reset to good standing</div>";
        $h .= "<div style=\"color:#555;font-size:0.85rem;margin-bottom:8px\">No triggers for the full monitoring window.</div>";
        foreach ($resets as $r) {
            $h .= "<div style=\"margin-top:4px\"><b>" . htmlspecialchars($r['name']) . "</b> &mdash; was " . prodStageLabel($r['prev_stage']) . "</div>";
        }
        $h .= "</div>";
    }

    return $h;
}

function prodStagePill($stage) {
    $colour = prodStageColour($stage);
    $label  = prodStageLabel($stage);
    return "<span style=\"display:inline-block;background:$colour;color:#ffffff;padding:4px 10px;border-radius:12px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px\">$label</span>";
}

function prodReasonPills($reasons) {
    $pills = [];
    foreach ($reasons as $r) {
        $pills[] = "<span style=\"display:inline-block;background:#fdecea;color:#c0392b;padding:3px 8px;border-radius:10px;font-size:0.72rem;font-weight:600;margin-right:4px;margin-bottom:2px\">" . prodTriggerLabel($r) . "</span>";
    }
    return implode('', $pills);
}
