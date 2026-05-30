<?php
/**
 * POST /api/submit.php
 *
 * Receives sensor readings from Pi Zero devices.
 *
 * Expected headers:
 *   X-API-Key: <shared secret>
 *   Content-Type: application/json
 *
 * Expected JSON body:
 * {
 *   "sensor_id":     "kitchen",
 *   "sensor_type":   "bme280",        // bme280 | scd40
 *   "temperature_f": 72.5,
 *   "temperature_c": 22.5,
 *   "humidity":      45.2,            // nullable
 *   "co2":           null             // nullable – only SCD40
 * }
 *
 * Response: {"status":"ok"} or {"status":"error","message":"..."}
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/offline_alerts.php';

// --- Auth ---
$apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
if (!hash_equals(API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
    exit;
}

// --- Method check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}

// --- Parse body ---
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty request body']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || empty($data['sensor_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid JSON body']);
    exit;
}

// --- Sanitise & validate ---
$sensorId = substr(trim($data['sensor_id']), 0, 100);
if ($sensorId === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'sensor_id is required']);
    exit;
}

$validTypes = ['bme280', 'scd40'];
$sensorType = (isset($data['sensor_type']) && in_array($data['sensor_type'], $validTypes))
    ? $data['sensor_type']
    : 'bme280';

$temperatureF = isset($data['temperature_f']) ? (float)$data['temperature_f'] : null;
$temperatureC = isset($data['temperature_c']) ? (float)$data['temperature_c'] : null;
$humidity     = isset($data['humidity']) && $data['humidity'] !== null ? (float)$data['humidity'] : null;
$co2          = isset($data['co2']) && $data['co2'] !== null ? (int)$data['co2'] : null;

// Range validation
if ($temperatureF !== null && ($temperatureF < -80 || $temperatureF > 210)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'temperature_f out of range (-80 to 210)']);
    exit;
}
if ($humidity !== null && ($humidity < 0 || $humidity > 100)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'humidity out of range (0-100)']);
    exit;
}
if ($co2 !== null && ($co2 < 0 || $co2 > 40000)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'co2 out of range (0-40000)']);
    exit;
}

date_default_timezone_set(APP_TIMEZONE);

try {
    $db  = get_db();
    $now = date('Y-m-d H:i:s');

    // Prefer local_ip sent by the sensor (LAN address like 192.168.x.x).
    // Fall back to REMOTE_ADDR only if the sensor didn't send its own.
    $ipAddress = null;
    if (!empty($data['local_ip']) && filter_var($data['local_ip'], FILTER_VALIDATE_IP)) {
        $ipAddress = trim(substr($data['local_ip'], 0, 45));
    }

    // Only update IP if the sensor sent one; preserve any manually-set value otherwise.
    // We'll use a flag to skip the ip_address update when there's nothing new.
    $updateIp = ($ipAddress !== null);

    // Upsert sensor record.
    //
    // location_name is set to sensor_id on INSERT, but preserved on UPDATE so
    // a user-provided rename (via the dashboard's manage modal) isn't wiped
    // out by the very next sensor submission. Same for ip_address when the
    // sensor didn't send one this round.
    if ($updateIp) {
        $stmt = $db->prepare("
            INSERT INTO sensors (sensor_id, sensor_type, location_name, ip_address, last_seen)
            VALUES (:sid, :type, :loc, :ip, :seen)
            ON DUPLICATE KEY UPDATE
                sensor_type = VALUES(sensor_type),
                ip_address  = VALUES(ip_address),
                last_seen   = VALUES(last_seen)
        ");
        $stmt->execute([
            ':sid'  => $sensorId,
            ':type' => $sensorType,
            ':loc'  => $sensorId,
            ':ip'   => $ipAddress,
            ':seen' => $now,
        ]);
    } else {
        // No local_ip provided – don't overwrite a manually-set IP
        $stmt = $db->prepare("
            INSERT INTO sensors (sensor_id, sensor_type, location_name, last_seen)
            VALUES (:sid, :type, :loc, :seen)
            ON DUPLICATE KEY UPDATE
                sensor_type = VALUES(sensor_type),
                last_seen   = VALUES(last_seen)
        ");
        $stmt->execute([
            ':sid'  => $sensorId,
            ':type' => $sensorType,
            ':loc'  => $sensorId,
            ':seen' => $now,
        ]);
    }

    // Insert reading
    $stmt = $db->prepare("
        INSERT INTO readings (sensor_id, temperature_f, temperature_c, humidity, co2, recorded_at)
        VALUES (:sid, :tf, :tc, :hum, :co2, :at)
    ");
    $stmt->execute([
        ':sid' => $sensorId,
        ':tf'  => $temperatureF,
        ':tc'  => $temperatureC,
        ':hum' => $humidity,
        ':co2' => $co2,
        ':at'  => $now,
    ]);

    // Probabilistic cleanup of old data
    maybe_cleanup();

    // Sensor just reported – clear any outstanding offline-alert state and,
    // if this sensor opted into recovery notices, send a "back online" alert.
    // No-op if the sensor wasn't in an alert state.
    try {
        offline_alerts_clear_flag($db, $sensorId);
    } catch (Exception $e) {
        // Never let alert bookkeeping break ingestion.
        error_log('submit.php offline flag clear error: ' . $e->getMessage());
    }

    // Probabilistic offline sweep – piggyback on sensor traffic so we don't
    // strictly need a cron. Cheap no-op when no sensors have alerts enabled.
    maybe_offline_alerts_check(20);

    echo json_encode(['status' => 'ok']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    error_log('submit.php DB error: ' . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    error_log('submit.php error: ' . $e->getMessage());
}
