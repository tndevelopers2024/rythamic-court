<?php
/**
 * Rhythmic Beat Dance Court - Mail Dispatcher
 * 
 * Handles contact forms, lead submissions, franchise requests,
 * and workshop bookings via PHPMailer.
 */

// Enable error reporting for logs, but output clean JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Kolkata');

// Set JSON headers
header('Content-Type: application/json; charset=UTF-8');

// Load Configuration
$configFile = __DIR__ . '/mail-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Configuration file mail-config.php not found.'
    ]);
    exit;
}

$config = require $configFile;

// Handle CORS
$corsOrigin = $config['cors_origin'] ?? '*';
header("Access-Control-Allow-Origin: $corsOrigin");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit;
}

// Include PHPMailer library files
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Parse Input (JSON or standard form POST)
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

// Anti-Spam Honeypot check
if (!empty($data['website']) || !empty($data['hp']) || !empty($data['honeypot'])) {
    // Silently return success to fool spam bots
    echo json_encode([
        'success' => true,
        'message' => 'Your submission has been received.'
    ]);
    exit;
}

// Sanitize & Extract Fields
$kind     = trim(strip_tags($data['kind'] ?? 'lead'));
$name     = trim(strip_tags($data['name'] ?? ''));
$phone    = trim(strip_tags($data['phone'] ?? ''));
$email    = trim(filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL));
$page     = trim(strip_tags($data['page'] ?? ''));
$at       = trim(strip_tags($data['at'] ?? date('c')));

// Lead / Course Inquiry Fields
$courses  = $data['courses'] ?? [];
if (is_array($courses)) {
    $coursesList = implode(', ', array_map('strip_tags', $courses));
} else {
    $coursesList = trim(strip_tags((string)$courses));
}
$mode     = trim(strip_tags($data['mode'] ?? ''));
$location = trim(strip_tags($data['location'] ?? ''));
$msg      = trim(strip_tags($data['msg'] ?? ''));

// Franchise Fields
$city     = trim(strip_tags($data['city'] ?? ''));
$format   = trim(strip_tags($data['format'] ?? ''));

// Workshop Booking Fields
$workshop = trim(strip_tags($data['workshop'] ?? ''));
$tier     = trim(strip_tags($data['tier'] ?? ''));
$qty      = (int)($data['qty'] ?? 1);
$amount   = (float)($data['amount'] ?? 0);
$ref      = trim(strip_tags($data['ref'] ?? ''));
$method   = trim(strip_tags($data['method'] ?? ''));
$status   = trim(strip_tags($data['status'] ?? ''));
$payment_id = trim(strip_tags($data['payment_id'] ?? ''));
$order_id   = trim(strip_tags($data['order_id'] ?? ''));

// Basic Validation
if (strlen($name) < 2) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Name must be at least 2 characters.'
    ]);
    exit;
}

if (empty($phone) && empty($email)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Please provide either a mobile number or an email address.'
    ]);
    exit;
}

// Save lead to Google Sheets (and local safety backup)
require_once __DIR__ . '/save-sheet.php';
$sheetResult = saveLeadToGoogleSheet($data);

// Check if credentials are still placeholder defaults
$isPlaceholder = (
    strpos($config['smtp_user'], 'your-email') !== false ||
    strpos($config['smtp_pass'], 'your-app-password') !== false
);

if ($isPlaceholder) {
    // Log warning and provide actionable response
    error_log('[PHPMailer] Notice: SMTP credentials in mail-config.php are still default placeholders.');
    http_response_code(200); // return 200 so frontend continues smoothly while notifying
    echo json_encode([
        'success'        => true,
        'demo_mode'      => true,
        'sheet_synced'   => $sheetResult['sheet_synced'] ?? false,
        'sheet_info'     => $sheetResult['message'] ?? '',
        'message'        => 'Lead captured and recorded. (SMTP is in demo mode - update mail-config.php with real credentials to dispatch live emails).',
        'captured_entry' => [
            'name'  => $name,
            'phone' => $phone,
            'email' => $email,
            'kind'  => $kind
        ]
    ]);
    exit;
}

/**
 * Helper to initialize PHPMailer instance with config
 */
function createMailer($config) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $config['smtp_host'];
    $mail->SMTPAuth   = $config['smtp_auth'];
    $mail->Username   = trim($config['smtp_user']);
    $mail->Password   = str_replace(' ', '', trim($config['smtp_pass']));
    
    if (strtolower($config['smtp_secure']) === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif (strtolower($config['smtp_secure']) === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }
    
    $mail->Port       = (int)$config['smtp_port'];
    $mail->CharSet    = 'UTF-8';
    
    if (!empty($config['debug_mode'])) {
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    } else {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
    }
    
    $mail->setFrom($config['from_email'], $config['from_name']);
    return $mail;
}

// Determine Email Subject & Category Title
$kindTitle = 'New Enquiry';
$kindBadge = '#0B4EA2';

if ($kind === 'booking') {
    $kindTitle = 'Workshop Booking Reservation';
    $kindBadge = '#F47B20';
    $subject   = "[RB Booking] $ref - $workshop ($name)";
} elseif ($kind === 'franchise') {
    $kindTitle = 'Franchise Partner Request';
    $kindBadge = '#1F66C6';
    $subject   = "[RB Franchise] Inquiry from $name" . ($city ? " ($city)" : '');
} elseif ($kind === 'trial') {
    $kindTitle = 'Free Trial Class Request';
    $kindBadge = '#17905A';
    $subject   = "[RB Free Trial] $name - " . ($coursesList ?: 'General');
} else {
    $kindTitle = 'General Dance / Fitness Enquiry';
    $subject   = "[RB Enquiry] $name" . ($coursesList ? " - $coursesList" : '');
}

// Build Admin HTML Body
$htmlRows = '';
$addRow = function($label, $val) use (&$htmlRows) {
    if ($val === '' || $val === null) return;
    $htmlRows .= '<tr>'
               . '<td style="padding:10px 14px;border-bottom:1px solid #E6EAF0;font-weight:600;color:#5D6A7E;width:34%;font-size:14px;">' . htmlspecialchars($label) . '</td>'
               . '<td style="padding:10px 14px;border-bottom:1px solid #E6EAF0;color:#0F1B2D;font-size:14px;">' . nl2br(htmlspecialchars((string)$val)) . '</td>'
               . '</tr>';
};

$addRow('Customer / Participant', $name);
$addRow('Phone Number', $phone);
$addRow('Email Address', $email);

if ($kind === 'booking') {
    $addRow('Booking Reference', $ref);
    $addRow('Workshop', $workshop);
    $addRow('Ticket Tier', $tier);
    $addRow('Quantity', $qty);
    $addRow('Total Amount', '₹' . number_format($amount, 2));
    $addRow('Payment Method', $method);
    if (!empty($payment_id)) {
        $addRow('Razorpay Payment ID', $payment_id);
    }
    if (!empty($order_id)) {
        $addRow('Razorpay Order ID', $order_id);
    }
    $addRow('Booking Status', $status ?: 'Payment pending');
} elseif ($kind === 'franchise') {
    $addRow('City / Area', $city);
    $addRow('Format of Interest', $format);
} else {
    $addRow('Course(s) of Interest', $coursesList);
    $addRow('Preferred Training Mode', $mode);
    $addRow('Preferred Location', $location);
}

if (!empty($msg)) {
    $addRow('Message / Note', $msg);
}

$addRow('Submitted On', $at);
if (!empty($page)) {
    $addRow('Source Page', $page);
}

$adminHtmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:24px 10px;background-color:#F6F8FB;font-family:'Poppins',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F1B2D;line-height:1.6;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,27,45,0.08);border:1px solid #E6EAF0;">
    <!-- Brand Header -->
    <tr>
      <td style="background:#0B4EA2;padding:26px 28px;text-align:left;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td>
              <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-0.02em;text-transform:uppercase;">
                RHYTHMIC <span style="color:#F47B20;">BEAT</span>
              </h1>
              <p style="margin:4px 0 0 0;color:#EEF4FC;font-size:12px;letter-spacing:0.04em;">DANCE COURT &middot; NOTIFICATION</p>
            </td>
            <td style="text-align:right;">
              <span style="display:inline-block;background:{$kindBadge};color:#ffffff;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:0.08em;box-shadow:0 2px 8px rgba(0,0,0,0.2);">
                {$kindTitle}
              </span>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- Summary Box -->
    <tr>
      <td style="padding:28px 28px 10px 28px;">
        <h2 style="margin:0 0 6px 0;font-size:18px;color:#0F1B2D;font-weight:700;">
          New Submission Details
        </h2>
        <p style="margin:0 0 20px 0;font-size:14px;color:#5D6A7E;">
          You received a new submission from the Rhythmic Beat website.
        </p>
        
        <!-- Details Table -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #E6EAF0;border-radius:12px;overflow:hidden;">
          <tbody>
            {$htmlRows}
          </tbody>
        </table>
      </td>
    </tr>

    <!-- Quick Action Buttons -->
    <tr>
      <td style="padding:20px 28px 30px 28px;">
        <table cellpadding="0" cellspacing="0">
          <tr>
HTML;

if (!empty($phone)) {
    $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
    $adminHtmlBody .= <<<HTML
            <td style="padding-right:12px;">
              <a href="tel:{$cleanPhone}" style="display:inline-block;background:#0B4EA2;color:#ffffff;text-decoration:none;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:700;">
                Call Lead ({$phone})
              </a>
            </td>
HTML;
}

if (!empty($email)) {
    $adminHtmlBody .= <<<HTML
            <td>
              <a href="mailto:{$email}?subject=Regarding%20your%20inquiry%20at%20Rhythmic%20Beat" style="display:inline-block;background:#F47B20;color:#ffffff;text-decoration:none;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:700;">
                Reply by Email
              </a>
            </td>
HTML;
}

$adminHtmlBody .= <<<HTML
          </tr>
        </table>
      </td>
    </tr>

    <!-- Footer -->
    <tr>
      <td style="background:#F6F8FB;padding:16px 28px;border-top:1px solid #E6EAF0;text-align:center;">
        <p style="margin:0;font-size:12px;color:#5D6A7E;">
          Rhythmic Beat Dance Court &middot; 116A, Chowdry Nagar Main Rd, Valasaravakkam, Chennai 600087
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

// Admin Plaintext Alternative
$altText = "RHYTHMIC BEAT - $kindTitle\n"
         . "----------------------------------------\n"
         . "Name: $name\n"
         . "Phone: $phone\n"
         . "Email: $email\n";
if ($coursesList) $altText .= "Courses: $coursesList\n";
if ($mode)        $altText .= "Mode: $mode\n";
if ($location)    $altText .= "Location: $location\n";
if ($city)        $altText .= "City: $city\n";
if ($format)      $altText .= "Format: $format\n";
if ($workshop)    $altText .= "Workshop: $workshop\n";
if ($ref)         $altText .= "Ref: $ref\n";
if ($msg)         $altText .= "Message: $msg\n";
$altText .= "Time: $at\n";

$userEmailed = false;

try {
    // -------------------------------------------------------------
    // 1. Send Admin Notification Email
    // -------------------------------------------------------------
    $adminMail = createMailer($config);
    $adminMail->addAddress($config['admin_email'], $config['admin_name'] ?? 'Admin');
    
    // Add CC / BCC if specified
    if (!empty($config['cc_email'])) {
        $adminMail->addCC($config['cc_email']);
    }
    if (!empty($config['bcc_email'])) {
        $adminMail->addBCC($config['bcc_email']);
    }

    // Set Reply-To to the customer's email so the admin can simply hit "Reply"
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $adminMail->addReplyTo($email, $name);
    }

    $adminMail->isHTML(true);
    $adminMail->Subject = $subject;
    $adminMail->Body    = $adminHtmlBody;
    $adminMail->AltBody = $altText;

    $adminMail->send();

    // -------------------------------------------------------------
    // 2. Send Customer Confirmation / Acknowledgment Email
    // -------------------------------------------------------------
    if (!empty($config['send_user_confirmation']) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $userMail = createMailer($config);
            $userMail->addAddress($email, $name);
            $userMail->isHTML(true);

            if ($kind === 'booking') {
                $isPaid = (stripos($status, 'paid') !== false) || !empty($payment_id);
                $userSubject = $isPaid 
                    ? "Workshop Booking & Payment Confirmed: $workshop [Ref: $ref]"
                    : "Workshop Reservation Confirmed: $workshop [Ref: $ref]";
                $userHeadline = $isPaid ? "Your Workshop Slot & Payment Are Confirmed!" : "Your Workshop Slot Is Reserved!";
                $userMessage = $isPaid
                    ? "Thank you for your payment of <b>₹" . number_format($amount, 2) . "</b> (Razorpay ID: <b>" . htmlspecialchars($payment_id) . "</b>) for <b>" . htmlspecialchars($workshop) . "</b>. Your e-ticket slot is reserved! Please show this reference at the studio reception desk."
                    : "We have received your reservation for <b>" . htmlspecialchars($workshop) . "</b>. Our team will verify your details and connect with you on <b>" . htmlspecialchars($phone) . "</b> shortly.";
            } elseif ($kind === 'franchise') {
                $userSubject = "Thank You for Your Franchise Interest - Rhythmic Beat Dance Court";
                $userHeadline = "Thank You for Partnering with Rhythmic Beat";
                $userMessage = "We have received your franchise inquiry for " . htmlspecialchars($city ?: 'your city') . ". Our expansion team will review your details and reach out with complete format and studio investment details.";
            } elseif ($kind === 'trial') {
                $userSubject = "Free Trial Class Request Received - Rhythmic Beat";
                $userHeadline = "Your Free Trial Class Request Is Received!";
                $userMessage = "Thank you for choosing Rhythmic Beat! Our instructor coordination team will call you at <b>" . htmlspecialchars($phone) . "</b> to schedule your trial session.";
            } else {
                $userSubject = "Thank You for Contacting Rhythmic Beat Dance Court";
                $userHeadline = "We Received Your Message!";
                $userMessage = "Thank you for getting in touch with Rhythmic Beat. We have noted your interest in <b>" . htmlspecialchars($coursesList ?: 'our dance & fitness programmes') . "</b> and our team will get in touch with you shortly.";
            }

            $userHtmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{$userSubject}</title>
</head>
<body style="margin:0;padding:24px 10px;background-color:#F6F8FB;font-family:'Poppins',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F1B2D;line-height:1.6;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,27,45,0.08);border:1px solid #E6EAF0;">
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
        <h2 style="margin:0 0 12px 0;font-size:20px;color:#0B4EA2;font-weight:700;">
          Hi {$name},
        </h2>
        <p style="margin:0 0 18px 0;font-size:15px;color:#0F1B2D;">
          {$userHeadline}
        </p>
        <p style="margin:0 0 20px 0;font-size:14px;color:#5D6A7E;line-height:1.7;">
          {$userMessage}
        </p>

        <!-- Summary box -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#EEF4FC;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
          <tr>
            <td>
              <p style="margin:0 0 6px 0;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#0B4EA2;font-weight:700;">Our Locations & Hotline</p>
              <p style="margin:0 0 4px 0;font-size:13px;color:#0F1B2D;"><b>HQ:</b> 116A, Chowdry Nagar Main Rd, Valasaravakkam, Chennai</p>
              <p style="margin:0 0 4px 0;font-size:13px;color:#0F1B2D;"><b>Courts:</b> Valasaravakkam &middot; Kolapakkam &middot; Ambattur</p>
              <p style="margin:0;font-size:13px;color:#0F1B2D;"><b>Hotline:</b> +91 96555 51055</p>
            </td>
          </tr>
        </table>

        <p style="margin:0;font-size:13px;color:#5D6A7E;">
          Warm regards,<br>
          <b style="color:#0F1B2D;">Team Rhythmic Beat Dance Court</b>
        </p>
      </td>
    </tr>
    <tr>
      <td style="background:#F6F8FB;padding:14px 28px;border-top:1px solid #E6EAF0;text-align:center;">
        <p style="margin:0;font-size:11px;color:#5D6A7E;">
          This is an automated confirmation from Rhythmic Beat Dance Court.
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

            $userMail->Subject = $userSubject;
            $userMail->Body    = $userHtmlBody;
            $userMail->AltBody = "Hi $name,\n\n$userHeadline\n\n$userMessage\n\nHotline: +91 96555 51055\nTeam Rhythmic Beat";
            $userMail->send();
            $userEmailed = true;
        } catch (Exception $eUser) {
            error_log('[PHPMailer] Notice: Could not send user confirmation email: ' . $eUser->getMessage());
        }
    }

    echo json_encode([
        'success'      => true,
        'message'      => 'Inquiry sent and notification email dispatched successfully.',
        'kind'         => $kind,
        'user_emailed' => $userEmailed,
        'sheet_synced' => $sheetResult['sheet_synced'] ?? false,
        'sheet_info'   => $sheetResult['message'] ?? ''
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('[PHPMailer] Error: ' . $e->getMessage());
    echo json_encode([
        'success'      => false,
        'error'        => 'Could not send email notification.',
        'sheet_synced' => $sheetResult['sheet_synced'] ?? false,
        'sheet_info'   => $sheetResult['message'] ?? '',
        'details'      => $config['debug_mode'] ? $e->getMessage() : 'Please check SMTP settings in mail-config.php.'
    ]);
}
