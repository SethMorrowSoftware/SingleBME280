# SingleSensor – BME280 / SCD40 Monitoring with Self-Hosted Dashboard

Raspberry Pi Zero sensor monitoring system with a self-hosted PHP dashboard. Supports **BME280** (temperature + humidity) and **SCD40** (temperature + humidity + CO2) sensors. Designed for deploying many Pi Zeros across different locations, all reporting to a single dashboard hosted on shared cPanel hosting.

## Architecture

```
┌──────────────┐     HTTPS POST      ┌─────────────────────┐
│  Pi Zero #1  │ ──────────────────>  │                     │
│  (BME280)    │                      │  cPanel Dashboard   │
├──────────────┤                      │  (PHP + MySQL)      │
│  Pi Zero #2  │ ──────────────────>  │                     │
│  (SCD40)     │                      │  - Chart.js UI      │
├──────────────┤                      │  - REST API         │
│  Pi Zero #N  │ ──────────────────>  │  - Auto-purge       │
│  (BME280)    │                      │                     │
└──────────────┘                      └─────────────────────┘
```

Each Pi Zero runs `SingleSensor.py`, which:
- Auto-detects the connected sensor (BME280 or SCD40)
- Reads temperature, humidity, and CO2 (SCD40 only) on a configurable interval
- POSTs JSON to the dashboard API over HTTPS
- Sends Slack alerts on high/low temperature, high CO2 (SCD40), and high/low humidity thresholds
- Serves a local dark-themed settings page on port 5000 (self-contained — works with no internet)
- Stores live settings outside git so `git pull` + re-install never loses them

## Prerequisites

- **Per Pi Zero**: Raspberry Pi Zero W with either a BME280 or SCD40 sensor
- **Dashboard server**: Any cPanel shared hosting account with PHP 7.4+ and MySQL

## Hardware Setup

### BME280 Wiring (I2C)

| BME280 Pin | Pi GPIO |
|------------|---------|
| VCC        | 3.3V    |
| GND        | GND     |
| SDA        | GPIO 2  |
| SCL        | GPIO 3  |

### SCD40 Wiring (I2C)

| SCD40 Pin | Pi GPIO |
|-----------|---------|
| VCC       | 3.3V    |
| GND       | GND     |
| SDA       | GPIO 2  |
| SCL       | GPIO 3  |

Enable I2C on your Pi if not already done:
```bash
sudo raspi-config   # Interface Options → I2C → Enable
```

## Pi Zero Setup

### 1. Dependencies

`install.sh` (step 3) installs the Python dependencies for you — they're listed
in `requirements.txt` — so you can skip ahead to cloning. Install them by hand
only for development, or to run `python3 SingleSensor.py` directly.

If you do install manually, note two things: the service runs the **system**
interpreter (`/usr/bin/python3`), so the packages must land there (a per-user
venv won't be seen); and Raspberry Pi OS Bookworm (Python 3.11+) blocks
system-wide `pip` by default (PEP 668), so pass `--break-system-packages`. Run
this from inside the cloned repo (after step 2):

```bash
sudo apt update && sudo apt install -y python3-pip
sudo python3 -m pip install --break-system-packages -r requirements.txt

# SCD40 sensors also need:
sudo python3 -m pip install --break-system-packages adafruit-circuitpython-scd4x
```

### 2. Clone and configure

```bash
# The repository is still hosted as SingleBME280 on GitHub for compatibility;
# we clone it into a folder called SingleSensor to match the new project name.
git clone https://github.com/morroware/SingleBME280.git SingleSensor
cd SingleSensor
```

Configure the sensor one of two ways:

- **Web UI (recommended):** finish the install below, then open
  `http://<pi_ip>:5000/settings` and fill in the dark-themed settings page.
- **Edit the template:** edit `SingleSensorSettings.conf` *before* the first
  run. On first run its contents are copied into a gitignored live file
  (`SingleSensorSettings.local.conf`) that the app reads/writes from then on.

```ini
[General]
sensor_location_name = kitchen        # Unique name for this sensor
sensor_type = auto                    # auto | bme280 | scd40
minutes_between_reads = 5
sensor_threshold_temp = 88.0          # High temp alert (°F)
sensor_lower_threshold_temp = 40.0    # Low temp alert (°F)
threshold_count = 3                   # Consecutive readings before any alert
slack_channel = alerts
slack_api_token = xoxb-your-token
dashboard_url = https://yourdomain.com/dashboard/api/submit.php
dashboard_api_key = your-secret-api-key
bme280_address = 0x76                 # 0x76 or 0x77

# Optional air-quality alerts (omit or set 0 to disable):
# sensor_co2_threshold = 1500           # High CO2 alert (ppm, SCD40 only)
# sensor_humidity_high_threshold = 70   # High humidity alert (%)
# sensor_humidity_low_threshold = 20    # Low humidity alert (%)
```

> **Your live settings live in `SingleSensorSettings.local.conf`, which is
> gitignored.** That's what makes updates safe: `git pull` and re-running
> `install.sh` never touch it. The tracked `SingleSensorSettings.conf` is just
> the seed template.

### 3. Run on boot (systemd)

```bash
sudo bash install.sh
```

The install script automatically detects your user and install path, generates the systemd service, removes any old `@reboot` cron entries, and (on upgrade) removes the legacy `singlebme280` service before starting `singlesensor`.

Check status:
```bash
sudo systemctl status singlesensor
journalctl -u singlesensor -f
```

> **Note:** The service waits 15 seconds before starting to ensure I2C and networking are ready.

### 4. Test

```bash
python3 SingleSensor.py
```
Access settings at `http://<pi_ip>:5000/settings`.

### 5. Updating a deployed sensor

Updates are idempotent and **non-destructive** — your settings are stored in
the gitignored `SingleSensorSettings.local.conf`, so this never overwrites them:

```bash
cd ~/SingleSensor
git pull
sudo bash install.sh      # re-renders the service; leaves your settings as-is
```

> **First upgrade from an older checkout:** older installs kept settings in the
> tracked `SingleSensorSettings.conf`. On the first run after pulling, the app
> automatically copies those existing values into the new live file, so nothing
> is lost. To tidy git afterward, you can optionally reset the now-unused
> template with `git checkout -- SingleSensorSettings.conf` (your real settings
> are safe in `SingleSensorSettings.local.conf`).

## Dashboard Setup (cPanel)

### 1. Create a MySQL database

In cPanel → MySQL Databases:
- Create a database (e.g., `youruser_sensors`)
- Create a user with a strong password
- Assign the user to the database with ALL PRIVILEGES

### 2. Upload dashboard files

Upload the entire `dashboard/` folder to your cPanel account, for example to `public_html/dashboard/`.

### 3. Configure

Edit `dashboard/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'youruser_sensors');
define('DB_USER', 'youruser_dbuser');
define('DB_PASS', 'your_db_password');
define('API_KEY', 'your-secret-api-key');  // Must match sensor configs

// Dashboard sign-in password (bcrypt hash). Generate one with:
//   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
define('DASHBOARD_PASSWORD_HASH', '$2y$12$...');
define('SESSION_LIFETIME', 7 * 24 * 60 * 60);  // 7 days

define('RETENTION_DAYS', 90);
define('APP_TIMEZONE', 'America/New_York');
define('OFFLINE_MINUTES', 15);           // minutes with no data = "offline" dot

// --- Offline-alert delivery (configured PER SENSOR in the dashboard) ---
// These are the shared credentials/defaults the per-sensor toggles use.

// Slack (leave blank to make Slack delivery unavailable)
define('SLACK_API_TOKEN', '');           // xoxb- bot token (same one used by sensors)
define('SLACK_CHANNEL', '');             // e.g. alerts

// Email via the host's mail() (leave FROM blank to make email unavailable)
define('ALERT_EMAIL_FROM', '');          // e.g. sensors@yourdomain.com
define('ALERT_EMAIL_FROM_NAME', 'Sensor Dashboard');
define('ALERT_EMAIL_TO', '');            // default recipient if a sensor sets none

define('OFFLINE_ALERT_MINUTES', 60);     // default threshold; overridable per sensor
define('ALERT_HISTORY_RETENTION_DAYS', 90);
```

> **Important**: change `DASHBOARD_PASSWORD_HASH` before exposing the
> dashboard. The shipped default is a placeholder and must not be reused
> on a public deployment.

### 3a. Per-sensor offline alerts

Each sensor can be **independently** configured to alert when it stops
reporting. Open the dashboard, click the **gear (Manage sensors)** icon, and
expand **Offline alerts** for any sensor:

| Option | Description |
|--------|-------------|
| **Enable** | Master on/off for this sensor's offline alerts. |
| **Alert after N minutes** | How long the sensor must be silent before alerting. Blank = use the global `OFFLINE_ALERT_MINUTES` default. |
| **Slack / Email** | Which channels to deliver over. Each is independent and only selectable if configured in `config.php`. |
| **Email to** | Per-sensor recipient (falls back to `ALERT_EMAIL_TO`). |
| **Notify on recovery** | Also send a "back online" message when the sensor reports again. |
| **Repeat every N minutes** | Re-notify on an interval while still offline (`0` = alert once). |

There's also a **Send test** button per channel to verify delivery.

How it works:

- **The dashboard is a zero-config channel.** A sensor with alerts enabled but
  no Slack/email selected still surfaces in the dashboard's alert **banner**,
  the topbar **bell** (with a live count), and the **alert history** — so you
  get alerting with no external setup at all.
- **Slack** uses the same `chat.postMessage` endpoint as the Pi scripts; point
  it at your existing bot token + channel and offline alerts land alongside
  temperature alerts. Fill in `SLACK_API_TOKEN` / `SLACK_CHANNEL`.
- **Email** uses the host's `mail()` transport (works out-of-the-box on most
  cPanel accounts). Set `ALERT_EMAIL_FROM` to an address on your own domain for
  best deliverability.
- **History** of every offline / recovery / reminder / test event is recorded
  and viewable from the bell icon → **Alerts** modal.
- **No duplicates:** every state change is an atomic claim, so concurrent
  checks (cron + dashboard piggyback) never double-send.
- **Upgrades are drop-in:** the new `sensors` columns and `alert_events` table
  are added automatically on first use (or re-run `install.php` after deleting
  `install.lock`).

> **Reliable detection needs a cron.** Detecting *absence* of data can't rely on
> incoming traffic alone, so add a cron job that hits `check_offline.php` every
> minute or two. The dashboard also runs a best-effort sweep on its own
> refreshes, but the cron is what guarantees alerts fire even when nobody has
> the dashboard open:
>
> ```
> */2 * * * * curl -sS -H "X-API-Key: <YOUR_API_KEY>" https://yourdomain.com/dashboard/api/check_offline.php >/dev/null
> ```

### 4. Install database tables

Visit `https://yourdomain.com/dashboard/install.php` once in your browser. This creates the required MySQL tables and writes a lock file so it cannot be re-run accidentally.

### 5. Access the dashboard

Visit `https://yourdomain.com/dashboard/` to see the live dashboard.

## Dashboard Features

- **Sensor cards**: Current temperature, humidity, and CO2 for each sensor with online/offline status
- **Temperature chart**: Line chart of all sensors over time (Chart.js)
- **Humidity chart**: Line chart of all sensors over time
- **CO2 chart**: Shown automatically when SCD40 sensors are present
- **Time ranges**: 1H, 6H, 24H, 7D, 30D with automatic downsampling for large ranges
- **Rename sensors**: Set a friendly display name per sensor from the Manage modal (survives future submissions)
- **Per-sensor offline alerts**: Independent enable, threshold, Slack/email channels, recovery notices, and repeat reminders per sensor (see [Per-sensor offline alerts](#3a-per-sensor-offline-alerts))
- **Alert UI**: Live offline-alert banner, topbar bell with active-alert count, in-card ALERT badges, and an alert-history timeline
- **Auto-refresh**: Dashboard updates every 60 seconds
- **Data retention**: Automatically purges readings older than the configured retention period
- **Dark theme**: Professional monitoring interface, responsive on mobile

## API Reference

### POST `/api/submit.php`

Ingest a sensor reading.

**Headers**: `X-API-Key: <your-key>`, `Content-Type: application/json`

```json
{
    "sensor_id": "kitchen",
    "sensor_type": "bme280",
    "temperature_f": 72.5,
    "temperature_c": 22.5,
    "humidity": 45.2,
    "co2": null
}
```

### GET `/api/sensors.php`

Returns all sensors with their latest reading and online status.

### GET `/api/readings.php`

Returns time-series data for charts.

| Parameter   | Default | Description |
|-------------|---------|-------------|
| `sensor_id` | `all`   | Comma-separated IDs or `all` |
| `range`     | `24h`   | `1h`, `6h`, `24h`, `7d`, `30d` |
| `start`     | —       | Custom start (ISO date) |
| `end`       | —       | Custom end (ISO date) |

### POST `/api/update_sensor.php`

Update the editable fields of a sensor, including its per-sensor alert config.

```json
{
    "sensor_id":             "kitchen",
    "ip_address":            "192.168.1.42",
    "location_name":         "Kitchen",
    "alerts_enabled":        true,
    "alert_offline_minutes": 30,
    "alert_slack":           true,
    "alert_email":           true,
    "alert_email_to":        "ops@example.com",
    "alert_recovery":        true,
    "alert_repeat_minutes":  120
}
```

All fields except `sensor_id` are optional. `alert_offline_minutes` blank/`null`
falls back to the global default; `alert_repeat_minutes` `0` means alert once.

### GET `/api/alerts.php`

Returns `active` (sensors currently alerting) and `history` (recent alert
events). Query params: `sensor_id` (filter), `limit` (default 100, max 500).

### POST `/api/test_alert.php`

Send a test notification for a sensor to verify delivery.

```json
{ "sensor_id": "kitchen", "channel": "both" }   // channel: slack | email | both
```

### GET `/api/check_offline.php`

Runs the offline-alert sweep (intended for cron). Requires `X-API-Key` or a
logged-in session. Returns `{status, enabled, slack_available, email_available, alerts_sent}`.

## File Structure

```
SingleSensor/
├── SingleSensor.py              # Pi Zero sensor script
├── SingleSensorSettings.conf    # Seed template (tracked)
├── SingleSensorSettings.local.conf  # Live per-device settings (gitignored, auto-created)
├── singlesensor.service         # systemd unit for auto-start on boot
├── requirements.txt             # Python dependencies (installed by install.sh)
├── install.sh                   # Installer: deps + systemd service + log perms
├── templates/
│   └── settings.html            # Pi Zero web settings UI (self-contained dark theme)
├── readme.md
└── dashboard/                   # Self-hosted on cPanel
    ├── index.php                # Dashboard UI
    ├── login.php                # Password sign-in page
    ├── logout.php               # Session teardown
    ├── config.php               # Server configuration
    ├── install.php              # One-time DB setup
    ├── .htaccess                # Security rules
    ├── api/
    │   ├── submit.php           # Data ingestion endpoint (+ recovery alerts)
    │   ├── readings.php         # Chart data endpoint
    │   ├── sensors.php          # Sensor listing (+ per-sensor alert config/state)
    │   ├── update_sensor.php    # Edit IP / display name / alert config
    │   ├── delete_sensor.php    # Remove a sensor + its readings
    │   ├── layout.php           # Persist UI layout customizations
    │   ├── alerts.php           # Active alerts + alert history
    │   ├── test_alert.php       # Send a test notification
    │   └── check_offline.php    # Cron entrypoint for the offline-alert sweep
    ├── includes/
    │   ├── db.php               # Database connection
    │   ├── auth.php             # Session + API-key auth
    │   ├── notify.php           # Slack + email delivery layer
    │   └── offline_alerts.php   # Per-sensor offline-alert engine
    └── assets/
        ├── css/
        │   └── style.css
        └── js/
            ├── dashboard.js
            └── modules/         # render / charts / state / drag-drop / manage / alerts / api / utils
```

## Security Notes

- The API key is a shared secret between all sensors and the dashboard. Use a long random string.
- The `.htaccess` file blocks direct access to `config.php`, `includes/`, and lock files.
- Sensor settings (including Slack tokens) are stored in plaintext on each Pi. Secure physical access to your Pis.
- The Pi settings web interface has no authentication. It is only accessible on your local network.
- Dashboard login redirects are strictly validated to same-origin paths to prevent open-redirect phishing.
