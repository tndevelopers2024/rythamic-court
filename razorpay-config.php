<?php
/**
 * Rhythmic Beat Dance Court - Razorpay Configuration
 * 
 * Update your Razorpay API credentials below.
 * Generate API keys from:
 * 👉 Razorpay Dashboard: https://dashboard.razorpay.com/app/keys
 * 
 * For testing: Use Test Keys (starts with 'rzp_test_')
 * For live:    Use Live Keys (starts with 'rzp_live_')
 */

// Prevent direct URL execution
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

return [
    // -------------------------------------------------------------
    // Razorpay API Credentials
    // -------------------------------------------------------------
    // Replace placeholders with your actual Razorpay Key ID & Key Secret
    'key_id'         => getenv('RAZORPAY_KEY_ID') ?: 'rzp_live_TpFcpwWqYlYUib',
    'key_secret'     => getenv('RAZORPAY_KEY_SECRET') ?: 'X5CjAo21C4ytbxNxKJc4Gmfg',

    // -------------------------------------------------------------
    // Merchant & Checkout Details
    // -------------------------------------------------------------
    'company_name'   => 'Rhythmic Beat Dance Court',
    'company_logo'   => 'img/logo.png', // Logo displayed in Razorpay checkout modal
    'theme_color'    => '#0B4EA2',      // Rhythmic Beat Royal Blue brand color
    'currency'       => 'INR',

    // -------------------------------------------------------------
    // Webhook Configuration (Optional)
    // -------------------------------------------------------------
    // If you enable webhooks in Razorpay Dashboard -> Settings -> Webhooks:
    // Webhook URL: http://yourdomain.com/razorpay-webhook.php
    // Secret defined when adding webhook:
    'webhook_secret' => getenv('RAZORPAY_WEBHOOK_SECRET') ?: '',

    // -------------------------------------------------------------
    // Diagnostics & Environment
    // -------------------------------------------------------------
    // Set to true to include debug details in JSON errors
    'debug_mode'     => true,

    // When keys are still placeholders, allow demo mode so frontend UI can be tested
    'allow_demo'     => false,
];
