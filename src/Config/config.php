<?php
// ── LumineSense Configuration ─────────────────────────────
// All values can be overridden via .env — see .env.example.

// Hostinger SMTP
define('MAIL_HOST',       getenv('MAIL_HOST')       ?: 'localhost');
define('MAIL_PORT',       getenv('MAIL_PORT')       ?: '');
define('MAIL_USERNAME',   getenv('MAIL_USERNAME')   ?: '');
define('MAIL_PASSWORD',   getenv('MAIL_PASSWORD')   ?: '');
define('MAIL_FROM_NAME',  getenv('MAIL_FROM_NAME')  ?: '');
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: '');

// Admin registration code
define('VALID_ADMIN_CODE', getenv('VALID_ADMIN_CODE') ?: 'CHANGE_ME');

// Device token (sent as X-Device-Token header by ESP32/PIR devices)
define('DEVICE_TOKEN', getenv('DEVICE_TOKEN') ?: '');

// ESP32 simple token (sent as ?token= query param)
define('ESP32_TOKEN', getenv('ESP32_TOKEN') ?: '');

// ID encryption key (32-byte base64-encoded, used by IdQuarantine)
define('ID_ENCRYPTION_KEY', getenv('ID_ENCRYPTION_KEY') ?: '');

// Anomaly detection thresholds (tune for your power scale)
define('DROPOUT_POWER_THRESHOLD', getenv('DROPOUT_POWER_THRESHOLD') ?: 0.2);
define('DROPOUT_CONFIRM_COUNT',   getenv('DROPOUT_CONFIRM_COUNT')   ?: 3);
define('SPIKE_MIN_AVG_POWER',     getenv('SPIKE_MIN_AVG_POWER')     ?: 0.5);
define('SPIKE_RAISE_RATIO',       getenv('SPIKE_RAISE_RATIO')       ?: 2.0);
define('SPIKE_RESOLVE_RATIO',     getenv('SPIKE_RESOLVE_RATIO')     ?: 1.3);
