<?php
/**
 * GET /api/sensors.php
 *
 * Returns the list of known sensors with current status and latest reading.
 * Optimised query avoids correlated subqueries so it stays fast with many sensors.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/offline_alerts.php';
auth_require_api();

// Piggyback an offline-alert sweep on dashboard refreshes. Kept probabilistic
// so a momentarily-unreachable Slack/email host can't stall the dashboard on
// every refresh (the sweep dispatches synchronously). A cron hitting
// check_offline.php every minute or two is the reliable driver — see the
// README — this is just a best-effort top-up while someone's watching.
maybe_offline_alerts_check(8);

date_default_timezone_set(APP_TIMEZONE);

try {
    $db = get_db();
    // Make sure the per-sensor alert columns exist before we select them
    // (lazy migration for installs that predate the alerts engine).
    alerts_ensure_schema($db);

    $offlineMinutes = (int)OFFLINE_MINUTES;
    $defaultAlertMinutes = alerts_global_default_minutes();
    $nowTs = time();

    // Fetch latest reading ID per sensor in a single pass
    $sql = "
        SELECT
            s.sensor_id,
            s.sensor_type,
            s.location_name,
            s.ip_address,
            s.last_seen,
            s.last_seen > DATE_SUB(NOW(), INTERVAL :offline MINUTE) AS is_online,
            s.alerts_enabled,
            s.alert_offline_minutes,
            s.alert_slack,
            s.alert_email,
            s.alert_email_to,
            s.alert_recovery,
            s.alert_repeat_minutes,
            s.offline_alerted,
            s.alert_started_at,
            r.temperature_f,
            r.temperature_c,
            r.humidity,
            r.co2
        FROM sensors s
        LEFT JOIN readings r ON r.sensor_id = s.sensor_id
            AND r.id = (
                SELECT r2.id FROM readings r2
                WHERE r2.sensor_id = s.sensor_id
                ORDER BY r2.id DESC
                LIMIT 1
            )
        ORDER BY s.location_name ASC, s.sensor_id ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':offline' => $offlineMinutes]);
    $rows = $stmt->fetchAll();

    $tz = new DateTimeZone(APP_TIMEZONE);

    $sensors = [];
    foreach ($rows as $row) {
        // Convert last_seen to ISO 8601 with timezone so JS can compare correctly
        $lastSeen = $row['last_seen']
            ? (new DateTime($row['last_seen'], $tz))->format('c')
            : null;

        // How long has this sensor been silent (minutes)?
        $offlineForMinutes = null;
        if ($row['last_seen']) {
            $lastSeenTs = strtotime((string)$row['last_seen']);
            if ($lastSeenTs !== false) {
                $offlineForMinutes = max(0, (int)floor(($nowTs - $lastSeenTs) / 60));
            }
        }

        $rawThreshold = isset($row['alert_offline_minutes']) && $row['alert_offline_minutes'] !== null
            ? (int)$row['alert_offline_minutes'] : null;

        $alertStarted = !empty($row['alert_started_at'])
            ? (new DateTime($row['alert_started_at'], $tz))->format('c')
            : null;

        $sensors[] = [
            'sensor_id'     => $row['sensor_id'],
            'sensor_type'   => $row['sensor_type'],
            'location_name' => $row['location_name'],
            'ip_address'    => $row['ip_address'] ?: null,
            'last_seen'     => $lastSeen,
            'online'        => (bool)$row['is_online'],
            'offline_for_minutes' => $offlineForMinutes,
            'latest' => [
                'temperature_f' => $row['temperature_f'] !== null ? (float)$row['temperature_f'] : null,
                'temperature_c' => $row['temperature_c'] !== null ? (float)$row['temperature_c'] : null,
                'humidity'      => $row['humidity'] !== null ? (float)$row['humidity'] : null,
                'co2'           => $row['co2'] !== null ? (int)$row['co2'] : null,
            ],
            'alerts' => [
                'enabled'             => (bool)$row['alerts_enabled'],
                'threshold_minutes'   => $rawThreshold,                                  // null = use default
                'effective_minutes'   => $rawThreshold !== null && $rawThreshold > 0 ? $rawThreshold : $defaultAlertMinutes,
                'slack'               => (bool)$row['alert_slack'],
                'email'               => (bool)$row['alert_email'],
                'email_to'            => $row['alert_email_to'] !== null && $row['alert_email_to'] !== '' ? $row['alert_email_to'] : null,
                'recovery'            => (bool)$row['alert_recovery'],
                'repeat_minutes'      => (int)$row['alert_repeat_minutes'],
                'alerting'            => (bool)$row['offline_alerted'],
                'alert_started_at'    => $alertStarted,
            ],
        ];
    }

    echo json_encode([
        'sensors' => $sensors,
        'defaults' => [
            'offline_alert_minutes' => $defaultAlertMinutes,
            'offline_minutes'       => $offlineMinutes,
            'slack_available'       => notify_slack_enabled(),
            'email_available'       => notify_email_enabled(),
            'default_email_to'      => defined('ALERT_EMAIL_TO') && ALERT_EMAIL_TO !== '' ? ALERT_EMAIL_TO : null,
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    error_log('sensors.php DB error: ' . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('sensors.php error: ' . $e->getMessage());
}
