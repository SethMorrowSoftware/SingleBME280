<?php
/**
 * Notification dispatch layer.
 *
 * Thin, dependency-free wrappers around the two delivery channels the
 * dashboard supports: Slack (chat.postMessage) and email (PHP mail()).
 *
 * Every function here is safe to call unconditionally:
 *   - If a channel isn't configured, its *_enabled() helper returns false and
 *     the send function is a no-op that returns false.
 *   - All network / transport errors are swallowed and reported via the
 *     optional &$err out-param, never thrown, so callers (the alert engine,
 *     the ingestion endpoint) can never break because a channel is down.
 *
 * Channel credentials are global (config.php). Whether a given sensor uses a
 * channel is decided per-sensor by the alert engine (includes/offline_alerts.php).
 */

require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// Slack
// ---------------------------------------------------------------------------

/** True only when Slack delivery is fully configured with a real token. */
function notify_slack_enabled(): bool {
    if (!defined('SLACK_API_TOKEN') || !defined('SLACK_CHANNEL')) {
        return false;
    }
    $token   = (string)SLACK_API_TOKEN;
    $channel = (string)SLACK_CHANNEL;
    if ($token === '' || $channel === '') {
        return false;
    }
    // Treat the placeholder token shipped in the sample config as disabled
    // (mirrors SingleSensor.py's behaviour).
    if (strpos($token, 'xoxb-slack') === 0) {
        return false;
    }
    return true;
}

/**
 * Post a plain-text message to the configured Slack channel.
 * Returns true on success, false on any failure (reason in &$err).
 */
function notify_slack(string $text, &$err = null): bool {
    if (!notify_slack_enabled()) {
        $err = 'Slack not configured';
        return false;
    }
    $token   = (string)SLACK_API_TOKEN;
    $channel = (string)SLACK_CHANNEL;

    $payload = json_encode(['channel' => $channel, 'text' => $text]);
    if ($payload === false) {
        $err = 'Unable to encode Slack payload';
        return false;
    }

    // Prefer cURL when available; fall back to a stream context otherwise.
    if (function_exists('curl_init')) {
        $ch = curl_init('https://slack.com/api/chat.postMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            $err = 'Slack request failed: ' . $curlErr;
            error_log('notify_slack curl error: ' . $curlErr);
            return false;
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $err = 'Slack API error: ' . (is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : 'unknown');
            error_log('notify_slack API error: ' . $resp);
            return false;
        }
        return true;
    }

    // Stream fallback
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json; charset=utf-8\r\n"
                             . 'Authorization: Bearer ' . $token . "\r\n",
            'content'       => $payload,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents('https://slack.com/api/chat.postMessage', false, $ctx);
    if ($resp === false) {
        $err = 'Slack request failed';
        return false;
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        $err = 'Slack API error';
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------

/** True only when email delivery is configured and mail() is available. */
function notify_email_enabled(): bool {
    if (!defined('ALERT_EMAIL_FROM')) {
        return false;
    }
    if ((string)ALERT_EMAIL_FROM === '') {
        return false;
    }
    return function_exists('mail');
}

/**
 * Send a plain-text email via the host's mail transport.
 * Returns true on success, false on any failure (reason in &$err).
 *
 * Subject and From are sanitised against header injection.
 */
function notify_email(string $to, string $subject, string $body, &$err = null): bool {
    if (!notify_email_enabled()) {
        $err = 'Email not configured';
        return false;
    }

    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid recipient address';
        return false;
    }

    $from     = (string)ALERT_EMAIL_FROM;
    $fromName = defined('ALERT_EMAIL_FROM_NAME') ? (string)ALERT_EMAIL_FROM_NAME : 'Sensor Dashboard';

    // Strip CR/LF (and quotes from the display name) so a crafted subject or
    // name can't inject extra mail headers.
    $subject  = trim(str_replace(["\r", "\n"], ' ', $subject));
    $fromName = str_replace(["\r", "\n", '"'], ' ', $fromName);

    $headers = [];
    $headers[] = 'From: ' . ($fromName !== '' ? '"' . $fromName . '" ' : '') . '<' . $from . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: SingleSensor-Dashboard';

    $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
    if (!$ok) {
        $err = 'mail() returned false (check host mail configuration)';
        error_log('notify_email failed for ' . $to);
    }
    return (bool)$ok;
}
