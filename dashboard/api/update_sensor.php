<?php
/**
 * POST /api/update_sensor.php
 *
 * Updates editable fields for a sensor (ip_address, location_name).
 * Requires API key or dashboard session authentication.
 *
 * Expected JSON body (all fields optional except sensor_id):
 * {
 *   "sensor_id":     "kitchen",
 *   "ip_address":    "192.168.1.42",   // or "" to clear
 *   "location_name": "Kitchen Sensor"  // display label
 * }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/offline_alerts.php';

// --- Auth: accept either a logged-in dashboard session or a valid X-API-Key ---
auth_require_api();

/**
 * Coerce a JSON value (bool, int, or "0"/"1"/"true"/"false") to 0/1.
 */
function as_bool_flag($v): int {
    if (is_bool($v))   return $v ? 1 : 0;
    if (is_int($v))    return $v ? 1 : 0;
    $s = strtolower(trim((string)$v));
    return ($s === '1' || $s === 'true' || $s === 'on' || $s === 'yes') ? 1 : 0;
}

// --- Method check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}

// --- Parse body ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['sensor_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'sensor_id is required']);
    exit;
}

$sensorId = trim($data['sensor_id']);

// --- Build update ---
date_default_timezone_set(APP_TIMEZONE);

try {
    $db = get_db();
    // Ensure alert columns exist before we try to UPDATE them.
    alerts_ensure_schema($db);

    // Require the sensor to exist before building any UPDATE.
    $check = $db->prepare("SELECT 1 FROM sensors WHERE sensor_id = :sid");
    $check->execute([':sid' => $sensorId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sensor not found']);
        exit;
    }

    $sets = [];
    $params = [':sid' => $sensorId];

    // ip_address (optional; "" clears it)
    if (array_key_exists('ip_address', $data)) {
        $ip = trim((string)$data['ip_address']);
        if ($ip === '') {
            $sets[] = 'ip_address = NULL';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP)) {
            $sets[] = 'ip_address = :ip';
            $params[':ip'] = substr($ip, 0, 45);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid IP address']);
            exit;
        }
    }

    // location_name (optional; "" resets to sensor_id)
    if (array_key_exists('location_name', $data)) {
        $loc = trim((string)$data['location_name']);
        if ($loc === '') {
            $loc = $sensorId;
        }
        $sets[] = 'location_name = :loc';
        $params[':loc'] = substr($loc, 0, 255);
    }

    // --- Per-sensor offline-alert configuration (all optional) ---

    // Master enable/disable
    if (array_key_exists('alerts_enabled', $data)) {
        $sets[] = 'alerts_enabled = :ae';
        $params[':ae'] = as_bool_flag($data['alerts_enabled']);
    }

    // Offline threshold (minutes). Blank/null clears it → use global default.
    if (array_key_exists('alert_offline_minutes', $data)) {
        $val = $data['alert_offline_minutes'];
        if ($val === null || $val === '' ) {
            $sets[] = 'alert_offline_minutes = NULL';
        } else {
            $mins = (int)$val;
            if ($mins < 1 || $mins > 525600) { // 1 minute .. 1 year
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'alert_offline_minutes must be 1–525600']);
                exit;
            }
            $sets[] = 'alert_offline_minutes = :aom';
            $params[':aom'] = $mins;
        }
    }

    // Channel toggles
    if (array_key_exists('alert_slack', $data)) {
        $sets[] = 'alert_slack = :asl';
        $params[':asl'] = as_bool_flag($data['alert_slack']);
    }
    if (array_key_exists('alert_email', $data)) {
        $sets[] = 'alert_email = :aem';
        $params[':aem'] = as_bool_flag($data['alert_email']);
    }

    // Per-sensor email recipient ("" clears → fall back to global default)
    if (array_key_exists('alert_email_to', $data)) {
        $to = trim((string)$data['alert_email_to']);
        if ($to === '') {
            $sets[] = 'alert_email_to = NULL';
        } elseif (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $sets[] = 'alert_email_to = :aet';
            $params[':aet'] = substr($to, 0, 255);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid alert email address']);
            exit;
        }
    }

    // Recovery ("back online") notifications
    if (array_key_exists('alert_recovery', $data)) {
        $sets[] = 'alert_recovery = :arc';
        $params[':arc'] = as_bool_flag($data['alert_recovery']);
    }

    // Repeat-reminder interval (minutes; 0 = no reminders)
    if (array_key_exists('alert_repeat_minutes', $data)) {
        $val = $data['alert_repeat_minutes'];
        $rep = ($val === null || $val === '') ? 0 : (int)$val;
        if ($rep < 0 || $rep > 525600) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'alert_repeat_minutes must be 0–525600']);
            exit;
        }
        $sets[] = 'alert_repeat_minutes = :arm';
        $params[':arm'] = $rep;
    }

    if (!$sets) {
        // Nothing to update – treat as success rather than 400 so the UI can
        // call this idempotently.
        echo json_encode(['status' => 'ok']);
        exit;
    }

    $sql = 'UPDATE sensors SET ' . implode(', ', $sets) . ' WHERE sensor_id = :sid';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['status' => 'ok']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    error_log('update_sensor.php DB error: ' . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    error_log('update_sensor.php error: ' . $e->getMessage());
}
