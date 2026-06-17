<?php
// php/config.php
// Central place for all secrets and device tokens.
// Never commit real tokens to a public repo.

define('DEVICE_TOKEN',  'LS_ESP32_TOKEN_2025');  // X-Device-Token header (pzem_push)
define('ESP32_TOKEN',   'LS_ESP32_TOKEN_2025');  // ?token= query param  (schedule-flag)
define('PIR_TOKEN',     'LS_PIR_TOKEN_2025');    // arduino_token POST field (pir.php)
define('PZEM_TOKEN',    'LS_PZEM_TOKEN_2025');    // arduino_token POST field (pzem.php)