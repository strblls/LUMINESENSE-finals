<?php
// TEMP diagnostic — DELETE after use
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain');

echo "PHP ", PHP_VERSION, "\n";
echo "mysqli: ", extension_loaded('mysqli') ? 'yes' : 'NO', "\n";
echo "curl: ", extension_loaded('curl') ? 'yes' : 'NO', "\n";
echo "mbstring: ", extension_loaded('mbstring') ? 'yes' : 'NO', "\n";
echo "pdo_mysql: ", extension_loaded('pdo_mysql') ? 'yes' : 'NO', "\n";

$root = __DIR__;
echo "root: $root\n";
echo ".env exists: ", file_exists($root . '/.env') ? 'yes' : 'NO', "\n";
if (file_exists($root . '/.env')) {
    echo ".env path: ", realpath($root . '/.env'), "\n";
    $keys = ['DB_HOST', 'DB_USER', 'DB_NAME'];
    foreach ($keys as $k) {
        $v = getenv($k) ?: '(unset)';
        echo "$k = $v\n";
    }
}
echo "vendor/autoload exists: ", file_exists($root . '/vendor/autoload.php') ? 'yes' : 'NO', "\n";
echo "Monolog present: ", class_exists('Monolog\Logger') ? 'yes' : 'NO', "\n";
echo "logs dir writable: ", (is_dir($root . '/logs') || @mkdir($root . '/logs', 0775, true)) && is_writable($root . '/logs') ? 'yes' : 'NO', "\n";

echo "\n--- Including db_connect.php ---\n";
require_once $root . '/src/Config/db_connect.php';
echo "db_connect included OK\n";
echo "DB_HOST = ", defined('DB_HOST') ? DB_HOST : '(undefined)', "\n";
echo "DB_USER = ", defined('DB_USER') ? DB_USER : '(undefined)', "\n";
echo "DB_NAME = ", defined('DB_NAME') ? DB_NAME : '(undefined)', "\n";
echo "connected = ", ($conn && !$conn->connect_error) ? 'yes' : 'NO: ' . ($conn->connect_error ?? 'null'), "\n";
