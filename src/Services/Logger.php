<?php
namespace LumineSense\Services;

use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;

/**
 * Logger.php
 * ---------------------------------------------------------
 * Centralized application logger built on Monolog.
 *
 * Writes to logs/app.log with daily rotation (30 days kept),
 * plus a separate error.log for warnings and above so errors
 * are easy to tail/filter.
 *
 * Usage from anywhere (autoloaded via PSR-4):
 *   LumineSense\Services\Logger::error('message', ['ctx' => $x]);
 *   LumineSense\Services\Logger::info('message');
 * ---------------------------------------------------------
 */
final class Logger
{
    private static ?MonologLogger $instance = null;

    public static function instance(): MonologLogger
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logger = new MonologLogger('luminesense');

        // Daily-rotating full log (all levels)
        $logger->pushHandler(new RotatingFileHandler($logDir . '/app.log', 30, Level::Debug));
        // Error-only log, separate file
        $logger->pushHandler(new StreamHandler($logDir . '/error.log', Level::Warning));

        self::$instance = $logger;
        return $logger;
    }

    public static function __callStatic(string $method, array $arguments): void
    {
        if (!method_exists(self::instance(), $method)) {
            return;
        }
        self::instance()->{$method}(...$arguments);
    }

    public static function setLogger(MonologLogger $logger): void
    {
        self::$instance = $logger;
    }
}
