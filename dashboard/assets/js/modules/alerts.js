/**
 * Sensor Dashboard — offline-alert overview UI.
 *
 * Owns the dashboard-facing half of the alert system that ISN'T per-sensor
 * configuration (that lives in the Manage modal / manage.js):
 *   - the topbar bell button + active-alert count badge
 *   - the sticky "N sensors offline and alerting" banner
 *   - the Alerts modal (live alerts + history timeline)
 *
 * Per-sensor *state* (which sensors are alerting) is derived from the cached
 * sensors list so the banner/bell update for free on every auto-refresh; the
 * modal additionally pulls the audit history from /api/alerts.php on open.
 */

import { esc, timeAgo, formatDuration, formatDateTime } from './utils.js';
import { fetchAlerts } from './api.js';

let ctx = null;
let modalOpen = false;

export function initAlerts(options) {
    ctx = options; // { getSensors, openManage }

    const alertsBtn        = document.getElementById('alertsBtn');
    const alertsModal      = document.getElementById('alertsModal');
    const alertsModalClose = document.getElementById('alertsModalClose');
    const alertsRefreshBtn = document.getElementById('alertsRefreshBtn');
    const bannerEl         = document.getElementById('alertBanner');

    if (alertsBtn) alertsBtn.addEventListener('click', openAlertsModal);
    if (alertsModalClose) alertsModalClose.addEventListener('click', closeAlertsModal);
    if (alertsRefreshBtn) alertsRefreshBtn.addEventListener('click', loadAlertsIntoModal);
    if (alertsModal) alertsModal.addEventListener('click', (e) => {
        if (e.target === alertsModal) closeAlertsModal();
    });

    // Delegate clicks inside the banner ("View" / "Manage").
    if (bannerEl) bannerEl.addEventListener('click', (e) => {
        const view = e.target.closest('[data-alert-view]');
        if (view) { openAlertsModal(); return; }
        const manage = e.target.closest('[data-alert-manage]');
        if (manage && ctx && typeof ctx.openManage === 'function') ctx.openManage();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modalOpen) closeAlertsModal();
    });
}

// ------------------------------------------------------------------
// Chrome: bell badge + banner (called on every data refresh)
// ------------------------------------------------------------------
export function updateAlertChrome(sensors) {
    const list = sensors || [];
    const active = list.filter(s => s.alerts && s.alerts.alerting);
    const configured = list.filter(s => s.alerts && s.alerts.enabled).length;

    // Bell badge
    const badge = document.getElementById('alertCountBadge');
    const bell  = document.getElementById('alertsBtn');
    if (badge) {
        badge.textContent = active.length ? String(active.length) : '';
        badge.classList.toggle('visible', active.length > 0);
    }
    if (bell) {
        bell.classList.toggle('has-alerts', active.length > 0);
        bell.setAttribute('title',
            active.length ? (active.length + ' sensor(s) alerting')
                          : (configured ? (configured + ' sensor(s) armed') : 'Alerts'));
    }

    // Banner
    const banner = document.getElementById('alertBanner');
    if (!banner) return;
    if (active.length === 0) {
        banner.classList.remove('visible');
        banner.innerHTML = '';
    } else {
        const names = active.map(s => esc(s.location_name || s.sensor_id));
        const lead = active.length === 1
            ? ('<strong>' + names[0] + '</strong> is offline and alerting')
            : ('<strong>' + active.length + ' sensors</strong> are offline and alerting');
        const detail = active.length > 1
            ? '<span class="alert-banner-names">' + names.slice(0, 4).join(', ') +
              (names.length > 4 ? ' +' + (names.length - 4) + ' more' : '') + '</span>'
            : '';
        banner.innerHTML =
            '<div class="alert-banner-inner">' +
                '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">' +
                    '<path d="M8 1.5L15 14H1z" stroke-linejoin="round"/><path d="M8 6v3.5" stroke-linecap="round"/><circle cx="8" cy="11.6" r="0.5" fill="currentColor"/>' +
                '</svg>' +
                '<span class="alert-banner-text">' + lead + detail + '</span>' +
                '<div class="alert-banner-actions">' +
                    '<button class="alert-banner-btn" data-alert-view>View alerts</button>' +
                    '<button class="alert-banner-btn ghost" data-alert-manage>Manage</button>' +
                '</div>' +
            '</div>';
        banner.classList.add('visible');
    }
}

// ------------------------------------------------------------------
// Alerts modal
// ------------------------------------------------------------------
export function openAlertsModal() {
    const modal = document.getElementById('alertsModal');
    if (!modal) return;
    modal.classList.add('visible');
    modalOpen = true;
    loadAlertsIntoModal();
}

function closeAlertsModal() {
    const modal = document.getElementById('alertsModal');
    if (modal) modal.classList.remove('visible');
    modalOpen = false;
}

/** Re-fetch + re-render the modal contents if it's currently open. */
export function refreshAlertsModalIfOpen() {
    if (modalOpen) loadAlertsIntoModal();
}

function loadAlertsIntoModal() {
    const body = document.getElementById('alertsModalBody');
    if (!body) return;
    const btn = document.getElementById('alertsRefreshBtn');
    if (btn) btn.classList.add('spinning');

    fetchAlerts({ limit: 100 })
        .then((res) => {
            body.innerHTML = buildAlertsHtml(res && res.active ? res.active : [],
                                             res && res.history ? res.history : []);
        })
        .catch((err) => {
            if (err && err.message === 'unauthenticated') return;
            body.innerHTML = '<p class="alerts-empty">Unable to load alerts.</p>';
        })
        .finally(() => { if (btn) btn.classList.remove('spinning'); });
}

function buildAlertsHtml(active, history) {
    let html = '';

    // --- Active alerts ---
    html += '<div class="alerts-section">';
    html += '<div class="alerts-section-title">Active alerts ' +
            '<span class="alerts-count-pill ' + (active.length ? 'danger' : 'ok') + '">' + active.length + '</span></div>';
    if (active.length === 0) {
        html += '<p class="alerts-empty">No sensors are currently alerting. All clear.</p>';
    } else {
        html += '<div class="alerts-active-list">';
        for (let i = 0; i < active.length; i++) {
            const a = active[i];
            const dur = a.offline_for_minutes != null ? formatDuration(a.offline_for_minutes) : '';
            html += '<div class="alerts-active-item">';
            html += '<span class="alerts-dot danger"></span>';
            html += '<div class="alerts-active-main">';
            html += '<span class="alerts-active-name">' + esc(a.location_name || a.sensor_id) + '</span>';
            html += '<span class="alerts-active-meta">Offline ' + esc(dur) +
                    ' · since last seen ' + esc(timeAgo(a.last_seen)) +
                    ' · threshold ' + esc(formatDuration(a.threshold_minutes)) + '</span>';
            html += '</div>';
            html += '<div class="alerts-active-chips">' + channelChips(a) + '</div>';
            html += '</div>';
        }
        html += '</div>';
    }
    html += '</div>';

    // --- History ---
    html += '<div class="alerts-section">';
    html += '<div class="alerts-section-title">Recent history</div>';
    if (!history || history.length === 0) {
        html += '<p class="alerts-empty">No alert events recorded yet.</p>';
    } else {
        html += '<div class="alerts-history-list">';
        for (let i = 0; i < history.length; i++) {
            const e = history[i];
            const t = (e.event_type || '').toLowerCase();
            html += '<div class="alerts-history-row">';
            html += '<span class="alerts-evt ' + esc(t) + '">' + eventIcon(t) + esc(eventLabel(t)) + '</span>';
            html += '<span class="alerts-history-name">' + esc(e.location_name || e.sensor_id) + '</span>';
            html += '<span class="alerts-history-msg">' + esc(e.message || '') + '</span>';
            html += '<span class="alerts-history-chan">' + channelTextChips(e.channels) + '</span>';
            html += '<span class="alerts-history-time" title="' + esc(formatDateTime(e.created_at)) + '">' +
                    esc(timeAgo(e.created_at)) + '</span>';
            html += '</div>';
        }
        html += '</div>';
    }
    html += '</div>';

    return html;
}

function channelChips(a) {
    let html = '';
    if (a.slack) html += '<span class="chan-chip slack">Slack</span>';
    if (a.email) html += '<span class="chan-chip email">Email</span>';
    if (!a.slack && !a.email) html += '<span class="chan-chip dash">Dashboard</span>';
    if (a.repeat_minutes > 0) html += '<span class="chan-chip repeat">↻ ' + esc(formatDuration(a.repeat_minutes)) + '</span>';
    return html;
}

function channelTextChips(channels) {
    if (!channels) return '';
    const parts = String(channels).split(',').filter(Boolean);
    let html = '';
    for (let i = 0; i < parts.length; i++) {
        const c = parts[i].trim().toLowerCase();
        html += '<span class="chan-chip ' + esc(c) + '">' + esc(c.charAt(0).toUpperCase() + c.slice(1)) + '</span>';
    }
    return html;
}

function eventLabel(t) {
    if (t === 'offline')  return 'Offline';
    if (t === 'recovery') return 'Recovered';
    if (t === 'reminder') return 'Reminder';
    if (t === 'test')     return 'Test';
    return t || 'Event';
}

function eventIcon(t) {
    if (t === 'recovery') {
        return '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8.5l3 3 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if (t === 'test') {
        return '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2a3 3 0 0 0-3 3c0 3-1.5 4-1.5 4h9S11 8 11 5a3 3 0 0 0-3-3z" stroke-linejoin="round"/><path d="M6.5 12a1.5 1.5 0 0 0 3 0" stroke-linecap="round"/></svg>';
    }
    // offline / reminder
    return '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2L15 14H1z" stroke-linejoin="round"/><path d="M8 6v3.5" stroke-linecap="round"/></svg>';
}
