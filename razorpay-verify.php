<?php
/**
 * Rhythmic Beat Dance Court - Razorpay Payment Verifier & Confirmation Dispatcher
 * 
 * Verifies the cryptographic HMAC SHA256 signature returned by Razorpay Checkout.
 * Once verified, dispatches booking emails to studio admin & customer.
 */

// Enable error reporting for logs, output clean JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=UTF-8');

// Load Razorpay Configuration
$rzpConfigFile = __DIR__ . '/razorpay-config.php';
if (!file_exists($rzpConfigFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Configuration file razorpay-config.php not found.'
    ]);
    exit;
}

$rzpConfig = require $rzpConfigFile;

// Handle CORS
$corsOrigin = $rzpConfig['cors_origin'] ?? '*';
header("Access-Control-Allow-Origin: $corsOrigin");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit;
}

// Parse Input
$rawInput = file_get_contents('php://input');
$data = [];

if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

$orderId   = trim($data['razorpay_order_id'] ?? '');
$paymentId = trim($data['razorpay_payment_id'] ?? '');
$signature = trim($data['razorpay_signature'] ?? '');
$booking   = is_array($data['booking'] ?? null) ? $data['booking'] : [];

if (empty($orderId) || empty($paymentId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing order_id or payment_id in request.'
    ]);
    exit;
}

$keySecret = trim($rzpConfig['key_secret'] ?? '');
$isDemo    = strpos($orderId, 'order_demo_') === 0;

// Verify signature
if ($isDemo) {
    if (empty($rzpConfig['allow_demo'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Demo payments are disabled.'
        ]);
        exit;
    }
    // Demo mode: signature check simulated
    $verified = true;
} else {
    if (empty($signature)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Missing Razorpay signature.'
        ]);
        exit;
    }

    $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret);
    $verified = hash_equals($expectedSignature, $signature);

    if (!$verified) {
        http_response_code(400);
        error_log("[Razorpay] Signature mismatch for Order ID $orderId and Payment ID $paymentId");
        echo json_encode([
            'success' => false,
            'error'   => 'Payment signature verification failed. The transaction could not be verified.'
        ]);
        exit;
    }
}

// -------------------------------------------------------------
// Payment Verified! Prepare Booking Data
// -------------------------------------------------------------
$name      = trim(strip_tags($booking['name'] ?? ''));
$phone     = trim(strip_tags($booking['phone'] ?? ''));
$email     = trim(filter_var($booking['email'] ?? '', FILTER_SANITIZE_EMAIL));
$workshop  = trim(strip_tags($booking['workshop'] ?? ''));
$tier      = trim(strip_tags($booking['tier'] ?? ''));
$qty       = (int)($booking['qty'] ?? 1);
$amount    = (float)($booking['amount'] ?? 0);
$ref       = trim(strip_tags($booking['ref'] ?? ('RB-' . date('md') . '-' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4))));
$at        = date('c');
$method    = trim(strip_tags($booking['method'] ?? 'Razorpay'));
$status    = $isDemo ? 'Paid (Demo Verified)' : 'Paid & Confirmed';

// -------------------------------------------------------------
// Dispatch Confirmation Emails via PHPMailer (if configured)
// -------------------------------------------------------------
$mailSent = false;
$mailConfigFile = __DIR__ . '/mail-config.php';
$mailerFilesOk  = (
    file_exists(__DIR__ . '/phpmailer/Exception.php') &&
    file_exists(__DIR__ . '/phpmailer/PHPMailer.php') &&
    file_exists(__DIR__ . '/phpmailer/SMTP.php')
);

if (file_exists($mailConfigFile) && $mailerFilesOk) {
    $mailConfig = require $mailConfigFile;

    // Check if mail credentials are not placeholder
    $isMailPlaceholder = (
        strpos($mailConfig['smtp_user'] ?? '', 'your-email') !== false ||
        strpos($mailConfig['smtp_pass'] ?? '', 'your-app-password') !== false
    );

    if (!$isMailPlaceholder) {
        require_once __DIR__ . '/phpmailer/Exception.php';
        require_once __DIR__ . '/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/SMTP.php';

        $createMailer = function($cfg) {
            $m = new PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host       = $cfg['smtp_host'];
            $m->SMTPAuth   = $cfg['smtp_auth'];
            $m->Username   = trim($cfg['smtp_user']);
            $m->Password   = str_replace(' ', '', trim($cfg['smtp_pass']));
            if (strtolower($cfg['smtp_secure'] ?? '') === 'ssl') {
                $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif (strtolower($cfg['smtp_secure'] ?? '') === 'tls') {
                $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $m->SMTPSecure = false;
                $m->SMTPAutoTLS = false;
            }
            $m->Port    = (int)($cfg['smtp_port'] ?? 587);
            $m->CharSet = 'UTF-8';
            $m->setFrom($cfg['from_email'], $cfg['from_name']);
            return $m;
        };

        try {
            // 1. Send Admin Notification Email
            $adminMail = $createMailer($mailConfig);
            $adminMail->addAddress($mailConfig['admin_email'], $mailConfig['admin_name'] ?? 'Admin');
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $adminMail->addReplyTo($email, $name);
            }
            if (!empty($mailConfig['cc_email'])) {
                $adminMail->addCC($mailConfig['cc_email']);
            }

            $adminMail->isHTML(true);
            $adminMail->Subject = "[RB Paid] $ref - $workshop ($name)";

            $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
            $amountFormatted = '₹' . number_format($amount, 2);

            $adminMail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px 10px;background-color:#F6F8FB;font-family:'Poppins',-apple-system,BlinkMacSystemFont,sans-serif;color:#0F1B2D;line-height:1.6;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,27,45,0.08);border:1px solid #E6EAF0;">
    <tr>
      <td style="background:#0B4EA2;padding:24px 28px;border-bottom:4px solid #F47B20;">
        <table width="100%">
          <tr>
            <td>
              <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-0.02em;text-transform:uppercase;">
                RHYTHMIC <span style="color:#F47B20;">BEAT</span>
              </h1>
              <p style="margin:4px 0 0 0;color:#EEF4FC;font-size:12px;letter-spacing:0.04em;">DANCE COURT &middot; CHENNAI</p>
            </td>
            <td style="text-align:right;">
              <span style="background:#17905A;color:#ffffff;font-size:12px;font-weight:700;padding:6px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:0.04em;">
                PAID VIA RAZORPAY
              </span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 28px 12px 28px;">
        <h2 style="margin:0 0 8px 0;font-size:19px;color:#0B4EA2;">Verified Workshop Booking</h2>
        <p style="margin:0;color:#5D6A7E;font-size:14px;">Customer has successfully completed payment via Razorpay.</p>
      </td>
    </tr>
    <tr>
      <td style="padding:12px 28px;">
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #E6EAF0;border-radius:12px;overflow:hidden;">
          <tr style="background:#F6F8FB;"><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;width:36%;font-size:13px;border-bottom:1px solid #E6EAF0;">Booking Reference</td><td style="padding:10px 14px;font-weight:700;color:#0B4EA2;font-size:14px;border-bottom:1px solid #E6EAF0;">{$ref}</td></tr>
          <tr><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">Customer Name</td><td style="padding:10px 14px;font-weight:600;color:#0F1B2D;font-size:14px;border-bottom:1px solid #E6EAF0;">{$name}</td></tr>
          <tr style="background:#F6F8FB;"><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">Mobile Phone</td><td style="padding:10px 14px;color:#0F1B2D;font-size:14px;border-bottom:1px solid #E6EAF0;"><a href="tel:{$cleanPhone}" style="color:#0B4EA2;font-weight:600;text-decoration:none;">{$phone}</a></td></tr>
          <tr><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">Email Address</td><td style="padding:10px 14px;color:#0F1B2D;font-size:14px;border-bottom:1px solid #E6EAF0;">{$email}</td></tr>
          <tr style="background:#F6F8FB;"><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">Workshop</td><td style="padding:10px 14px;font-weight:600;color:#0F1B2D;font-size:14px;border-bottom:1px solid #E6EAF0;">{$workshop}</td></tr>
          <tr><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">Pass / Quantity</td><td style="padding:10px 14px;color:#0F1B2D;font-size:14px;border-bottom:1px solid #E6EAF0;">{$tier} &times; {$qty}</td></tr>
          <tr style="background:#FFF4EA;"><td style="padding:10px 14px;font-weight:700;color:#D9650E;font-size:14px;border-bottom:1px solid #E6EAF0;">Amount Paid</td><td style="padding:10px 14px;font-weight:800;color:#D9650E;font-size:16px;border-bottom:1px solid #E6EAF0;">{$amountFormatted}</td></tr>
          <tr><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">Razorpay Payment ID</td><td style="padding:10px 14px;font-family:monospace;font-weight:700;color:#0B4EA2;font-size:13px;border-bottom:1px solid #E6EAF0;">{$paymentId}</td></tr>
          <tr style="background:#F6F8FB;"><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">Razorpay Order ID</td><td style="padding:10px 14px;font-family:monospace;color:#5D6A7E;font-size:13px;border-bottom:1px solid #E6EAF0;">{$orderId}</td></tr>
          <tr><td style="padding:10px 14px;font-weight:600;color:#5D6A7E;font-size:13px;">Payment Status</td><td style="padding:10px 14px;color:#17905A;font-weight:700;font-size:14px;">{$status}</td></tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 28px 24px 28px;">
        <a href="tel:{$cleanPhone}" style="display:inline-block;background:#0B4EA2;color:#ffffff;text-decoration:none;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:700;margin-right:8px;">
          Call Attendee ({$phone})
        </a>
        <a href="mailto:{$email}?subject=Your%20Rhythmic%20Beat%20Workshop%20Ticket%20[{$ref}]" style="display:inline-block;background:#F47B20;color:#ffffff;text-decoration:none;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:700;">
          Reply Email
        </a>
      </td>
    </tr>
    <tr>
      <td style="background:#F6F8FB;padding:16px 28px;border-top:1px solid #E6EAF0;text-align:center;">
        <p style="margin:0;font-size:12px;color:#5D6A7E;">Rhythmic Beat Dance Court &middot; 116A, Chowdry Nagar Main Rd, Valasaravakkam, Chennai 600087</p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
            $adminMail->AltBody = "RHYTHMIC BEAT - WORKSHOP PAYMENT CONFIRMED\nRef: $ref\nName: $name\nPhone: $phone\nWorkshop: $workshop\nAmount: $amountFormatted\nPayment ID: $paymentId\nStatus: $status";
            $adminMail->send();

            // 2. Send Customer E-Ticket Confirmation Email
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $userMail = $createMailer($mailConfig);
                $userMail->addAddress($email, $name);
                $userMail->isHTML(true);
                $userMail->Subject = "Booking & Payment Confirmed: $workshop [Ref: $ref]";

                $userMail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px 10px;background-color:#F6F8FB;font-family:'Poppins',-apple-system,BlinkMacSystemFont,sans-serif;color:#0F1B2D;line-height:1.6;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,27,45,0.08);border:1px solid #E6EAF0;">
    <tr>
      <td style="background:#0B4EA2;padding:26px 28px;text-align:center;">
        <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:800;letter-spacing:-0.02em;text-transform:uppercase;">
          RHYTHMIC <span style="color:#F47B20;">BEAT</span>
        </h1>
        <p style="margin:4px 0 0 0;color:#EEF4FC;font-size:12px;letter-spacing:0.04em;">DANCE COURT &middot; CHENNAI</p>
      </td>
    </tr>
    <tr>
      <td style="padding:32px 28px;">
        <div style="display:inline-block;background:#E7F6EE;color:#17905A;font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;margin-bottom:12px;">
          ✓ PAYMENT RECEIVED &amp; SLOT CONFIRMED
        </div>
        <h2 style="margin:0 0 12px 0;font-size:22px;color:#0B4EA2;font-weight:700;">
          Hi {$name}, your slot is booked!
        </h2>
        <p style="margin:0 0 18px 0;font-size:15px;color:#0F1B2D;">
          We have received your payment of <b>{$amountFormatted}</b> for <b>{$workshop}</b>. We are excited to dance with you!
        </p>

        <!-- Ticket Card -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#F6F8FB;border-radius:14px;border:1.5px dashed #CBD3DE;margin-bottom:24px;padding:18px 20px;">
          <tr>
            <td>
              <div style="font-size:11px;font-weight:700;color:#5D6A7E;text-transform:uppercase;letter-spacing:0.08em;">Booking Reference ID</div>
              <div style="font-size:20px;font-weight:800;color:#0B4EA2;letter-spacing:0.04em;margin:2px 0 14px 0;font-family:monospace;">{$ref}</div>
              <div style="font-size:13px;color:#0F1B2D;margin-bottom:6px;"><b>Workshop:</b> {$workshop}</div>
              <div style="font-size:13px;color:#0F1B2D;margin-bottom:6px;"><b>Ticket Tier:</b> {$tier} &times; {$qty} participant(s)</div>
              <div style="font-size:13px;color:#0F1B2D;margin-bottom:6px;"><b>Razorpay Payment ID:</b> <span style="font-family:monospace;color:#5D6A7E;">{$paymentId}</span></div>
              <div style="font-size:14px;font-weight:700;color:#D9650E;margin-top:8px;border-top:1px solid #E6EAF0;padding-top:8px;">Total Paid: {$amountFormatted}</div>
            </td>
          </tr>
        </table>

        <!-- Studio Venue & Instructions -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#EEF4FC;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
          <tr>
            <td>
              <p style="margin:0 0 6px 0;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#0B4EA2;font-weight:700;">Venue &amp; Entry Information</p>
              <p style="margin:0 0 4px 0;font-size:13px;color:#0F1B2D;"><b>Location:</b> Rhythmic Beat Dance Court, 116A, Chowdry Nagar Main Rd, Valasaravakkam, Chennai</p>
              <p style="margin:0 0 4px 0;font-size:13px;color:#0F1B2D;"><b>Entry:</b> Please show this email or reference <b>{$ref}</b> at the reception desk.</p>
              <p style="margin:0;font-size:13px;color:#0F1B2D;"><b>Support / Hotline:</b> +91 96555 51055</p>
            </td>
          </tr>
        </table>

        <p style="margin:0;font-size:13px;color:#5D6A7E;">
          See you on the dance floor!<br>
          <b style="color:#0F1B2D;">Team Rhythmic Beat Dance Court</b>
        </p>
      </td>
    </tr>
    <tr>
      <td style="background:#F6F8FB;padding:14px 28px;border-top:1px solid #E6EAF0;text-align:center;">
        <p style="margin:0;font-size:11px;color:#5D6A7E;">This is an automated booking confirmation receipt from Rhythmic Beat Dance Court.</p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
                $userMail->AltBody = "Hi $name,\n\nYour workshop booking is confirmed!\nRef: $ref\nWorkshop: $workshop\nAmount Paid: $amountFormatted\nPayment ID: $paymentId\n\nShow this ref at reception.\nHotline: +91 96555 51055";
                $userMail->send();
            }

            $mailSent = true;
        } catch (Exception $eMail) {
            error_log('[Razorpay/PHPMailer] Notice: Could not send email notification: ' . $eMail->getMessage());
        }
    }
}

// -------------------------------------------------------------
// Save Booking to Google Sheet (and safety backup)
// -------------------------------------------------------------
$sheetSynced = false;
$sheetInfo   = '';
if (file_exists(__DIR__ . '/save-sheet.php')) {
    require_once __DIR__ . '/save-sheet.php';
    $sheetPayload = [
        'kind'       => 'booking',
        'name'       => $name,
        'phone'      => $phone,
        'email'      => $email,
        'workshop'   => $workshop,
        'tier'       => $tier,
        'qty'        => $qty,
        'amount'     => $amount,
        'ref'        => $ref,
        'method'     => $method,
        'payment_id' => $paymentId,
        'order_id'   => $orderId,
        'status'     => $status,
        'page'       => '#workshops',
        'at'         => $at
    ];
    $sheetResult = saveLeadToGoogleSheet($sheetPayload);
    $sheetSynced = $sheetResult['sheet_synced'] ?? false;
    $sheetInfo   = $sheetResult['message'] ?? '';
}

echo json_encode([
    'success'      => true,
    'verified'     => true,
    'is_demo'      => $isDemo,
    'payment_id'   => $paymentId,
    'order_id'     => $orderId,
    'ref'          => $ref,
    'status'       => $status,
    'mail_sent'    => $mailSent,
    'sheet_synced' => $sheetSynced,
    'sheet_info'   => $sheetInfo,
    'message'      => 'Payment verified successfully and workshop slot is confirmed.'
]);
