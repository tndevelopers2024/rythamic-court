<?php
/**
 * Rhythmic Beat Dance Court - Google Sheets Integration Diagnostic & Test Suite
 * 
 * Run in browser: http://localhost/Rhythmic-Beat/test-sheet.php
 * OR run in terminal: php test-sheet.php [--test]
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=UTF-8');
}

date_default_timezone_set('Asia/Kolkata');

// -------------------------------------------------------------
// System Checks
// -------------------------------------------------------------
$checks = [];

$checks['php_version'] = [
    'name'   => 'PHP Version (>= 7.4)',
    'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'info'   => PHP_VERSION
];

$checks['curl'] = [
    'name'   => 'cURL Extension (HTTP Webhook Dispatcher)',
    'status' => extension_loaded('curl'),
    'info'   => extension_loaded('curl') ? 'Enabled' : 'Missing (Enable in php.ini)'
];

$checks['openssl'] = [
    'name'   => 'OpenSSL Extension (Required for HTTPS to Google)',
    'status' => extension_loaded('openssl'),
    'info'   => extension_loaded('openssl') ? 'Enabled' : 'Missing (Enable in php.ini)'
];

$configFile = __DIR__ . '/sheet-config.php';
$hasConfig = file_exists($configFile);
$config = $hasConfig ? require($configFile) : [];

$checks['config_file'] = [
    'name'   => 'sheet-config.php Configuration File',
    'status' => $hasConfig,
    'info'   => $hasConfig ? 'Present' : 'Missing'
];

$sheetId = $config['sheet_id'] ?? '1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c';
$sheetUrl = $config['sheet_url'] ?? "https://docs.google.com/spreadsheets/d/{$sheetId}/edit?usp=sharing";
$scriptUrl = trim($config['apps_script_url'] ?? '');

$isUrlConfigured = !empty($scriptUrl) && strpos($scriptUrl, 'script.google.com') !== false;

$checks['sheet_id'] = [
    'name'   => 'Target Google Sheet ID',
    'status' => !empty($sheetId),
    'info'   => $sheetId
];

$checks['script_url'] = [
    'name'   => 'Google Apps Script Web App URL',
    'status' => $isUrlConfigured,
    'info'   => $isUrlConfigured ? htmlspecialchars($scriptUrl) : 'Pending Deployment (Follow setup instructions below)'
];

$backupFile = $config['backup_file'] ?? (__DIR__ . '/leads-backup.log');
$checks['backup_log'] = [
    'name'   => 'Local Safety Backup Log',
    'status' => true,
    'info'   => file_exists($backupFile) ? 'Active (' . count(file($backupFile)) . ' entries recorded)' : 'Ready (leads-backup.log)'
];

// -------------------------------------------------------------
// Handle Quick Save of Webhook URL from Web UI
// -------------------------------------------------------------
$saveNotice = '';
if (!$isCli && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_url'])) {
    $newUrl = trim($_POST['apps_script_url'] ?? '');
    if (!empty($newUrl)) {
        $configContent = file_get_contents($configFile);
        $pattern = "/'apps_script_url'\s*=>\s*getenv\('GOOGLE_SHEET_WEBHOOK_URL'\)\s*\?:\s*'.*?'/";
        $replacement = "'apps_script_url' => getenv('GOOGLE_SHEET_WEBHOOK_URL') ?: '" . addslashes($newUrl) . "'";
        $updated = preg_replace($pattern, $replacement, $configContent);
        if ($updated && file_put_contents($configFile, $updated)) {
            $saveNotice = 'Webhook URL saved successfully into sheet-config.php!';
            $config = require($configFile);
            $scriptUrl = trim($config['apps_script_url'] ?? '');
            $isUrlConfigured = !empty($scriptUrl) && strpos($scriptUrl, 'script.google.com') !== false;
            $checks['script_url']['status'] = $isUrlConfigured;
            $checks['script_url']['info'] = htmlspecialchars($scriptUrl);
        } else {
            $saveNotice = 'Error writing to sheet-config.php. Please paste URL manually into the file.';
        }
    }
}

// -------------------------------------------------------------
// Execute Live Test if requested
// -------------------------------------------------------------
$testOutput = null;
$action = $_GET['action'] ?? ($isCli && in_array('--booking', $argv ?? []) ? 'test_booking' : ($isCli && in_array('--test', $argv ?? []) ? 'test_enquiry' : ''));

if (!empty($action)) {
    require_once __DIR__ . '/save-sheet.php';

    if ($action === 'test_enquiry') {
        $sample = [
            'kind'     => 'lead',
            'name'     => 'Test Student (Enquiry)',
            'phone'    => '+91 96555 51055',
            'email'    => 'test.enquiry@rhythmicbeat.in',
            'courses'  => ['Western Dance', 'Zumba'],
            'mode'     => 'Offline',
            'location' => 'Valasaravakkam',
            'msg'      => 'Automated test enquiry from test-sheet.php diagnostic suite.',
            'page'     => '#courses',
            'at'       => date('c')
        ];
        $testOutput = saveLeadToGoogleSheet($sample, $config);
    } elseif ($action === 'test_contact') {
        $sample = [
            'kind'     => 'contact',
            'name'     => 'Test Visitor (Contact Form)',
            'phone'    => '+91 98400 12345',
            'email'    => 'test.contact@rhythmicbeat.in',
            'courses'  => ['Gymnastics'],
            'mode'     => 'Offline',
            'location' => 'Kolapakkam',
            'msg'      => 'Automated contact message test from test-sheet.php diagnostic suite.',
            'page'     => '#contact',
            'at'       => date('c')
        ];
        $testOutput = saveLeadToGoogleSheet($sample, $config);
    } elseif ($action === 'test_booking') {
        $sample = [
            'kind'       => 'booking',
            'name'       => 'Ananya Iyer (Workshop Booking)',
            'phone'      => '+91 97910 88888',
            'email'      => 'ananya.iyer@gmail.com',
            'workshop'   => 'Kuthu & Commercial Street Choreography by Master Yuvi',
            'tier'       => 'Full Workshop Pass',
            'qty'        => 2,
            'amount'     => 2400,
            'ref'        => 'RB-1021-TEST',
            'method'     => 'Razorpay (UPI)',
            'payment_id' => 'pay_test_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'order_id'   => 'order_test_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'status'     => 'Paid & Confirmed',
            'page'       => '#ws-yuvi-commercial',
            'at'         => date('c')
        ];
        $testOutput = saveLeadToGoogleSheet($sample, $config);
    } elseif ($action === 'ping') {
        if ($isUrlConfigured) {
            $ch = curl_init($scriptUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            $testOutput = [
                'ping_success' => ($code >= 200 && $code < 400),
                'http_code'    => $code,
                'curl_error'   => $err,
                'response'     => json_decode($resp, true) ?: substr($resp, 0, 500)
            ];
        } else {
            $testOutput = [
                'ping_success' => false,
                'error'        => 'Cannot ping: Web App URL is not yet configured.'
            ];
        }
    }
}

// -------------------------------------------------------------
// CLI Output
// -------------------------------------------------------------
if ($isCli) {
    echo "========================================================\n";
    echo "  Rhythmic Beat - Google Sheet Sync Health Check\n";
    echo "========================================================\n\n";

    foreach ($checks as $key => $check) {
        $icon = $check['status'] ? '  [OK]  ' : ' [FAIL] ';
        echo sprintf("%-8s %-32s : %s\n", $icon, $check['name'], $check['info']);
    }
    echo "\nTarget Sheet: $sheetUrl\n";

    if ($testOutput !== null) {
        echo "\n--- Test Execution Output ---\n";
        print_r($testOutput);
    } else {
        echo "\nTo send a live test entry via CLI, run:\n";
        echo "php test-sheet.php --test\n";
    }
    exit;
}

// -------------------------------------------------------------
// HTML Browser View
// -------------------------------------------------------------
$scriptCode = file_exists(__DIR__ . '/google-apps-script.js') 
    ? file_get_contents(__DIR__ . '/google-apps-script.js') 
    : '// google-apps-script.js file not found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Google Sheets Sync Diagnostics - Rhythmic Beat</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <style>
    :root {
      --blue: #0B4EA2;
      --blue-dark: #073570;
      --orange: #F47B20;
      --ink: #0F1B2D;
      --muted: #5D6A7E;
      --line: #E6EAF0;
      --bg: #F6F8FB;
      --card: #FFFFFF;
      --ok: #17905A;
      --ok-bg: #E8F5E9;
      --warn: #C2410C;
      --warn-bg: #FFF4EA;
      --fail: #D92D20;
      --fail-bg: #FEE4E2;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      line-height: 1.6;
      padding: 32px 16px 80px;
    }
    .wrap {
      max-width: 980px;
      margin: 0 auto;
    }
    .header {
      display: flex;
      align-items: center;
      gap: 16px;
      background: var(--card);
      padding: 24px 28px;
      border-radius: 16px;
      border: 1px solid var(--line);
      margin-bottom: 24px;
      box-shadow: 0 4px 18px rgba(15, 27, 45, 0.04);
    }
    .logo {
      width: 52px;
      height: 52px;
      background: var(--blue);
      border-radius: 12px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 26px;
    }
    .header h1 {
      font-size: 1.5rem;
      color: var(--blue);
      font-weight: 800;
      line-height: 1.2;
    }
    .header p {
      color: var(--muted);
      font-size: 0.88rem;
    }
    .badge {
      margin-left: auto;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .badge-ok { background: var(--ok-bg); color: var(--ok); }
    .badge-warn { background: var(--warn-bg); color: var(--warn); }
    
    .card {
      background: var(--card);
      border-radius: 16px;
      border: 1px solid var(--line);
      padding: 24px 28px;
      margin-bottom: 24px;
      box-shadow: 0 4px 18px rgba(15, 27, 45, 0.04);
    }
    .card h2 {
      font-size: 1.15rem;
      color: var(--ink);
      font-weight: 700;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .check-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid var(--line);
      font-size: 0.92rem;
    }
    .check-row:last-child { border-bottom: 0; }
    .check-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      font-size: 0.84rem;
      padding: 4px 10px;
      border-radius: 6px;
    }
    .status-ok { background: var(--ok-bg); color: var(--ok); }
    .status-fail { background: var(--fail-bg); color: var(--fail); }
    .status-warn { background: var(--warn-bg); color: var(--warn); }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 11px 20px;
      border-radius: 10px;
      font-family: inherit;
      font-size: 0.9rem;
      font-weight: 700;
      cursor: pointer;
      border: 0;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-o { background: var(--orange); color: #fff; }
    .btn-o:hover { background: #d9650e; }
    .btn-b { background: var(--blue); color: #fff; }
    .btn-b:hover { background: var(--blue-dark); }
    .btn-line { background: #fff; border: 1.5px solid var(--line); color: var(--ink); }
    .btn-line:hover { border-color: var(--blue); color: var(--blue); }
    .btn-sm { padding: 8px 14px; font-size: 0.82rem; border-radius: 8px; }

    .actions-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 18px;
    }

    .output-box {
      margin-top: 20px;
      background: #0f172a;
      color: #38bdf8;
      border-radius: 12px;
      padding: 16px 20px;
      font-family: 'Consolas', monospace;
      font-size: 0.82rem;
      overflow-x: auto;
      max-height: 380px;
    }

    .step-list {
      counter-reset: steps;
      list-style: none;
      display: grid;
      gap: 16px;
    }
    .step-list li {
      position: relative;
      padding-left: 44px;
      font-size: 0.92rem;
    }
    .step-list li::before {
      counter-increment: steps;
      content: counter(steps);
      position: absolute;
      left: 0;
      top: 0;
      width: 30px;
      height: 30px;
      background: var(--blue);
      color: #fff;
      font-weight: 700;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 0.84rem;
    }

    .code-box {
      position: relative;
      background: #1e293b;
      color: #f1f5f9;
      border-radius: 12px;
      padding: 20px;
      font-family: 'Consolas', monospace;
      font-size: 0.8rem;
      max-height: 280px;
      overflow-y: auto;
      margin-top: 12px;
      border: 1px solid #334155;
    }
    .copy-btn {
      position: absolute;
      top: 12px;
      right: 12px;
    }
    .input-row {
      display: flex;
      gap: 10px;
      margin-top: 12px;
    }
    .input-row input {
      flex: 1;
      padding: 10px 14px;
      border-radius: 8px;
      border: 1.5px solid var(--line);
      font-family: inherit;
      font-size: 0.88rem;
    }
    .input-row input:focus {
      outline: none;
      border-color: var(--blue);
    }
    .alert {
      padding: 12px 18px;
      border-radius: 10px;
      font-size: 0.88rem;
      font-weight: 600;
      margin-bottom: 16px;
    }
    .alert-success { background: var(--ok-bg); color: var(--ok); }
  </style>
</head>
<body>

<div class="wrap">
  <!-- Header -->
  <div class="header">
    <div class="logo">📊</div>
    <div>
      <h1>Google Sheets Integration Diagnostics</h1>
      <p>Target Sheet: <a href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" style="color:var(--blue);font-weight:600;text-decoration:none;"><?= htmlspecialchars($sheetId) ?> ↗</a></p>
    </div>
    <span class="badge <?= $isUrlConfigured ? 'badge-ok' : 'badge-warn' ?>">
      <?= $isUrlConfigured ? 'Live Sync Active' : 'Setup Required' ?>
    </span>
  </div>

  <?php if (!empty($saveNotice)): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($saveNotice) ?></div>
  <?php endif; ?>

  <!-- System Health -->
  <div class="card">
    <h2><span>⚙️</span> System & Environment Status</h2>
    <?php foreach ($checks as $check): ?>
      <div class="check-row">
        <div>
          <b><?= htmlspecialchars($check['name']) ?></b>
          <div style="font-size:0.8rem;color:var(--muted);"><?= $check['info'] ?></div>
        </div>
        <span class="check-status <?= $check['status'] ? 'status-ok' : 'status-fail' ?>">
          <?= $check['status'] ? '✓ Operational' : '✕ Action Needed' ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Interactive Tests -->
  <div class="card">
    <h2><span>🧪</span> Live Form Sync Testing</h2>
    <p style="font-size:0.9rem;color:var(--muted);margin-bottom:14px;">
      Click below to send a sample form submission into your Google Sheet to verify that columns, formatting, and row appending are functioning properly.
    </p>

    <div class="actions-grid">
      <a href="test-sheet.php?action=test_enquiry" class="btn btn-o">
        📝 Send Test Enquiry Form
      </a>
      <a href="test-sheet.php?action=test_contact" class="btn btn-b">
        💬 Send Test Contact Form
      </a>
      <a href="test-sheet.php?action=test_booking" class="btn btn-o" style="background:#17905A;">
        🎟️ Send Test Workshop Booking
      </a>
      <a href="test-sheet.php?action=ping" class="btn btn-line">
        ⚡ Ping Webhook Health Check
      </a>
      <a href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" class="btn btn-line">
        ↗ Open Google Sheet
      </a>
    </div>

    <?php if ($testOutput !== null): ?>
      <div class="output-box">
        <div style="color:#94a3b8;margin-bottom:8px;font-weight:700;">// Server Response from <?= htmlspecialchars($action) ?>:</div>
        <pre><?= htmlspecialchars(json_encode($testOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      </div>
      <p style="margin-top:12px;font-size:0.86rem;color:var(--ok);font-weight:600;">
        👉 <a href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" style="color:inherit;">Click here to view your Google Sheet and see the new row!</a>
      </p>
    <?php endif; ?>
  </div>

  <!-- Configure / Update Webhook URL -->
  <div class="card">
    <h2><span>🔗</span> Quick Webhook URL Configuration</h2>
    <p style="font-size:0.88rem;color:var(--muted);">
      Once you have deployed the Google Apps Script in your spreadsheet, paste your Web App URL below to update <code style="background:#eef4fc;padding:2px 6px;border-radius:4px;color:var(--blue);">sheet-config.php</code>:
    </p>
    <form method="POST" action="test-sheet.php">
      <div class="input-row">
        <input type="url" name="apps_script_url" placeholder="https://script.google.com/macros/s/AKfycb.../exec" value="<?= htmlspecialchars($scriptUrl) ?>" required>
        <button type="submit" name="save_url" value="1" class="btn btn-o btn-sm">Save URL</button>
      </div>
    </form>
  </div>

  <!-- Setup Instructions -->
  <div class="card">
    <h2><span>📋</span> 2-Minute Google Sheet Setup Guide</h2>
    <ol class="step-list">
      <li>
        <b>Open Your Google Sheet:</b><br>
        Navigate to <a href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" style="color:var(--blue);font-weight:600;">your Google Sheet</a>.
      </li>
      <li>
        <b>Open Apps Script Editor:</b><br>
        In the Google Sheets top menu, click <b>Extensions</b> &rarr; <b>Apps Script</b>.
      </li>
      <li>
        <b>Paste the Webhook Code:</b><br>
        Delete any default code in the editor, and paste the code from below:
        <div class="code-box">
          <button class="btn btn-sm btn-o copy-btn" id="copyCodeBtn" type="button">Copy Code</button>
          <pre id="scriptCodeBlock"><?= htmlspecialchars($scriptCode) ?></pre>
        </div>
      </li>
      <li>
        <b>Initialize Tabs (Optional):</b><br>
        In the toolbar function dropdown, select <code style="background:#334155;padding:2px 6px;border-radius:4px;">setupSheets</code> and click <b>Run</b>. This will instantly create and style the <b>Enquiries</b>, <b>Contact Messages</b>, and <b>All Submissions</b> tabs!
      </li>
      <li>
        <b>Deploy as Web App:</b><br>
        Click the blue <b>Deploy</b> button (top right) &rarr; <b>New deployment</b>.<br>
        - Click the gear icon next to "Select type" &rarr; select <b>Web app</b>.<br>
        - Description: <code>Rhythmic Beat Webhook</code><br>
        - Execute as: <b>Me</b> (your Google account)<br>
        - Who has access: <b>Anyone</b> <i>(Important: Must be "Anyone" so submissions from your website reach the sheet)</i>.<br>
        - Click <b>Deploy</b>, authorize permissions when prompted, and copy the Web App URL!
      </li>
      <li>
        <b>Paste into Website:</b><br>
        Paste your Web App URL into the form above or into <code>sheet-config.php</code>.
      </li>
    </ol>
  </div>
</div>

<script>
document.getElementById('copyCodeBtn').addEventListener('click', function() {
  var code = document.getElementById('scriptCodeBlock').innerText;
  navigator.clipboard.writeText(code).then(function() {
    var btn = document.getElementById('copyCodeBtn');
    btn.textContent = 'Copied! ✓';
    setTimeout(function() { btn.textContent = 'Copy Code'; }, 2000);
  });
});
</script>

</body>
</html>
