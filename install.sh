#!/usr/bin/env bash
# install.sh — Install SingleSensor as a systemd service on Raspberry Pi.
# Supports both BME280 and SCD40 sensors (auto-detected at runtime).
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SERVICE_NAME="singlesensor"
SERVICE_FILE="${SCRIPT_DIR}/${SERVICE_NAME}.service"
DEST="/etc/systemd/system/${SERVICE_NAME}.service"
CURRENT_USER="$(whoami)"

# Legacy service name from before the rename. We'll tear it down if found so
# two copies of the monitoring loop don't run in parallel after an upgrade.
LEGACY_SERVICE_NAME="singlebme280"
LEGACY_DEST="/etc/systemd/system/${LEGACY_SERVICE_NAME}.service"

# Must run as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: This script must be run with sudo."
    echo "  sudo bash ${0}"
    exit 1
fi

if [ ! -f "$SERVICE_FILE" ]; then
    echo "Error: ${SERVICE_FILE} not found."
    exit 1
fi

# Detect the actual user (the one who called sudo, not root)
REAL_USER="${SUDO_USER:-$CURRENT_USER}"
REAL_HOME=$(eval echo "~${REAL_USER}")

echo "Installing ${SERVICE_NAME} service..."
echo "  User:      ${REAL_USER}"
echo "  Directory: ${SCRIPT_DIR}"
echo "  Log file:  ${REAL_HOME}/sensor.log"
echo ""

# --- Install Python dependencies for the service's interpreter -------------
# The unit runs the system python (/usr/bin/python3), so deps must be visible
# there — a per-user venv wouldn't help. On Raspberry Pi OS Bookworm, pip
# refuses system-wide installs (PEP 668) unless --break-system-packages is
# passed, so add the flag when this pip supports it (harmless otherwise).
PYTHON_BIN="/usr/bin/python3"
REQUIREMENTS="${SCRIPT_DIR}/requirements.txt"
if [ -f "$REQUIREMENTS" ]; then
    echo "Installing Python dependencies (${PYTHON_BIN})..."
    if ! "$PYTHON_BIN" -m pip --version >/dev/null 2>&1; then
        echo "  pip not found — installing python3-pip via apt..."
        apt-get update && apt-get install -y python3-pip
    fi
    PIP_FLAGS=""
    if "$PYTHON_BIN" -m pip install --help 2>/dev/null | grep -q -- '--break-system-packages'; then
        PIP_FLAGS="--break-system-packages"
    fi
    "$PYTHON_BIN" -m pip install $PIP_FLAGS -r "$REQUIREMENTS"
    echo "  Done."
else
    echo "Warning: ${REQUIREMENTS} not found; skipping dependency install."
fi
echo ""

# --- Ensure live settings exist (idempotent; never clobbers existing) -------
# The user's live config is gitignored so `git pull` + re-install can't lose
# it. Seed it from the tracked template only when it's missing.
LIVE_CONF="${SCRIPT_DIR}/SingleSensorSettings.local.conf"
TEMPLATE_CONF="${SCRIPT_DIR}/SingleSensorSettings.conf"
if [ -f "$LIVE_CONF" ]; then
    echo "Existing settings found (SingleSensorSettings.local.conf) — left untouched."
elif [ -f "$TEMPLATE_CONF" ]; then
    cp "$TEMPLATE_CONF" "$LIVE_CONF"
    echo "Created SingleSensorSettings.local.conf from the template."
else
    echo "No template found; the service will create default settings on first run."
fi
# The service runs as ${REAL_USER}, so it must own and be able to write its
# settings. Lock the file down — it holds the Slack token and dashboard key.
if [ -f "$LIVE_CONF" ]; then
    chown "${REAL_USER}" "$LIVE_CONF" 2>/dev/null || true
    chmod 600 "$LIVE_CONF" 2>/dev/null || true
fi
echo ""

# --- Reclaim ownership of runtime log files for the service user -----------
# A one-off `sudo python3 SingleSensor.py` test (or the old root @reboot cron)
# can leave *.log files in the install dir owned by root. The service runs as
# ${REAL_USER}, so hand them back — otherwise its logging setup hits
# "PermissionError: ... app.log" on startup.
shopt -s nullglob
for f in "${SCRIPT_DIR}"/*.log "${SCRIPT_DIR}"/*.log.*; do
    chown "${REAL_USER}" "$f" 2>/dev/null || true
done
shopt -u nullglob
echo ""

# --- Remove the legacy service if present (safe no-op on fresh installs) ---
if systemctl list-unit-files "${LEGACY_SERVICE_NAME}.service" 2>/dev/null | grep -q "${LEGACY_SERVICE_NAME}"; then
    echo "Found legacy ${LEGACY_SERVICE_NAME} service – removing it first..."
    systemctl stop    "${LEGACY_SERVICE_NAME}" 2>/dev/null || true
    systemctl disable "${LEGACY_SERVICE_NAME}" 2>/dev/null || true
    rm -f "${LEGACY_DEST}"
    systemctl daemon-reload
    echo "  Done."
fi

# Render the shipped template into the systemd location, substituting the
# detected user/paths. Keeping the unit definition in singlesensor.service
# (instead of a HEREDOC here) means there's a single source of truth.
sed \
    -e "s|__USER__|${REAL_USER}|g" \
    -e "s|__INSTALL_DIR__|${SCRIPT_DIR}|g" \
    -e "s|__HOME__|${REAL_HOME}|g" \
    "$SERVICE_FILE" > "$DEST"

# Sanity-check: any leftover placeholders mean the template drifted.
if grep -q '__USER__\|__INSTALL_DIR__\|__HOME__' "$DEST"; then
    echo "Error: ${DEST} still contains template placeholders after substitution."
    echo "       Check ${SERVICE_FILE} for unexpected tokens."
    exit 1
fi

# Remove old @reboot cron entries (both legacy and current names)
for TAG in SingleBME280 SingleSensor; do
    if crontab -u "$REAL_USER" -l 2>/dev/null | grep -q "$TAG"; then
        echo "Removing old @reboot cron entry (${TAG}) from ${REAL_USER}'s crontab..."
        crontab -u "$REAL_USER" -l 2>/dev/null | grep -v "$TAG" | crontab -u "$REAL_USER" -
    fi
    if crontab -l 2>/dev/null | grep -q "$TAG"; then
        echo "Removing old @reboot cron entry (${TAG}) from root crontab..."
        crontab -l 2>/dev/null | grep -v "$TAG" | crontab -
    fi
done

# Enable and start
systemctl daemon-reload
systemctl enable "$SERVICE_NAME"
systemctl restart "$SERVICE_NAME"

echo ""
echo "Service installed and started."
echo ""
echo "Useful commands:"
echo "  sudo systemctl status ${SERVICE_NAME}    # check status"
echo "  sudo systemctl restart ${SERVICE_NAME}   # restart"
echo "  sudo systemctl stop ${SERVICE_NAME}      # stop"
echo "  journalctl -u ${SERVICE_NAME} -f         # follow logs"
echo "  tail -f ${REAL_HOME}/sensor.log          # follow sensor log"
