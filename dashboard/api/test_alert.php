<?php
/**
 * POST /api/test_alert.php
 *
 * Sends a one-off test notification for a sensor so an admin can verify their
 * Slack / email delivery is wired up correctly. Records a 'test' event in the
 * alert history.
 *
 * Body (JSON):
 *   { "sensor_id": "kitchen", "channel": "both" }   // channel: slack|email|both
 *
 * Requires a logged-in dashboard session or a valid X-API-Key.
 *
 * Response:
 *   { "status":"ok", "delivered":["slack"], "errors":{"email":"..."} }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/offline_alerts.php';
auth_require_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['sensor_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'sensor_id is required']);
    exit;
}

$sensorId = trim((string)$data['sensor_id']);
$channel  = isset($data['channel']) ? strtolower(trim((string)$data['channel'])) : 'both';
if (!in_array($channel, ['slack', 'email', 'both'], true)) {
    $channel = 'both';
}

date_default_timezone_set(APP_TIMEZONE);

try {
    $db = get_db();

    $check = $db->prepare("SELECT 1 FROM sensors WHERE sensor_id = :sid");
    $check->execute([':sid' => $sensorId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sensor not found']);
        exit;
    }

    $result = alerts_send_test($db, $sensorId, $channel);

    echo json_encode([
        'status'    => 'ok',
        'delivered' => $result['delivered'],
        'errors'    => (object)$result['errors'],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    error_log('test_alert.php DB error: ' . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    error_log('test_alert.php error: ' . $e->getMessage());
}
