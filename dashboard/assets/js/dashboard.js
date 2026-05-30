/**
 * Sensor Dashboard — entry point.
 *
 * Loaded as `<script type="module">` from index.php. Wires the topbar
 * controls, fetches data on an interval, and delegates rendering,
 * drag-and-drop and the manage-sensors modal to modules under ./modules/.
 */

import {
    loadState, saveState, resetState, hydrateStateFromServer,
    isEditMode, toggleEditMode, setEditMode,
    setPanelCollapsed, setFeedChartType, setFeedHidden,
    getPanelState,
} from './modules/state.js';
import { render } from './modules/render.js';
import { bindPanelDragAndDrop, bindFeedDragAndDrop } from './modules/dragdrop.js';
import { initManageModal, openManageModal } from './modules/manage.js';
import { initAlerts, updateAlertChrome, refreshAlertsModalIfOpen } from './modules/alerts.js';
import { fetchSensors, fetchReadings } from './modules/api.js';

const AUTO_REFRESH_MS = 60000;

// ---------------------------------------------------------------------
// State
// ---------------------------------------------------------------------
let currentRange   = '24h';
let autoTimer      = null;
let cachedSensors  = null;
let cachedReadings = null;
let cachedDefaults = {};

// ---------------------------------------------------------------------
// DOM refs
// ---------------------------------------------------------------------
const mainContent     = document.getElementById('mainContent');
const sensorCountEl   = document.getElementById('sensorCount');
const refreshBtn      = document.getElementById('refreshBtn');
const rangeButtons    = document.querySelectorAll('.range-btn');
const collapseAllBtn  = document.getElementById('collapseAllBtn');
const expandAllBtn    = document.getElementById('expandAllBtn');
const editModeBtn     = document.getElementById('editModeBtn');
const editModeDoneBtn = document.getElementById('editModeDoneBtn');
const editBanner      = document.getElementById('editBanner');
const resetLayoutBtn  = document.getElementById('resetLayoutBtn');

// ---------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------
// Load instantly from localStorage so the first render isn't blocked by
// a network round-trip, then hydrate from the server so customizations
// made on another device/browser take over.
loadState();

initManageModal({
    getSensors: () => cachedSensors || [],
    getDefaults: () => cachedDefaults || {},
    onSensorDeleted: () => fetchAll(),
    onSensorsChanged: () => fetchAll(),
});

initAlerts({
    getSensors: () => cachedSensors || [],
    openManage: () => openManageModal(),
});

bindTopbar();
fetchAll();
autoTimer = setInterval(fetchAll, AUTO_REFRESH_MS);

hydrateStateFromServer().then((changed) => {
    if (changed && cachedSensors) rerender();
});

// ---------------------------------------------------------------------
// Render pipeline
// ---------------------------------------------------------------------
function rerender() {
    if (!cachedSensors) return;
    render(mainContent, cachedSensors, cachedReadings, sensorCountEl, currentRange);
    bindRenderedInteractions();
    updateEditChrome();
}

function bindRenderedInteractions() {
    // Glance cards scroll to sensor panels (expanding if collapsed)
    mainContent.querySelectorAll('.glance-card[data-scroll]').forEach(card => {
        card.addEventListener('click', () => {
            const id = card.getAttribute('data-scroll');
            const target = document.getElementById(id);
            if (!target) return;
            const sid = id.replace('panel-', '');
            if (getPanelState(sid).collapsed) {
                setPanelCollapsed(sid, false);
                rerender();
            }
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // Panel header collapse toggles
    mainContent.querySelectorAll('.panel-header[data-toggle]').forEach(h => {
        h.addEventListener('click', (e) => {
            if (e.target.closest('a')) return;
            if (e.target.closest('.drag-handle')) return; // don't toggle while initiating drag
            const sid = h.getAttribute('data-toggle');
            const ps = getPanelState(sid);
            const next = !ps.collapsed;
            setPanelCollapsed(sid, next);
            const body = document.getElementById('body-' + sid);
            const chevron = h.querySelector('.panel-chevron');
            if (body) body.classList.toggle('collapsed', next);
            if (chevron) chevron.classList.toggle('collapsed', next);
            // Charts need to be (re)mounted when expanding
            if (!next) rerender();
        });
    });

    // Per-feed chart-type buttons
    mainContent.querySelectorAll('.feed-type-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const sid   = btn.getAttribute('data-sensor-id');
            const fkey  = btn.getAttribute('data-feed-key');
            const type  = btn.getAttribute('data-chart-type');
            if (!sid || !fkey || !type) return;
            setFeedChartType(sid, fkey, type);
            rerender();
        });
    });

    // Hide feed (edit mode)
    mainContent.querySelectorAll('[data-hide-feed]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const sid = btn.getAttribute('data-hide-feed');
            const fkey = btn.getAttribute('data-feed-key');
            if (!sid || !fkey) return;
            setFeedHidden(sid, fkey, true);
            rerender();
        });
    });

    // Restore feed chip (edit mode)
    mainContent.querySelectorAll('[data-restore-feed]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const sid = btn.getAttribute('data-restore-feed');
            const fkey = btn.getAttribute('data-feed-key');
            if (!sid || !fkey) return;
            setFeedHidden(sid, fkey, false);
            rerender();
        });
    });

    // Drag & drop (only binds if edit mode)
    bindPanelDragAndDrop(mainContent, rerender);
    bindFeedDragAndDrop(mainContent, rerender);
}

function updateEditChrome() {
    const on = isEditMode();
    document.body.classList.toggle('edit-mode-on', on);
    if (editBanner) editBanner.classList.toggle('visible', on);
    if (editModeBtn) editModeBtn.classList.toggle('active', on);
}

// ---------------------------------------------------------------------
// Topbar wiring
// ---------------------------------------------------------------------
function bindTopbar() {
    rangeButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            rangeButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentRange = btn.dataset.range;
            fetchAll();
        });
    });

    if (refreshBtn) refreshBtn.addEventListener('click', fetchAll);

    if (collapseAllBtn) collapseAllBtn.addEventListener('click', () => {
        if (!cachedSensors) return;
        for (let i = 0; i < cachedSensors.length; i++) {
            setPanelCollapsed(cachedSensors[i].sensor_id, true);
        }
        rerender();
    });

    if (expandAllBtn) expandAllBtn.addEventListener('click', () => {
        if (!cachedSensors) return;
        for (let i = 0; i < cachedSensors.length; i++) {
            setPanelCollapsed(cachedSensors[i].sensor_id, false);
        }
        rerender();
    });

    if (editModeBtn) editModeBtn.addEventListener('click', () => {
        toggleEditMode();
        rerender();
    });

    if (editModeDoneBtn) editModeDoneBtn.addEventListener('click', () => {
        setEditMode(false);
        rerender();
    });

    if (resetLayoutBtn) resetLayoutBtn.addEventListener('click', () => {
        if (!confirm('Reset dashboard layout to default? This clears panel order, chart-type choices, and hidden feeds.')) return;
        resetState();
        rerender();
    });
}

// ---------------------------------------------------------------------
// Error banner (lightweight)
// ---------------------------------------------------------------------
function showError(msg) {
    let el = document.getElementById('errorBanner');
    if (!el) {
        el = document.createElement('div');
        el.id = 'errorBanner';
        el.style.cssText = 'background:#7f1d1d;color:#fca5a5;padding:8px 16px;text-align:center;' +
            'font-size:0.85rem;position:sticky;top:56px;z-index:99;';
        mainContent.parentNode.insertBefore(el, mainContent);
    }
    el.textContent = msg;
    el.style.display = '';
}

function clearError() {
    const el = document.getElementById('errorBanner');
    if (el) el.style.display = 'none';
}

// ---------------------------------------------------------------------
// Data fetching
// ---------------------------------------------------------------------
function fetchAll() {
    if (refreshBtn) refreshBtn.classList.add('spinning');

    Promise.all([fetchSensors(), fetchReadings(currentRange)])
        .then(([sensorsRes, readings]) => {
            cachedSensors = (sensorsRes && sensorsRes.sensors) || [];
            cachedDefaults = (sensorsRes && sensorsRes.defaults) || {};
            cachedReadings = readings || { sensors: {} };
            rerender();
            // Update the bell badge + offline-alert banner, and refresh the
            // alerts modal if the user has it open.
            updateAlertChrome(cachedSensors);
            refreshAlertsModalIfOpen();
            // Persist any freshly-initialized panel states
            saveState();
            clearError();
        })
        .catch((err) => {
            if (err && err.message === 'unauthenticated') return;
            console.error('Dashboard fetch error:', err);
            if (!cachedSensors) {
                mainContent.innerHTML =
                    '<div class="empty-state">' +
                        '<p style="font-size:1.1rem;margin-bottom:8px;">Unable to load sensor data.</p>' +
                        '<p>Check your database settings in <code>config.php</code> and ensure <code>install.php</code> has been run.</p>' +
                    '</div>';
            } else {
                showError('Failed to refresh data. Retrying in 60s\u2026');
            }
        })
        .finally(() => {
            if (refreshBtn) refreshBtn.classList.remove('spinning');
        });
}
