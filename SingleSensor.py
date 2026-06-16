#!/usr/bin/env python3
"""
SingleSensor - Temperature/Humidity/CO2 monitoring for Raspberry Pi Zero.
Supports BME280 (temp + humidity) and SCD40 (temp + humidity + CO2) sensors.
Posts readings to a self-hosted PHP dashboard and sends Slack alerts.
"""

from flask import Flask, request, render_template, redirect, jsonify, flash, url_for
import time
import configparser
from threading import Thread, Event, Lock
import os
import socket
import sys
import logging
import tempfile
import traceback
import signal
import json
from logging.handlers import RotatingFileHandler

# Pin working directory to the script's own folder so relative paths work
# regardless of how the process is launched (cron, systemd, etc.)
_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
os.chdir(_SCRIPT_DIR)

# Conditional imports for sensors - only loaded when needed
smbus2 = None
bme280 = None
board = None
adafruit_scd4x = None

# ---------------------------------------------------------------------------
# Flask app
# ---------------------------------------------------------------------------
app = Flask(__name__)
# Secret key for flash messages; not used for user sessions. Generated fresh
# on each start since it only needs to be stable within a single run.
app.secret_key = os.urandom(24)

# Serialise config file writes so the Flask thread and the monitoring thread
# can't clobber each other.
_CONFIG_LOCK = Lock()

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
# All logs live in the install dir. If an earlier run left one owned by another
# user (e.g. a one-off `sudo python3 SingleSensor.py` test), opening it raises
# PermissionError. A logging glitch must never crash the service, so fall back
# to console output — which systemd captures in sensor.log — instead.
def _rotating_handler_or_console(path, formatter, **kwargs):
    """RotatingFileHandler for `path`, or a console handler if it can't open."""
    try:
        file_handler = RotatingFileHandler(path, **kwargs)
    except OSError as exc:
        sys.stderr.write(
            f"WARNING: could not open log file {path!r} ({exc}); "
            "logging to console instead.\n"
        )
        file_handler = logging.StreamHandler()
    file_handler.setFormatter(formatter)
    return file_handler


formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')

logger = logging.getLogger(__name__)
logger.setLevel(logging.DEBUG)
# The console handler is always present (systemd captures it in sensor.log); the
# rotating app.log is supplementary, so skip it rather than crash if it won't open.
logger.addHandler(logging.StreamHandler())
try:
    _app_handler = RotatingFileHandler('app.log', maxBytes=5 * 1024 * 1024, backupCount=3)
    _app_handler.setLevel(logging.DEBUG)
    _app_handler.setFormatter(formatter)
    logger.addHandler(_app_handler)
except OSError as exc:
    logger.warning("Could not open app.log (%s); using console logging only.", exc)
# Prevent root logger from re-emitting our records (systemd would duplicate them).
logger.propagate = False

LOG_FILE = "sensor_readings.log"
ERROR_LOG_FILE = "error_log.log"

# Rotating file handlers for the two data/error logs so they don't fill the SD card.
# 2 MB each, 2 backups = max ~6 MB per log.
_readings_logger = logging.getLogger('readings')
_readings_logger.setLevel(logging.INFO)
_readings_logger.addHandler(_rotating_handler_or_console(
    LOG_FILE, logging.Formatter('%(message)s'), maxBytes=2 * 1024 * 1024, backupCount=2))
_readings_logger.propagate = False

_error_logger = logging.getLogger('errors')
_error_logger.setLevel(logging.ERROR)
_error_logger.addHandler(_rotating_handler_or_console(
    ERROR_LOG_FILE, logging.Formatter('%(message)s'), maxBytes=2 * 1024 * 1024, backupCount=2))
_error_logger.propagate = False

# Silence Flask/Werkzeug's per-request access log – it's noisy and not useful
# for our use case (local settings UI only).
logging.getLogger('werkzeug').setLevel(logging.WARNING)

# ---------------------------------------------------------------------------
# Global state
# ---------------------------------------------------------------------------
shutdown_event = Event()
monitoring_error = None  # Set by monitoring thread if init fails
detected_sensor_type = None  # Populated by monitoring thread on successful init

alert_states = {
    'high_temp': False,
    'low_temp': False,
    'high_co2': False,
    'high_hum': False,
    'low_hum': False,
}
alert_counters = {
    'high_temp': 0,
    'low_temp': 0,
    'high_co2': 0,
    'high_hum': 0,
    'low_hum': 0,
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def log_error(message):
    """Log error messages to rotating error log and console."""
    timestamp = time.strftime('%Y-%m-%d %H:%M:%S')
    _error_logger.error(f"{timestamp} - ERROR: {message}")
    logger.error(message)


def celsius_to_fahrenheit(celsius):
    return (celsius * 9 / 5) + 32


def get_local_ip():
    """Return this device's LAN IP (e.g. 192.168.x.x) without sending traffic."""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(('8.8.8.8', 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return None


# Fixed settings-UI port. The dashboard's "Open sensor" link assumes this,
# so falling back to a different port would silently break that feature.
# If the port is taken, we fail loudly and let systemd restart after a delay.
SETTINGS_PORT = 5000


def port_is_free(port):
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            s.bind(('', port))
            return True
    except OSError:
        return False


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
# The user's LIVE settings live outside git (gitignored) so that `git pull`
# and re-running install.sh never clobber a deployed sensor's configuration.
# On first run we seed the live file from the tracked template
# (SingleSensorSettings.conf) — which, on an already-deployed device, still
# holds that sensor's real settings in the working tree — falling back to
# built-in defaults for anything missing.
TEMPLATE_CONF_FILE = 'SingleSensorSettings.conf'
CONF_FILE          = 'SingleSensorSettings.local.conf'

# Built-in defaults used to backfill any key the template is missing, so the
# app can always start with a complete, valid config.
_DEFAULT_SETTINGS = {
    'sensor_location_name':        'sensor-location',
    'sensor_type':                 'auto',
    'minutes_between_reads':       '5',
    'sensor_threshold_temp':       '88.0',
    'sensor_lower_threshold_temp': '40.0',
    'threshold_count':             '3',
    'slack_channel':               'alerts',
    'slack_api_token':             '',
    'dashboard_url':               'https://yourdomain.com/dashboard/api/submit.php',
    'dashboard_api_key':           'change-me-to-a-secure-random-key',
    'bme280_address':              '0x76',
}

# Keys required regardless of sensor type
_REQUIRED_KEYS = [
    'SENSOR_LOCATION_NAME', 'SENSOR_TYPE', 'MINUTES_BETWEEN_READS',
    'SENSOR_THRESHOLD_TEMP', 'SENSOR_LOWER_THRESHOLD_TEMP',
    'THRESHOLD_COUNT', 'SLACK_API_TOKEN', 'SLACK_CHANNEL',
    'DASHBOARD_URL', 'DASHBOARD_API_KEY',
]

_FLOAT_KEYS = {'SENSOR_THRESHOLD_TEMP', 'SENSOR_LOWER_THRESHOLD_TEMP'}
_INT_KEYS = {'MINUTES_BETWEEN_READS', 'THRESHOLD_COUNT'}


def read_settings_from_conf(conf_file):
    """Read and validate settings from configuration file."""
    config = configparser.ConfigParser()
    with _CONFIG_LOCK:
        config.read(conf_file)
    settings = {}

    current_key = None
    try:
        for key in _REQUIRED_KEYS:
            current_key = key
            if key in _FLOAT_KEYS:
                settings[key] = config.getfloat('General', key)
            elif key in _INT_KEYS:
                settings[key] = config.getint('General', key)
            else:
                settings[key] = config.get('General', key)
    except configparser.NoOptionError as e:
        log_error(f"Missing {current_key} in configuration file.")
        raise ValueError(f"Missing {current_key} in configuration file.") from e
    except Exception as e:
        log_error(f"Error reading configuration: {e}")
        raise ValueError(f"Error reading configuration: {e}") from e

    # Optional key: BME280 address (hex string like '0x76')
    try:
        settings['BME280_ADDRESS'] = config.get('General', 'BME280_ADDRESS')
    except configparser.NoOptionError:
        settings['BME280_ADDRESS'] = '0x76'

    # Validate critical values
    if settings['MINUTES_BETWEEN_READS'] < 1:
        settings['MINUTES_BETWEEN_READS'] = 1
        logger.warning("MINUTES_BETWEEN_READS was < 1, clamped to 1.")

    if not settings['SENSOR_LOCATION_NAME'].strip():
        raise ValueError("SENSOR_LOCATION_NAME must not be empty.")

    # Validate BME280 address parses as hex
    try:
        int(settings['BME280_ADDRESS'], 16)
    except (TypeError, ValueError):
        logger.warning(f"Invalid BME280_ADDRESS {settings['BME280_ADDRESS']!r}, "
                       f"falling back to 0x76.")
        settings['BME280_ADDRESS'] = '0x76'

    # Optional threshold alerts (None = disabled). These are strictly opt-in:
    # a blank or zero value disables the alert. CO2 only applies to SCD40.
    def _opt_num(name, cast):
        if not config.has_option('General', name):
            return None
        raw = config.get('General', name).strip()
        if raw == '' or raw in ('0', '0.0'):
            return None
        try:
            return cast(raw)
        except (TypeError, ValueError):
            logger.warning(f"Invalid {name} {raw!r}; treating the alert as disabled.")
            return None

    settings['SENSOR_CO2_THRESHOLD']           = _opt_num('SENSOR_CO2_THRESHOLD', lambda x: int(float(x)))
    settings['SENSOR_HUMIDITY_HIGH_THRESHOLD'] = _opt_num('SENSOR_HUMIDITY_HIGH_THRESHOLD', float)
    settings['SENSOR_HUMIDITY_LOW_THRESHOLD']  = _opt_num('SENSOR_HUMIDITY_LOW_THRESHOLD', float)

    return settings


def _atomic_write_config(conf_file, config):
    """Write a configparser object to conf_file atomically (temp + rename)."""
    target_dir = os.path.dirname(os.path.abspath(conf_file)) or '.'
    fd, tmp_path = tempfile.mkstemp(prefix='.settings-', suffix='.tmp', dir=target_dir)
    try:
        with os.fdopen(fd, 'w') as f:
            config.write(f)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp_path, conf_file)
    except Exception:
        try:
            os.remove(tmp_path)
        except OSError:
            pass
        raise


def write_settings_to_conf(conf_file, new_settings):
    """Merge `new_settings` with existing config and write atomically.

    Preserves any existing keys the caller didn't include (e.g. future
    optional keys, or ones the current UI doesn't expose).
    """
    with _CONFIG_LOCK:
        config = configparser.ConfigParser()
        config.read(conf_file)
        if not config.has_section('General'):
            config.add_section('General')
        for k, v in new_settings.items():
            # None (a disabled optional threshold) is written as an empty
            # string, not the literal "None".
            config.set('General', str(k), '' if v is None else str(v))
        _atomic_write_config(conf_file, config)


def ensure_live_config():
    """Seed the gitignored live config on first run, then leave it alone.

    Keeping live settings out of git is what lets a user `git pull` and re-run
    install.sh without losing this sensor's configuration. We prefer the
    tracked template (which, on an already-deployed device, still contains the
    real settings in its working tree) and backfill any missing keys from
    built-in defaults. Idempotent: a no-op once the live config exists.
    """
    with _CONFIG_LOCK:
        if os.path.exists(CONF_FILE):
            return
        config = configparser.ConfigParser()
        seeded_from = 'defaults'
        if os.path.exists(TEMPLATE_CONF_FILE):
            try:
                config.read(TEMPLATE_CONF_FILE)
                if config.has_section('General'):
                    seeded_from = 'template'
            except Exception as e:
                logger.warning(f"Could not read template {TEMPLATE_CONF_FILE}: {e}")
        if not config.has_section('General'):
            config.add_section('General')
        # Backfill any key the template lacked so the app always starts valid.
        for k, v in _DEFAULT_SETTINGS.items():
            if not config.has_option('General', k):
                config.set('General', k, v)
        try:
            _atomic_write_config(CONF_FILE, config)
            logger.info(f"Seeded live config {CONF_FILE} from {seeded_from}.")
        except Exception as e:
            log_error(f"Failed to seed live config {CONF_FILE}: {e}")


# ---------------------------------------------------------------------------
# Slack alerts
# ---------------------------------------------------------------------------
def send_slack_alert(message, settings):
    """Send alert to Slack channel. Fails silently on import/network errors."""
    try:
        from slack_sdk import WebClient

        token = settings['SLACK_API_TOKEN']
        channel = settings['SLACK_CHANNEL']

        # Skip if placeholder values
        if not token or token.startswith('xoxb-slack'):
            logger.debug("Slack token is placeholder, skipping alert.")
            return False

        client = WebClient(token=token)
        client.chat_postMessage(channel=channel, text=message)
        logger.info(f"Slack alert sent: {message}")
        return True
    except Exception as e:
        log_error(f"Failed to send Slack alert: {e}")
        return False


def _handle_simple_alert(key, tripped, detail, label, settings):
    """Hysteresis + Slack notify for the additive CO2 / humidity alerts.

    Mirrors the temperature-alert pattern (fire once after THRESHOLD_COUNT
    consecutive trips, send a "normal" notice on recovery) but is kept separate
    so the field-proven temperature path is untouched. Uses the shared
    alert_states / alert_counters dicts keyed by `key`.
    """
    loc = settings['SENSOR_LOCATION_NAME']
    count_needed = settings['THRESHOLD_COUNT']
    if tripped:
        alert_counters[key] += 1
        if alert_counters[key] >= count_needed and not alert_states[key]:
            send_slack_alert(f"{label} alert at {loc}: {detail}", settings)
            alert_states[key] = True
    else:
        alert_counters[key] = 0
        if alert_states[key]:
            send_slack_alert(f"{label} normal at {loc}: {detail}", settings)
            alert_states[key] = False


# ---------------------------------------------------------------------------
# Dashboard API submission
# ---------------------------------------------------------------------------
def send_to_dashboard(settings, temperature_f, temperature_c, humidity, co2=None,
                      detected_type='bme280'):
    """POST sensor reading to the self-hosted dashboard API.

    Uses urllib from stdlib so we don't require the 'requests' package.
    Retries up to 3 times with back-off on failure.
    """
    import urllib.request
    import urllib.error

    url = settings['DASHBOARD_URL']
    api_key = settings['DASHBOARD_API_KEY']

    if not url or url == 'https://yourdomain.com/dashboard/api/submit.php':
        logger.debug("Dashboard URL is placeholder, skipping submission.")
        return False

    payload = {
        'sensor_id': settings['SENSOR_LOCATION_NAME'],
        'sensor_type': detected_type,
        'temperature_f': round(temperature_f, 2),
        'temperature_c': round(temperature_c, 2),
        'humidity': round(humidity, 2) if humidity is not None else None,
        'co2': int(co2) if co2 is not None else None,
        'local_ip': get_local_ip(),
    }

    data = json.dumps(payload).encode('utf-8')
    headers = {
        'Content-Type': 'application/json',
        'X-API-Key': api_key,
    }

    max_retries = 3
    for attempt in range(max_retries):
        try:
            req = urllib.request.Request(url, data=data, headers=headers, method='POST')
            with urllib.request.urlopen(req, timeout=15) as resp:
                body = resp.read().decode('utf-8')
                logger.debug(f"Dashboard response: {body}")
                return True
        except urllib.error.HTTPError as e:
            log_error(f"Dashboard HTTP {e.code} on attempt {attempt + 1}: {e.reason}")
        except Exception as e:
            log_error(f"Dashboard send error on attempt {attempt + 1}: {e}")

        if attempt < max_retries - 1:
            time.sleep(2 * (attempt + 1))

    return False


# ---------------------------------------------------------------------------
# Sensor initialisation & reading
# ---------------------------------------------------------------------------
def _init_bme280(address_str='0x76'):
    """Initialise BME280 sensor. Returns (bus, address, calibration_params)."""
    global smbus2, bme280
    import smbus2 as _smbus2
    import bme280 as _bme280
    smbus2 = _smbus2
    bme280 = _bme280

    address = int(address_str, 16)
    bus = smbus2.SMBus(1)

    # Try the configured address first, then the alternate
    for addr in [address, 0x77 if address == 0x76 else 0x76]:
        try:
            cal = bme280.load_calibration_params(bus, addr)
            logger.info(f"BME280 initialised at address {hex(addr)}")
            return bus, addr, cal
        except Exception:
            continue

    raise RuntimeError("BME280 not found at 0x76 or 0x77")


def _init_scd40():
    """Initialise SCD40 sensor. Returns the scd4x object."""
    global board, adafruit_scd4x
    import board as _board
    import adafruit_scd4x as _scd4x
    board = _board
    adafruit_scd4x = _scd4x

    i2c = board.I2C()
    scd = adafruit_scd4x.SCD4X(i2c)
    scd.start_periodic_measurement()
    logger.info("SCD40 initialised and periodic measurement started")
    # Give the sensor time to produce its first reading
    time.sleep(6)
    return scd


def init_sensor(sensor_type, bme280_address='0x76'):
    """Detect and initialise the configured sensor.

    sensor_type: 'auto', 'bme280', or 'scd40'
    Returns: ('bme280', bus, address, cal) or ('scd40', scd_obj)
    """
    if sensor_type in ('auto', 'bme280'):
        try:
            bus, addr, cal = _init_bme280(bme280_address)
            return ('bme280', bus, addr, cal)
        except Exception as e:
            if sensor_type == 'bme280':
                raise RuntimeError(f"BME280 init failed: {e}") from e
            logger.info(f"BME280 not found ({e}), trying SCD40...")

    if sensor_type in ('auto', 'scd40'):
        try:
            scd = _init_scd40()
            return ('scd40', scd)
        except Exception as e:
            if sensor_type == 'scd40':
                raise RuntimeError(f"SCD40 init failed: {e}") from e
            logger.info(f"SCD40 not found ({e})")

    raise RuntimeError("No supported sensor detected. Check wiring and I2C.")


def read_sensor(sensor_info):
    """Read current values from the initialised sensor.

    Returns dict with keys: temperature_c, temperature_f, humidity, co2 (or None).
    """
    sensor_type = sensor_info[0]

    if sensor_type == 'bme280':
        _, bus, addr, cal = sensor_info
        data = bme280.sample(bus, addr, cal)
        temp_c = data.temperature
        temp_f = celsius_to_fahrenheit(temp_c)
        humidity = data.humidity
        return {
            'temperature_c': temp_c,
            'temperature_f': temp_f,
            'humidity': humidity,
            'co2': None,
        }

    elif sensor_type == 'scd40':
        scd = sensor_info[1]
        # Wait for data to be ready (up to 10 seconds)
        deadline = time.time() + 10
        while not scd.data_ready:
            if time.time() > deadline:
                raise RuntimeError("SCD40 data not ready within timeout")
            time.sleep(0.5)

        temp_c = scd.temperature
        temp_f = celsius_to_fahrenheit(temp_c)
        humidity = scd.relative_humidity
        co2 = scd.CO2
        return {
            'temperature_c': temp_c,
            'temperature_f': temp_f,
            'humidity': humidity,
            'co2': co2,
        }

    raise RuntimeError(f"Unknown sensor type: {sensor_type}")


# ---------------------------------------------------------------------------
# Flask routes
# ---------------------------------------------------------------------------
@app.route('/')
def home():
    return redirect('/settings')


@app.route('/status')
def status_route():
    """Quick health check – shows whether monitoring is running."""
    if monitoring_error:
        return jsonify(status='error', message=monitoring_error), 503
    return jsonify(
        status='ok',
        sensor=detected_sensor_type,
    ), 200


@app.route('/settings', methods=['GET', 'POST'])
def settings_route():
    conf_file = CONF_FILE
    if request.method == 'POST':
        try:
            action = request.form.get('action', 'save')

            # Start from the existing settings so the form can omit keys
            # (e.g. the optional BME280_ADDRESS) without losing them.
            try:
                merged = read_settings_from_conf(conf_file)
            except Exception:
                # Config was missing/corrupt – start empty so we can at least
                # write whatever the user submitted.
                merged = {}

            for key, value in request.form.items():
                if key == 'action':
                    continue
                try:
                    if key in _FLOAT_KEYS:
                        merged[key] = float(value)
                    elif key in _INT_KEYS:
                        merged[key] = int(value)
                    else:
                        merged[key] = value.strip()
                except (ValueError, KeyError):
                    flash(f'Invalid value for {key}', 'error')
                    return redirect(url_for('settings_route'))

            # Every required key must be present so the monitoring loop can
            # re-read successfully. Blank strings are allowed for most keys
            # (e.g. SLACK_API_TOKEN empty = Slack disabled), but a handful
            # genuinely cannot be empty.
            must_be_nonempty = {'SENSOR_LOCATION_NAME'}
            missing = []
            for k in _REQUIRED_KEYS:
                if k not in merged:
                    missing.append(k)
                elif k in must_be_nonempty and str(merged[k]).strip() == '':
                    missing.append(k)
            if missing:
                flash('Missing required values: ' + ', '.join(missing), 'error')
                return redirect(url_for('settings_route'))

            write_settings_to_conf(conf_file, merged)

            if action == "reboot":
                return reboot_system()

            flash('Settings updated successfully.', 'success')
            return redirect(url_for('settings_route'))
        except Exception as e:
            log_error(f"Settings update error: {e}")
            flash(f'Error updating settings: {e}', 'error')
            return redirect(url_for('settings_route'))
    else:
        try:
            current_settings = read_settings_from_conf(conf_file)
            return render_template('settings.html', settings=current_settings)
        except Exception as e:
            log_error(f"Error loading settings: {e}")
            return jsonify(error=f'Error loading settings: {e}'), 500


def reboot_system():
    try:
        logger.info("System reboot requested")
        os.system('sudo shutdown -r now')
        return jsonify(message='System is rebooting...'), 200
    except Exception as e:
        log_error(f"Reboot failed: {e}")
        return jsonify(error='Failed to reboot system'), 500


# ---------------------------------------------------------------------------
# Main monitoring loop
# ---------------------------------------------------------------------------
def run_monitoring():
    global alert_states, alert_counters, monitoring_error, detected_sensor_type

    # Read settings
    try:
        settings = read_settings_from_conf(CONF_FILE)
    except Exception as e:
        log_error(f"Failed to read settings: {e}")
        monitoring_error = str(e)
        return

    # Initialise sensor – retry a few times so transient I2C startup glitches
    # (common right after boot) don't take the service down. systemd already
    # delays 15s before launch, but the I2C bus can still be slow to settle.
    sensor_info = None
    init_attempts = 5
    for attempt in range(1, init_attempts + 1):
        if shutdown_event.is_set():
            return
        try:
            sensor_type_cfg = settings.get('SENSOR_TYPE', 'auto').lower().strip()
            bme_addr = settings.get('BME280_ADDRESS', '0x76')
            sensor_info = init_sensor(sensor_type_cfg, bme_addr)
            detected_type = sensor_info[0]
            detected_sensor_type = detected_type
            logger.info(f"Sensor active: {detected_type}")
            break
        except Exception as e:
            log_error(f"Sensor init attempt {attempt}/{init_attempts} failed: {e}")
            if attempt == init_attempts:
                monitoring_error = str(e)
                return
            # Exponential-ish backoff, capped at ~15s.
            shutdown_event.wait(timeout=min(15, 2 * attempt))

    minutes_between_reads = settings['MINUTES_BETWEEN_READS']
    last_read_time = 0

    while not shutdown_event.is_set():
        try:
            current_time = time.time()
            if current_time - last_read_time >= (minutes_between_reads * 60):
                # Re-read settings each cycle so live changes take effect
                try:
                    settings = read_settings_from_conf(CONF_FILE)
                    minutes_between_reads = settings['MINUTES_BETWEEN_READS']
                except Exception:
                    pass  # Use previous settings on read failure

                try:
                    reading = read_sensor(sensor_info)
                    temp_f = reading['temperature_f']
                    temp_c = reading['temperature_c']
                    humidity = reading['humidity']
                    co2 = reading['co2']

                    co2_str = f", CO2: {co2} ppm" if co2 is not None else ""
                    hum_log = f"{humidity:.1f}%" if humidity is not None else "n/a"
                    logger.info(
                        f"Read - Temp: {temp_f:.1f}F ({temp_c:.1f}C), "
                        f"Humidity: {hum_log}{co2_str}"
                    )

                    # --- High temperature alert ---
                    if temp_f >= settings['SENSOR_THRESHOLD_TEMP']:
                        alert_counters['high_temp'] += 1
                        if (alert_counters['high_temp'] >= settings['THRESHOLD_COUNT']
                                and not alert_states['high_temp']):
                            msg = (
                                f"High temp alert at {settings['SENSOR_LOCATION_NAME']}: "
                                f"{temp_f:.1f}F ({temp_c:.1f}C)"
                            )
                            send_slack_alert(msg, settings)
                            alert_states['high_temp'] = True
                    else:
                        alert_counters['high_temp'] = 0
                        if alert_states['high_temp']:
                            msg = (
                                f"Temp normal at {settings['SENSOR_LOCATION_NAME']}: "
                                f"{temp_f:.1f}F ({temp_c:.1f}C)"
                            )
                            send_slack_alert(msg, settings)
                            alert_states['high_temp'] = False

                    # --- Low temperature alert ---
                    if temp_f <= settings['SENSOR_LOWER_THRESHOLD_TEMP']:
                        alert_counters['low_temp'] += 1
                        if (alert_counters['low_temp'] >= settings['THRESHOLD_COUNT']
                                and not alert_states['low_temp']):
                            msg = (
                                f"Low temp alert at {settings['SENSOR_LOCATION_NAME']}: "
                                f"{temp_f:.1f}F ({temp_c:.1f}C)"
                            )
                            send_slack_alert(msg, settings)
                            alert_states['low_temp'] = True
                    else:
                        alert_counters['low_temp'] = 0
                        if alert_states['low_temp']:
                            msg = (
                                f"Temp normal at {settings['SENSOR_LOCATION_NAME']}: "
                                f"{temp_f:.1f}F ({temp_c:.1f}C)"
                            )
                            send_slack_alert(msg, settings)
                            alert_states['low_temp'] = False

                    # --- CO2 high alert (SCD40 only; opt-in) ---
                    co2_threshold = settings.get('SENSOR_CO2_THRESHOLD')
                    if co2_threshold is not None and co2 is not None:
                        _handle_simple_alert(
                            'high_co2', co2 >= co2_threshold,
                            f"{co2} ppm (limit {co2_threshold} ppm)",
                            "High CO2", settings)

                    # --- Humidity alerts (opt-in) ---
                    hum_high = settings.get('SENSOR_HUMIDITY_HIGH_THRESHOLD')
                    if hum_high is not None and humidity is not None:
                        _handle_simple_alert(
                            'high_hum', humidity >= hum_high,
                            f"{humidity:.1f}% (limit {hum_high:.0f}%)",
                            "High humidity", settings)
                    hum_low = settings.get('SENSOR_HUMIDITY_LOW_THRESHOLD')
                    if hum_low is not None and humidity is not None:
                        _handle_simple_alert(
                            'low_hum', humidity <= hum_low,
                            f"{humidity:.1f}% (limit {hum_low:.0f}%)",
                            "Low humidity", settings)

                    # --- Log locally (rotating) ---
                    ts = time.strftime('%Y-%m-%d %H:%M:%S')
                    hum_str = f", Humidity: {humidity:.1f}%" if humidity is not None else ""
                    _readings_logger.info(
                        f"{ts} - {settings['SENSOR_LOCATION_NAME']} - "
                        f"Temp: {temp_f:.2f}F{hum_str}{co2_str}"
                    )

                    # --- Send to dashboard ---
                    send_to_dashboard(settings, temp_f, temp_c, humidity, co2,
                                      detected_type=detected_type)

                    last_read_time = current_time

                except Exception as e:
                    log_error(f"Error reading/sending data: {e}\n{traceback.format_exc()}")

            # Wake on shutdown so service restarts are quick.
            if shutdown_event.wait(timeout=5):
                break
        except Exception as e:
            log_error(f"Error in monitoring loop: {e}")
            if shutdown_event.wait(timeout=5):
                break


# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------
def signal_handler(signum, frame):
    logger.info("Shutdown signal received. Cleaning up...")
    shutdown_event.set()
    # Raise SystemExit so Flask's serving loop actually stops.
    # Without this, Flask keeps running even though the event is set.
    raise SystemExit(0)


if __name__ == '__main__':
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    # Seed the gitignored live config before anything reads it, so a fresh
    # checkout (or a `git pull` that only updated code) still starts cleanly.
    ensure_live_config()

    monitoring_thread = None
    try:
        monitoring_thread = Thread(target=run_monitoring, daemon=True)
        monitoring_thread.start()

        if not port_is_free(SETTINGS_PORT):
            # Fail loudly so systemd restarts (after RestartSec). Falling back
            # to a random port would silently break the dashboard's "Open"
            # link, which assumes port 5000.
            raise RuntimeError(
                f"Port {SETTINGS_PORT} is in use. "
                "Stop any other process using it and retry."
            )

        logger.info(f"Starting Flask on port {SETTINGS_PORT}...")
        app.run(host='0.0.0.0', port=SETTINGS_PORT, debug=False, use_reloader=False)
    except SystemExit:
        pass  # Expected from signal_handler
    except Exception as e:
        log_error(f"Error starting server: {e}")
    finally:
        shutdown_event.set()
        if monitoring_thread is not None:
            monitoring_thread.join(timeout=10)
        logger.info("Application shut down.")
