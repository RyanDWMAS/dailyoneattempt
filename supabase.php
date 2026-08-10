<?php
/**
 * Supabase helper functions for posting review sessions and attempts.
 *
 * Used by both cron.php (CLI) and index.php (web UI) to push processed
 * CSV rows to Supabase after emailing. Returns a review URL for Tina.
 */

/**
 * Parse the processed daily CSV into attempt records for a session.
 *
 * @param string $csvContent  Processed CSV string (with header row)
 * @param mixed  $sessionId   review_sessions.id to attach each attempt to
 * @return array List of attempt rows (empty if there are no data rows)
 */
function csvToAttempts($csvContent, $sessionId) {
    $lines = explode("\n", trim($csvContent));
    $header = str_getcsv(array_shift($lines));
    $header = array_map('trim', $header);
    $colMap = array_flip($header);

    $attempts = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $row = str_getcsv($line);

        $attempts[] = [
            'session_id'    => $sessionId,
            'startdatetime' => $row[$colMap['startdatetime']] ?? '',
            'fullname'      => $row[$colMap['fullname']] ?? '',
            'resultcode'    => $row[$colMap['resultcodedescription']] ?? '',
            'phonenumber'   => $row[$colMap['phonenumber']] ?? '',
            'disconnector'  => $row[$colMap['disconnector']] ?? '',
        ];
    }
    return $attempts;
}

/**
 * Create a review session and insert all attempts into Supabase.
 *
 * @param string $reportDate  Date in YYYY-MM-DD format
 * @param string $csvContent  Processed CSV string (with header row)
 * @param int    $rowCount    Number of data rows
 * @return array [reviewUrl, error] — reviewUrl is null on failure
 */
function createReviewSession($reportDate, $csvContent, $rowCount) {
    $supabaseUrl = defined('SUPABASE_URL') ? SUPABASE_URL : getenv('SUPABASE_URL');
    $serviceKey  = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : getenv('SUPABASE_SERVICE_KEY');
    $pagesUrl    = defined('PAGES_URL') ? PAGES_URL : getenv('PAGES_URL');

    if (!$supabaseUrl || !$serviceKey || !$pagesUrl) {
        return [null, 'Supabase config missing (SUPABASE_URL, SUPABASE_SERVICE_KEY, or PAGES_URL)'];
    }

    // Check if a session already exists for this date
    $existing = supabaseGet($supabaseUrl, $serviceKey, 'review_sessions', 'report_date=eq.' . $reportDate . '&select=id,token,total_rows&limit=1');
    if (!empty($existing['data'])) {
        $existingId    = $existing['data'][0]['id'];
        $existingToken = $existing['data'][0]['token'];
        $existingRows  = (int) $existing['data'][0]['total_rows'];

        // Self-heal a poisoned placeholder. An earlier premature run for this
        // date (e.g. a GitHub schedule delay that fired before dialling had any
        // data) can leave a session with 0 rows. If this run actually has rows,
        // upgrade the existing session in place — otherwise it would return a
        // dead null link and silently drop the real attempts.
        if ($existingRows === 0 && $rowCount > 0) {
            $patch = supabasePatch($supabaseUrl, $serviceKey, 'review_sessions',
                'token=eq.' . $existingToken, ['total_rows' => $rowCount]);
            if ($patch['error']) {
                return [null, 'Failed to upgrade review session: ' . $patch['error']];
            }
            $attempts = csvToAttempts($csvContent, $existingId);
            if (!empty($attempts)) {
                $result = supabasePost($supabaseUrl, $serviceKey, 'attempts', $attempts);
                if ($result['error']) {
                    return [null, 'Failed to insert attempts: ' . $result['error']];
                }
            }
            return [rtrim($pagesUrl, '/') . '/review.html?token=' . $existingToken, null];
        }

        $reviewUrl = $existingRows > 0
            ? rtrim($pagesUrl, '/') . '/review.html?token=' . $existingToken
            : null;
        return [$reviewUrl, null];
    }

    // Generate a unique token
    $token = bin2hex(random_bytes(16));

    // Insert review session (even for 0 rows, so we can attach call stats)
    $sessionData = [
        'token'       => $token,
        'report_date' => $reportDate,
        'total_rows'  => $rowCount,
    ];

    $result = supabasePost($supabaseUrl, $serviceKey, 'review_sessions', $sessionData);
    if ($result['error']) {
        return [null, 'Failed to create review session: ' . $result['error']];
    }

    $sessionId = $result['data'][0]['id'] ?? null;
    if (!$sessionId) {
        return [null, 'No session ID returned from Supabase'];
    }

    // Parse CSV rows into attempt records (skip if no rows)
    if ($rowCount > 0) {
        $attempts = csvToAttempts($csvContent, $sessionId);
        if (!empty($attempts)) {
            $result = supabasePost($supabaseUrl, $serviceKey, 'attempts', $attempts);
            if ($result['error']) {
                return [null, 'Failed to insert attempts: ' . $result['error']];
            }
        }
    }

    $reviewUrl = $rowCount > 0
        ? rtrim($pagesUrl, '/') . '/review.html?token=' . $token
        : null;
    return [$reviewUrl, null];
}

/**
 * Store raw call statistics (total calls + per-agent breakdown) for a session.
 *
 * @param string $reportDate  Date in YYYY-MM-DD format
 * @param string $rawCsv      The raw, unfiltered CSV from MaxContact
 * @return string|null         Error message, or null on success
 */
function storeCallStats($reportDate, $rawCsv) {
    $supabaseUrl = defined('SUPABASE_URL') ? SUPABASE_URL : getenv('SUPABASE_URL');
    $serviceKey  = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : getenv('SUPABASE_SERVICE_KEY');

    if (!$supabaseUrl || !$serviceKey) return 'Supabase config missing';

    // Parse raw CSV to count total rows and per-agent breakdown
    $lines = explode("\n", $rawCsv);
    $header = array_map('trim', str_getcsv(array_shift($lines)));
    $fnIdx = array_search('fullname', $header);

    $agentCounts = [];
    $totalCalls = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $totalCalls++;
        $row = str_getcsv($line);
        $agent = trim($row[$fnIdx] ?? '');
        if ($agent === '') $agent = '(unassigned)';
        $agentCounts[$agent] = ($agentCounts[$agent] ?? 0) + 1;
    }

    // Find the session for this date
    $existing = supabaseGet($supabaseUrl, $serviceKey, 'review_sessions', 'report_date=eq.' . $reportDate . '&select=id&limit=1');
    if (empty($existing['data'])) return 'No session found for ' . $reportDate;
    $sessionId = $existing['data'][0]['id'];

    // Update total_calls on the session
    $patchResult = supabasePatch($supabaseUrl, $serviceKey, 'review_sessions', 'id=eq.' . $sessionId, ['total_calls' => $totalCalls]);
    if ($patchResult['error']) return 'Failed to update total_calls: ' . $patchResult['error'];

    // Insert per-agent stats
    $agentRows = [];
    foreach ($agentCounts as $name => $count) {
        $agentRows[] = [
            'session_id'  => $sessionId,
            'report_date' => $reportDate,
            'fullname'    => $name,
            'total_calls' => $count,
        ];
    }

    if (!empty($agentRows)) {
        $result = supabasePost($supabaseUrl, $serviceKey, 'daily_agent_stats', $agentRows);
        if ($result['error']) return 'Failed to insert agent stats: ' . $result['error'];
    }

    return null;
}

/**
 * PATCH data on a Supabase table via REST API.
 */
function supabasePatch($baseUrl, $serviceKey, $table, $query, $data) {
    $url = rtrim($baseUrl, '/') . '/rest/v1/' . $table . '?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Prefer: return=minimal',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        return ['error' => $curlError ?: "HTTP $httpCode: $response"];
    }
    return ['error' => null];
}

/**
 * GET data from a Supabase table via REST API.
 */
function supabaseGet($baseUrl, $serviceKey, $table, $query) {
    $url = rtrim($baseUrl, '/') . '/rest/v1/' . $table . '?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        return ['data' => null, 'error' => $curlError ?: "HTTP $httpCode"];
    }

    return ['data' => json_decode($response, true), 'error' => null];
}

/**
 * POST data to a Supabase table via REST API.
 */
function supabasePost($baseUrl, $serviceKey, $table, $data) {
    $url = rtrim($baseUrl, '/') . '/rest/v1/' . $table;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Prefer: return=representation',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['data' => null, 'error' => "cURL: $curlError"];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['data' => null, 'error' => "HTTP $httpCode: $response"];
    }

    return ['data' => json_decode($response, true), 'error' => null];
}
