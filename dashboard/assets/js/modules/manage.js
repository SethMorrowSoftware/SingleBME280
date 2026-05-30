/**
 * Sensor Dashboard — "Manage Sensors" modal.
 *
 * Owns: edit display name + IP, per-sensor offline-alert configuration,
 * delete sensors, and the confirm-delete flow.
 * Does NOT own: top-level fetching or re-rendering — it asks the host
 * (dashboard.js) for those via the callbacks passed in init().
 */

import { esc, timeAgo, sensorUrl, SENSOR_PORT, formatDuration } from './utils.js';
import { removePanelState } from './state.js';
import {
    updateSensorIp, updateSensorLocation, deleteSensor,
    updateSensorAlerts, sendTestAlert,
} from './api.js';

let ctx = null;
let pendingDeleteId = null;

export function initManageModal(options) {
    ctx = options; // { getSensors, getDefaults, onSensorDeleted, onSensorsChanged }

    const manageBtn        = document.getElementById('manageBtn');
    const manageModal      = document.getElementById('manageModal');
    const manageModalClose = document.getElementById('manageModalClose');
    const deleteModal      = document.getElementById('deleteModal');
    const deleteModalClose = document.getElementById('deleteModalClose');
    const deleteCancelBtn  = document.getElementById('deleteCancelBtn');
    const deleteConfirmBtn = document.getElementById('deleteConfirmBtn');

    if (manageBtn) manageBtn.addEventListener('click', openManageModal);
    if (manageModalClose) manageModalClose.addEventListener('click', closeManageModal);
    if (manageModal) manageModal.addEventListener('click', (e) => {
        if (e.target === manageModal) closeManageModal();
    });

    if (deleteModalClose) deleteModalClose.addEventListener('click', closeDeleteModal);
    if (deleteCancelBtn)  deleteCancelBtn.addEventListener('click', closeDeleteModal);
    if (deleteConfirmBtn) deleteConfirmBtn.addEventListener('click', () => confirmDelete(deleteConfirmBtn));
    if (deleteModal) deleteModal.addEventListener('click', (e) => {
        if (e.target === deleteModal) closeDeleteModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (deleteModal && deleteModal.classList.contains('visible')) closeDeleteModal();
        else if (manageModal && manageModal.classList.contains('visible')) closeManageModal();
    });
}

function getDefaults() {
    return (ctx && typeof ctx.getDefaults === 'function') ? (ctx.getDefaults() || {}) : {};
}

export function openManageModal() {
    const manageModal     = document.getElementById('manageModal');
    const manageModalBody = document.getElementById('manageModalBody');
    if (!manageModal || !manageModalBody) return;

    const sensors = (ctx && typeof ctx.getSensors === 'function') ? ctx.getSensors() : [];
    if (!sensors || sensors.length === 0) {
        manageModalBody.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:20px;">No sensors registered.</p>';
    } else {
        manageModalBody.innerHTML = buildManageHtml(sensors);
        bindManageInteractions(manageModalBody);
    }
    manageModal.classList.add('visible');
}

function closeManageModal() {
    const m = document.getElementById('manageModal');
    if (m) m.classList.remove('visible');
}

function closeDeleteModal() {
    const m = document.getElementById('deleteModal');
    if (m) m.classList.remove('visible');
    pendingDeleteId = null;
}

// ------------------------------------------------------------------
// HTML
// ------------------------------------------------------------------
function buildManageHtml(sensors) {
    const defaults = getDefaults();
    let html = '';
    for (let i = 0; i < sensors.length; i++) {
        html += buildManageItem(sensors[i], defaults);
    }
    return html;
}

function buildManageItem(s, defaults) {
    const onClass = s.online ? 'status-online' : 'status-offline';
    const onText  = s.online ? 'Online' : 'Offline';
    const url = sensorUrl(s);

    let html = '<div class="manage-sensor-item">';
    html += '<div class="manage-sensor-info">';
    html += '<div class="manage-sensor-name">' + esc(s.location_name) + '</div>';
    html += '<div class="manage-sensor-meta">';
    html += '<span class="status-dot ' + onClass + '" style="display:inline-block;margin-right:4px;"></span>';
    html += esc(onText) + ' · ' + esc((s.sensor_type || '').toUpperCase()) + ' · ID: ' + esc(s.sensor_id) + ' · Last seen ' + esc(timeAgo(s.last_seen));
    html += '</div>';

    html += '<div class="manage-sensor-ip">';
    html += '<label class="ip-label">Display name</label>';
    html += '<input type="text" class="ip-input" data-sensor-loc="' + esc(s.sensor_id) + '" value="' + esc(s.location_name || '') + '" placeholder="' + esc(s.sensor_id) + '" maxlength="255">';
    html += '<button class="btn btn-sm btn-secondary loc-save-btn" data-save-loc="' + esc(s.sensor_id) + '">Save</button>';
    html += '</div>';

    html += '<div class="manage-sensor-ip">';
    html += '<label class="ip-label">Local IP</label>';
    html += '<input type="text" class="ip-input" data-sensor-ip="' + esc(s.sensor_id) + '" value="' + esc(s.ip_address || '') + '" placeholder="192.168.1.x">';
    html += '<button class="btn btn-sm btn-secondary ip-save-btn" data-save-ip="' + esc(s.sensor_id) + '">Save</button>';
    if (url) html += '<a class="btn-link" href="' + esc(url) + '" target="_blank">Open</a>';
    html += '</div>';

    html += buildAlertConfig(s, defaults);

    html += '</div>'; // .manage-sensor-info
    html += '<div class="manage-sensor-actions">';
    html += '<button class="btn btn-danger btn-sm" data-delete-sensor="' + esc(s.sensor_id) + '" data-delete-name="' + esc(s.location_name) + '">Remove</button>';
    html += '</div>';
    html += '</div>'; // .manage-sensor-item
    return html;
}

function buildAlertConfig(s, defaults) {
    const sid = esc(s.sensor_id);
    const a = s.alerts || {};
    const enabled = !!a.enabled;
    const slackAvail = !!defaults.slack_available;
    const emailAvail = !!defaults.email_available;
    const defMin = defaults.offline_alert_minutes || 60;
    const defEmail = defaults.default_email_to || '';

    // Status pill
    let statusClass = 'off', statusText = 'Off';
    if (a.alerting) { statusClass = 'alerting'; statusText = 'Alerting'; }
    else if (enabled) { statusClass = 'armed'; statusText = 'Armed'; }

    let html = '<div class="alert-config' + (enabled ? ' open' : '') + '" data-alert-config="' + sid + '">';

    // Header: switch + title + status
    html += '<div class="alert-config-head">';
    html += '<label class="switch" title="Enable offline alerts for this sensor">';
    html += '<input type="checkbox" data-alert-enabled="' + sid + '"' + (enabled ? ' checked' : '') + '>';
    html += '<span class="switch-slider"></span>';
    html += '</label>';
    html += '<span class="alert-config-title">Offline alerts</span>';
    html += '<span class="alert-status alert-status-' + statusClass + '" data-alert-statuspill="' + sid + '">' + statusText + '</span>';
    html += '</div>';

    // Body
    html += '<div class="alert-config-body">';

    // Threshold
    html += '<div class="alert-row">';
    html += '<label class="alert-label">Alert after</label>';
    html += '<input type="number" class="alert-num" min="1" max="525600" step="1" data-alert-minutes="' + sid + '" value="' +
            (a.threshold_minutes != null ? esc(a.threshold_minutes) : '') + '" placeholder="' + esc(defMin) + '">';
    html += '<span class="alert-suffix">min offline <span class="alert-default-note">(default ' + esc(formatDuration(defMin)) + ')</span></span>';
    html += '</div>';

    // Channels
    html += '<div class="alert-row">';
    html += '<label class="alert-label">Channels</label>';
    html += '<label class="chk' + (slackAvail ? '' : ' chk-disabled') + '" title="' + (slackAvail ? 'Post to Slack' : 'Slack not configured on the server') + '">';
    html += '<input type="checkbox" data-alert-slack="' + sid + '"' + (a.slack ? ' checked' : '') + (slackAvail ? '' : ' disabled') + '> Slack</label>';
    html += '<label class="chk' + (emailAvail ? '' : ' chk-disabled') + '" title="' + (emailAvail ? 'Send email' : 'Email not configured on the server') + '">';
    html += '<input type="checkbox" data-alert-email="' + sid + '"' + (a.email ? ' checked' : '') + (emailAvail ? '' : ' disabled') + '> Email</label>';
    html += '</div>';

    // Email recipient (revealed when Email is checked)
    html += '<div class="alert-row alert-email-row" data-alert-email-row="' + sid + '"' + (a.email ? '' : ' hidden') + '>';
    html += '<label class="alert-label">Email to</label>';
    html += '<input type="email" class="alert-text" data-alert-email-to="' + sid + '" value="' + esc(a.email_to || '') + '" placeholder="' +
            (defEmail ? esc(defEmail) + ' (default)' : 'recipient@example.com') + '">';
    html += '</div>';

    // Recovery + repeat
    html += '<div class="alert-row">';
    html += '<label class="chk" title="Send a message when the sensor reports again"><input type="checkbox" data-alert-recovery="' + sid + '"' + (a.recovery ? ' checked' : '') + '> Notify on recovery</label>';
    html += '</div>';

    html += '<div class="alert-row">';
    html += '<label class="alert-label">Repeat every</label>';
    html += '<input type="number" class="alert-num" min="0" max="525600" step="1" data-alert-repeat="' + sid + '" value="' + esc(a.repeat_minutes || 0) + '">';
    html += '<span class="alert-suffix">min while offline <span class="alert-default-note">(0 = once)</span></span>';
    html += '</div>';

    // Actions
    html += '<div class="alert-actions">';
    html += '<button class="btn btn-sm btn-primary" data-alert-save="' + sid + '">Save alerts</button>';
    html += '<button class="btn btn-sm btn-secondary" data-alert-test="' + sid + '" data-test-chan="slack"' + (slackAvail ? '' : ' disabled') + '>Test Slack</button>';
    html += '<button class="btn btn-sm btn-secondary" data-alert-test="' + sid + '" data-test-chan="email"' + (emailAvail ? '' : ' disabled') + '>Test Email</button>';
    html += '<span class="alert-save-status" data-alert-savestatus="' + sid + '"></span>';
    html += '</div>';

    if (!slackAvail && !emailAvail) {
        html += '<div class="alert-hint">No delivery channel is configured on the server. Alert-enabled sensors still show in the dashboard’s alert banner, bell and history. Configure Slack or email in <code>config.php</code> to also get pushed notifications.</div>';
    }

    html += '</div>'; // .alert-config-body
    html += '</div>'; // .alert-config
    return html;
}

// ------------------------------------------------------------------
// Interactions
// ------------------------------------------------------------------
function bindManageInteractions(root) {
    root.querySelectorAll('[data-delete-sensor]').forEach(btn => {
        btn.addEventListener('click', () => {
            pendingDeleteId = btn.getAttribute('data-delete-sensor');
            const name = btn.getAttribute('data-delete-name') || '';
            const label = document.getElementById('deleteSensorName');
            if (label) label.textContent = name;
            const modal = document.getElementById('deleteModal');
            if (modal) modal.classList.add('visible');
        });
    });

    root.querySelectorAll('[data-save-ip]').forEach(btn => {
        btn.addEventListener('click', () => {
            const sid = btn.getAttribute('data-save-ip');
            const input = root.querySelector('[data-sensor-ip="' + cssEscape(sid) + '"]');
            const ip = input ? input.value.trim() : '';
            saveIp(sid, ip, btn);
        });
    });

    root.querySelectorAll('[data-save-loc]').forEach(btn => {
        btn.addEventListener('click', () => {
            const sid = btn.getAttribute('data-save-loc');
            const input = root.querySelector('[data-sensor-loc="' + cssEscape(sid) + '"]');
            const loc = input ? input.value.trim() : '';
            saveLocation(sid, loc, btn);
        });
    });

    root.querySelectorAll('.ip-input').forEach(inp => {
        inp.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const ipSid = inp.getAttribute('data-sensor-ip');
            const locSid = inp.getAttribute('data-sensor-loc');
            const sid = ipSid || locSid;
            if (!sid) return;
            const selector = ipSid ? '[data-save-ip="' + cssEscape(sid) + '"]' : '[data-save-loc="' + cssEscape(sid) + '"]';
            const saveBtn = root.querySelector(selector);
            if (saveBtn) saveBtn.click();
        });
    });

    bindAlertInteractions(root);
}

function bindAlertInteractions(root) {
    // Enable switch reveals/hides the config body
    root.querySelectorAll('[data-alert-enabled]').forEach(chk => {
        chk.addEventListener('change', () => {
            const sid = chk.getAttribute('data-alert-enabled');
            const box = root.querySelector('[data-alert-config="' + cssEscape(sid) + '"]');
            if (box) box.classList.toggle('open', chk.checked);
        });
    });

    // Email checkbox reveals/hides the recipient row
    root.querySelectorAll('[data-alert-email]').forEach(chk => {
        chk.addEventListener('change', () => {
            const sid = chk.getAttribute('data-alert-email');
            const row = root.querySelector('[data-alert-email-row="' + cssEscape(sid) + '"]');
            if (row) row.hidden = !chk.checked;
        });
    });

    // Save alerts
    root.querySelectorAll('[data-alert-save]').forEach(btn => {
        btn.addEventListener('click', () => saveAlerts(root, btn.getAttribute('data-alert-save'), btn));
    });

    // Test buttons
    root.querySelectorAll('[data-alert-test]').forEach(btn => {
        btn.addEventListener('click', () => testAlert(btn.getAttribute('data-alert-test'), btn.getAttribute('data-test-chan'), btn));
    });
}

// Minimal CSS.escape fallback (sensor_ids are user-controlled labels).
function cssEscape(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/["\\\]\[#.:>+~*^$|=()]/g, '\\$&');
}

function gatherAlertConfig(root, sid) {
    const escaped = cssEscape(sid);
    const get = (attr) => root.querySelector('[' + attr + '="' + escaped + '"]');
    const enabled = get('data-alert-enabled');
    const minutes = get('data-alert-minutes');
    const slack   = get('data-alert-slack');
    const email   = get('data-alert-email');
    const emailTo = get('data-alert-email-to');
    const recov   = get('data-alert-recovery');
    const repeat  = get('data-alert-repeat');

    return {
        alerts_enabled:        enabled ? (enabled.checked ? 1 : 0) : 0,
        alert_offline_minutes: minutes && minutes.value.trim() !== '' ? parseInt(minutes.value, 10) : '',
        alert_slack:           slack ? (slack.checked ? 1 : 0) : 0,
        alert_email:           email ? (email.checked ? 1 : 0) : 0,
        alert_email_to:        emailTo ? emailTo.value.trim() : '',
        alert_recovery:        recov ? (recov.checked ? 1 : 0) : 0,
        alert_repeat_minutes:  repeat && repeat.value.trim() !== '' ? parseInt(repeat.value, 10) : 0,
    };
}

function saveAlerts(root, sid, btn) {
    const cfg = gatherAlertConfig(root, sid);

    // Light client-side validation for friendlier errors.
    if (cfg.alert_offline_minutes !== '' && (isNaN(cfg.alert_offline_minutes) || cfg.alert_offline_minutes < 1)) {
        return flashStatus(root, sid, 'Threshold must be ≥ 1', true);
    }
    if (cfg.alert_email && cfg.alert_email_to === '' && !(getDefaults().default_email_to)) {
        return flashStatus(root, sid, 'Enter an email recipient', true);
    }

    const orig = btn.textContent;
    btn.textContent = 'Saving…';
    btn.disabled = true;

    updateSensorAlerts(sid, cfg)
        .then(() => {
            flashStatus(root, sid, 'Saved', false);
            updateStatusPill(root, sid, cfg.alerts_enabled);
            // Update cached sensor so a re-open of the modal shows new values,
            // and trigger a refresh so the bell/banner reflect the change.
            const sensors = (ctx && typeof ctx.getSensors === 'function') ? ctx.getSensors() : [];
            for (let i = 0; i < sensors.length; i++) {
                if (sensors[i].sensor_id === sid) {
                    sensors[i].alerts = Object.assign({}, sensors[i].alerts, {
                        enabled: !!cfg.alerts_enabled,
                        threshold_minutes: cfg.alert_offline_minutes === '' ? null : cfg.alert_offline_minutes,
                        slack: !!cfg.alert_slack,
                        email: !!cfg.alert_email,
                        email_to: cfg.alert_email_to || null,
                        recovery: !!cfg.alert_recovery,
                        repeat_minutes: cfg.alert_repeat_minutes || 0,
                    });
                    break;
                }
            }
            if (ctx && typeof ctx.onSensorsChanged === 'function') ctx.onSensorsChanged();
        })
        .catch((err) => {
            const msg = (err && err.message && /\b400\b/.test(err.message)) ? 'Invalid values' : 'Save failed';
            flashStatus(root, sid, msg, true);
        })
        .finally(() => {
            btn.textContent = orig;
            btn.disabled = false;
        });
}

function testAlert(sid, channel, btn) {
    const orig = btn.textContent;
    btn.textContent = 'Sending…';
    btn.disabled = true;

    sendTestAlert(sid, channel)
        .then((res) => {
            const delivered = (res && res.delivered) || [];
            const ok = delivered.indexOf(channel) !== -1;
            if (ok) {
                btn.textContent = 'Sent ✓';
                btn.style.color = 'var(--accent-green)';
            } else {
                const errs = (res && res.errors) || {};
                btn.textContent = 'Failed';
                btn.style.color = 'var(--accent-red)';
                if (errs && errs[channel]) console.warn('Test ' + channel + ' failed:', errs[channel]);
            }
        })
        .catch(() => {
            btn.textContent = 'Failed';
            btn.style.color = 'var(--accent-red)';
        })
        .finally(() => {
            setTimeout(() => {
                btn.textContent = orig;
                btn.style.color = '';
                btn.disabled = false;
            }, 1800);
        });
}

function flashStatus(root, sid, text, isError) {
    const el = root.querySelector('[data-alert-savestatus="' + cssEscape(sid) + '"]');
    if (!el) return;
    el.textContent = text;
    el.style.color = isError ? 'var(--accent-red)' : 'var(--accent-green)';
    if (!isError) {
        setTimeout(() => { if (el) el.textContent = ''; }, 2500);
    }
}

function updateStatusPill(root, sid, enabled) {
    const pill = root.querySelector('[data-alert-statuspill="' + cssEscape(sid) + '"]');
    if (!pill) return;
    pill.classList.remove('alert-status-off', 'alert-status-armed', 'alert-status-alerting');
    if (enabled) {
        pill.classList.add('alert-status-armed');
        pill.textContent = 'Armed';
    } else {
        pill.classList.add('alert-status-off');
        pill.textContent = 'Off';
    }
}

// ------------------------------------------------------------------
// Save handlers (name / IP) + delete
// ------------------------------------------------------------------
function saveIp(sensorId, ip, btn) {
    const origText = btn.textContent;
    btn.textContent = 'Saving...';
    btn.disabled = true;

    updateSensorIp(sensorId, ip)
        .then(() => {
            btn.textContent = 'Saved!';
            btn.style.color = 'var(--accent-green)';
            const sensors = (ctx && typeof ctx.getSensors === 'function') ? ctx.getSensors() : [];
            for (let i = 0; i < sensors.length; i++) {
                if (sensors[i].sensor_id === sensorId) {
                    sensors[i].ip_address = ip || null;
                    break;
                }
            }
            setTimeout(() => {
                btn.textContent = origText;
                btn.style.color = '';
                btn.disabled = false;
                const row = btn.closest('.manage-sensor-ip');
                if (!row) return;
                const existingLink = row.querySelector('.btn-link');
                const newUrl = ip ? 'http://' + ip + ':' + SENSOR_PORT : null;
                if (existingLink && !newUrl) existingLink.remove();
                else if (newUrl && !existingLink) {
                    const a = document.createElement('a');
                    a.className = 'btn-link';
                    a.href = newUrl;
                    a.target = '_blank';
                    a.textContent = 'Open';
                    row.appendChild(a);
                } else if (existingLink && newUrl) {
                    existingLink.href = newUrl;
                }
            }, 1500);
        })
        .catch((err) => {
            console.error('Save IP error:', err);
            btn.textContent = 'Error';
            btn.style.color = 'var(--accent-red)';
            setTimeout(() => {
                btn.textContent = origText;
                btn.style.color = '';
                btn.disabled = false;
            }, 2000);
        });
}

function saveLocation(sensorId, locationName, btn) {
    const origText = btn.textContent;
    btn.textContent = 'Saving...';
    btn.disabled = true;

    updateSensorLocation(sensorId, locationName)
        .then(() => {
            btn.textContent = 'Saved!';
            btn.style.color = 'var(--accent-green)';
            const effective = locationName || sensorId;
            const sensors = (ctx && typeof ctx.getSensors === 'function') ? ctx.getSensors() : [];
            for (let i = 0; i < sensors.length; i++) {
                if (sensors[i].sensor_id === sensorId) {
                    sensors[i].location_name = effective;
                    break;
                }
            }
            const row = btn.closest('.manage-sensor-item');
            if (row) {
                const nameEl = row.querySelector('.manage-sensor-name');
                if (nameEl) nameEl.textContent = effective;
            }
            setTimeout(() => {
                btn.textContent = origText;
                btn.style.color = '';
                btn.disabled = false;
            }, 1500);
        })
        .catch((err) => {
            console.error('Save location error:', err);
            btn.textContent = 'Error';
            btn.style.color = 'var(--accent-red)';
            setTimeout(() => {
                btn.textContent = origText;
                btn.style.color = '';
                btn.disabled = false;
            }, 2000);
        });
}

function confirmDelete(deleteConfirmBtn) {
    if (!pendingDeleteId) return;
    const sid = pendingDeleteId;

    deleteConfirmBtn.textContent = 'Removing...';
    deleteConfirmBtn.disabled = true;

    deleteSensor(sid)
        .then(() => {
            removePanelState(sid);
            closeDeleteModal();
            closeManageModal();
            if (ctx && typeof ctx.onSensorDeleted === 'function') ctx.onSensorDeleted(sid);
        })
        .catch((err) => {
            console.error('Delete error:', err);
            alert('Failed to remove sensor. Check that API_KEY is configured in config.php.');
        })
        .finally(() => {
            deleteConfirmBtn.textContent = 'Remove Sensor';
            deleteConfirmBtn.disabled = false;
        });
}
