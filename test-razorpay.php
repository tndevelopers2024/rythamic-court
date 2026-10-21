<?php
/**
 * Rhythmic Beat Dance Court - Razorpay Diagnostics & Interactive Test Suite
 * 
 * Run in browser: http://localhost/Rhythmic-Beat/test-razorpay.php
 * OR run in CLI: php test-razorpay.php [--test]
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=UTF-8');
}

$checks = [];

// 1. PHP Version
$checks['php_version'] = [
    'name'   => 'PHP Version (>= 7.4)',
    'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'info'   => PHP_VERSION
];

// 2. cURL Extension
$checks['curl'] = [
    'name'   => 'cURL Extension (Required for Razorpay API calls)',
    'status' => extension_loaded('curl'),
    'info'   => extension_loaded('curl') ? 'Enabled' : 'Missing (Enable in php.ini)'
];

// 3. OpenSSL & Hash Extensions
$checks['crypto'] = [
    'name'   => 'OpenSSL & Hash Extensions (Required for HMAC-SHA256 verification)',
    'status' => extension_loaded('openssl') && extension_loaded('hash'),
    'info'   => (extension_loaded('openssl') && extension_loaded('hash')) ? 'Enabled' : 'Missing'
];

// 4. Config File
$configFile = __DIR__ . '/razorpay-config.php';
$checks['config_file'] = [
    'name'   => 'razorpay-config.php file',
    'status' => file_exists($configFile),
    'info'   => file_exists($configFile) ? 'Found' : 'Missing'
];

$config = file_exists($configFile) ? require($configFile) : [];
$keyId     = trim($config['key_id'] ?? '');
$keySecret = trim($config['key_secret'] ?? '');

$isPlaceholder = (
    empty($keyId) ||
    empty($keySecret) ||
    strpos($keyId, 'YOUR_') !== false ||
    strpos($keySecret, 'YOUR_') !== false
);

$keyType = 'Not configured';
if (!$isPlaceholder) {
    if (strpos($keyId, 'rzp_test_') === 0) {
        $keyType = 'Test Mode (rzp_test_...)';
    } elseif (strpos($keyId, 'rzp_live_') === 0) {
        $keyType = 'Live Production Mode (rzp_live_...)';
    } else {
        $keyType = 'Custom Key Format';
    }
}

// 5. Credentials Status
$maskedSecret = !empty($keySecret) && !$isPlaceholder
    ? substr($keySecret, 0, 4) . '••••••••' . substr($keySecret, -3)
    : 'Not set';

$checks['keys_configured'] = [
    'name'   => 'Razorpay API Keys Status',
    'status' => !$isPlaceholder,
    'info'   => $isPlaceholder
        ? 'Placeholder keys detected (Enter Key ID & Secret in razorpay-config.php)'
        : "Active: Key ID: " . htmlspecialchars($keyId) . " | Secret: $maskedSecret | ($keyType)"
];

// 6. PHPMailer Integration
$mailerConfigured = file_exists(__DIR__ . '/mail-config.php');
$checks['mailer'] = [
    'name'   => 'PHPMailer (Automated booking & ticket dispatch)',
    'status' => $mailerConfigured,
    'info'   => $mailerConfigured ? 'mail-config.php present (E-ticket emails enabled)' : 'mail-config.php missing'
];

// Run API Connection Test if requested
$apiTestResult = null;
$shouldTestApi = false;

if ($isCli) {
    $shouldTestApi = in_array('--test', $argv ?? []);
} else {
    $shouldTestApi = isset($_GET['test']) && $_GET['test'] === '1';
}

if ($shouldTestApi && !$isPlaceholder) {
    $testReceipt = 'TEST-' . time();
    $payload = [
        'amount'   => 100, // ₹1 (100 paise)
        'currency' => 'INR',
        'receipt'  => $testReceipt,
        'notes'    => [
            'purpose' => 'Rhythmic Beat Diagnostics Order Test'
        ]
    ];

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if (!empty($curlErr)) {
        $apiTestResult = [
            'success' => false,
            'message' => 'Network error connecting to Razorpay: ' . $curlErr
        ];
    } else {
        $json = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($json['id'])) {
            $apiTestResult = [
                'success'  => true,
                'message'  => 'Successfully connected to Razorpay API and created a test order!',
                'order_id' => $json['id'],
                'amount'   => $json['amount'],
                'currency' => $json['currency'],
                'status'   => $json['status']
            ];
        } else {
            $errDesc = $json['error']['description'] ?? 'Unexpected response from Razorpay.';
            $apiTestResult = [
                'success'   => false,
                'message'   => "Razorpay API error (HTTP $httpCode): $errDesc",
                'raw_error' => $json
            ];
        }
    }
}

// CLI Output
if ($isCli) {
    echo "====================================================\n";
    echo "  Rhythmic Beat - Razorpay Payment Diagnostic Suite\n";
    echo "====================================================\n\n";

    foreach ($checks as $c) {
        $icon = $c['status'] ? "[OK]" : "[FAIL]";
        echo sprintf("%-6s %-32s : %s\n", $icon, $c['name'], $c['info']);
    }

    echo "\n";
    if ($apiTestResult !== null) {
        if ($apiTestResult['success']) {
            echo "[SUCCESS] " . $apiTestResult['message'] . "\n";
            echo "          Order ID: " . $apiTestResult['order_id'] . "\n";
        } else {
            echo "[ERROR] " . $apiTestResult['message'] . "\n";
        }
    } else {
        echo "Tip: Run `php test-razorpay.php --test` to perform a live API connection test.\n";
    }
    echo "\n";
    exit(0);
}

// Browser HTML View
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Razorpay Diagnostics &middot; Rhythmic Beat Dance Court</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
  <style>
    :root {
      --blue: #0B4EA2;
      --blue-dark: #073570;
      --orange: #F47B20;
      --orange-dark: #D9650E;
      --ink: #0F1B2D;
      --muted: #5D6A7E;
      --line: #E6EAF0;
      --bg: #F6F8FB;
      --card-bg: #FFFFFF;
      --green: #17905A;
      --red: #D9383A;
      --font: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
      --mono: 'JetBrains Mono', monospace;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--ink);
      font-family: var(--font);
      line-height: 1.6;
      padding: 36px 16px 64px 16px;
    }
    .container {
      max-width: 820px;
      margin: 0 auto;
    }
    .header {
      background: var(--blue);
      color: #fff;
      padding: 32px 36px;
      border-radius: 20px 20px 0 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
      box-shadow: 0 10px 30px rgba(11, 78, 162, 0.15);
    }
    .header h1 {
      font-size: 1.55rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      text-transform: uppercase;
    }
    .header h1 span { color: var(--orange); }
    .header p {
      font-size: 0.86rem;
      color: #EEF4FC;
      margin-top: 4px;
    }
    .badge-mode {
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(8px);
      padding: 8px 16px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      border: 1px solid rgba(255, 255, 255, 0.25);
    }
    .body-card {
      background: var(--card-bg);
      padding: 36px;
      border-radius: 0 0 20px 20px;
      box-shadow: 0 12px 40px rgba(15, 27, 45, 0.08);
      border: 1px solid var(--line);
      border-top: none;
    }
    .section-title {
      font-size: 1.05rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--blue);
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .check-list {
      display: grid;
      gap: 12px;
      margin-bottom: 32px;
    }
    .check-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      background: var(--bg);
      border-radius: 12px;
      border: 1px solid var(--line);
      flex-wrap: wrap;
      gap: 12px;
    }
    .check-title {
      font-weight: 600;
      font-size: 0.94rem;
      color: var(--ink);
    }
    .check-info {
      font-size: 0.82rem;
      color: var(--muted);
      margin-top: 2px;
      font-family: var(--mono);
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .status-ok { background: #E7F6EE; color: var(--green); }
    .status-warn { background: #FFF4EA; color: var(--orange-dark); }
    .status-fail { background: #FDE8E8; color: var(--red); }
    
    .actions-box {
      background: #EEF4FC;
      border-radius: 16px;
      padding: 24px;
      margin-bottom: 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
      border: 1px solid #D7E5F8;
    }
    .actions-box h3 {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--blue);
    }
    .actions-box p {
      font-size: 0.85rem;
      color: var(--muted);
      margin-top: 4px;
    }
    .btn-group {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 20px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 0.88rem;
      cursor: pointer;
      text-decoration: none;
      border: none;
      transition: all 0.15s ease;
      font-family: var(--font);
    }
    .btn:hover { transform: translateY(-1px); }
    .btn-primary {
      background: var(--blue);
      color: #fff;
      box-shadow: 0 4px 14px rgba(11, 78, 162, 0.3);
    }
    .btn-primary:hover { background: var(--blue-dark); }
    .btn-orange {
      background: var(--orange);
      color: #fff;
      box-shadow: 0 4px 14px rgba(244, 123, 32, 0.35);
    }
    .btn-orange:hover { background: var(--orange-dark); }
    .btn-outline {
      background: #fff;
      color: var(--ink);
      border: 1.5px solid var(--line);
    }
    .btn-outline:hover {
      border-color: var(--blue);
      color: var(--blue);
    }

    .alert {
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 28px;
      font-size: 0.92rem;
    }
    .alert-success {
      background: #E7F6EE;
      color: #0E6B40;
      border: 1px solid #B8E7D1;
    }
    .alert-error {
      background: #FDE8E8;
      color: #A31C1E;
      border: 1px solid #F8B4B4;
    }
    .alert-warning {
      background: #FFF4EA;
      color: #B45309;
      border: 1px solid #FCD34D;
    }

    .guide {
      background: #FAFBFD;
      border-radius: 14px;
      padding: 22px;
      border: 1px solid var(--line);
      font-size: 0.88rem;
    }
    .guide h4 {
      font-size: 0.96rem;
      font-weight: 700;
      color: var(--ink);
      margin-bottom: 8px;
    }
    .guide ol {
      margin-left: 20px;
      color: var(--muted);
      line-height: 1.75;
    }
    .code-inline {
      background: #EAEFF6;
      color: var(--blue);
      font-family: var(--mono);
      padding: 2px 6px;
      border-radius: 5px;
      font-size: 0.82rem;
    }
  </style>
</head>
<body>

<div class="container">
  <div class="header">
    <div>
      <h1>RHYTHMIC <span>BEAT</span> &middot; RAZORPAY</h1>
      <p>Payment Gateway Setup &amp; Diagnostics Suite</p>
    </div>
    <div class="badge-mode">
      <?= htmlspecialchars($keyType) ?>
    </div>
  </div>

  <div class="body-card">

    <?php if ($apiTestResult !== null): ?>
      <?php if ($apiTestResult['success']): ?>
        <div class="alert alert-success">
          <strong>✓ Razorpay API Connection Succeeded!</strong><br>
          <?= htmlspecialchars($apiTestResult['message']) ?><br>
          <span style="font-size:0.82rem;font-family:var(--mono);">
            Test Order ID: <b><?= htmlspecialchars($apiTestResult['order_id']) ?></b> &middot;
            Amount: ₹<?= ($apiTestResult['amount'] / 100) ?> &middot;
            Status: <?= htmlspecialchars($apiTestResult['status']) ?>
          </span>
        </div>
      <?php else: ?>
        <div class="alert alert-error">
          <strong>✗ Razorpay API Connection Failed:</strong><br>
          <?= htmlspecialchars($apiTestResult['message']) ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($isPlaceholder): ?>
      <div class="alert alert-warning">
        <strong>⚠️ Razorpay API Keys Required:</strong><br>
        Your credentials in <span class="code-inline">razorpay-config.php</span> are still placeholders. Provide your Razorpay Key ID and Key Secret to activate live payments.
      </div>
    <?php endif; ?>

    <div class="section-title">
      <span>1. Server Environment &amp; Requirements</span>
    </div>

    <div class="check-list">
      <?php foreach ($checks as $k => $c): ?>
        <div class="check-item">
          <div>
            <div class="check-title"><?= htmlspecialchars($c['name']) ?></div>
            <div class="check-info"><?= htmlspecialchars($c['info']) ?></div>
          </div>
          <div>
            <?php if ($c['status']): ?>
              <span class="status-pill status-ok">✓ Ready</span>
            <?php else: ?>
              <span class="status-pill status-fail">✗ Needs Attention</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="section-title">
      <span>2. Interactive Test Actions</span>
    </div>

    <div class="actions-box">
      <div>
        <h3>Test Razorpay API Connectivity</h3>
        <p>Creates a ₹1 test order with your configured keys to verify API communication.</p>
      </div>
      <div class="btn-group">
        <a href="test-razorpay.php?test=1" class="btn btn-primary">
          Test API Connection
        </a>
        <?php if (!$isPlaceholder): ?>
          <button id="testCheckoutBtn" class="btn btn-orange">
            Launch Test Checkout (₹1)
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="section-title">
      <span>3. Razorpay Key Configuration</span>
    </div>

    <div class="actions-box" style="background:#FFF9F2;border-color:#FCD34D;">
      <div style="width:100%;">
        <h3 style="color:#B45309;">Enter Razorpay API Keys</h3>
        <p style="margin-bottom:12px;">Save your Key ID and Key Secret here to immediately activate checkout:</p>
        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;">
          <div>
            <label style="font-size:0.75rem;font-weight:700;display:block;margin-bottom:4px;">KEY ID (e.g. rzp_test_...)</label>
            <input id="cfgKeyId" placeholder="rzp_test_..." value="<?= $isPlaceholder ? '' : htmlspecialchars($keyId) ?>" style="width:100%;padding:10px 12px;border-radius:8px;border:1.5px solid var(--line);font-family:var(--mono);font-size:0.85rem;">
          </div>
          <div>
            <label style="font-size:0.75rem;font-weight:700;display:block;margin-bottom:4px;">KEY SECRET</label>
            <input id="cfgKeySec" type="password" placeholder="Key Secret" style="width:100%;padding:10px 12px;border-radius:8px;border:1.5px solid var(--line);font-family:var(--mono);font-size:0.85rem;">
          </div>
          <div>
            <button id="saveKeysBtn" class="btn btn-orange" style="height:44px;">Save Keys</button>
          </div>
        </div>
        <div id="saveKeysMsg" style="margin-top:8px;font-size:0.82rem;font-weight:600;"></div>
      </div>
    </div>

    <div class="section-title">
      <span>4. How to Get Your Keys</span>
    </div>

    <div class="guide">
      <h4>Getting your Razorpay API Credentials:</h4>
      <ol>
        <li>Log in to your <b>Razorpay Dashboard</b> at <a href="https://dashboard.razorpay.com" target="_blank" style="color:var(--blue);font-weight:600;">dashboard.razorpay.com</a>.</li>
        <li>Switch between <b>Test Mode</b> (for testing) or <b>Live Mode</b> (for accepting real payments).</li>
        <li>Go to <b>Account &amp; Settings</b> &rarr; <b>API Keys</b> &rarr; <b>Generate Key</b>.</li>
        <li>Copy your Key ID &amp; Secret and paste them above, or into <span class="code-inline">razorpay-config.php</span>.</li>
      </ol>
    </div>

    <div style="margin-top:28px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
      <a href="index.html#workshops" class="btn btn-outline">&larr; Back to Website Workshops</a>
      <a href="test-mail.php" class="btn btn-outline">Check Mailer Diagnostics &rarr;</a>
    </div>

  </div>
</div>

<script>
document.getElementById('saveKeysBtn')?.addEventListener('click', async function() {
  const kid = document.getElementById('cfgKeyId').value.trim();
  const ksec = document.getElementById('cfgKeySec').value.trim();
  const msg = document.getElementById('saveKeysMsg');
  if(!kid || !ksec) {
    alert('Please enter both Key ID and Key Secret.');
    return;
  }
  this.disabled = true;
  this.textContent = 'Saving…';
  try {
    const res = await fetch('razorpay-save-keys.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key_id: kid, key_secret: ksec })
    });
    const data = await res.json();
    if(data.success) {
      msg.style.color = 'var(--green)';
      msg.textContent = '✓ ' + data.message + ' Reloading diagnostics…';
      setTimeout(() => location.href = 'test-razorpay.php?test=1', 700);
    } else {
      this.disabled = false;
      this.textContent = 'Save Keys';
      msg.style.color = 'var(--red)';
      msg.textContent = '✗ ' + (data.error || 'Failed to save');
    }
  } catch(e) {
    this.disabled = false;
    this.textContent = 'Save Keys';
    msg.style.color = 'var(--red)';
    msg.textContent = '✗ Error: ' + e.message;
  }
});

document.getElementById('testCheckoutBtn')?.addEventListener('click', async function() {
  const btn = this;
  btn.disabled = true;
  btn.textContent = 'Creating Order…';

  try {
    const res = await fetch('razorpay-create-order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        amount: 1, // ₹1
        receipt: 'TEST-DIAG-' + Date.now(),
        name: 'Test Customer',
        email: 'test@example.com',
        phone: '9876543210',
        notes: { test: 'Diagnostics Checkout' }
      })
    });

    const data = await res.json();
    if (!data.success) {
      alert('Order Creation Error: ' + (data.error || 'Failed'));
      btn.disabled = false;
      btn.textContent = 'Launch Test Checkout (₹1)';
      return;
    }

    const options = {
      key: data.key_id,
      amount: data.amount,
      currency: data.currency,
      name: data.company_name,
      description: 'Diagnostics ₹1 Test Payment',
      image: data.company_logo,
      order_id: data.order_id,
      prefill: {
        name: 'Rhythmic Beat Tester',
        email: 'tester@rhythmicbeat.in',
        contact: '9655551055'
      },
      theme: { color: data.theme_color || '#0B4EA2' },
      handler: function(response) {
        alert('Payment completed successfully in popup!\nPayment ID: ' + response.razorpay_payment_id + '\nOrder ID: ' + response.razorpay_order_id);
      },
      modal: {
        ondismiss: function() {
          btn.disabled = false;
          btn.textContent = 'Launch Test Checkout (₹1)';
        }
      }
    };

    const rzp = new Razorpay(options);
    rzp.open();
  } catch (err) {
    alert('Error initializing Razorpay: ' + err.message);
    btn.disabled = false;
    btn.textContent = 'Launch Test Checkout (₹1)';
  }
});
</script>

</body>
</html>
