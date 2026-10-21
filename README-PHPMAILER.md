# PHPMailer Setup for Rhythmic Beat Dance Court

This website is equipped with a complete, production-ready **PHPMailer** integration to deliver lead notifications, course enquiries, franchise applications, and workshop booking confirmations directly to your inbox.

---

## 📁 File Structure

```text
Website Rhythmic Beat Redesign/
├── phpmailer/               # Standalone PHPMailer library (PHP 7.4 - 8.3 compatible)
│   ├── Exception.php
│   ├── PHPMailer.php
│   ├── SMTP.php
│   └── ...
├── mail-config.php          # SMTP settings (host, port, user, app password, admin email)
├── send-mail.php            # AJAX endpoint for forms (validates, formats HTML email, dispatches)
├── test-mail.php            # Diagnostics tool (tests server environment & SMTP connection)
└── index.html               # Frontend forms automatically wired to send-mail.php
```

---

## ⚡ Quick Start: Configure Your SMTP

Open [`mail-config.php`](file:///c:/Users/pc/Desktop/Website%20Rhythmic%20Beat%20Redesign/mail-config.php) and update the credentials with your mail server details.

### Option A: Using Gmail (Recommended)

1. Enable **2-Step Verification** on your Google Account:  
   👉 [Google Account Security](https://myaccount.google.com/security)
2. Generate an **App Password**:
   - Go to **Security** → **2-Step Verification** → **App Passwords** (bottom of page).
   - Enter App Name: `Rhythmic Beat Website`
   - Copy the 16-character password (e.g. `abcd efgh ijkl mnop`).
3. Set your settings in `mail-config.php`:
   ```php
   'smtp_host'   => 'smtp.gmail.com',
   'smtp_auth'   => true,
   'smtp_user'   => 'yourbusiness@gmail.com',
   'smtp_pass'   => 'abcdefghijklmnop', // your 16-character App Password (without spaces)
   'smtp_secure' => 'tls',
   'smtp_port'   => 587,
   'from_email'  => 'yourbusiness@gmail.com',
   'from_name'   => 'Rhythmic Beat Dance Court',
   'admin_email' => 'info@rhythmicbeat.in', // where leads should be delivered
   ```

### Option B: Using Hostinger Webmail

```php
'smtp_host'   => 'smtp.hostinger.com',
'smtp_auth'   => true,
'smtp_user'   => 'info@rhythmicbeat.in',
'smtp_pass'   => 'your-email-password',
'smtp_secure' => 'ssl', // or 'tls' with port 587
'smtp_port'   => 465,
'from_email'  => 'info@rhythmicbeat.in',
```

### Option C: Using cPanel / Standard Web Hosting

```php
'smtp_host'   => 'mail.yourdomain.com',
'smtp_auth'   => true,
'smtp_user'   => 'contact@yourdomain.com',
'smtp_pass'   => 'your-email-password',
'smtp_secure' => 'ssl',
'smtp_port'   => 465, // or tls on 587
'from_email'  => 'contact@yourdomain.com',
```

---

## 🧪 Testing Your Mail Setup

### 1. Run Diagnostics in Terminal
Using XAMPP PHP:
```powershell
C:\xampp\php\php.exe test-mail.php
```
To send a live test email:
```powershell
C:\xampp\php\php.exe test-mail.php --send
```

### 2. Run Diagnostics in Browser
Start a local server or view in XAMPP:
```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000
```
Then visit:  
👉 `http://127.0.0.1:8000/test-mail.php`

Click **"Send Test Email"** to verify that your credentials and network connection connect successfully to the mail server.

---

## 📬 Integrated Forms & Email Features

All website forms in `index.html` automatically send data to `send-mail.php`:

| Form Type | Trigger Location | What Happens |
| :--- | :--- | :--- |
| **Free Trial Class** | Header, Course details, Modal popups | Admin gets lead email with chosen courses & location; User gets trial booking acknowledgment. |
| **Course Enquiry** | Course pages & general enquiry buttons | Admin gets course enquiry with participant contact details. |
| **Contact Message** | Contact page message form | Admin receives user query with direct `Reply-To` and `tel:` call button. |
| **Franchise Inquiry** | Franchise section modal | Admin receives city, format of interest, and investor phone/email. |
| **Workshop Booking** | Workshop booking flow | Admin & attendee receive branded booking confirmation with Ref ID, ticket tier & total price. |

### Key Features:
- **Branded HTML Emails:** Styled with Rhythmic Beat's royal blue (`#0B4EA2`) & energetic orange (`#F47B20`).
- **Direct Reply-To:** Hitting "Reply" in your email client replies directly to the client's email.
- **Click-to-Call Button:** Easily call the lead from mobile or desktop email clients.
- **Spam Protection:** Hidden honeypot fields filter out spam bots silently.
- **Fail-Safe Offline Mode:** If hosting is static or offline, lead details remain safely preserved in browser local storage and Claude DB.
