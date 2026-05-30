<?php
/**
 * Dashboard Configuration
 *
 * Copy this file and update the values for your cPanel hosting environment.
 * The API_KEY here must match the dashboard_api_key in each sensor's
 * SingleSensorSettings.conf file.
 */

// --- Database (MySQL on cPanel) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');       // e.g. cpaneluser_sensors
define('DB_USER', 'your_db_user');       // e.g. cpaneluser_dbuser
define('DB_PASS', 'your_db_password');

// --- API authentication ---
define('API_KEY', 'change-me-to-a-secure-random-key');

// --- Dashboard password protection ---
// bcrypt hash of the dashboard password. Generate a new hash with:
//   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
// Default hash below corresponds to password: 109Brookside01!
define('DASHBOARD_PASSWORD_HASH', '$2y$12$AtxQ9ovY5g4E.oTboGvaE.eRHoYNadUnoP/R9qAcX.Ed6Mc9uJcRu');

// Session lifetime in seconds (default: 7 days)
define('SESSION_LIFETIME', 7 * 24 * 60 * 60);

// --- Data retention (days) – readings older than this are auto-purged ---
define('RETENTION_DAYS', 90);

// --- Timezone ---
define('APP_TIMEZONE', 'America/New_York');

// --- Sensor offline threshold (minutes with no data = offline) ---
// Controls the online/offline dot on the dashboard. This is independent of
// alerting (see OFFLINE_ALERT_MINUTES below).
define('OFFLINE_MINUTES', 15);

// =====================================================================
// Per-sensor offline alerts
// =====================================================================
// Offline alerts are configured PER SENSOR from the dashboard's "Manage
// sensors" modal: each sensor can be independently enabled, given its own
// offline threshold, delivered over Slack and/or email, send a recovery
// ("back online") notice, and repeat on an interval while still offline.
//
// The settings below are the shared infrastructure + defaults those
// per-sensor toggles build on. A sensor with alerts enabled but no delivery
// channel configured still shows up in the dashboard's alert UI and history,
// so the dashboard itself acts as a zero-config alert channel.

// --- Slack delivery (shared bot credentials) ---
// Per-sensor "Slack" toggles post to this channel via chat.postMessage.
// Leave SLACK_API_TOKEN blank to make Slack delivery unavailable.
define('SLACK_API_TOKEN', '');               // e.g. xoxb-xxxxx (blank = Slack delivery off)
define('SLACK_CHANNEL', '');                 // e.g. alerts

// --- Email delivery (shared transport) ---
// Per-sensor "Email" toggles send via PHP's mail() using the host's mail
// transport, so it works on most cPanel accounts with no extra libraries.
// For best deliverability, use a FROM address on your own domain so it
// aligns with that domain's SPF/DKIM records.
// Leave ALERT_EMAIL_FROM blank to make email delivery unavailable.
define('ALERT_EMAIL_FROM', '');              // e.g. sensors@yourdomain.com (blank = email delivery off)
define('ALERT_EMAIL_FROM_NAME', 'Sensor Dashboard');
define('ALERT_EMAIL_TO', '');                // default recipient when a sensor doesn't set its own

// --- Default offline threshold (minutes) ---
// Used for any alert-enabled sensor that doesn't set its own threshold.
define('OFFLINE_ALERT_MINUTES', 60);         // minutes offline before alert

// --- Alert history retention (days) – older alert events are auto-purged ---
define('ALERT_HISTORY_RETENTION_DAYS', 90);
