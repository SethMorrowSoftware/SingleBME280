<?php
/**
 * GET /api/check_offline.php
 *
 * Explicit offline-alert trigger for use from cron. Example cPanel cron:
 *
 *   * /10 * * * * curl -sS -H "X-API-Key: <KEY>" https://yourdomain.com/dashboard/api/check_offline.php
 *
 * Runs the same check that submit.php and sensors.php already run
 * probabilistically. Auth accepts the X-API-Key header OR a logged-in
 * dashboard session.
 *
 * Returns JSON: {"status":"ok","alerts_sent":N,"enabled":true|false}
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/offline_alerts.php';
auth_require_api();

// Always run the engine: alert-enabled sensors are tracked even when no
// external (Slack/email) channel is configured, since the dashboard's own
// alert UI is a valid zero-config channel. `enabled` reports whether any
// external delivery channel is available.
$sent = 0;
try {
    $sent = offline_alerts_check();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Check failed']);
    error_log('check_offline.php error: ' . $e->getMessage());
    exit;
}

echo json_encode([
    'status'          => 'ok',
    'enabled'         => offline_alerts_enabled(),
    'slack_available' => notify_slack_enabled(),
    'email_available' => notify_email_enabled(),
    'alerts_sent'     => $sent,
]);
