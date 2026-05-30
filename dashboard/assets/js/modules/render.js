/**
 * Sensor Dashboard — HTML builders + chart mounting.
 *
 * Every render() destroys previous charts and rebuilds the DOM from the
 * cached sensors/readings. Interaction bindings (collapse, chart-type
 * buttons, drag handles, etc.) are attached fresh on each render by
 * dashboard.js.
 */

import {
    esc, timeAgo, fmt, calcStats, sensorUrl, feedsForSensor,
    extractFeedSeries, CHART_COLORS, rangeLabel, rangeLabelShort, formatDuration,
} from './utils.js';
import {
    getPanelState, getFeedState, isEditMode, sortSensors, sortFeeds,
} from './state.js';
import {
    destroyAllCharts, createLineChart, createAreaChart, createBarChart, drawGauge,
} from './charts.js';

const CHART_TYPES = [
    { id: 'line',  label: 'Line',  icon: iconLine()  },
    { id: 'area',  label: 'Area',  icon: iconArea()  },
    { id: 'bar',   label: 'Bars',  icon: iconBar()   },
    { id: 'gauge', label: 'Gauge', icon: iconGauge() },
];

// ------------------------------------------------------------------
// Public entry point
// ------------------------------------------------------------------
export function render(root, sensors, readingsData, sensorCountEl, currentRange) {
    destroyAllCharts();
    const range = currentRange || '24h';

    if (!sensors || sensors.length === 0) {
        root.innerHTML =
            '<div class="empty-state">' +
                '<p style="font-size:1.1rem;margin-bottom:8px;">No sensors registered yet.</p>' +
                '<p>Data will appear here once a Pi Zero posts its first reading.</p>' +
            '</div>';
        if (sensorCountEl) sensorCountEl.textContent = '';
        return;
    }

    let onlineCount = 0;
    for (let i = 0; i < sensors.length; i++) if (sensors[i].online) onlineCount++;
    if (sensorCountEl) sensorCountEl.textContent = onlineCount + '/' + sensors.length + ' online';

    const ordered = sortSensors(sensors);
    const readings = (readingsData && readingsData.sensors) || {};

    let html = buildGlanceSection(ordered);
    html += '<div class="sensor-panels" id="sensorPanels">';
    for (let i = 0; i < ordered.length; i++) {
        const sid = ordered[i].sensor_id;
        html += buildPanelHTML(ordered[i], readings[sid] || null, range);
    }
    html += '</div>';

    root.innerHTML = html;

    // Mount charts for expanded, visible feeds
    for (let j = 0; j < ordered.length; j++) {
        const s = ordered[j];
        const ps = getPanelState(s.sensor_id);
        if (ps.collapsed) continue;
        const rd = readings[s.sensor_id];
        const data = (rd && rd.data) ? rd.data : [];
        // Always attempt to mount: gauges still render from sensor.latest
        // when no time-series data is available for the chosen range.
        mountChartsForSensor(s, data);
    }
}

// ------------------------------------------------------------------
// Alert indicators (shared by glance cards + panel headers)
// ------------------------------------------------------------------
function alertIndicators(sensor) {
    const a = sensor && sensor.alerts;
    if (!a || !a.enabled) return '';
    if (a.alerting) {
        const dur = sensor.offline_for_minutes != null ? formatDuration(sensor.offline_for_minutes) : '';
        return '<span class="alert-pill" title="Offline alert active">' + iconBell() +
               'ALERT' + (dur ? ' <span class="alert-pill-dur">' + esc(dur) + '</span>' : '') + '</span>';
    }
    // Armed (alerts enabled, not currently firing)
    return '<span class="alert-armed" title="Offline alerts armed (' + esc(formatDuration(a.effective_minutes)) + ' threshold)">' + iconBell() + '</span>';
}

// ------------------------------------------------------------------
// Glance (at-a-glance cards)
// ------------------------------------------------------------------
function buildGlanceSection(sensors) {
    if (!sensors || sensors.length === 0) return '';
    let html = '<div class="glance-section">';
    html += '<div class="glance-header"><span class="glance-title">At a Glance</span></div>';
    html += '<div class="glance-grid">';
    for (let i = 0; i < sensors.length; i++) {
        const s = sensors[i];
        const onClass  = s.online ? 'status-online' : 'status-offline';
        const onText   = s.online ? 'Online' : 'Offline';
        const offClass = s.online ? '' : ' offline';
        const badgeClass = s.sensor_type === 'scd40' ? 'badge-scd40' : 'badge-bme280';
        const temp = s.latest && s.latest.temperature_f !== null ? fmt(s.latest.temperature_f, 1, '\u00B0') : '--';
        const hum  = s.latest && s.latest.humidity       !== null ? fmt(s.latest.humidity, 0, '%') : '--';
        const co2  = s.latest && s.latest.co2            !== null ? fmt(s.latest.co2, 0, '') : null;
        const url  = sensorUrl(s);
        const alerting = s.alerts && s.alerts.alerting;
        const cardClass = offClass + (alerting ? ' alerting' : '');

        html += '<div class="glance-card' + cardClass + '" data-scroll="panel-' + esc(s.sensor_id) + '">';
        html += '<div class="glance-card-top">';
        html += '<span class="glance-name">' + esc(s.location_name) + '</span>';
        html += '<span class="glance-top-right">' + alertIndicators(s);
        html += '<span class="glance-badge ' + badgeClass + '">' + esc((s.sensor_type || '').toUpperCase()) + '</span>';
        html += '</span>';
        html += '</div>';
        html += '<div class="glance-values">';
        html += '<span class="gv-temp">' + temp + '</span>';
        html += '<span class="gv-hum">' + hum + '</span>';
        if (co2 !== null) html += '<span class="gv-co2">' + co2 + ' ppm</span>';
        html += '</div>';
        html += '<div class="glance-footer">';
        html += '<span class="glance-status"><span class="status-dot ' + onClass + '"></span>' + onText + ' \u00B7 ' + esc(timeAgo(s.last_seen)) + '</span>';
        if (url) {
            html += '<a class="glance-link" href="' + esc(url) + '" target="_blank" onclick="event.stopPropagation()">Open</a>';
        }
        html += '</div></div>';
    }
    html += '</div></div>';
    return html;
}

// ------------------------------------------------------------------
// Sensor panel
// ------------------------------------------------------------------
function buildPanelHTML(sensor, sensorReadings, range) {
    const sid   = sensor.sensor_id;
    const state = getPanelState(sid);
    const edit  = isEditMode();
    const onClass = sensor.online ? 'status-online' : 'status-offline';
    const onText  = sensor.online ? 'Online' : 'Offline';
    const badgeClass = sensor.sensor_type === 'scd40' ? 'badge-scd40' : 'badge-bme280';
    const collClass = state.collapsed ? ' collapsed' : '';
    const url = sensorUrl(sensor);
    const latest = (sensor && sensor.latest) ? sensor.latest : {};
    const alerting = sensor.alerts && sensor.alerts.alerting;

    const allFeeds = feedsForSensor(sensor);
    const orderedFeeds = sortFeeds(sid, allFeeds);
    const data = (sensorReadings && sensorReadings.data) ? sensorReadings.data : [];

    let html = '<div class="sensor-panel' + (edit ? ' edit-mode' : '') + (alerting ? ' alerting' : '') + '"' +
               ' id="panel-' + esc(sid) + '"' +
               ' data-sensor-id="' + esc(sid) + '"' +
               (edit ? ' draggable="true"' : '') + '>';

    // Header (clickable to collapse)
    html += '<div class="panel-header" data-toggle="' + esc(sid) + '">';
    if (edit) {
        html += '<span class="drag-handle panel-drag-handle" title="Drag to reorder">' + iconGrip() + '</span>';
    }
    html += '<svg class="panel-chevron' + collClass + '" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    html += '<span class="panel-name">' + esc(sensor.location_name) + '</span>';
    html += '<span class="glance-badge ' + badgeClass + '">' + esc((sensor.sensor_type || '').toUpperCase()) + '</span>';
    html += '<div class="panel-meta">';
    html += alertIndicators(sensor);
    html += '<span class="range-tag" title="Min / Max / Avg are calculated over the ' + esc(rangeLabel(range)) + '">' + esc(rangeLabelShort(range)) + '</span>';
    html += '<span class="panel-status"><span class="status-dot ' + onClass + '"></span>' + onText + '</span>';
    html += '<span class="panel-last-seen">' + esc(timeAgo(sensor.last_seen)) + '</span>';
    if (url) {
        html += '<a class="panel-link" href="' + esc(url) + '" target="_blank" onclick="event.stopPropagation()" title="Open sensor settings">Settings</a>';
    }
    html += '</div>';
    html += '</div>';

    // Body
    html += '<div class="panel-body' + collClass + '" id="body-' + esc(sid) + '">';
    html += '<div class="panel-charts" data-sensor-id="' + esc(sid) + '">';

    let visibleCount = 0;
    for (let i = 0; i < orderedFeeds.length; i++) {
        const fdef = orderedFeeds[i];
        const fs = getFeedState(sid, fdef.key);
        if (fs.hidden) continue;
        const { values } = extractFeedSeries(data, fdef.key);
        // Pass the actual most-recent reading from /api/sensors.php as the
        // Current override so it doesn't drift when the user switches range.
        const latestVal = latest[fdef.field];
        const stats = calcStats(values, latestVal);
        html += buildChartBlock(sid, fdef, fs, stats, range, sensor);
        visibleCount++;
    }

    if (visibleCount === 0) {
        html += '<div class="chart-nodata" style="grid-column:1/-1;">All feeds hidden. Enter <strong>Customize</strong> mode to bring them back.</div>';
    }

    html += '</div>'; // .panel-charts

    // Hidden feed restore chips (edit mode only)
    if (edit) {
        const hidden = orderedFeeds.filter(f => getFeedState(sid, f.key).hidden);
        if (hidden.length > 0) {
            html += '<div class="hidden-feeds-row">';
            html += '<span class="hidden-feeds-label">Hidden feeds:</span>';
            for (let k = 0; k < hidden.length; k++) {
                const hf = hidden[k];
                html += '<button class="hidden-feed-chip" data-restore-feed="' + esc(sid) + '" data-feed-key="' + esc(hf.key) + '">';
                html += '<span class="hidden-feed-dot" style="background:' + hf.color + '"></span>';
                html += esc(hf.title) + ' <span class="chip-plus">+</span>';
                html += '</button>';
            }
            html += '</div>';
        }
    }

    html += '</div>'; // .panel-body
    html += '</div>'; // .sensor-panel
    return html;
}

// ------------------------------------------------------------------
// Per-feed chart block
// ------------------------------------------------------------------
function buildChartBlock(sid, fdef, fs, stats, range, sensor) {
    const edit = isEditMode();
    const key = fdef.key;
    const statClass = 'stat-' + key;
    const rangeShort = rangeLabelShort(range);
    const rangeLong  = rangeLabel(range);
    const lastSeenLabel = sensor && sensor.last_seen ? timeAgo(sensor.last_seen) : '';
    const liveClass = sensor && sensor.online ? ' live' : '';
    const dataAttrs =
        ' data-sensor-id="' + esc(sid) + '"' +
        ' data-feed-key="' + esc(key) + '"' +
        (edit ? ' draggable="true"' : '');

    let html = '<div class="chart-block' + (edit ? ' edit-mode' : '') + '"' + dataAttrs + '>';

    // Block header row: title + feed controls
    html += '<div class="chart-block-header">';
    if (edit) {
        html += '<span class="drag-handle feed-drag-handle" title="Drag to reorder">' + iconGrip() + '</span>';
    }
    html += '<span class="chart-block-title" style="color:' + fdef.color + '">' + esc(fdef.title) + '</span>';

    // Chart-type selector (segmented)
    html += '<div class="feed-type-seg" role="group" aria-label="Chart type">';
    for (let i = 0; i < CHART_TYPES.length; i++) {
        const ct = CHART_TYPES[i];
        const active = fs.chartType === ct.id ? ' active' : '';
        html += '<button class="feed-type-btn' + active + '"' +
                ' data-sensor-id="' + esc(sid) + '"' +
                ' data-feed-key="' + esc(key) + '"' +
                ' data-chart-type="' + ct.id + '"' +
                ' title="' + ct.label + '" aria-label="' + ct.label + '">' +
                ct.icon + '</button>';
    }
    html += '</div>';

    if (edit) {
        html += '<button class="feed-hide-btn" title="Hide this feed"' +
                ' data-hide-feed="' + esc(sid) + '"' +
                ' data-feed-key="' + esc(key) + '">' + iconEye() + '</button>';
    }
    html += '</div>'; // .chart-block-header

    if (!stats) {
        html += '<div class="chart-nodata">No ' + esc(fdef.title.toLowerCase()) + ' data for this range</div>';
        html += '</div>';
        return html;
    }

    const dec = fdef.decimals;
    const unit = fdef.unit;
    const unitSm = fdef.unitSm;

    // Stats row: a prominent "Now" tile (range-independent — pulled straight
    // from /api/sensors.php) sits next to a compact Min / Max / Avg group
    // that's explicitly labelled with the active range, so the user always
    // knows what window the aggregates cover.
    html += '<div class="chart-stats">';
    html += '<div class="stat stat-now ' + statClass + '">';
    html += '<span class="stat-now-label"><span class="live-dot' + liveClass + '"></span>Now';
    if (lastSeenLabel) {
        html += ' <span class="stat-now-time">· ' + esc(lastSeenLabel) + '</span>';
    }
    html += '</span>';
    html += '<span class="stat-value">' + fmt(stats.current, dec, unit) + '</span>';
    html += '</div>';

    html += '<div class="stat-range-group" title="Calculated over the ' + esc(rangeLong) + '">';
    html += '<div class="stat-range-tag">' + esc(rangeShort) + '</div>';
    html += '<div class="stat-range-cells">';
    html += '<div class="stat ' + statClass + '"><span class="stat-value-sm">' + fmt(stats.min, dec, unitSm) + '</span><span class="stat-label">Min</span></div>';
    html += '<div class="stat ' + statClass + '"><span class="stat-value-sm">' + fmt(stats.avg, dec, unitSm) + '</span><span class="stat-label">Avg</span></div>';
    html += '<div class="stat ' + statClass + '"><span class="stat-value-sm">' + fmt(stats.max, dec, unitSm) + '</span><span class="stat-label">Max</span></div>';
    html += '</div>';
    html += '</div>';
    html += '</div>';

    if (fs.chartType === 'gauge') {
        html += '<div class="gauge-container">';
        html += '<div class="gauge-wrap">';
        html += '<canvas class="gauge-canvas" id="gauge-' + esc(key) + '-' + esc(sid) + '" width="160" height="100"></canvas>';
        html += '<span class="gauge-value-display" style="color:' + fdef.color + '">' + fmt(stats.current, dec, unit) + '</span>';
        html += '</div>';
        html += '</div>';
    } else {
        html += '<div class="chart-container"><canvas id="chart-' + esc(key) + '-' + esc(sid) + '"></canvas></div>';
    }

    html += '</div>';
    return html;
}

// ------------------------------------------------------------------
// Chart instantiation (runs after DOM is in place)
// ------------------------------------------------------------------
function mountChartsForSensor(sensor, data) {
    const sid = sensor.sensor_id;
    const feeds = feedsForSensor(sensor);
    const latest = (sensor && sensor.latest) ? sensor.latest : {};
    for (let i = 0; i < feeds.length; i++) {
        const fdef = feeds[i];
        const fs = getFeedState(sid, fdef.key);
        if (fs.hidden) continue;
        mountFeedChart(sid, fdef, fs, data, latest[fdef.field]);
    }
}

function mountFeedChart(sid, fdef, fs, data, latestVal) {
    const { values, points } = extractFeedSeries(data, fdef.key);

    if (fs.chartType === 'gauge') {
        // Gauge always tracks the truly-latest reading so it doesn't change
        // value when the user switches between time ranges.
        const stats = calcStats(values, latestVal);
        if (!stats) return;
        const color = CHART_COLORS[fdef.field] || fdef.color;
        drawGauge('gauge-' + fdef.key + '-' + sid, stats.current,
                  fdef.gaugeMin, fdef.gaugeMax, color, fdef.unit);
        return;
    }

    if (points.length === 0) return;
    const canvas = document.getElementById('chart-' + fdef.key + '-' + sid);
    if (!canvas) return;
    if (fs.chartType === 'bar')       createBarChart(canvas, points, fdef.field, fdef.unit);
    else if (fs.chartType === 'area') createAreaChart(canvas, points, fdef.field, fdef.unit);
    else                              createLineChart(canvas, points, fdef.field, fdef.unit);
}

// ------------------------------------------------------------------
// Inline SVG icons (keep them tiny; reused)
// ------------------------------------------------------------------
function iconLine() {
    return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12l3-4 3 2 3-5 3 3"/></svg>';
}
function iconArea() {
    return '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"><path d="M2 13V9l3-3 3 2 3-5 3 4v6z" fill-opacity="0.35"/><path fill="none" d="M2 9l3-3 3 2 3-5 3 4"/></svg>';
}
function iconBar() {
    return '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><rect x="2"  y="8"  width="2.5" height="6" rx="0.5"/><rect x="5.5" y="5" width="2.5" height="9" rx="0.5"/><rect x="9"  y="9"  width="2.5" height="5" rx="0.5"/><rect x="12.5" y="3" width="2.5" height="11" rx="0.5"/></svg>';
}
function iconGauge() {
    return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2.5 12a5.5 5.5 0 0 1 11 0"/><path d="M8 12l3-4"/></svg>';
}
function iconGrip() {
    return '<svg width="12" height="14" viewBox="0 0 12 14" fill="currentColor"><circle cx="3.5" cy="3"  r="1"/><circle cx="8.5" cy="3"  r="1"/><circle cx="3.5" cy="7"  r="1"/><circle cx="8.5" cy="7"  r="1"/><circle cx="3.5" cy="11" r="1"/><circle cx="8.5" cy="11" r="1"/></svg>';
}
function iconEye() {
    return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 8S4 3 8 3s6.5 5 6.5 5-2.5 5-6.5 5-6.5-5-6.5-5z"/><circle cx="8" cy="8" r="2"/><path d="M2 14L14 2"/></svg>';
}
function iconBell() {
    return '<svg class="bell-ico" width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path d="M8 2a3.2 3.2 0 0 0-3.2 3.2c0 3.3-1.3 4.3-1.3 4.3h9s-1.3-1-1.3-4.3A3.2 3.2 0 0 0 8 2z"/><path d="M6.7 12a1.4 1.4 0 0 0 2.6 0" stroke-linecap="round"/></svg>';
}
