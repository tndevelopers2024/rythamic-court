# 📊 Google Sheets Integration for Rhythmic Beat Dance Court

This integration automatically saves all submissions from the **Enquiry Form**, **Contact Form**, **Free Trial requests**, and other lead forms directly into your Google Sheet in real time.

---

## 🎯 Target Google Sheet

- **Spreadsheet URL:** [Open in Google Sheets](https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit?usp=sharing)
- **Spreadsheet ID:** `1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c`

---

## 📁 Files Created / Modified

| File | Purpose |
| :--- | :--- |
| `google-apps-script.js` | Full Google Apps Script code to paste into your Google Sheet. Automatically creates and styles tabs with RB Blue headers (`#0B4EA2`). |
| `sheet-config.php` | Main configuration file where your Google Sheet ID and deployed Apps Script Web App URL are set. |
| `save-sheet.php` | Backend dispatcher that formats lead data, writes to `leads-backup.log`, and sends the entry to Google Sheets via cURL. |
| `test-sheet.php` | Interactive diagnostic tool and Web UI to test submissions, check environment health, and save your Web App URL. |
| `send-mail.php` | Integrated so that every form submission both dispatches email notifications **and** writes to Google Sheets in one step. |
| `index.html` | Frontend forms wired to sync leads to `send-mail.php` with direct webhook fallback capability. |

---

## ⚡ 2-Minute Deployment Steps

Follow these simple steps to activate the sync with your Google Sheet:

### Step 1: Open Your Google Sheet & Apps Script Editor
1. Open your sheet: [https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit](https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit)
2. In the top navigation bar, click **Extensions** &rarr; **Apps Script**.

### Step 2: Paste the Webhook Code
1. Delete any placeholder code in the `Code.gs` editor window.
2. Open [`google-apps-script.js`](file:///c:/xampp/htdocs/Rhythmic-Beat/google-apps-script.js), copy the entire code, and paste it into the editor.
3. Click the **Save** icon (disk icon or `Ctrl + S`).

### Step 3: Initialize Tabs (Optional but Recommended)
1. In the function dropdown at the top (next to "Debug"), choose **`setupSheets`**.
2. Click **Run**.
3. *If prompted for permissions, click **Review permissions** &rarr; select your Google account &rarr; click **Advanced** &rarr; click **Go to Untitled project (unsafe)** &rarr; click **Allow**.*
4. This will immediately create and style four tabs in your sheet:
   - **Enquiries** (Course inquiries, free trial requests)
   - **Contact Messages** (Contact page messages)
   - **Workshop Bookings** (Paid ticket pass reservations, booking ref, amount, Razorpay IDs)
   - **All Submissions** (Master ledger of every lead & booking chronologically)

### Step 4: Deploy as a Web App
1. Click the blue **Deploy** button (top right) &rarr; **New deployment**.
2. Click the gear icon (⚙️) next to "Select type" &rarr; select **Web app**.
3. Configure the fields:
   - **Description:** `Rhythmic Beat Website Sync`
   - **Execute as:** `Me` (your Google email)
   - **Who has access:** `Anyone` *(Crucial: must be set to "Anyone" so the website can post form entries to your sheet).*
4. Click **Deploy**.
5. Copy the **Web App URL** (it looks like `https://script.google.com/macros/s/AKfycb.../exec`).

### Step 5: Save the Web App URL into Your Website
You can save the URL in either of two ways:
- **Option A (Via Browser):** Open `http://localhost/Rhythmic-Beat/test-sheet.php` in your browser, paste the URL in the "Quick Webhook URL Configuration" box, and click **Save URL**.
- **Option B (Direct in Code):** Open [`sheet-config.php`](file:///c:/xampp/htdocs/Rhythmic-Beat/sheet-config.php) and update line 26:
  ```php
  'apps_script_url' => 'https://script.google.com/macros/s/YOUR_DEPLOYED_URL_HERE/exec',
  ```

---

## 🧪 Testing Your Setup

### Test via Web Browser
1. Start Apache in XAMPP.
2. Visit:  
   👉 `http://localhost/Rhythmic-Beat/test-sheet.php`
3. Click **"Send Test Enquiry Form"** or **"Send Test Contact Form"**.
4. Check your Google Sheet — the test entry will appear instantly with styled cells and timestamp!

### Test via Terminal
Run using XAMPP PHP:
```powershell
C:\xampp\php\php.exe test-sheet.php --test
```

---

## 📋 Data Columns Recorded in the Sheet

Every form submission records the following structured columns:

1. **Timestamp (IST):** Date & time formatted in Indian Standard Time (e.g. `21 Oct 2026, 10:30 am`)
2. **Form Category:** `Course Enquiry`, `Contact Form`, `Free Trial Class`, etc.
3. **Full Name:** Participant / visitor name
4. **Mobile Number:** Stored as text to prevent dropping leading zeroes or country codes
5. **Email Address:** Lead email
6. **Courses Interested:** Comma-separated list (e.g., `Western Dance, Zumba`)
7. **Training Mode:** `Offline` (at studio) or `Online`
8. **Preferred Location:** Valasaravakkam, Kolapakkam, Ambattur, etc.
9. **Message / Notes:** Detailed query with auto-wrap enabled
10. **Source Page:** Page URL hash (e.g., `#contact`, `#courses`, `#home`)
11. **Additional Details:** Extra context (Franchise city/format, Workshop title/ref, etc.)
12. **Lead Status:** Default `New Lead` badge

---

## 🛡️ Reliability & Safety Features

- **Zero Data Loss Guarantee:** Every submission is saved to `leads-backup.log` on your server before reaching Google.
- **Concurrent Lock Protection:** Google Apps Script utilizes `LockService` to prevent conflicts or overwritten rows when multiple users submit simultaneously.
- **Dual Pipeline:** If email dispatch fails or SMTP is in demo mode, your leads still reach the Google Sheet safely.
