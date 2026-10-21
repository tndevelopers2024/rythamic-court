<?php
/**
 * Rhythmic Beat Dance Court - Save Razorpay Credentials Endpoint
 * 
 * Allows saving Key ID and Key Secret into razorpay-config.php.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = [];
if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

$keyId     = trim($data['key_id'] ?? '');
$keySecret = trim($data['key_secret'] ?? '');

if (empty($keyId) || empty($keySecret)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Both Razorpay Key ID and Key Secret are required.'
    ]);
    exit;
}

if (!preg_match('/^rzp_(test|live)_[A-Za-z0-9]+$/', $keyId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Key ID must start with rzp_test_ or rzp_live_'
    ]);
    exit;
}

$configFile = __DIR__ . '/razorpay-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'razorpay-config.php not found']);
    exit;
}

$content = file_get_contents($configFile);

// Update key_id
$content = preg_replace(
    "/'key_id'\s*=>\s*getenv\('RAZORPAY_KEY_ID'\)\s*\?:\s*'[^']*'/",
    "'key_id'         => getenv('RAZORPAY_KEY_ID') ?: '" . addslashes($keyId) . "'",
    $content
);

// Update key_secret
$content = preg_replace(
    "/'key_secret'\s*=>\s*getenv\('RAZORPAY_KEY_SECRET'\)\s*\?:\s*'[^']*'/",
    "'key_secret'     => getenv('RAZORPAY_KEY_SECRET') ?: '" . addslashes($keySecret) . "'",
    $content
);

// Disable allow_demo now that real keys are in place
$content = str_replace("'allow_demo'     => true", "'allow_demo'     => false", $content);

file_put_contents($configFile, $content);

echo json_encode([
    'success' => true,
    'key_id'  => $keyId,
    'message' => 'Razorpay API credentials saved successfully.'
]);
