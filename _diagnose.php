<?php
// TEMP diagnostic v2 — DELETE after use
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain');

$root = __DIR__;

require_once $root . '/vendor/autoload.php';
echo "vendor/autoload.php loaded\n";
echo "Monolog\\Logger class: ", class_exists('Monolog\Logger') ? 'yes' : 'NO', "\n";
echo "monolog dir: ", is_dir($root . '/vendor/monolog/monolog') ? 'yes' : 'NO', "\n";
echo "LumineSense\\Services\\Logger class: ", class_exists('LumineSense\Services\Logger') ? 'yes' : 'NO', "\n";
echo "config.php exists: ", file_exists($root . '/src/Config/config.php') ? 'yes' : 'NO', "\n";
echo "load-env.php exists: ", file_exists($root . '/src/Config/load-env.php') ? 'yes' : 'NO', "\n";
echo "Logger.php exists: ", file_exists($root . '/src/Services/Logger.php') ? 'yes' : 'NO', "\n";

echo "\n--- Including db_connect.php (with try/catch) ---\n";
try {
    require $root . '/src/Config/db_connect.php';
    echo "db_connect.php included OK (no throw)\n";
} catch (\Throwable $e) {
    echo "THROWN: ", get_class($e), ": ", $e->getMessage(), "\n";
    echo "at ", $e->getFile(), ":", $e->getLine(), "\n";
    echo $e->getTraceAsString(), "\n";
}

echo "\n--- Capture fatal errors ---\n";
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err) {
        echo "LAST ERROR: type={$err['type']} msg={$err['message']}\n";
        echo "at {$err['file']}:{$err['line']}\n";
    }
});

echo "done\n";
