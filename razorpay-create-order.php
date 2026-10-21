<?php
/**
 * Rhythmic Beat Dance Court - Razorpay Order Creator
 * 
 * Creates an order on Razorpay servers via the Orders API
 * and returns the order_id to initialize Razorpay Checkout.
 */

// Enable error reporting for logs, output clean JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');

// Load Configuration
$configFile = __DIR__ . '/razorpay-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Configuration file razorpay-config.php not found.'
    ]);
    exit;
}

$config = require $configFile;

// Handle CORS
$corsOrigin = $config['cors_origin'] ?? '*';
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

// Extract fields
$amountRaw = $data['amount'] ?? 0;
$amount    = (float)$amountRaw;
$receipt   = trim(strip_tags($data['receipt'] ?? 'RB-' . time()));
$name      = trim(strip_tags($data['name'] ?? ''));
$email     = trim(filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL));
$phone     = trim(strip_tags($data['phone'] ?? ''));
$notes     = is_array($data['notes'] ?? null) ? $data['notes'] : [];

// Amount validation (in Rupees, must be > 0)
if ($amount <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid amount. Amount must be greater than zero.'
    ]);
    exit;
}

// Razorpay takes amount in smallest currency unit (paise for INR)
$amountInPaise = (int)round($amount * 100);

$keyId     = trim($config['key_id'] ?? '');
$keySecret = trim($config['key_secret'] ?? '');

$isPlaceholder = (
    empty($keyId) ||
    empty($keySecret) ||
    strpos($keyId, 'YOUR_') !== false ||
    strpos($keySecret, 'YOUR_') !== false
);

// If keys are not configured yet
if ($isPlaceholder) {
    http_response_code(200); // return 200 with clear setup_needed flag so frontend can prompt for keys
    echo json_encode([
        'success'      => false,
        'setup_needed' => true,
        'error'        => 'Razorpay API keys are not configured yet. Please enter your Key ID and Key Secret to activate checkout.'
    ]);
    exit;
}

// Add useful metadata to notes
$orderNotes = array_merge([
    'merchant' => 'Rhythmic Beat Dance Court',
    'receipt'  => $receipt,
    'customer' => $name,
    'phone'    => $phone,
    'email'    => $email
], $notes);

// Razorpay receipts max length is 40 characters
$cleanReceipt = substr($receipt, 0, 40);

$orderPayload = [
    'amount'          => $amountInPaise,
    'currency'        => $config['currency'] ?? 'INR',
    'receipt'         => $cleanReceipt,
    'notes'           => $orderNotes,
    'payment_capture' => 1
];

// Call Razorpay Orders API via cURL
$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $keyId . ':' . $keySecret,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'User-Agent: Rhythmic-Beat-Razorpay-Client/1.0'
    ],
    CURLOPT_POSTFIELDS     => json_encode($orderPayload),
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if (!empty($curlErr)) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'Could not reach Razorpay API servers: ' . $curlErr
    ]);
    exit;
}

$result = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300 && !empty($result['id'])) {
    echo json_encode([
        'success'      => true,
        'is_demo'      => false,
        'order_id'     => $result['id'],
        'amount'       => $result['amount'],
        'currency'     => $result['currency'],
        'key_id'       => $keyId,
        'company_name' => $config['company_name'] ?? 'Rhythmic Beat Dance Court',
        'company_logo' => $config['company_logo'] ?? 'img/logo.png',
        'theme_color'  => $config['theme_color'] ?? '#0B4EA2',
        'receipt'      => $result['receipt'] ?? $cleanReceipt
    ]);
    exit;
}

// Razorpay returned an error
http_response_code($httpCode ?: 400);
$errorMessage = $result['error']['description'] ?? 'Failed to create Razorpay order.';
echo json_encode([
    'success' => false,
    'error'   => $errorMessage,
    'details' => !empty($config['debug_mode']) ? $result : null
]);
