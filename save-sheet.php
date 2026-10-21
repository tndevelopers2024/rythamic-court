<?php
/**
 * Rhythmic Beat Dance Court - Google Sheets Sync Dispatcher
 * 
 * Handles recording lead submissions, enquiries, and contact messages
 * into the Google Sheet via Google Apps Script Webhook.
 * 
 * Target Sheet:
 * https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit?usp=sharing
 */

// Report errors in log, return clean JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Kolkata');

/**
 * Reusable helper function to forward a lead to Google Sheets
 * 
 * @param array $leadData Raw or sanitized lead data
 * @param array|null $config Optional custom config (defaults to sheet-config.php)
 * @return array Result array with status, message, and details
 */
function saveLeadToGoogleSheet(array $leadData, ?array $config = null): array {
    if ($config === null) {
        $configFile = __DIR__ . '/sheet-config.php';
        $config = file_exists($configFile) ? require($configFile) : [];
    }

    $sheetId   = $config['sheet_id'] ?? '1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c';
    $scriptUrl = trim($config['apps_script_url'] ?? '');
    $enabled   = $config['sync_enabled'] ?? true;
    $timeout   = (int)($config['timeout'] ?? 10);

    // Prepare payload
    $payload = [
        'kind'      => trim(strip_tags((string)($leadData['kind'] ?? 'lead'))),
        'name'      => trim(strip_tags((string)($leadData['name'] ?? ''))),
        'phone'     => trim(strip_tags((string)($leadData['phone'] ?? ''))),
        'email'     => trim(filter_var((string)($leadData['email'] ?? ''), FILTER_SANITIZE_EMAIL)),
        'courses'   => $leadData['courses'] ?? [],
        'mode'      => trim(strip_tags((string)($leadData['mode'] ?? ''))),
        'location'  => trim(strip_tags((string)($leadData['location'] ?? ''))),
        'msg'       => trim(strip_tags((string)($leadData['msg'] ?? $leadData['message'] ?? ''))),
        'page'      => trim(strip_tags((string)($leadData['page'] ?? '#home'))),
        'at'        => trim(strip_tags((string)($leadData['at'] ?? date('c')))),
        'city'      => trim(strip_tags((string)($leadData['city'] ?? ''))),
        'format'    => trim(strip_tags((string)($leadData['format'] ?? ''))),
        'workshop'  => trim(strip_tags((string)($leadData['workshop'] ?? ''))),
        'tier'      => trim(strip_tags((string)($leadData['tier'] ?? ''))),
        'qty'       => (int)($leadData['qty'] ?? 1),
        'amount'    => (float)($leadData['amount'] ?? 0),
        'ref'       => trim(strip_tags((string)($leadData['ref'] ?? ''))),
        'payment_id'=> trim(strip_tags((string)($leadData['payment_id'] ?? ''))),
        'order_id'  => trim(strip_tags((string)($leadData['order_id'] ?? ''))),
        'method'    => trim(strip_tags((string)($leadData['method'] ?? ''))),
        'status'    => trim(strip_tags((string)($leadData['status'] ?? ''))),
        'sheet_id'  => $sheetId,
    ];

    $backupFile = $config['backup_file'] ?? (__DIR__ . '/leads-backup.log');

    // Deduplication check: avoid double posting identical booking ref or payment ID within 60 seconds
    $dedupKey = !empty($payload['ref']) ? $payload['ref'] : (!empty($payload['payment_id']) ? $payload['payment_id'] : '');
    if (!empty($dedupKey) && file_exists($backupFile)) {
        $recentLines = @file($backupFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!empty($recentLines)) {
            foreach (array_slice($recentLines, -10) as $line) {
                $prev = json_decode($line, true);
                $prevRef   = $prev['data']['ref'] ?? '';
                $prevPayId = $prev['data']['payment_id'] ?? '';
                $prevTime  = strtotime($prev['logged_at'] ?? '2000-01-01');
                if (($prevRef === $dedupKey || $prevPayId === $dedupKey) && (time() - $prevTime < 60)) {
                    return [
                        'success'      => true,
                        'sheet_synced' => true,
                        'message'      => 'Booking already recorded (deduplicated).',
                        'deduplicated' => true
                    ];
                }
            }
        }
    }

    // 1. Local backup logging (ensures zero data loss)
    if (!empty($config['local_backup'])) {
        $logEntry = [
            'logged_at' => date('Y-m-d H:i:s'),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'data'      => $payload
        ];
        @file_put_contents($backupFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    // 2. Check if sync is enabled
    if (!$enabled) {
        return [
            'success'      => true,
            'sheet_synced' => false,
            'reason'       => 'sync_disabled',
            'message'      => 'Google Sheet sync is currently disabled in sheet-config.php.'
        ];
    }

    // 3. Check if Google Apps Script URL has been configured
    if (empty($scriptUrl) || strpos($scriptUrl, 'your-web-app-url') !== false) {
        return [
            'success'      => true,
            'sheet_synced' => false,
            'reason'       => 'pending_deployment',
            'message'      => 'Lead saved to local backup. Deploy Google Apps Script and set apps_script_url in sheet-config.php to sync live.'
        ];
    }

    // 4. Send request to Google Apps Script Web App
    $jsonPayload = json_encode($payload);
    $httpCode = 0;
    $responseBody = '';
    $curlError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $scriptUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        if (defined('CURL_REDIR_POST_ALL')) {
            curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
    } else {
        // Fallback using stream_context
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($jsonPayload) . "\r\n",
                'content'       => $jsonPayload,
                'timeout'       => $timeout,
                'follow_location'=> 1,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer'   => true,
                'verify_peer_name' => true
            ]
        ]);
        $responseBody = @file_get_contents($scriptUrl, false, $context);
        if (isset($http_response_header) && preg_match('#HTTP/[0-9\.]+\s+([0-9]+)#', $http_response_header[0] ?? '', $matches)) {
            $httpCode = (int)$matches[1];
        }
    }

    // 5. Evaluate result
    $parsedResponse = json_decode((string)$responseBody, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300) || (!empty($parsedResponse['status']) && $parsedResponse['status'] === 'success');

    if ($isSuccess) {
        return [
            'success'      => true,
            'sheet_synced' => true,
            'http_code'    => $httpCode,
            'message'      => 'Lead recorded in Google Sheet successfully.',
            'sheet_info'   => $parsedResponse
        ];
    }

    // In case of error, log notice
    error_log("[GoogleSheets] Notice: Webhook responded with HTTP $httpCode. Error: $curlError. Response: $responseBody");
    return [
        'success'      => false,
        'sheet_synced' => false,
        'http_code'    => $httpCode,
        'error'        => !empty($curlError) ? $curlError : 'Google Sheets Webhook returned error.',
        'raw_response' => substr($responseBody, 0, 500)
    ];
}

// -----------------------------------------------------------------
// Handle direct HTTP POST requests to save-sheet.php
// -----------------------------------------------------------------
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json; charset=UTF-8');

    // Load config
    $configFile = __DIR__ . '/sheet-config.php';
    $config = file_exists($configFile) ? require($configFile) : [];

    // CORS Headers
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Only POST is accepted.']);
        exit;
    }

    // Parse input
    $rawInput = file_get_contents('php://input');
    $inputData = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $inputData = $decoded;
        }
    }
    if (empty($inputData) && !empty($_POST)) {
        $inputData = $_POST;
    }

    // Honeypot spam check
    if (!empty($inputData['website']) || !empty($inputData['hp']) || !empty($inputData['honeypot'])) {
        echo json_encode(['success' => true, 'message' => 'Submission received.']);
        exit;
    }

    // Validation
    $name = trim((string)($inputData['name'] ?? ''));
    $phone = trim((string)($inputData['phone'] ?? ''));
    $email = trim((string)($inputData['email'] ?? ''));

    if (strlen($name) < 2) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Name must be at least 2 characters.']);
        exit;
    }
    if (empty($phone) && empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide either a phone number or email.']);
        exit;
    }

    // Dispatch to Google Sheet
    $result = saveLeadToGoogleSheet($inputData, $config);
    echo json_encode($result);
    exit;
}
