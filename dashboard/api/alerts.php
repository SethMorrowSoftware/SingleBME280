<?php
/**
 * GET /api/alerts.php
 *
 * Returns the offline-alert overview the dashboard's bell / alerts modal use:
 *   - `active`  : sensors currently in an alert (offline) state
 *   - `history` : recent alert events (offline / reminder / recovery / test)
 *
 * Query parameters:
 *   sensor_id  – filter history to one sensor (optional)
 *   limit      – max history rows (default 100, max 500)
 *
 * Requires a logged-in dashboard session or a valid X-API-Key.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/offline_alerts.php';
auth_require_api();

date_default_timezone_set(APP_TIMEZONE);

$sensorId = isset($_GET['sensor_id']) ? trim((string)$_GET['sensor_id']) : '';
$limit    = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

try {
    $db = get_db();
    alerts_ensure_schema($db);

    $tz = new DateTimeZone(APP_TIMEZONE);
    $nowTs = time();

    // --- Currently active alerts ---
    $stmt = $db->query(
        "SELECT sensor_id, location_name, last_seen, alert_started_at,
                alert_offline_minutes, alert_slack, alert_email, alert_repeat_minutes
           FROM sensors
          WHERE offline_alerted = 1
          ORDER BY alert_started_at ASC, location_name ASC"
    );

    $active = [];
    foreach ($stmt->fetchAll() as $row) {
        $offlineForMinutes = null;
        if ($row['last_seen']) {
            $ts = strtotime((string)$row['last_seen']);
            if ($ts !== false) {
                $offlineForMinutes = max(0, (int)floor(($nowTs - $ts) / 60));
            }
        }
        $active[] = [
            'sensor_id'           => $row['sensor_id'],
            'location_name'       => $row['location_name'] !== '' ? $row['location_name'] : $row['sensor_id'],
            'last_seen'           => $row['last_seen'] ? (new DateTime($row['last_seen'], $tz))->format('c') : null,
            'alert_started_at'    => $row['alert_started_at'] ? (new DateTime($row['alert_started_at'], $tz))->format('c') : null,
            'offline_for_minutes' => $offlineForMinutes,
            'threshold_minutes'   => $row['alert_offline_minutes'] !== null ? (int)$row['alert_offline_minutes'] : alerts_global_default_minutes(),
            'slack'               => (bool)$row['alert_slack'],
            'email'               => (bool)$row['alert_email'],
            'repeat_minutes'      => (int)$row['alert_repeat_minutes'],
        ];
    }

    // --- History ---
    $history = alerts_history($db, $sensorId !== '' ? $sensorId : null, $limit);

    echo json_encode([
        'status'  => 'ok',
        'active'  => $active,
        'history' => $history,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    error_log('alerts.php DB error: ' . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    error_log('alerts.php error: ' . $e->getMessage());
}
