<?php
/**
 * Rhythmic Beat Dance Court - PHPMailer Configuration
 * 
 * Configure your SMTP settings below to enable email sending.
 * Supports Gmail, Hostinger, cPanel, Outlook, Zoho, SendGrid, etc.
 */

// Prevent direct access from browser URL
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

return [
    // -------------------------------------------------------------
    // SMTP Server Settings
    // -------------------------------------------------------------
    
    // SMTP Host:
    // Gmail:        'smtp.gmail.com'
    // Hostinger:    'smtp.hostinger.com'
    // cPanel / Web: 'mail.yourdomain.com'
    // Outlook:      'smtp.office365.com'
    // Zoho:         'smtp.zoho.com' (or 'smtp.zoho.in')
    'smtp_host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',

    // SMTP Authentication (usually true)
    'smtp_auth' => true,

    // SMTP Username: your full email address
    'smtp_user' => getenv('SMTP_USER') ?: 'rbdancecourtofficial@gmail.com',

    // SMTP Password:
    // Note: For Gmail, you MUST use an 'App Password' (not your standard password).
    // Generate one at: Google Account -> Security -> 2-Step Verification -> App Passwords
    'smtp_pass' => getenv('SMTP_PASS') ?: 'dzjicdykndferrik',

    // Encryption type: 'tls' (recommended for port 587) or 'ssl' (port 465)
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'tls',

    // SMTP Port: 587 (TLS) or 465 (SSL)
    'smtp_port' => (int)(getenv('SMTP_PORT') ?: 587),

    // -------------------------------------------------------------
    // Sender & Recipient Information
    // -------------------------------------------------------------

    // Sender email (usually the same as smtp_user)
    'from_email' => getenv('FROM_EMAIL') ?: 'rbdancecourtofficial@gmail.com',

    // Sender name shown in the inbox
    'from_name'  => 'Rhythmic Beat Dance Court',

    // Admin / Studio email where customer inquiries & bookings should be sent
    'admin_email' => getenv('ADMIN_EMAIL') ?: 'rbdancecourtofficial@gmail.com',
    'admin_name'  => 'Rhythmic Beat Team',

    // Optional CC or BCC email (leave empty string if not needed)
    'cc_email'    => getenv('CC_EMAIL') ?: 'hello@rbdancecourt.com',
    'bcc_email'   => '',

    // -------------------------------------------------------------
    // Behavior Settings
    // -------------------------------------------------------------

    // Send an automated acknowledgment email back to the customer/lead
    'send_user_confirmation' => true,

    // Set to true to output verbose SMTP debug info (use only when troubleshooting)
    'debug_mode' => false,

    // Allowed CORS origin (use '*' for local testing or set your domain like 'https://rhythmicbeat.in')
    'cors_origin' => '*',
];
