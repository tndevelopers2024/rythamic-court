# Razorpay Payment Integration - Rhythmic Beat Dance Court

This website is equipped with a complete, secure, production-grade **Razorpay Payment Gateway** integration supporting UPI (Google Pay, PhonePe, Paytm, BHIM), Credit/Debit Cards, Net Banking, and Wallets for workshop bookings.

---

## 📁 File Structure

```text
c:\xampp\htdocs\Rhythmic-Beat/
├── razorpay-config.php        # Razorpay API credentials (Key ID, Key Secret, Branding)
├── razorpay-create-order.php  # AJAX endpoint to create orders via Razorpay Orders API
├── razorpay-verify.php        # Verifies HMAC-SHA256 signature and triggers booking confirmation
├── razorpay-webhook.php       # (Optional) Webhook handler for server-to-server notifications
├── test-razorpay.php          # Browser & CLI diagnostic tool with interactive test checkout
├── send-mail.php              # Automated email notifications (Admin alert & Customer E-Ticket)
├── mail-config.php            # SMTP configuration
└── index.html                 # Frontend checkout popup wired to Razorpay
```

---

## ⚡ How to Add Your Razorpay Keys

Open [`razorpay-config.php`](file:///c:/xampp/htdocs/Rhythmic-Beat/razorpay-config.php) and update the credentials:

```php
return [
    // Replace placeholders with your keys:
    'key_id'     => 'rzp_test_...', // or 'rzp_live_...'
    'key_secret' => 'your_secret_key_here',

    'company_name' => 'Rhythmic Beat Dance Court',
    'company_logo' => 'img/logo.png',
    'theme_color'  => '#0B4EA2', // Rhythmic Beat Royal Blue
    'currency'     => 'INR',
];
```

### Where to get your keys:
1. Log in to your **[Razorpay Dashboard](https://dashboard.razorpay.com/)**.
2. Select **Test Mode** (for development) or **Live Mode** (for accepting real payments).
3. Navigate to **Account & Settings** &rarr; **API Keys** &rarr; **Generate Key**.
4. Copy the **Key ID** (`rzp_test_...` or `rzp_live_...`) and **Key Secret**.
5. Paste them into [`razorpay-config.php`](file:///c:/xampp/htdocs/Rhythmic-Beat/razorpay-config.php) or set them as environment variables (`RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET`).

---

## 🧪 Testing Your Setup

### 1. Terminal / CLI Diagnostics
Run the diagnostic suite directly in terminal:
```powershell
C:\xampp\php\php.exe test-razorpay.php
```

To test live order creation via Razorpay API:
```powershell
C:\xampp\php\php.exe test-razorpay.php --test
```

### 2. Browser Diagnostics & Interactive Test Checkout
Visit the test page in your browser:
👉 **`http://localhost/Rhythmic-Beat/test-razorpay.php`**

Features on the test page:
- Verifies PHP cURL, OpenSSL, and Hash extensions.
- Detects whether keys are in Test or Live mode.
- Button: **"Test API Connection"** (Creates a ₹1 test order with Razorpay servers).
- Button: **"Launch Test Checkout (₹1)"** (Opens the interactive Razorpay Checkout popup so you can test UPI/cards immediately).

---

## 🔄 Payment & Booking Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as Customer
    participant Front as Frontend (index.html)
    participant Create as razorpay-create-order.php
    participant RZP as Razorpay API Server
    participant Verify as razorpay-verify.php
    participant Mail as PHPMailer (send-mail.php)

    User->>Front: Selects Workshop & fills details
    User->>Front: Clicks "Pay with Razorpay"
    Front->>Create: POST { amount, receipt, name, phone, email }
    Create->>RZP: POST /v1/orders (amount in paise, Basic Auth)
    RZP-->>Create: Returns order_id
    Create-->>Front: Returns order_id, key_id, amount
    Front->>RZP: Opens Razorpay Checkout Popup
    User->>RZP: Completes payment (UPI / Card / Netbanking)
    RZP-->>Front: Returns payment_id, order_id, signature
    Front->>Verify: POST { razorpay_payment_id, order_id, signature, booking }
    Verify->>Verify: Validates HMAC-SHA256 signature
    Verify->>Mail: Dispatches Admin Notification & Customer E-Ticket
    Verify-->>Front: Returns { success: true, verified: true }
    Front->>User: Displays Confirmed E-Ticket & Reference ID
```

---

## 🛡️ Security Features

- **Cryptographic Signature Verification:** Every payment signature is validated on the backend using `hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature)`.
- **Zero Frontend Secrets:** Only the public `key_id` is ever shared with the browser. The `key_secret` remains safe on your server.
- **Auto Capture Enabled:** Orders are pre-configured with `payment_capture = 1` for immediate capture.
- **Direct Access Prevention:** Configuration files reject direct browser execution with HTTP 403.
- **Fail-Safe Offline Mode:** If hosting is static or offline, lead details remain safely preserved in browser local storage.

---

## 🔔 Webhooks (Optional)

If you wish to handle asynchronous payment captures or edge cases where customers close their browser tab before redirect:
1. In the Razorpay Dashboard, go to **Account & Settings** &rarr; **Webhooks**.
2. Add Webhook URL: `https://yourdomain.com/razorpay-webhook.php`
3. Select Events: `payment.captured`, `order.paid`, `payment.failed`.
4. Enter a Secret and save it in `razorpay-config.php` under `'webhook_secret'`.
