<?php
require_once __DIR__ . '/includes/auth.php';
auth_require_login('login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sensor Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <div class="topbar-left">
        <h1>Sensor Dashboard</h1>
        <span class="sensor-count" id="sensorCount"></span>
    </div>
    <div class="topbar-controls">
        <div class="range-group">
            <button class="range-btn" data-range="1h">1H</button>
            <button class="range-btn" data-range="6h">6H</button>
            <button class="range-btn active" data-range="24h">24H</button>
            <button class="range-btn" data-range="7d">7D</button>
            <button class="range-btn" data-range="30d">30D</button>
        </div>
        <div class="topbar-actions">
            <button class="icon-btn edit-toggle-btn" id="editModeBtn" title="Customize layout (drag to reorder, pick chart types, hide feeds)">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2l2 2-8 8-3 1 1-3 8-8z" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="icon-btn-label">Customize</span>
            </button>
            <button class="icon-btn" id="resetLayoutBtn" title="Reset layout to default">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 8a6 6 0 1 0 1.8-4.3" stroke-linecap="round"/>
                    <path d="M2 2v4h4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            <button class="icon-btn" id="collapseAllBtn" title="Collapse all panels">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 4h12M2 8h12M2 12h12" stroke-linecap="round"/>
                </svg>
            </button>
            <button class="icon-btn" id="expandAllBtn" title="Expand all panels">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="2" width="12" height="12" rx="2"/>
                </svg>
            </button>
            <button class="icon-btn alerts-btn" id="alertsBtn" title="Alerts">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
                    <path d="M8 1.5a4 4 0 0 0-4 4c0 4-1.6 5.2-1.6 5.2h11.2S12 9.5 12 5.5a4 4 0 0 0-4-4z"/>
                    <path d="M6.3 13a1.8 1.8 0 0 0 3.4 0" stroke-linecap="round"/>
                </svg>
                <span class="alert-count-badge" id="alertCountBadge"></span>
            </button>
            <button class="icon-btn" id="manageBtn" title="Manage sensors">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="8" cy="8" r="2"/><path d="M8 2v2M8 12v2M2 8h2M12 8h2M3.8 3.8l1.4 1.4M10.8 10.8l1.4 1.4M3.8 12.2l1.4-1.4M10.8 5.2l1.4-1.4" stroke-linecap="round"/>
                </svg>
            </button>
            <button class="icon-btn" id="refreshBtn" title="Refresh now">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 8A6 6 0 1 1 8 2" stroke-linecap="round"/>
                    <path d="M14 2v4h-4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            <span class="auto-refresh">
                <span class="auto-refresh-dot"></span> 60s
            </span>
            <a class="icon-btn" href="logout.php" title="Sign out" aria-label="Sign out">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M10 11l3-3-3-3" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M13 8H6" stroke-linecap="round"/>
                </svg>
            </a>
        </div>
    </div>
</div>

<!-- Edit mode banner (shown only when editing) -->
<div class="edit-banner" id="editBanner" role="status" aria-live="polite">
    <div class="edit-banner-inner">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2l2 2-8 8-3 1 1-3 8-8z" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span><strong>Customize mode:</strong> drag panels &amp; feeds to reorder, pick chart types per feed, or hide feeds you don't need. Your layout is saved automatically.</span>
        <button class="edit-banner-done" id="editModeDoneBtn">Done</button>
    </div>
</div>

<!-- Offline-alert banner (populated by alerts.js when sensors are alerting) -->
<div class="alert-banner" id="alertBanner" role="alert" aria-live="assertive"></div>

<!-- Main content: dynamically populated by JS -->
<div class="main" id="mainContent">
    <div class="loading-state" id="globalLoading">
        <div class="loading-spinner"></div>
        <div>Loading sensors...</div>
    </div>
</div>

<!-- Sensor management modal -->
<div class="modal-overlay" id="manageModal">
    <div class="modal">
        <div class="modal-header">
            <h2>Manage Sensors</h2>
            <button class="modal-close" id="manageModalClose">&times;</button>
        </div>
        <div class="modal-body" id="manageModalBody">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<!-- Confirm delete modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2>Remove Sensor</h2>
            <button class="modal-close" id="deleteModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to remove <strong id="deleteSensorName"></strong>?</p>
            <p class="text-muted">This will permanently delete all historical readings for this sensor.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="deleteCancelBtn">Cancel</button>
            <button class="btn btn-danger" id="deleteConfirmBtn">Remove Sensor</button>
        </div>
    </div>
</div>

<!-- Alerts modal (active alerts + history) -->
<div class="modal-overlay" id="alertsModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h2>Alerts</h2>
            <div class="modal-header-actions">
                <button class="icon-btn" id="alertsRefreshBtn" title="Refresh alerts">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 8A6 6 0 1 1 8 2" stroke-linecap="round"/>
                        <path d="M14 2v4h-4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button class="modal-close" id="alertsModalClose">&times;</button>
            </div>
        </div>
        <div class="modal-body" id="alertsModalBody">
            <!-- Populated by alerts.js -->
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script type="module" src="assets/js/dashboard.js"></script>
</body>
</html>
