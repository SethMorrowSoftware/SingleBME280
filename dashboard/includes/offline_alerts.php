<?php
/**
 * Per-sensor offline alert engine.
 *
 * Each sensor independently opts in to offline alerting and configures its
 * own threshold, delivery channels (Slack / email), recovery notice, and
 * repeat-reminder interval. State lives in extra columns on the `sensors`
 * table; every alert/recovery/reminder/test is recorded in `alert_events`
 * so the dashboard can show an audit timeline.
 *
 * Design notes
 * ------------
 *  - DASHBOARD AS A CHANNEL: a sensor can be alert-enabled with no Slack or
 *    email channel. It still transitions alert state and records history, so
 *    the dashboard's own alert UI works with zero delivery config.
 *  - RACE SAFETY: every state transition (go-offline, reminder, recovery) is
 *    an atomic conditional UPDATE. Whoever's UPDATE affects the row "wins"
 *    and is the one that sends — so concurrent workers (submit.php piggyback,
 *    sensors.php piggyback, cron) never double-send.
 *  - DELIVERY RELIABILITY: when a sensor has external channels configured but
 *    every one fails at go-offline time, the claim is rolled back so the next
 *    check retries. Dashboard-only sensors are never rolled back.
 *  - FAIL-SAFE: all DB/transport errors are caught and logged; this module
 *    never throws into its callers (ingestion, dashboard refreshes).
 *
 * Backwards compatibility: the public functions used by existing endpoints
 * (offline_alerts_enabled, offline_alerts_check, maybe_offline_alerts_check,
 * offline_alerts_clear_flag) keep their names and behaviour contracts.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notify.php';

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

/**
 * Ensure the per-sensor alert columns and the alert_events table exist.
 * Idempotent and cheap on the common (already-migrated) path: a single
 * `LIMIT 0` probe of the newest column decides whether any ALTERs are needed,
 * so we don't fire a dozen throwing ALTERs on every request.
 */
function alerts_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) {
        return;
    }

    $needsColumns = false;
    try {
        // Probe the most-recently-added column. If this succeeds, every alert
        // column is present (they're always added together below).
        $db->query("SELECT alert_started_at FROM sensors LIMIT 0");
    } catch (PDOException $e) {
        $needsColumns = true;
    }

    if ($needsColumns) {
        $alters = [
            "ALTER TABLE sensors ADD COLUMN alerts_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE sensors ADD COLUMN alert_offline_minutes INT NULL",
            "ALTER TABLE sensors ADD COLUMN alert_slack TINYINT(1) NOT NULL DEFAULT 1",
            "ALTER TABLE sensors ADD COLUMN alert_email TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE sensors ADD COLUMN alert_email_to VARCHAR(255) NULL",
            "ALTER TABLE sensors ADD COLUMN alert_recovery TINYINT(1) NOT NULL DEFAULT 1",
            "ALTER TABLE sensors ADD COLUMN alert_repeat_minutes INT NOT NULL DEFAULT 0",
            // offline_alerted predates this engine; keep the ADD for installs
            // that never had the original Slack feature.
            "ALTER TABLE sensors ADD COLUMN offline_alerted TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE sensors ADD COLUMN alert_count INT NOT NULL DEFAULT 0",
            "ALTER TABLE sensors ADD COLUMN alert_last_sent DATETIME NULL",
            "ALTER TABLE sensors ADD COLUMN alert_started_at DATETIME NULL",
        ];
        foreach ($alters as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                // Column already exists (partial prior migration) – fine.
            }
        }
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS alert_events (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                sensor_id   VARCHAR(100) NOT NULL,
                event_type  VARCHAR(20)  NOT NULL,
                channels    VARCHAR(64)  NOT NULL DEFAULT '',
                message     VARCHAR(255) NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sensor_time (sensor_id, created_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        error_log('alerts_ensure_schema alert_events error: ' . $e->getMessage());
    }

    $done = true;
}

// ---------------------------------------------------------------------------
// Config helpers
// ---------------------------------------------------------------------------

/** The global default offline threshold (minutes), clamped to >= 1. */
function alerts_global_default_minutes(): int {
    $m = defined('OFFLINE_ALERT_MINUTES') ? (int)OFFLINE_ALERT_MINUTES : 60;
    return $m < 1 ? 60 : $m;
}

/** Effective per-sensor threshold: the sensor's own value, or the default. */
function alerts_effective_minutes(array $row): int {
    $m = isset($row['alert_offline_minutes']) ? (int)$row['alert_offline_minutes'] : 0;
    return $m > 0 ? $m : alerts_global_default_minutes();
}

/**
 * Friendly label for a sensor row (display name, falling back to its id).
 */
function alerts_label(array $row): string {
    $loc = isset($row['location_name']) ? (string)$row['location_name'] : '';
    return $loc !== '' ? $loc : (string)$row['sensor_id'];
}

// ---------------------------------------------------------------------------
// Message building + dispatch
// ---------------------------------------------------------------------------

/**
 * Build [subject, slackText, emailBody] for an event kind.
 * $kind: 'offline' | 'reminder' | 'recovery' | 'test'
 */
function alerts_build_message(array $row, string $kind, int $minutes): array {
    $label    = alerts_label($row);
    $sid      = (string)$row['sensor_id'];
    $lastSeen = isset($row['last_seen']) && $row['last_seen'] ? (string)$row['last_seen'] : 'unknown';
    $now      = date('Y-m-d H:i:s');

    switch ($kind) {
        case 'offline':
            return [
                "[ALERT] Sensor offline: {$label}",
                ":warning: Sensor *{$label}* is OFFLINE — no data for over {$minutes} min (last seen {$lastSeen}).",
                "Sensor:   {$label} ({$sid})\nStatus:   OFFLINE\nDetail:   No data received for more than {$minutes} minutes.\nLast seen: {$lastSeen}\nDetected: {$now}\n",
            ];
        case 'reminder':
            return [
                "[ALERT] Still offline: {$label}",
                ":warning: Reminder — sensor *{$label}* is STILL OFFLINE (last seen {$lastSeen}).",
                "Sensor:   {$label} ({$sid})\nStatus:   STILL OFFLINE\nDetail:   This sensor has not reported since {$lastSeen}.\nAs of:    {$now}\n",
            ];
        case 'recovery':
            return [
                "[RESOLVED] Sensor back online: {$label}",
                ":white_check_mark: Sensor *{$label}* is back ONLINE (recovered {$now}).",
                "Sensor:   {$label} ({$sid})\nStatus:   RECOVERED\nDetail:   The sensor is reporting again.\nRecovered: {$now}\n",
            ];
        case 'test':
        default:
            return [
                "[TEST] Sensor alert test: {$label}",
                ":bell: Test alert for sensor *{$label}* — your offline-alert delivery is working.",
                "Sensor:   {$label} ({$sid})\nStatus:   TEST\nDetail:   This is a test of the dashboard's offline-alert delivery.\nSent:     {$now}\n",
            ];
    }
}

/**
 * Deliver an event to the sensor's configured channels.
 *
 * @param string|null $onlyChannel For tests: 'slack' | 'email' | 'both' to
 *        bypass the per-sensor toggles. Null = honour the toggles.
 * @return array{0:string[],1:array<string,string>} [deliveredChannels, errorsByChannel]
 */
function alerts_dispatch(array $row, string $kind, int $minutes, ?string $onlyChannel = null): array {
    list($subject, $slackText, $emailBody) = alerts_build_message($row, $kind, $minutes);

    $delivered = [];
    $errors    = [];

    $wantSlack = ($onlyChannel === null || $onlyChannel === 'slack' || $onlyChannel === 'both');
    $wantEmail = ($onlyChannel === null || $onlyChannel === 'email' || $onlyChannel === 'both');

    // Honour per-sensor channel toggles unless this is an explicit test.
    if ($onlyChannel === null) {
        $wantSlack = $wantSlack && !empty($row['alert_slack']);
        $wantEmail = $wantEmail && !empty($row['alert_email']);
    }

    if ($wantSlack) {
        if (!notify_slack_enabled()) {
            $errors['slack'] = 'Slack delivery not configured on the server';
        } else {
            $err = null;
            if (notify_slack($slackText, $err)) {
                $delivered[] = 'slack';
            } else {
                $errors['slack'] = $err ?: 'Slack send failed';
            }
        }
    }

    if ($wantEmail) {
        $to = isset($row['alert_email_to']) ? trim((string)$row['alert_email_to']) : '';
        if ($to === '' && defined('ALERT_EMAIL_TO')) {
            $to = (string)ALERT_EMAIL_TO;
        }
        if (!notify_email_enabled()) {
            $errors['email'] = 'Email delivery not configured on the server';
        } elseif ($to === '') {
            $errors['email'] = 'No recipient set for this sensor or as a default';
        } else {
            $err = null;
            if (notify_email($to, $subject, $emailBody, $err)) {
                $delivered[] = 'email';
            } else {
                $errors['email'] = $err ?: 'Email send failed';
            }
        }
    }

    return [$delivered, $errors];
}

/** Record a row in alert_events. Never throws. */
function alerts_record_event(PDO $db, string $sid, string $type, array $channels, ?string $message = null): void {
    try {
        $chan = implode(',', $channels);
        if ($chan === '') {
            $chan = 'dashboard';
        }
        $stmt = $db->prepare(
            "INSERT INTO alert_events (sensor_id, event_type, channels, message, created_at)
             VALUES (:sid, :type, :chan, :msg, NOW())"
        );
        $stmt->execute([
            ':sid'  => $sid,
            ':type' => substr($type, 0, 20),
            ':chan' => substr($chan, 0, 64),
            ':msg'  => $message !== null ? substr($message, 0, 255) : null,
        ]);
    } catch (PDOException $e) {
        error_log('alerts_record_event error: ' . $e->getMessage());
    }
}

/** True if the sensor has at least one external channel that could deliver. */
function alerts_has_external_channel(array $row): bool {
    if (!empty($row['alert_slack']) && notify_slack_enabled()) {
        return true;
    }
    if (!empty($row['alert_email']) && notify_email_enabled()) {
        $to = isset($row['alert_email_to']) ? trim((string)$row['alert_email_to']) : '';
        if ($to === '' && defined('ALERT_EMAIL_TO')) {
            $to = (string)ALERT_EMAIL_TO;
        }
        if ($to !== '') {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// The engine
// ---------------------------------------------------------------------------

/**
 * Columns the engine needs for each candidate sensor.
 */
function alerts_sensor_columns(): string {
    return 's.sensor_id, s.location_name, s.last_seen, s.alerts_enabled,
            s.alert_offline_minutes, s.alert_slack, s.alert_email, s.alert_email_to,
            s.alert_recovery, s.alert_repeat_minutes, s.offline_alerted,
            s.alert_count, s.alert_last_sent, s.alert_started_at';
}

/**
 * Check every alert-enabled sensor and fire go-offline alerts + reminders.
 *
 * @return int Number of alert/reminder messages dispatched this pass.
 */
function offline_alerts_check(): int {
    $sent = 0;
    try {
        $db = get_db();
        alerts_ensure_schema($db);

        $stmt = $db->query(
            "SELECT " . alerts_sensor_columns() . "
             FROM sensors s
             WHERE s.alerts_enabled = 1 AND s.last_seen IS NOT NULL"
        );
        $rows = $stmt->fetchAll();
        if (!$rows) {
            // Occasionally prune history even when nothing is alerting.
            if (mt_rand(1, 50) === 1) {
                alerts_cleanup_history($db);
            }
            return 0;
        }

        $nowTs = time();

        // Prepared statements reused across the loop.
        $claimOffline = $db->prepare(
            "UPDATE sensors
                SET offline_alerted = 1, alert_started_at = NOW(),
                    alert_last_sent = NOW(), alert_count = 1
              WHERE sensor_id = :sid AND alerts_enabled = 1
                AND (offline_alerted = 0 OR offline_alerted IS NULL)
                AND last_seen < DATE_SUB(NOW(), INTERVAL :mins MINUTE)"
        );
        $rollback = $db->prepare(
            "UPDATE sensors
                SET offline_alerted = 0, alert_started_at = NULL,
                    alert_last_sent = NULL, alert_count = 0
              WHERE sensor_id = :sid"
        );
        $claimReminder = $db->prepare(
            "UPDATE sensors
                SET alert_last_sent = NOW(), alert_count = alert_count + 1
              WHERE sensor_id = :sid AND offline_alerted = 1
                AND alert_repeat_minutes > 0
                AND (alert_last_sent IS NULL
                     OR alert_last_sent < DATE_SUB(NOW(), INTERVAL alert_repeat_minutes MINUTE))"
        );

        foreach ($rows as $row) {
            $sid  = (string)$row['sensor_id'];
            $mins = alerts_effective_minutes($row);

            // Is it currently offline past its own threshold?
            $lastSeenTs = strtotime((string)$row['last_seen']);
            $isOffline  = ($lastSeenTs !== false) && (($nowTs - $lastSeenTs) >= $mins * 60);
            if (!$isOffline) {
                continue;
            }

            $alreadyAlerting = !empty($row['offline_alerted']);

            if (!$alreadyAlerting) {
                // --- New outage: claim, then send. ---
                try {
                    $claimOffline->execute([':sid' => $sid, ':mins' => $mins]);
                } catch (PDOException $e) {
                    error_log('alerts claim(offline) failed: ' . $e->getMessage());
                    continue;
                }
                if ($claimOffline->rowCount() !== 1) {
                    continue; // another worker won, or no longer eligible
                }

                list($delivered, $errs) = alerts_dispatch($row, 'offline', $mins);

                // If external channels were expected but all failed, roll the
                // claim back so the next pass retries delivery.
                if (alerts_has_external_channel($row) && count($delivered) === 0) {
                    try { $rollback->execute([':sid' => $sid]); } catch (PDOException $e) {}
                    if ($errs) {
                        error_log('alerts offline delivery failed for ' . $sid . ': ' . json_encode($errs));
                    }
                    continue;
                }

                alerts_record_event($db, $sid, 'offline', $delivered, "Offline > {$mins} min");
                $sent++;

            } elseif ((int)$row['alert_repeat_minutes'] > 0) {
                // --- Still offline: maybe send a repeat reminder. ---
                try {
                    $claimReminder->execute([':sid' => $sid]);
                } catch (PDOException $e) {
                    error_log('alerts claim(reminder) failed: ' . $e->getMessage());
                    continue;
                }
                if ($claimReminder->rowCount() !== 1) {
                    continue; // not due yet, or another worker sent it
                }

                list($delivered, $errs) = alerts_dispatch($row, 'reminder', $mins);
                alerts_record_event($db, $sid, 'reminder', $delivered, 'Still offline');
                if ($errs && count($delivered) === 0) {
                    error_log('alerts reminder delivery failed for ' . $sid . ': ' . json_encode($errs));
                }
                $sent++;
            }
        }

        if (mt_rand(1, 50) === 1) {
            alerts_cleanup_history($db);
        }

    } catch (PDOException $e) {
        error_log('offline_alerts_check DB error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('offline_alerts_check error: ' . $e->getMessage());
    }

    return $sent;
}

/**
 * Handle a fresh reading from a sensor: if it was in an alert state, clear it
 * and (if recovery notices are enabled) send a "back online" message.
 *
 * Called by submit.php AFTER last_seen has been bumped to now.
 */
function alerts_on_reading(PDO $db, string $sensorId): void {
    try {
        alerts_ensure_schema($db);

        $stmt = $db->prepare(
            "SELECT " . alerts_sensor_columns() . "
             FROM sensors s WHERE s.sensor_id = :sid"
        );
        $stmt->execute([':sid' => $sensorId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['offline_alerted'])) {
            return; // wasn't alerting – nothing to do
        }

        // Atomically claim the recovery so only one worker notifies.
        $claim = $db->prepare(
            "UPDATE sensors
                SET offline_alerted = 0, alert_count = 0,
                    alert_last_sent = NULL, alert_started_at = NULL
              WHERE sensor_id = :sid AND offline_alerted = 1"
        );
        $claim->execute([':sid' => $sensorId]);
        if ($claim->rowCount() !== 1) {
            return; // someone else handled the recovery
        }

        $delivered = [];
        if (!empty($row['alert_recovery'])) {
            list($delivered, $errs) = alerts_dispatch($row, 'recovery', 0);
            if ($errs && count($delivered) === 0) {
                error_log('alerts recovery delivery failed for ' . $sensorId . ': ' . json_encode($errs));
            }
        }
        // Always log the recovery for the audit timeline, even if the sensor
        // opted out of recovery *notifications*.
        alerts_record_event($db, $sensorId, 'recovery', $delivered, 'Sensor reporting again');

    } catch (PDOException $e) {
        error_log('alerts_on_reading error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('alerts_on_reading error: ' . $e->getMessage());
    }
}

/**
 * Send a one-off test notification for a sensor over the requested channel(s).
 *
 * @param string $which 'slack' | 'email' | 'both'
 * @return array{delivered:string[],errors:array<string,string>}
 */
function alerts_send_test(PDO $db, string $sensorId, string $which = 'both'): array {
    alerts_ensure_schema($db);

    $stmt = $db->prepare(
        "SELECT " . alerts_sensor_columns() . "
         FROM sensors s WHERE s.sensor_id = :sid"
    );
    $stmt->execute([':sid' => $sensorId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['delivered' => [], 'errors' => ['_' => 'Sensor not found']];
    }

    if (!in_array($which, ['slack', 'email', 'both'], true)) {
        $which = 'both';
    }

    list($delivered, $errors) = alerts_dispatch($row, 'test', alerts_effective_minutes($row), $which);
    alerts_record_event($db, $sensorId, 'test', $delivered, 'Test alert');

    return ['delivered' => $delivered, 'errors' => $errors];
}

/**
 * Return recent alert events (newest first), optionally filtered to one sensor.
 *
 * @return array<int,array<string,mixed>>
 */
function alerts_history(PDO $db, ?string $sensorId, int $limit): array {
    alerts_ensure_schema($db);
    $limit = max(1, min(500, $limit));

    if ($sensorId !== null && $sensorId !== '') {
        $stmt = $db->prepare(
            "SELECT e.id, e.sensor_id, e.event_type, e.channels, e.message, e.created_at,
                    s.location_name
               FROM alert_events e
               LEFT JOIN sensors s ON s.sensor_id = e.sensor_id
              WHERE e.sensor_id = :sid
              ORDER BY e.id DESC
              LIMIT {$limit}"
        );
        $stmt->execute([':sid' => $sensorId]);
    } else {
        $stmt = $db->query(
            "SELECT e.id, e.sensor_id, e.event_type, e.channels, e.message, e.created_at,
                    s.location_name
               FROM alert_events e
               LEFT JOIN sensors s ON s.sensor_id = e.sensor_id
              ORDER BY e.id DESC
              LIMIT {$limit}"
        );
    }

    $tz   = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC');
    $out  = [];
    foreach ($stmt->fetchAll() as $r) {
        $iso = null;
        if (!empty($r['created_at'])) {
            try { $iso = (new DateTime($r['created_at'], $tz))->format('c'); }
            catch (Exception $e) { $iso = null; }
        }
        $out[] = [
            'id'            => (int)$r['id'],
            'sensor_id'     => $r['sensor_id'],
            'location_name' => $r['location_name'] !== null && $r['location_name'] !== '' ? $r['location_name'] : $r['sensor_id'],
            'event_type'    => $r['event_type'],
            'channels'      => $r['channels'],
            'message'       => $r['message'],
            'created_at'    => $iso,
        ];
    }
    return $out;
}

/** Delete alert events older than ALERT_HISTORY_RETENTION_DAYS. */
function alerts_cleanup_history(PDO $db): void {
    $days = defined('ALERT_HISTORY_RETENTION_DAYS') ? (int)ALERT_HISTORY_RETENTION_DAYS : 90;
    if ($days < 1) {
        return;
    }
    try {
        $stmt = $db->prepare("DELETE FROM alert_events WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
        $stmt->execute([':days' => $days]);
    } catch (PDOException $e) {
        // Non-fatal.
    }
}

// ---------------------------------------------------------------------------
// Probabilistic + compatibility wrappers
// ---------------------------------------------------------------------------

/**
 * True if any external delivery channel is configured on the server. Used by
 * endpoints to report capability; the engine itself runs regardless because
 * the dashboard is a valid (zero-config) alert channel.
 */
function offline_alerts_enabled(): bool {
    return notify_slack_enabled() || notify_email_enabled();
}

/**
 * Probabilistic wrapper so busy endpoints (submit.php, sensors.php) only pay
 * the cost of an alert sweep occasionally rather than on every request.
 */
function maybe_offline_alerts_check(int $oneIn = 10): void {
    if (mt_rand(1, max(1, $oneIn)) !== 1) {
        return;
    }
    try {
        offline_alerts_check();
    } catch (Exception $e) {
        error_log('maybe_offline_alerts_check error: ' . $e->getMessage());
    }
}

/**
 * Backwards-compatible alias. submit.php calls this when a sensor reports;
 * it now also fires recovery notifications.
 */
function offline_alerts_clear_flag(PDO $db, string $sensorId): void {
    alerts_on_reading($db, $sensorId);
}
