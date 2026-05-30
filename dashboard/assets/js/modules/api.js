/**
 * Sensor Dashboard — API wrappers.
 */

function getApiKey() {
    const meta = document.querySelector('meta[name="api-key"]');
    if (meta) return meta.getAttribute('content');
    try { return sessionStorage.getItem('dashboard_api_key') || ''; }
    catch (e) { return ''; }
}

function handle401() {
    window.location.href = 'login.php';
    throw new Error('unauthenticated');
}

async function jsonFetch(url, opts) {
    const res = await fetch(url, opts);
    if (res.status === 401) handle401();
    if (!res.ok) throw new Error(url + ' ' + res.status);
    return res.json();
}

export function fetchSensors() {
    // sensors.php returns { sensors: [...], defaults: {...} }. Older callers
    // expected a bare array, so normalize to always expose both.
    return jsonFetch('api/sensors.php').then((res) => {
        if (Array.isArray(res)) return { sensors: res, defaults: {} };
        return { sensors: (res && res.sensors) || [], defaults: (res && res.defaults) || {} };
    });
}

export function fetchReadings(range) {
    return jsonFetch('api/readings.php?range=' + encodeURIComponent(range) + '&sensor_id=all');
}

// -------------------------------------------------------------------------
// Per-sensor offline alerts
// -------------------------------------------------------------------------

/**
 * Update a sensor's alert configuration. `cfg` may contain any of:
 *   alerts_enabled, alert_offline_minutes, alert_slack, alert_email,
 *   alert_email_to, alert_recovery, alert_repeat_minutes
 */
export function updateSensorAlerts(sensorId, cfg) {
    return jsonFetch('api/update_sensor.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': getApiKey(),
        },
        body: JSON.stringify(Object.assign({ sensor_id: sensorId }, cfg)),
    });
}

/** Fetch active alerts + recent alert history. */
export function fetchAlerts(opts) {
    const o = opts || {};
    const params = [];
    if (o.sensorId) params.push('sensor_id=' + encodeURIComponent(o.sensorId));
    if (o.limit)    params.push('limit=' + encodeURIComponent(o.limit));
    const qs = params.length ? ('?' + params.join('&')) : '';
    return jsonFetch('api/alerts.php' + qs);
}

/** Send a test notification for a sensor over the given channel (slack|email|both). */
export function sendTestAlert(sensorId, channel) {
    return jsonFetch('api/test_alert.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': getApiKey(),
        },
        body: JSON.stringify({ sensor_id: sensorId, channel: channel || 'both' }),
    });
}

export function updateSensorIp(sensorId, ip) {
    return jsonFetch('api/update_sensor.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': getApiKey(),
        },
        body: JSON.stringify({ sensor_id: sensorId, ip_address: ip }),
    });
}

export function updateSensorLocation(sensorId, locationName) {
    return jsonFetch('api/update_sensor.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': getApiKey(),
        },
        body: JSON.stringify({ sensor_id: sensorId, location_name: locationName }),
    });
}

export function deleteSensor(sensorId) {
    return jsonFetch('api/delete_sensor.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': getApiKey(),
        },
        body: JSON.stringify({ sensor_id: sensorId }),
    });
}

// -------------------------------------------------------------------------
// Dashboard layout persistence
// -------------------------------------------------------------------------
export function fetchLayout() {
    return jsonFetch('api/layout.php');
}

export function saveLayout(layout) {
    return jsonFetch('api/layout.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': getApiKey(),
        },
        body: JSON.stringify(layout),
    });
}

export function deleteLayout() {
    return jsonFetch('api/layout.php', {
        method: 'DELETE',
        headers: { 'X-API-Key': getApiKey() },
    });
}
