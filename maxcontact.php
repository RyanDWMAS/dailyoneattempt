<?php
/**
 * MaxContact API client.
 *
 * Shared between cron.php (daily one-attempt report),
 * verifications-report.php (weekly productivity report), and
 * verifications-agent-report.php (weekly agent-facing report).
 *
 * Requires the following constants to be defined:
 *   MC_BASE_URL, MC_USERNAME, MC_PASSWORD, MC_CAMPAIGN_ID
 */

/**
 * Log in to the MaxContact Manager Portal and return session cookies.
 *
 * @return array [cookieHeader, error] — cookieHeader is null on failure
 */
function mcLogin() {
    $ch = curl_init(MC_BASE_URL . '/Home/Login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $respHeaders = substr($resp, 0, $headerSize);
    curl_close($ch);

    $cookies = '';
    if (preg_match('/ASP\.NET_SessionId=([^;\s]+)/', $respHeaders, $sm)) {
        $cookies = 'ASP.NET_SessionId=' . $sm[1];
    }

    $ch = curl_init(MC_BASE_URL . '/Home/Login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'Login'    => MC_USERNAME,
            'Password' => MC_PASSWORD,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
            'Cookie: ' . $cookies,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $respHeaders = substr($resp, 0, $headerSize);
    curl_close($ch);

    if (preg_match('/UserCookie=([^;\s]+)/', $respHeaders, $um)) {
        $cookies .= '; UserCookie=' . $um[1];
    }
    if (preg_match('/ASP\.NET_SessionId=([^;\s]+)/', $respHeaders, $sm2)) {
        $cookies = preg_replace('/ASP\.NET_SessionId=[^;]+/', 'ASP.NET_SessionId=' . $sm2[1], $cookies);
    }

    if ($httpCode !== 302 && $httpCode !== 200) {
        return [null, "Login failed with HTTP $httpCode."];
    }

    return [$cookies, null];
}

/**
 * Fetch the raw RecordHistory CSV export for a single day.
 *
 * @param string $dateStr Date in d/m/Y format (e.g. "20/02/2026")
 * @return array [csvData, error]
 */
function fetchMaxContactCSV($dateStr) {
    [$cookies, $err] = mcLogin();
    if ($err) return [null, $err];

    $startDate = $dateStr . ' 00:00';
    $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
    $dt->modify('+1 day');
    $endDate = $dt->format('d/m/Y') . ' 00:00';

    $params = http_build_query([
        'startDate'         => $startDate,
        'endDate'           => $endDate,
        'identity'          => '',
        'userID'            => 0,
        'campaignID'        => MC_CAMPAIGN_ID,
        'listID'            => 0,
        'reference'         => '',
        'isSuccess'         => 'false',
        'isAssociated'      => 'false',
        'isHotKey'          => 'false',
        'isThreadFiltered'  => 'false',
        'csatRating'        => 0,
        'leadID'            => 0,
        'leadPhoneID'       => 0,
        'resultCodeID'      => 0,
        'recLengthSearch'   => 1,
        'recScaleSearch'    => 0,
        'recLength1'        => 0,
        'recLength2'        => 0,
        'channelId'         => 0,
        'teamID'            => 0,
        'name'              => '',
        'name2'             => '',
    ]);

    $ch = curl_init(MC_BASE_URL . '/RecordHistory/Export?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookies],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $csvData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return [null, "cURL error: $curlError"];
    if ($httpCode !== 200) return [null, "MaxContact returned HTTP $httpCode."];
    if (empty($csvData) || stripos($csvData, 'historyid') === false) {
        return [null, "Response did not contain expected CSV data."];
    }

    return [$csvData, null];
}

/**
 * Fetch the "Occupancy Summary by Campaign" Telerik report.
 *
 * Returns per-agent time breakdown: log_on, man_hours, break, productive,
 * talk, hold, ready, preview, not_ready, ringing, conferencing, wrap,
 * callback, interacting, other (all as HH:MM:SS strings).
 *
 * @param string $startISO    Start datetime, e.g. "2026-05-05T00:00:00"
 * @param string $endISO      End datetime, e.g. "2026-05-12T00:00:00"
 * @param array  $campaignIds Campaign IDs as strings, e.g. ['238']
 * @return array [agentData, error]
 */
function fetchOccupancyReport($startISO, $endISO, $campaignIds) {
    [$cookies, $err] = mcLogin();
    if ($err) return [null, $err];

    $base = MC_BASE_URL . '/api/reportwebapi';

    // 1. Register client
    [$h, $body] = mcReportApiCall($base . '/clients', $cookies, 'POST', '{}');
    if ($h !== 200) return [null, "Telerik client registration failed: HTTP $h $body"];
    $clientId = json_decode($body, true)['clientId'] ?? null;
    if (!$clientId) return [null, "No clientId in response: $body"];

    // 2. Create instance
    $instanceBody = json_encode([
        'report' => 'OccupancySummarybyCampaign.trdx',
        'parameterValues' => [
            'startDate'   => $startISO,
            'endDate'     => $endISO,
            'campaignIds' => array_values(array_map('strval', $campaignIds)),
            'Culture'     => ['en-GB'],
        ],
    ]);
    [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances", $cookies, 'POST', $instanceBody);
    if ($h !== 201) return [null, "Telerik instance creation failed: HTTP $h $body"];
    $instId = json_decode($body, true)['instanceId'] ?? null;
    if (!$instId) return [null, "No instanceId in response: $body"];

    // 3. Request CSV document
    [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents", $cookies, 'POST', json_encode(['format' => 'CSV', 'deviceInfo' => new stdClass()]));
    if ($h !== 202) return [null, "Telerik document request failed: HTTP $h $body"];
    $docId = json_decode($body, true)['documentId'] ?? null;
    if (!$docId) return [null, "No documentId in response: $body"];

    // 4. Poll for ready (up to 60 seconds)
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents/$docId/info", $cookies);
        if ($h !== 200 && $h !== 202) return [null, "Polling failed: HTTP $h $body"];
        $info = json_decode($body, true);
        if (!empty($info['documentReady'])) { $ready = true; break; }
        sleep(2);
    }
    if (!$ready) return [null, "Document never became ready"];

    // 5. Download CSV
    [$h, $csv] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents/$docId", $cookies);
    if ($h !== 200) return [null, "Download failed: HTTP $h"];

    return [parseOccupancyCsv($csv), null];
}

/**
 * Internal helper for Telerik Reports REST API calls.
 *
 * $accept must be widened to a wildcard when downloading a rendered
 * document (MHTML/PDF/etc) — the server errors if asked for JSON.
 */
function mcReportApiCall($url, $cookies, $method = 'GET', $body = null, $accept = 'application/json') {
    $ch = curl_init($url);
    $headers = ['Cookie: ' . $cookies, 'Accept: ' . $accept];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, $resp];
}

/**
 * Fetch the "Activity Summary" report (Manager Portal reportId=90).
 *
 * Returns each agent's shift start/end plus every away-from-desk event
 * (Lunch, Bathroom, Other, Admin, AgentDisconnect ...) with its start,
 * end and duration.
 *
 * Rendered as MHTML rather than CSV: this report groups Campaign/Date/
 * User/Event, and Telerik's CSV export collapses that to a single row,
 * silently dropping every agent but the first. MHTML carries the fully
 * rendered report, so we decode it and read the laid-out rows back.
 *
 * @param string $startISO    Start datetime, e.g. "2026-07-11T00:00:00"
 * @param string $endISO      End datetime, e.g. "2026-07-12T00:00:00"
 * @param array  $campaignIds Campaign IDs as strings, e.g. ['238']
 * @return array [agents, error] — agents keyed by name:
 *   ['shift_start','shift_end','duration','man_hours','break_total',
 *    'events' => [['type','start','end','duration'], ...]]
 */
function fetchActivitySummary($startISO, $endISO, $campaignIds) {
    [$cookies, $err] = mcLogin();
    if ($err) return [null, $err];

    $base = MC_BASE_URL . '/api/reportwebapi';

    [$h, $body] = mcReportApiCall($base . '/clients', $cookies, 'POST', '{}');
    if ($h !== 200) return [null, "Telerik client registration failed: HTTP $h $body"];
    $clientId = json_decode($body, true)['clientId'] ?? null;
    if (!$clientId) return [null, "No clientId in response: $body"];

    // teamIds and userIds are mandatory for this report, so ask the API for
    // the full available list and pass everything — the campaign filter is
    // what actually narrows the result.
    $campaignIds = array_values(array_map('strval', $campaignIds));
    $teamIds = mcParameterValues($base, $clientId, $cookies, ['campaignIds' => $campaignIds], 'teamIds');
    if ($teamIds === null) return [null, 'Could not resolve teamIds'];
    $userIds = mcParameterValues($base, $clientId, $cookies, ['campaignIds' => $campaignIds, 'teamIds' => $teamIds], 'userIds');
    if ($userIds === null) return [null, 'Could not resolve userIds'];

    $instanceBody = json_encode([
        'report' => 'ActivitySummary.trdx',
        'parameterValues' => [
            'startDate'   => $startISO,
            'endDate'     => $endISO,
            'campaignIds' => $campaignIds,
            'teamIds'     => $teamIds,
            'userIds'     => $userIds,
            'Lookup_agentstatus_BreakTime' => '0',  // 0 = No Filter (show all users)
            'showcalcs'   => false,
            'Culture'     => ['en-GB'],
        ],
    ]);
    [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances", $cookies, 'POST', $instanceBody);
    if ($h !== 201) return [null, "Activity instance creation failed: HTTP $h $body"];
    $instId = json_decode($body, true)['instanceId'] ?? null;
    if (!$instId) return [null, "No instanceId in response: $body"];

    [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents", $cookies, 'POST', json_encode(['format' => 'MHTML', 'deviceInfo' => new stdClass()]));
    if ($h !== 202) return [null, "Activity document request failed: HTTP $h $body"];
    $docId = json_decode($body, true)['documentId'] ?? null;
    if (!$docId) return [null, "No documentId in response: $body"];

    $ready = false;
    for ($i = 0; $i < 45; $i++) {
        [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents/$docId/info", $cookies);
        if ($h !== 200 && $h !== 202) return [null, "Activity polling failed: HTTP $h $body"];
        if (!empty(json_decode($body, true)['documentReady'])) { $ready = true; break; }
        sleep(2);
    }
    if (!$ready) return [null, 'Activity document never became ready'];

    [$h, $mht] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents/$docId", $cookies, 'GET', null, '*/*');
    if ($h !== 200) return [null, "Activity download failed: HTTP $h"];

    $html = mcExtractHtmlFromMhtml($mht);
    if ($html === null) return [null, 'Could not extract HTML from MHTML response'];

    return [parseActivitySummaryHtml($html), null];
}

/**
 * Ask the Telerik parameters endpoint for the available values of one
 * (cascading) parameter, given the values chosen so far.
 *
 * @return array|null List of values as strings, or null on failure.
 */
function mcParameterValues($base, $clientId, $cookies, $parameterValues, $want) {
    $body = json_encode(['report' => 'ActivitySummary.trdx', 'parameterValues' => $parameterValues]);
    [$h, $resp] = mcReportApiCall($base . "/clients/$clientId/parameters", $cookies, 'POST', $body);
    if ($h !== 200) return null;
    foreach (json_decode($resp, true) ?: [] as $p) {
        if (($p['name'] ?? '') === $want) {
            return array_map(fn($a) => (string) $a['value'], $p['availableValues'] ?? []);
        }
    }
    return null;
}

/**
 * Pull the text/html part out of a Telerik MHTML (multipart/related) export.
 */
function mcExtractHtmlFromMhtml($mht) {
    if (!preg_match('/boundary="([^"]+)"/', $mht, $m)) return null;
    foreach (explode('--' . $m[1], $mht) as $part) {
        if (stripos($part, 'Content-Type: text/html') === false) continue;
        $split = preg_split("/\r?\n\r?\n/", $part, 2);
        if (count($split) < 2) continue;
        [$hdr, $body] = $split;
        return stripos($hdr, 'base64') !== false
            ? base64_decode(preg_replace('/\s+/', '', $body))
            : quoted_printable_decode($body);
    }
    return null;
}

/**
 * Parse the rendered Activity Summary HTML into per-agent activity.
 *
 * The report lays out absolutely-positioned divs rather than a table, but
 * emits them in reading order, which gives a reliable grammar:
 *   <label> followed by a run of HH:MM:SS (or N/A) values
 *     5 values => a User row  (start, end, duration, man hours, break total)
 *     3 values => an Event row (start, end, duration)
 * Date rows (dd/mm/yyyy), "Total" and "N/A" rows are group headers.
 *
 * The report renders the same data twice — once grouped Date/User/Event and
 * again grouped Campaign/Date/User/Event — and paginates, repeating "Total"
 * page-footers between users. So we can't delimit on "Total" counts. Instead:
 *   - keep the FIRST occurrence of each agent (the second grouping repeats
 *     them, so later duplicates are ignored to avoid double-counting events);
 *   - skip the campaign parent row (also 5 values) — it is the only 5-value
 *     row immediately followed by a date rather than by event rows;
 *   - never reset the current agent on a "Total", so a user whose events span
 *     a page break still accumulates correctly;
 *   - guard on the event timeline: an agent's events run forward in time, so a
 *     large backward jump means a page break has spilled a re-rendered agent's
 *     events in with no user row of their own — close the current agent and
 *     ignore them until the next real user row.
 */
function parseActivitySummaryHtml($html) {
    $html = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $html);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    // Collect text from leaf nodes only, in document order
    $tokens = [];
    foreach ($xp->query('//div | //span') as $n) {
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE) continue 2;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $n->textContent));
        if ($t !== '') $tokens[] = $t;
    }

    $isTime = fn($s) => (bool) preg_match('/^\d{1,3}:\d{2}:\d{2}$/', $s);
    $isDate = fn($s) => (bool) preg_match('#^\d{2}/\d{2}/\d{4}$#', $s);

    $agents   = [];
    $current  = null;   // agent name currently accumulating events, or null to ignore
    $lastEnd  = null;   // seconds-of-day of the current agent's last event end

    for ($i = 0; $i < count($tokens); $i++) {
        $label = $tokens[$i];
        if ($isTime($label) || $label === 'N/A') continue;   // consumed as values

        // Gather the run of values following this label
        $vals = [];
        for ($j = $i + 1; $j < count($tokens) && ($isTime($tokens[$j]) || $tokens[$j] === 'N/A'); $j++) {
            $vals[] = $tokens[$j];
        }
        if (!$vals) continue;
        $nextLabel = $tokens[$j] ?? null;   // the label following this row's values

        if ($label === 'Total') continue;              // page/grand total — leave $current as-is
        if ($isDate($label)) { $current = null; continue; }

        if (count($vals) >= 5) {
            // A 5-value row is either a User row or the Campaign parent row.
            // The campaign row is the parent of dates, so it is followed by a
            // date; a user is followed by its event rows.
            if ($nextLabel !== null && $isDate($nextLabel)) { $current = null; continue; }
            // User row — keep only the first occurrence (later groupings repeat it)
            if (isset($agents[$label])) { $current = null; continue; }
            $current = $label;
            $lastEnd = parseHmsTime($vals[0]);   // baseline: shift start
            $agents[$current] = [
                'shift_start' => $vals[0],
                'shift_end'   => $vals[1],
                'duration'    => $vals[2],
                'man_hours'   => $vals[3],
                'break_total' => $vals[4],
                'events'      => [],
            ];
        } elseif (count($vals) === 3 && $current !== null) {
            // Event row. Guard against a page break spilling another agent's
            // re-rendered events in with no user row: their timeline jumps
            // backwards, so close the current agent and ignore them.
            $startSecs = parseHmsTime($vals[0]);
            if ($lastEnd !== null && $startSecs < $lastEnd - 60) {
                $current = null;
                $lastEnd = null;
                continue;
            }
            $agents[$current]['events'][] = [
                'type'     => $label,
                'start'    => $vals[0],
                'end'      => $vals[1],
                'duration' => $vals[2],
            ];
            $lastEnd = parseHmsTime($vals[1]);
        }
    }

    return $agents;
}

/**
 * Parse the Telerik OccupancySummary CSV into per-agent metrics.
 *
 * The CSV is wide and irregular (Telerik dumps each textbox as a column),
 * but each row contains a "User" marker followed by the agent name, then
 * alternating labels and HH:MM:SS values. We just scan each row for the
 * labels we care about.
 *
 * @return array [agentName => [metricSlug => 'HH:MM:SS', ...]]
 */
function parseOccupancyCsv($csv) {
    $labels = [
        'Log On Time'           => 'log_on',
        'Man Hours'             => 'man_hours',
        'Break Time'            => 'break',
        'Productive Time'       => 'productive',
        'Talk Time'             => 'talk',
        'Hold Time'             => 'hold',
        'Ready Time'            => 'ready',
        'Preview Time'          => 'preview',
        'Not Ready Time'        => 'not_ready',
        'Ringing Time '         => 'ringing',  // trailing space is intentional - matches the report
        'Conferencing Time'     => 'conferencing',
        'Wrap Time'             => 'wrap',
        'Managing Callback Time' => 'callback',
        'Interacting TIme '     => 'interacting',  // typo and trailing space match the report
        'Other Time'            => 'other',
    ];

    $agents = [];
    $lines = preg_split('/\r?\n/', trim($csv));
    array_shift($lines);  // skip header row

    foreach ($lines as $line) {
        $cells = str_getcsv($line);
        $userIdx = array_search('User', $cells, true);
        if ($userIdx === false) continue;
        $name = trim($cells[$userIdx + 1] ?? '');
        if ($name === '' || $name === 'Total') continue;

        $data = [];
        $n = count($cells);
        for ($i = $userIdx + 2; $i < $n; $i++) {
            if (isset($labels[$cells[$i]])) {
                $slug = $labels[$cells[$i]];
                $data[$slug] = $cells[$i + 2] ?? '';
            }
        }
        $agents[$name] = $data;
    }

    return $agents;
}

/**
 * Parse "HH:MM:SS" into total seconds.
 */
function parseHmsTime($str) {
    if (!preg_match('/^(\d+):(\d+):(\d+)$/', trim($str), $m)) return 0;
    return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
}

/**
 * Format seconds as "Xh:Ym:Zs".
 */
function formatHms($seconds) {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return "{$h}h:{$m}m:{$s}s";
}
