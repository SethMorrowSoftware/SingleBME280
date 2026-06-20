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

# --- Install Python dependencies -------------------------------------------
# The service just runs `python3 SingleSensor.py`, so every module that script
# imports has to be present system-wide or it dies on startup with
# "ModuleNotFoundError: No module named 'flask'". We install both sensor stacks
# (BME280 + SCD40) because the sensor is auto-detected at runtime and may not
# even be attached yet when this runs.
#
#   flask                          - local web settings UI (always imported)
#   slack_sdk                      - Slack alerts
#   smbus2 + RPi.bme280            - BME280 (temp + humidity)
#   adafruit-circuitpython-scd4x   - SCD40 (temp + humidity + CO2)
#   adafruit-blinka                - provides `board` for the SCD40 path
echo "Installing Python dependencies (this can take a few minutes on a Pi Zero)..."

# pip and the I2C tools the sensors need. Non-fatal: if apt is offline but the
# packages are already present, the pip step below still succeeds.
export DEBIAN_FRONTEND=noninteractive
apt-get update -y      2>/dev/null || true
apt-get install -y python3-pip i2c-tools 2>/dev/null || true

# Bookworm and newer mark the system Python as "externally managed" (PEP 668),
# which aborts a plain `pip install`. Pass --break-system-packages when pip
# understands it; on older pip that lacks the flag we simply omit it.
PIP_BREAK=""
if python3 -m pip install --help 2>/dev/null | grep -q -- '--break-system-packages'; then
    PIP_BREAK="--break-system-packages"
fi

# Installed as root, so they land in the system site-packages that the
# service's /usr/bin/python3 reads for every user (including ${REAL_USER}).
PY_PACKAGES="flask slack_sdk smbus2 RPi.bme280 adafruit-circuitpython-scd4x adafruit-blinka"
if ! python3 -m pip install $PIP_BREAK $PY_PACKAGES; then
    echo ""
    echo "Error: Failed to install Python dependencies."
    echo "       Check the Pi's network connection and re-run: sudo bash ${0}"
    exit 1
fi
echo "Python dependencies installed."
echo ""

# Enable the I2C bus the sensors hang off of (idempotent; needs a reboot to
# fully take effect if it was previously off). Best-effort — skipped on systems
# without raspi-config.
if command -v raspi-config >/dev/null 2>&1; then
    raspi-config nonint do_i2c 0 2>/dev/null || true
fi

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

# --- Make the service user own the install directory -----------------------
# The service runs as ${REAL_USER} with this directory as its WorkingDirectory,
# and SingleSensor.py writes its logs here (app.log, sensor_readings.log,
# error_log.log) plus rotated backups. If the repo was cloned as root, or the
# script was ever run with sudo, those files end up root-owned and the
# user-run service dies with:
#   PermissionError: [Errno 13] Permission denied: '.../app.log'
# Re-home the tree to ${REAL_USER} so it can read its code and write its logs.
REAL_GROUP="$(id -gn "${REAL_USER}" 2>/dev/null || echo "${REAL_USER}")"
chown -R "${REAL_USER}:${REAL_GROUP}" "${SCRIPT_DIR}" 2>/dev/null || true
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
echo ""
echo "If the sensor isn't detected, I2C may have just been enabled for the"
echo "first time — reboot the Pi (sudo reboot) and check the status again."
