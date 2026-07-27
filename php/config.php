<?php
// ── LumineSense Configuration ─────────────────────────────

// Hostinger SMTP
define('MAIL_HOST',       'smtp.hostinger.com');
define('MAIL_PORT',       587);
define('MAIL_USERNAME',   'lumi-admin@luminesense-bet.site');
define('MAIL_PASSWORD',   'Luminesense2026!'); 
define('MAIL_FROM_NAME',  'LumineSense');
define('MAIL_FROM_EMAIL', 'lumi-admin@luminesense-bet.site');

// Admin registration code
define('VALID_ADMIN_CODE', 'LUMINESENSE_ADMIN_2025');

// Device token
define('DEVICE_TOKEN', 'luminesense-secret-token');

// ID encryption key
define('ID_ENCRYPTION_KEY', 'SL7iGyu1apH0jn8HZ3JS5Ax+QPtFbtmJJqqnk1VQbbE=');

// Anomaly detection thresholds (tune for your power scale)
define('DROPOUT_POWER_THRESHOLD', 0.2);  // Watts — power below this with lights ON is a dropout
define('DROPOUT_CONFIRM_COUNT',   3);    // Consecutive readings to confirm dropout
define('SPIKE_MIN_AVG_POWER',     0.5);  // Watts — minimum rolling avg to consider spike
define('SPIKE_RAISE_RATIO',       2.0);  // Multiple of avg to trigger spike
define('SPIKE_RESOLVE_RATIO',     1.3);  // Multiple of avg to clear spike