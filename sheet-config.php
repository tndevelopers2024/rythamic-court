<?php
/**
 * Rhythmic Beat Dance Court - Google Sheets Integration Configuration
 * 
 * Target Google Sheet:
 * https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit?usp=sharing
 */

// Prevent direct browser execution
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

return [
    // -------------------------------------------------------------
    // Target Google Sheet Information
    // -------------------------------------------------------------
    'sheet_id'        => '1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c',
    'sheet_url'       => 'https://docs.google.com/spreadsheets/d/1CQNKxVK8hP3mxshpo0I3ObAcsGWI7XnbhokwowVh_6c/edit?usp=sharing',

    // -------------------------------------------------------------
    // Google Apps Script Web App Deployment URL
    // -------------------------------------------------------------
    // Follow the steps in README-GOOGLE-SHEETS.md to deploy the script in your sheet,
    // then paste your Web App URL here (e.g. 'https://script.google.com/macros/s/AKfycb.../exec'):
    'apps_script_url' => getenv('GOOGLE_SHEET_WEBHOOK_URL') ?: 'https://script.google.com/macros/s/AKfycbxbQ5vVBqgyLhbVRWDmqblUTAVB3-lizLzXWWUZqu6jDunHgJwJrUGQCsDU7VE3rJXyiw/exec',

    // -------------------------------------------------------------
    // Synchronization & Sheet Options
    // -------------------------------------------------------------
    // Master switch to enable/disable Google Sheet sync
    'sync_enabled'    => true,

    // How to organize tabs in Google Sheets:
    // 'tabs'   => Separates 'Enquiries' and 'Contact Messages' into dedicated tabs + 'All Submissions'
    // 'single' => Writes all submissions into a single consolidated tab
    'sheet_mode'      => 'tabs',

    // Default tab name if 'single' mode is chosen
    'single_tab_name' => 'All Submissions',

    // Local server backup (ensures zero data loss if Google is temporarily unreachable)
    'local_backup'    => true,
    'backup_file'     => __DIR__ . '/leads-backup.log',

    // Network timeout in seconds for Google Apps Script requests
    'timeout'         => 10,

    // Debug mode: logs verbose connection information to PHP error log
    'debug_mode'      => false,
];
