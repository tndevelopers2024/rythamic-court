/**
 * ==============================================================================
 * RHYTHMIC BEAT DANCE COURT - GOOGLE SHEETS FORM SYNC WEBHOOK (WITH DEDUPLICATION)
 * ==============================================================================
 * 
 * Target Spreadsheet:
 * https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit?usp=sharing
 * Spreadsheet ID: 1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c
 * 
 * HOW TO UPDATE IN GOOGLE SHEETS:
 * 1. Open your Google Sheet in your browser:
 *    https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit
 * 2. Click "Extensions" -> "Apps Script".
 * 3. Replace all code with this updated code.
 * 4. Click "Deploy" -> "Manage deployments" -> Click the Pencil ✏️ icon.
 * 5. Set Version to "New version", ensure Who has access is "Anyone", and click "Deploy".
 * ==============================================================================
 */

// Target Spreadsheet ID
var SPREADSHEET_ID = "1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c";

// Standard Column Headers (Enquiries, Contact, Master)
var HEADERS = [
  "Timestamp (IST)",
  "Form Category",
  "Full Name",
  "Mobile Number",
  "Email Address",
  "Courses Interested",
  "Training Mode",
  "Preferred Location",
  "Message / Notes",
  "Source Page",
  "Additional Details",
  "Lead Status"
];

// Dedicated Workshop Booking Headers
var BOOKING_HEADERS = [
  "Timestamp (IST)",
  "Booking Ref",
  "Participant Name",
  "Mobile Number",
  "Email Address",
  "Workshop Title",
  "Pass Tier",
  "Qty",
  "Amount Paid (₹)",
  "Payment Method",
  "Razorpay Payment ID",
  "Razorpay Order ID",
  "Booking Status"
];

// Sheet Tab Names
var TAB_ENQUIRIES = "Enquiries";
var TAB_CONTACT   = "Contact Messages";
var TAB_BOOKINGS  = "Workshop Bookings";
var TAB_MASTER    = "All Submissions";

/**
 * Helper to get the target spreadsheet
 */
function getSpreadsheet() {
  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    if (ss) return ss;
  } catch (e) {}
  return SpreadsheetApp.openById(SPREADSHEET_ID);
}

/**
 * Helper to search for an existing row by value in a specific column (1-indexed).
 * Returns the 1-indexed row number if found, or -1 if not found.
 */
function findRowByValue(sheet, colIndex, targetValue) {
  if (!targetValue || sheet.getLastRow() < 2) return -1;
  var target = String(targetValue).trim().toUpperCase();
  var rangeValues = sheet.getRange(2, colIndex, sheet.getLastRow() - 1, 1).getValues();
  for (var r = 0; r < rangeValues.length; r++) {
    if (String(rangeValues[r][0]).trim().toUpperCase() === target) {
      return r + 2; // 1-indexed row number in the sheet
    }
  }
  return -1;
}

/**
 * Format standard headers with Rhythmic Beat royal blue branding
 */
function styleStandardHeaderRow(sheet) {
  var headerRange = sheet.getRange(1, 1, 1, HEADERS.length);
  headerRange.setValues([HEADERS]);
  headerRange.setBackground("#0B4EA2"); // Rhythmic Beat Blue
  headerRange.setFontColor("#FFFFFF");
  headerRange.setFontWeight("bold");
  headerRange.setFontSize(10);
  headerRange.setHorizontalAlignment("center");
  headerRange.setVerticalAlignment("middle");
  sheet.setRowHeight(1, 38);
  sheet.setFrozenRows(1);

  var widths = [160, 140, 160, 135, 190, 200, 110, 140, 250, 110, 200, 110];
  for (var i = 0; i < widths.length; i++) {
    sheet.setColumnWidth(i + 1, widths[i]);
  }
}

/**
 * Format workshop booking headers with Rhythmic Beat orange branding
 */
function styleBookingHeaderRow(sheet) {
  var headerRange = sheet.getRange(1, 1, 1, BOOKING_HEADERS.length);
  headerRange.setValues([BOOKING_HEADERS]);
  headerRange.setBackground("#F47B20"); // Rhythmic Beat Orange
  headerRange.setFontColor("#FFFFFF");
  headerRange.setFontWeight("bold");
  headerRange.setFontSize(10);
  headerRange.setHorizontalAlignment("center");
  headerRange.setVerticalAlignment("middle");
  sheet.setRowHeight(1, 38);
  sheet.setFrozenRows(1);

  var widths = [160, 140, 170, 135, 190, 220, 130, 65, 125, 140, 190, 190, 130];
  for (var i = 0; i < widths.length; i++) {
    sheet.setColumnWidth(i + 1, widths[i]);
  }
}

/**
 * Ensure a sheet tab exists with the appropriate header styling
 */
function getOrCreateTab(ss, tabName) {
  var sheet = ss.getSheetByName(tabName);
  var isBooking = (tabName === TAB_BOOKINGS);

  if (!sheet) {
    sheet = ss.insertSheet(tabName);
    if (isBooking) {
      styleBookingHeaderRow(sheet);
    } else {
      styleStandardHeaderRow(sheet);
    }
  } else {
    if (sheet.getLastRow() === 0) {
      if (isBooking) {
        styleBookingHeaderRow(sheet);
      } else {
        styleStandardHeaderRow(sheet);
      }
    }
  }
  return sheet;
}

/**
 * One-click initialization: Run this once to prepare all tabs and formatting!
 */
function setupSheets() {
  var ss = getSpreadsheet();
  getOrCreateTab(ss, TAB_ENQUIRIES);
  getOrCreateTab(ss, TAB_CONTACT);
  getOrCreateTab(ss, TAB_BOOKINGS);
  getOrCreateTab(ss, TAB_MASTER);
  
  // Clean up default empty 'Sheet1' if it has no data
  var sheet1 = ss.getSheetByName("Sheet1");
  if (sheet1 && sheet1.getLastRow() <= 1 && ss.getSheets().length > 1) {
    try { ss.deleteSheet(sheet1); } catch (e) {}
  }
  
  Logger.log("✅ All Rhythmic Beat tabs created and formatted successfully!");
}

/**
 * Format timestamp nicely into Indian Standard Time (IST)
 */
function formatTimestamp(isoString) {
  var date = new Date();
  if (isoString) {
    var str = String(isoString).trim();
    // If valid ISO string with timezone indicator (+ or Z or T)
    if (str.indexOf("+") !== -1 || str.indexOf("Z") !== -1) {
      var parsed = new Date(str);
      if (!isNaN(parsed.getTime())) {
        date = parsed;
      }
    } else if (str.indexOf("T") !== -1) {
      var parsedT = new Date(str);
      if (!isNaN(parsedT.getTime())) {
        date = parsedT;
      }
    }
  }
  return Utilities.formatDate(date, "Asia/Kolkata", "dd MMM yyyy, hh:mm a");
}

/**
 * Map 'kind' into human-readable category
 */
function categorizeKind(kind) {
  var k = String(kind || "").toLowerCase().trim();
  if (k === "contact") return "Contact Form";
  if (k === "trial") return "Free Trial Class";
  if (k === "franchise") return "Franchise Request";
  if (k === "booking") return "Workshop Booking";
  if (k === "workshop enquiry") return "Workshop Enquiry";
  return "Course Enquiry";
}

/**
 * Main Webhook POST Handler
 * Receives JSON or Form Post from send-mail.php, razorpay-verify.php, or frontend forms
 */
function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.tryLock(15000);

  try {
    var data = {};

    // 1. Parse incoming payload
    if (e && e.postData && e.postData.contents) {
      try {
        data = JSON.parse(e.postData.contents);
      } catch (err) {
        data = e.parameter || {};
      }
    } else if (e && e.parameter) {
      data = e.parameter;
    }

    // 2. Extract and sanitize fields
    var kind      = String(data.kind || "lead").trim();
    var category  = categorizeKind(kind);
    var name      = String(data.name || "").trim();
    var phone     = String(data.phone || "").trim();
    var email     = String(data.email || "").trim();
    var mode      = String(data.mode || (kind === "contact" ? "Offline" : "")).trim();
    var location  = String(data.location || "").trim();
    var msg       = String(data.msg || data.message || "").trim();
    var page      = String(data.page || (kind === "booking" ? "#workshops" : "#home")).trim();
    var timestamp = formatTimestamp(data.at || data.timestamp);

    // Workshop specific fields
    var workshop   = String(data.workshop || "").trim();
    var tier       = String(data.tier || "").trim();
    var qty        = Number(data.qty) || 1;
    var amount     = Number(data.amount) || 0;
    var ref        = String(data.ref || "").trim();
    var method     = String(data.method || "Razorpay").trim();
    var payment_id = String(data.payment_id || "").trim();
    var order_id   = String(data.order_id || "").trim();
    var status     = String(data.status || (kind === "booking" ? "Paid & Confirmed" : "New Lead")).trim();

    // Format courses list
    var courses = "";
    if (Array.isArray(data.courses)) {
      courses = data.courses.join(", ");
    } else if (data.courses) {
      courses = String(data.courses);
    }
    if (!courses && workshop) {
      courses = "Workshops";
    }

    // Extract additional info summary
    var extra = [];
    if (data.city) extra.push("City: " + data.city);
    if (data.format) extra.push("Format: " + data.format);
    if (workshop) extra.push("Workshop: " + workshop);
    if (tier) extra.push("Tier: " + tier + (qty > 1 ? " × " + qty : ""));
    if (amount) extra.push("Amount: ₹" + amount);
    if (ref) extra.push("Ref: " + ref);
    if (payment_id) extra.push("PayID: " + payment_id);
    var extraDetails = extra.join(" | ");

    // Preserve phone formatting (prevents Google Sheets trimming + or 0)
    var phoneForSheet = phone ? "'" + phone : "";

    var ss = getSpreadsheet();
    var lastRowAdded = 0;
    var wasDeduplicated = false;

    // -------------------------------------------------------------
    // Case A: Workshop Booking -> Save to Dedicated Workshop Bookings Tab
    // -------------------------------------------------------------
    if (kind === "booking") {
      var bkSheet = getOrCreateTab(ss, TAB_BOOKINGS);
      var bkRowData = [
        timestamp,
        ref,
        name,
        phoneForSheet,
        email,
        workshop,
        tier,
        qty,
        amount,
        method,
        payment_id,
        order_id,
        status
      ];

      // DEDUPLICATION: Check if this booking reference or payment ID is already recorded
      var existingBkRow = -1;
      if (ref) {
        existingBkRow = findRowByValue(bkSheet, 2, ref); // Column 2 = Booking Ref
      }
      if (existingBkRow === -1 && payment_id) {
        existingBkRow = findRowByValue(bkSheet, 11, payment_id); // Column 11 = Payment ID
      }

      if (existingBkRow > 0) {
        // Row already exists! Update with the most complete details (e.g. updated payment method)
        bkSheet.getRange(existingBkRow, 1, 1, BOOKING_HEADERS.length).setValues([bkRowData]);
        lastRowAdded = existingBkRow;
        wasDeduplicated = true;
      } else {
        // New booking: append row
        bkSheet.appendRow(bkRowData);
        var bkRowNum = bkSheet.getLastRow();
        lastRowAdded = bkRowNum;

        // Styling booking row
        var bkRange = bkSheet.getRange(bkRowNum, 1, 1, BOOKING_HEADERS.length);
        bkRange.setVerticalAlignment("middle");
        bkRange.setFontSize(9.5);

        // Highlight Ref
        var refCell = bkSheet.getRange(bkRowNum, 2);
        refCell.setFontWeight("bold");
        refCell.setFontColor("#0B4EA2");

        // Format Amount Currency
        var amtCell = bkSheet.getRange(bkRowNum, 9);
        amtCell.setNumberFormat("₹#,##0.00");
        amtCell.setFontWeight("bold");
        amtCell.setFontColor("#F47B20");

        // Format Status Badge
        var bkStatusCell = bkSheet.getRange(bkRowNum, 13);
        bkStatusCell.setBackground("#E8F5E9");
        bkStatusCell.setFontColor("#1B5E20");
        bkStatusCell.setFontWeight("bold");
        bkStatusCell.setHorizontalAlignment("center");
      }
    }

    // -------------------------------------------------------------
    // Case B: Standard Forms (Enquiries, Contact)
    // -------------------------------------------------------------
    if (category === "Contact Form") {
      var cSheet = getOrCreateTab(ss, TAB_CONTACT);
      var cRowData = [
        timestamp, category, name, phoneForSheet, email,
        courses, mode, location, msg, page, extraDetails, status
      ];

      // Prevent quick accidental double submits
      var cLastRow = cSheet.getLastRow();
      var isDuplicateContact = false;
      if (cLastRow >= 2) {
        var lastPhone = String(cSheet.getRange(cLastRow, 4).getValue()).replace(/'/g, '').trim();
        var lastName  = String(cSheet.getRange(cLastRow, 3).getValue()).trim();
        if (lastPhone === phone && lastName.toLowerCase() === name.toLowerCase()) {
          isDuplicateContact = true;
          cSheet.getRange(cLastRow, 1, 1, HEADERS.length).setValues([cRowData]);
          lastRowAdded = cLastRow;
          wasDeduplicated = true;
        }
      }

      if (!isDuplicateContact) {
        cSheet.appendRow(cRowData);
        var cRowNum = cSheet.getLastRow();
        lastRowAdded = cRowNum;

        var cRange = cSheet.getRange(cRowNum, 1, 1, HEADERS.length);
        cRange.setVerticalAlignment("middle");
        cRange.setFontSize(9.5);
        cSheet.getRange(cRowNum, 9).setWrap(true);

        var cCat = cSheet.getRange(cRowNum, 2);
        cCat.setBackground("#FFF4EA");
        cCat.setFontColor("#C2410C");
        cCat.setFontWeight("bold");

        var cStatus = cSheet.getRange(cRowNum, 12);
        cStatus.setBackground("#E8F5E9");
        cStatus.setFontColor("#1B5E20");
        cStatus.setFontWeight("bold");
        cStatus.setHorizontalAlignment("center");
      }

    } else if (kind !== "booking") {
      // Course Enquiry, Free Trial, Franchise
      var eSheet = getOrCreateTab(ss, TAB_ENQUIRIES);
      var eRowData = [
        timestamp, category, name, phoneForSheet, email,
        courses, mode, location, msg, page, extraDetails, status
      ];

      // Prevent quick accidental double submits
      var eLastRow = eSheet.getLastRow();
      var isDuplicateEnquiry = false;
      if (eLastRow >= 2) {
        var lastEPhone = String(eSheet.getRange(eLastRow, 4).getValue()).replace(/'/g, '').trim();
        var lastEName  = String(eSheet.getRange(eLastRow, 3).getValue()).trim();
        if (lastEPhone === phone && lastEName.toLowerCase() === name.toLowerCase()) {
          isDuplicateEnquiry = true;
          eSheet.getRange(eLastRow, 1, 1, HEADERS.length).setValues([eRowData]);
          lastRowAdded = eLastRow;
          wasDeduplicated = true;
        }
      }

      if (!isDuplicateEnquiry) {
        eSheet.appendRow(eRowData);
        var eRowNum = eSheet.getLastRow();
        lastRowAdded = eRowNum;

        var eRange = eSheet.getRange(eRowNum, 1, 1, HEADERS.length);
        eRange.setVerticalAlignment("middle");
        eRange.setFontSize(9.5);
        eSheet.getRange(eRowNum, 9).setWrap(true);

        var eCat = eSheet.getRange(eRowNum, 2);
        eCat.setBackground(category === "Free Trial Class" ? "#EEF4FC" : "#F6F8FB");
        eCat.setFontColor("#0B4EA2");
        eCat.setFontWeight("bold");

        var eStatus = eSheet.getRange(eRowNum, 12);
        eStatus.setBackground("#E8F5E9");
        eStatus.setFontColor("#1B5E20");
        eStatus.setFontWeight("bold");
        eStatus.setHorizontalAlignment("center");
      }
    }

    // -------------------------------------------------------------
    // Always append/update in TAB_MASTER ("All Submissions")
    // -------------------------------------------------------------
    var mSheet = getOrCreateTab(ss, TAB_MASTER);
    var mRowData = [
      timestamp, category, name, phoneForSheet, email,
      courses, mode, location, msg, page, extraDetails, status
    ];

    // DEDUPLICATION: Check if ref already exists in master tab
    var existingMRow = -1;
    if (ref && mSheet.getLastRow() >= 2) {
      var mData = mSheet.getRange(2, 11, mSheet.getLastRow() - 1, 1).getValues();
      for (var mr = 0; mr < mData.length; mr++) {
        if (String(mData[mr][0]).indexOf(ref) !== -1) {
          existingMRow = mr + 2;
          break;
        }
      }
    }

    if (existingMRow > 0) {
      mSheet.getRange(existingMRow, 1, 1, HEADERS.length).setValues([mRowData]);
    } else {
      mSheet.appendRow(mRowData);
      var mRowNum = mSheet.getLastRow();

      var mRange = mSheet.getRange(mRowNum, 1, 1, HEADERS.length);
      mRange.setVerticalAlignment("middle");
      mRange.setFontSize(9.5);
      mSheet.getRange(mRowNum, 9).setWrap(true);

      var mCat = mSheet.getRange(mRowNum, 2);
      if (category === "Workshop Booking") {
        mCat.setBackground("#FFF4EA");
        mCat.setFontColor("#D9650E");
        mCat.setFontWeight("bold");
      } else if (category === "Contact Form") {
        mCat.setBackground("#FFF4EA");
        mCat.setFontColor("#C2410C");
        mCat.setFontWeight("bold");
      } else {
        mCat.setBackground("#EEF4FC");
        mCat.setFontColor("#0B4EA2");
        mCat.setFontWeight("bold");
      }

      var mStatus = mSheet.getRange(mRowNum, 12);
      mStatus.setBackground("#E8F5E9");
      mStatus.setFontColor("#1B5E20");
      mStatus.setFontWeight("bold");
      mStatus.setHorizontalAlignment("center");
    }

    // Return success response
    var response = {
      status: "success",
      message: wasDeduplicated ? "Record updated (deduplicated)" : "Recorded successfully in Google Sheet",
      spreadsheet_id: SPREADSHEET_ID,
      category: category,
      name: name,
      row: lastRowAdded,
      timestamp: timestamp,
      deduplicated: wasDeduplicated
    };

    return ContentService
      .createTextOutput(JSON.stringify(response))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (err) {
    var errorResponse = {
      status: "error",
      message: err.toString(),
      spreadsheet_id: SPREADSHEET_ID
    };

    return ContentService
      .createTextOutput(JSON.stringify(errorResponse))
      .setMimeType(ContentService.MimeType.JSON);

  } finally {
    lock.releaseLock();
  }
}

/**
 * GET Handler - Health Check & Diagnostics
 */
function doGet(e) {
  var ss = getSpreadsheet();
  var info = {
    status: "active",
    message: "Rhythmic Beat Dance Court - Google Sheet Form Sync Webhook is running!",
    spreadsheet_id: SPREADSHEET_ID,
    spreadsheet_title: ss.getName(),
    sheets: ss.getSheets().map(function(s) { return s.getName(); }),
    current_time_ist: formatTimestamp(new Date().toISOString())
  };

  return ContentService
    .createTextOutput(JSON.stringify(info, null, 2))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * Built-in Self Test
 */
function testAddSampleLead() {
  Logger.log("🧪 Running sample lead tests...");
  
  // Test 1: Course Enquiry
  doPost({
    postData: {
      contents: JSON.stringify({
        kind: "lead",
        name: "Priya Sharma (Sample)",
        phone: "+91 98765 43210",
        email: "priya.sample@gmail.com",
        courses: ["Western Dance", "Zumba"],
        mode: "Offline",
        location: "Valasaravakkam",
        msg: "Interested in evening batch timings.",
        page: "#courses",
        at: new Date().toISOString()
      })
    }
  });

  // Test 2: Contact Form
  doPost({
    postData: {
      contents: JSON.stringify({
        kind: "contact",
        name: "Rahul Verma (Sample)",
        phone: "+91 98400 12345",
        email: "rahul.sample@gmail.com",
        courses: ["Gymnastics"],
        mode: "Offline",
        location: "Kolapakkam",
        msg: "Question regarding weekend batch availability.",
        page: "#contact",
        at: new Date().toISOString()
      })
    }
  });

  // Test 3: Workshop Booking
  doPost({
    postData: {
      contents: JSON.stringify({
        kind: "booking",
        name: "Ananya Iyer (Sample Booking)",
        phone: "+91 97910 88888",
        email: "ananya.sample@gmail.com",
        workshop: "Kuthu & Commercial Street Choreography by Master Yuvi",
        tier: "Full Workshop Pass",
        qty: 2,
        amount: 2400,
        ref: "RB-1021-TEST",
        method: "Razorpay (UPI)",
        payment_id: "pay_test_00998877",
        order_id: "order_test_112233",
        status: "Paid & Confirmed",
        page: "#ws-yuvi-commercial",
        at: new Date().toISOString()
      })
    }
  });

  Logger.log("🎉 Sample tests completed! Check your Google Sheet to see the new rows.");
}
