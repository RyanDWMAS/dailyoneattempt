<?php
/**
 * Daily One Attempt — automated CLI runner
 *
 * Fetches today's CSV from MaxContact (run after dialling ends at 20:30),
 * processes it, and emails the result. Runs Mon–Sat.
 *
 * Usage:
 *   php cron.php              # today's data
 *   php cron.php 2026-02-20   # specific date (YYYY-MM-DD)
 *
 * Schedule examples:
 *   Linux cron:     0 21 * * 1-6  /usr/bin/php /path/to/cron.php
 *   Windows Task:   schtasks /create /tn "DailyOneAttempt" /tr "php C:\path\to\cron.php" /sc weekly /d MON,TUE,WED,THU,FRI,SAT /st 21:00
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/supabase.php';
require __DIR__ . '/maxcontact.php';

// Load config from file if available, otherwise from environment variables
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
    define('SUPABASE_URL',       getenv('SUPABASE_URL'));
    define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY'));
    define('PAGES_URL',           getenv('PAGES_URL'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Determine target date ──
// GitHub Actions runs scheduled jobs on a best-effort basis and can delay the
// 21:00 run by hours — occasionally past midnight. A run landing in the small
// hours (before noon UTC) is treated as a delayed evening run and reports the
// previous day, so the day that just finished dialling is always the one
// reported. (Matches the guard in endshift-report.php.)
if (isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
    $targetDate = new DateTime($argv[1]);
} else {
    $targetDate = new DateTime('now', new DateTimeZone('UTC'));
    if ((int) $targetDate->format('H') < 12) {
        $targetDate->modify('-1 day');
    }
}

$mcDate = $targetDate->format('d/m/Y');
$displayDate = $mcDate;
$dateStr = $targetDate->format('dmy');

log_msg("Target date: $mcDate");

// ── Fetch from MaxContact ──
log_msg("Fetching CSV from MaxContact...");
[$csvData, $fetchError] = fetchMaxContactCSV($mcDate);
if ($fetchError) {
    log_msg("FETCH ERROR: $fetchError");
    exit(1);
}
log_msg("Fetched " . strlen($csvData) . " bytes");

// ── Process CSV ──
log_msg("Processing CSV...");
$processResult = processCLI($csvData);
if (is_string($processResult)) {
    log_msg("PROCESS ERROR: $processResult");
    exit(1);
}

[$csvContent, $rowCount] = $processResult;
$filename = 'dailyoneattempt' . $dateStr . '.csv';
log_msg("Processing complete — $rowCount rows in result");

// ── Create review session in Supabase ──
$reportDateISO = $targetDate->format('Y-m-d');
$reviewUrl = null;
log_msg("Creating review session in Supabase...");
[$reviewUrl, $sbError] = createReviewSession($reportDateISO, $csvContent, $rowCount);
if ($sbError) {
    log_msg("SUPABASE WARNING: $sbError (email will still be sent)");
} else {
    log_msg("Review session created" . ($reviewUrl ? " — $reviewUrl" : " (no rows to review)"));
}

// ── Store raw call stats ──
log_msg("Storing call stats...");
$statsError = storeCallStats($reportDateISO, $csvData);
if ($statsError) {
    log_msg("STATS WARNING: $statsError");
} else {
    log_msg("Call stats stored.");
}

// ── Send email ──
if ($rowCount === 0) {
    log_msg("No rows — sending 'nothing to report' email...");
    sendEmail($displayDate, null, null, true, null);
} else {
    log_msg("Sending email with attachment ($filename)...");
    sendEmail($displayDate, $csvContent, $filename, false, $reviewUrl);
}

log_msg("Done.");
exit(0);

// ──────────────────────────────────────────────────────────────
// Functions
// ──────────────────────────────────────────────────────────────

function log_msg($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
}

function processCLI($csvString) {
    $lines = explode("\n", $csvString);
    $header = str_getcsv(array_shift($lines));
    $header = array_map('trim', $header);

    $keepCols = ['startdatetime', 'fullname', 'resultcodedescription', 'phonenumber', 'disconnector'];
    $colIndexes = [];
    foreach ($keepCols as $col) {
        $idx = array_search($col, $header);
        if ($idx === false) {
            return "Missing required column: $col";
        }
        $colIndexes[$col] = $idx;
    }

    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $rows[] = str_getcsv($line);
    }

    $phoneCounts = [];
    foreach ($rows as $row) {
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        $phoneCounts[$phone] = ($phoneCounts[$phone] ?? 0) + 1;
    }

    $allowedResults = ['Immediate Hang Up', 'No Answer'];
    $result = [];
    foreach ($rows as $row) {
        $desc = trim($row[$colIndexes['resultcodedescription']] ?? '');
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        if (in_array($desc, $allowedResults) && $phoneCounts[$phone] === 1) {
            $result[] = $row;
        }
    }

    // Remove invalid UK phone numbers
    $result = array_filter($result, function ($row) use ($colIndexes) {
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        return preg_match('/^0\d{10}$/', $phone);
    });

    $output = fopen('php://temp', 'r+');
    fputcsv($output, $keepCols);
    foreach ($result as $row) {
        $outRow = [];
        foreach ($keepCols as $col) {
            $outRow[] = $row[$colIndexes[$col]] ?? '';
        }
        fputcsv($output, $outRow);
    }
    rewind($output);
    $csvContent = stream_get_contents($output);
    fclose($output);

    return [$csvContent, count($result)];
}

function sendEmail($displayDate, $csvContent, $filename, $nothingToReport, $reviewUrl) {
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
        $mail->addAddress(EMAIL_TO);
        $mail->addCC(EMAIL_CC);
        $mail->addBCC(EMAIL_FROM);

        $mail->Subject = 'Daily One Attempt ' . $displayDate;
        $mail->isHTML(true);

        $signature = 'Kind regards,<br><br>Ryan Lancaster<br><b>Technical Product Manager<br>DWM Administration Services</b>';

        if ($nothingToReport) {
            $mail->Body = "Hi Tina,<br><br>Nothing to report today.<br><br>$signature";
        } else {
            $reviewLine = $reviewUrl
                ? "Please review these attempts here: <a href=\"$reviewUrl\">Review Attempts</a><br><br>"
                : '';
            $mail->Body = "Hi Tina,<br><br>Please see attached.<br><br>{$reviewLine}{$signature}";
            $mail->addStringAttachment($csvContent, $filename, 'base64', 'text/csv');
        }

        $mail->send();
        log_msg("Email sent successfully.");
    } catch (Exception $e) {
        log_msg("EMAIL ERROR: " . $mail->ErrorInfo);
        exit(1);
    }
}
