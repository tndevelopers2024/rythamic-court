<?php
/**
 * Rhythmic Beat Dance Court - Razorpay Webhook Handler
 * 
 * Handles server-to-server webhook events dispatched by Razorpay
 * (such as payment.captured, order.paid).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');

$configFile = __DIR__ . '/razorpay-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Config missing']);
    exit;
}

$config = require $configFile;
$webhookSecret = trim($config['webhook_secret'] ?? '');

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

// If webhook secret is configured, verify HMAC signature
if (!empty($webhookSecret)) {
    if (empty($signature)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing signature header']);
        exit;
    }

    $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);
    if (!hash_equals($expectedSignature, $signature)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature']);
        exit;
    }
}

$payload = json_decode($rawBody, true);
$event = $payload['event'] ?? 'unknown';

error_log("[Razorpay Webhook] Received event: $event");

switch ($event) {
    case 'payment.captured':
    case 'order.paid':
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $payment['order_id'] ?? '';
        $paymentId = $payment['id'] ?? '';
        $amount = ($payment['amount'] ?? 0) / 100;
        $email = $payment['email'] ?? '';
        $contact = $payment['contact'] ?? '';
        error_log("[Razorpay Webhook] Payment captured: ID: $paymentId, Order: $orderId, Amount: ₹$amount, Contact: $contact, Email: $email");
        break;

    case 'payment.failed':
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $paymentId = $payment['id'] ?? '';
        $reason = $payment['error_description'] ?? 'Payment failed';
        error_log("[Razorpay Webhook] Payment failed: ID: $paymentId, Reason: $reason");
        break;

    default:
        // Other events ignored gracefully
        break;
}

// Acknowledge receipt to Razorpay
echo json_encode(['status' => 'ok', 'event' => $event]);
