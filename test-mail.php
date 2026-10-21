<?php
/**
 * Rhythmic Beat Dance Court - PHPMailer Health Check & Test Suite
 * 
 * Run in browser (http://localhost/.../test-mail.php)
 * OR run in terminal (php test-mail.php)
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=UTF-8');
}

$checks = [];
$checks['php_version'] = [
    'name'   => 'PHP Version (>= 7.4)',
    'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'info'   => PHP_VERSION
];

$checks['openssl'] = [
    'name'   => 'OpenSSL Extension (Required for TLS/SSL SMTP)',
    'status' => extension_loaded('openssl'),
    'info'   => extension_loaded('openssl') ? 'Enabled' : 'Missing (Enable in php.ini)'
];

$checks['config_file'] = [
    'name'   => 'mail-config.php file',
    'status' => file_exists(__DIR__ . '/mail-config.php'),
    'info'   => file_exists(__DIR__ . '/mail-config.php') ? 'Found' : 'Missing'
];

$config = file_exists(__DIR__ . '/mail-config.php') ? require(__DIR__ . '/mail-config.php') : [];

$checks['phpmailer_files'] = [
    'name'   => 'PHPMailer Library Files',
    'status' => (
        file_exists(__DIR__ . '/phpmailer/PHPMailer.php') &&
        file_exists(__DIR__ . '/phpmailer/SMTP.php') &&
        file_exists(__DIR__ . '/phpmailer/Exception.php')
    ),
    'info'   => 'phpmailer/ folder present'
];

$isPlaceholder = false;
if (!empty($config)) {
    $isPlaceholder = (
        strpos($config['smtp_user'] ?? '', 'your-email') !== false ||
        strpos($config['smtp_pass'] ?? '', 'your-app-password') !== false
    );
}

$checks['smtp_configured'] = [
    'name'   => 'SMTP Credentials Status',
    'status' => !$isPlaceholder && !empty($config['smtp_user']),
    'info'   => $isPlaceholder ? 'Placeholder credentials detected (Update in mail-config.php)' : 'Custom credentials present (' . htmlspecialchars($config['smtp_user'] ?? '') . ')'
];

$testResult = null;
$debugOutput = '';

$shouldSend = false;
if ($isCli) {
    $shouldSend = in_array('--send', $argv ?? []);
} else {
    $shouldSend = isset($_GET['send']) && $_GET['send'] === '1';
}

if ($shouldSend && $checks['phpmailer_files']['status']) {
    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = $config['smtp_auth'];
        $mail->Username   = trim($config['smtp_user']);
        $mail->Password   = str_replace(' ', '', trim($config['smtp_pass']));

        if (strtolower($config['smtp_secure'] ?? '') === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (strtolower($config['smtp_secure'] ?? '') === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->Port    = (int)($config['smtp_port'] ?? 587);
        $mail->CharSet = 'UTF-8';

        // Capture SMTP Debug info
        $mail->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION;
        $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
            $debugOutput .= "[$level] $str\n";
        };

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($config['admin_email'], $config['admin_name'] ?? 'Admin');

        $mail->isHTML(true);
        $mail->Subject = '[PHPMailer Test] Rhythmic Beat SMTP Connectivity Verified';
        $mail->Body    = '<h2>Rhythmic Beat Dance Court - SMTP Test Successful!</h2><p>This is a test notification confirming that PHPMailer and your SMTP server are communicating and dispatching emails correctly.</p><p><b>Timestamp:</b> ' . date('Y-m-d H:i:s') . '</p>';
        $mail->AltBody = "Rhythmic Beat Dance Court - SMTP Test Successful!\nTimestamp: " . date('Y-m-d H:i:s');

        $mail->send();
        $testResult = [
            'success' => true,
            'message' => 'Test email successfully dispatched to ' . $config['admin_email'] . '!'
        ];
    } catch (Exception $e) {
        $testResult = [
            'success' => false,
            'message' => 'Failed to send test email: ' . $e->getMessage()
        ];
    }
}

// CLI Output
if ($isCli) {
    echo "========================================================\n";
    echo "  Rhythmic Beat Dance Court - PHPMailer Diagnostics\n";
    echo "========================================================\n\n";

    foreach ($checks as $key => $check) {
        $badge = $check['status'] ? '[ PASS ]' : '[ WARN ]';
        echo sprintf("%-10s %-45s : %s\n", $badge, $check['name'], $check['info']);
    }

    echo "\n";
    if ($testResult) {
        if ($testResult['success']) {
            echo ">>> SUCCESS: " . $testResult['message'] . "\n";
        } else {
            echo ">>> ERROR: " . $testResult['message'] . "\n";
            if ($debugOutput) {
                echo "\n--- SMTP Debug Output ---\n" . $debugOutput . "\n";
            }
        }
    } else {
        echo "Tip: Run 'php test-mail.php --send' to send a live test email.\n";
    }
    echo "========================================================\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Rhythmic Beat - PHPMailer Diagnostics</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap">
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background: #F6F8FB;
      color: #0F1B2D;
      padding: 40px 16px;
      margin: 0;
    }
    .card {
      max-width: 640px;
      margin: 0 auto;
      background: #ffffff;
      border-radius: 20px;
      padding: 32px;
      box-shadow: 0 12px 36px rgba(15,27,45,0.08);
      border: 1px solid #E6EAF0;
    }
    h1 {
      margin: 0 0 4px 0;
      font-size: 24px;
      font-weight: 800;
      color: #0B4EA2;
    }
    h1 span { color: #F47B20; }
    p.sub {
      margin: 0 0 24px 0;
      color: #5D6A7E;
      font-size: 14px;
    }
    .check-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid #EEF4FC;
    }
    .check-row:last-child { border-bottom: none; }
    .badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 700;
    }
    .badge.pass { background: #E6F8EE; color: #17905A; }
    .badge.warn { background: #FFF4EA; color: #D9650E; }
    .btn {
      display: inline-block;
      background: #F47B20;
      color: #fff;
      font-weight: 700;
      text-decoration: none;
      padding: 12px 24px;
      border-radius: 12px;
      border: none;
      font-size: 14px;
      cursor: pointer;
      margin-top: 20px;
      box-shadow: 0 8px 18px rgba(244,123,32,0.4);
      transition: transform .15s;
    }
    .btn:hover { transform: translateY(-1px); }
    .alert {
      padding: 16px 20px;
      border-radius: 12px;
      margin-top: 20px;
      font-size: 14px;
      line-height: 1.5;
    }
    .alert.success { background: #E6F8EE; border: 1px solid #17905A; color: #0C613A; }
    .alert.error { background: #FDE8E8; border: 1px solid #C2410C; color: #9A2408; }
    pre {
      background: #0F1B2D;
      color: #EEF4FC;
      padding: 16px;
      border-radius: 10px;
      font-size: 12px;
      overflow-x: auto;
      margin-top: 12px;
    }
  </style>
</head>
<body>

<div class="card">
  <h1>RHYTHMIC <span>BEAT</span> &middot; Mailer Diagnostics</h1>
  <p class="sub">Verify your server environment, PHPMailer files, and SMTP configuration.</p>

  <div>
    <?php foreach ($checks as $c): ?>
      <div class="check-row">
        <div>
          <div style="font-weight:600;font-size:14px;"><?= htmlspecialchars($c['name']) ?></div>
          <div style="font-size:12px;color:#5D6A7E;"><?= htmlspecialchars($c['info']) ?></div>
        </div>
        <div>
          <span class="badge <?= $c['status'] ? 'pass' : 'warn' ?>">
            <?= $c['status'] ? 'READY' : 'ACTION NEEDED' ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['success'] ? 'success' : 'error' ?>">
      <b><?= $testResult['success'] ? 'Success!' : 'SMTP Connection Error' ?></b><br>
      <?= htmlspecialchars($testResult['message']) ?>
      <?php if (!empty($debugOutput)): ?>
        <details style="margin-top:10px;">
          <summary style="cursor:pointer;font-weight:600;">View SMTP Debug Log</summary>
          <pre><?= htmlspecialchars($debugOutput) ?></pre>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="margin-top: 24px; display:flex; gap: 12px; align-items:center;">
    <a href="test-mail.php?send=1" class="btn">Send Test Email</a>
    <a href="index.html" style="color:#0B4EA2;font-size:13px;font-weight:600;text-decoration:none;margin-top:18px;">&larr; Back to Website</a>
  </div>
</div>

</body>
</html>
