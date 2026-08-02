<?php
namespace LumineSense\Services;

use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NullHandler;

/**
 * Logger.php
 * ---------------------------------------------------------
 * Centralized application logger built on Monolog.
 *
 * Writes to logs/app.log with daily rotation (30 days kept),
 * plus a separate error.log for warnings and above so errors
 * are easy to tail/filter.
 *
 * FAIL-SAFE: if the log directory can't be created/written
 * (e.g. read-only hosting), it silently falls back to a
 * NullHandler so logging can NEVER cause a 500 or break a
 * request.
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

        $logger = new MonologLogger('luminesense');

        try {
            $logDir = __DIR__ . '/../../logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            if (!is_writable($logDir)) {
                throw new \RuntimeException('Log directory not writable: ' . $logDir);
            }

            // Daily-rotating full log (all levels)
            $logger->pushHandler(new RotatingFileHandler($logDir . '/app.log', 30, Level::Debug));
            // Error-only log, separate file
            $logger->pushHandler(new StreamHandler($logDir . '/error.log', Level::Warning));
        } catch (\Throwable $e) {
            // Never let logging break the app — write to PHP's own error log as a last resort.
            $logger->pushHandler(new NullHandler(Level::Debug));
        }

        self::$instance = $logger;
        return $logger;
    }

    public static function __callStatic(string $method, array $arguments): void
    {
        try {
            $logger = self::instance();
            if (method_exists($logger, $method)) {
                $logger->{$method}(...$arguments);
            }
        } catch (\Throwable $e) {
            // Ignore logging failures entirely.
        }
    }

    public static function setLogger(MonologLogger $logger): void
    {
        self::$instance = $logger;
    }
}
