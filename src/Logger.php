<?php

declare(strict_types=1);
defined('GDBPATH') && defined('SAPI') || die('forbidden');

/**
 * Provides static logging functionality.
 */
class Logger
{
    private const LOG_PATH = GDBPATH . '.logs';
    private const LOG_FILE = 'error.log';
    private const FILE_PERMISSIONS = 0600;
    private const VALID_LEVELS = ['info', 'error', 'warning', 'debug'];

    private static bool $isProductionMode = true;
    private static ?string $logPath = null;
    private static ?string $logFile = null;

    /**
     * Initializes the logger with configuration.
     *
     * @param bool $isProductionMode Whether the application is in production mode.
     * @param string|null $logFilePath Custom log file path (defaults to error.log).
     * @throws RuntimeException If log file creation fails.
     */
    public static function initialize(bool $isProductionMode, ?string $logFilePath = null): void
    {
        self::$isProductionMode = $isProductionMode;

        ini_set('log_errors', '1');
        ini_set('display_errors', $isProductionMode ? '0' : '1');
        ini_set('display_startup_errors', $isProductionMode ? '0' : '1');
        error_reporting(E_ALL);

        if (self::$isProductionMode) {
            self::$logPath = $logFilePath ?? self::LOG_PATH;

            if (!is_dir(self::$logPath)) {
                if (!mkdir(self::$logPath, 0755, true)) {
                    ini_set('error_log', 'php://stderr');
                    throw new RuntimeException("[Logger::initialize] Failed to create log directory: " . self::$logPath);
                }
            }

            if (!is_writable(self::$logPath)) {
                ini_set('error_log', 'php://stderr');
                throw new RuntimeException("[Logger::initialize] Log directory is not writable: " . self::$logPath);
            }

            self::$logFile = realpath(self::$logPath) . DIRECTORY_SEPARATOR . self::LOG_FILE . '.' . date('Y-m-d') . '.log';
            ini_set('error_log', self::$logFile);

            if (!file_exists(self::$logFile)) {
                if (!touch(self::$logFile)) {
                    ini_set('error_log', 'php://stderr');
                    throw new RuntimeException('[Logger::initialize] Failed to create log file: ' . self::$logFile);
                }

                if (!chmod(self::$logFile, self::FILE_PERMISSIONS)) {
                    error_log('[Logger::initialize] Warning: Failed to set permissions on log file: ' . self::$logFile . PHP_EOL);
                }
            }
        }

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            $logMessage = sprintf(
                "Error [%d]: %s in %s:%d",
                $severity,
                $message,
                $file,
                $line
            );

            self::error($logMessage);

            return true;
        });

        set_exception_handler(function (Throwable $e) {
            $logMessage = sprintf(
                "Uncaught exception: %s in %s:%d\nStack Trace: %s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );

            self::error($logMessage);

            exit(1);
        });
    }

    /**
     * Logs a message with the specified level.
     *
     * @param string $message The message to log.
     * @param string $level Log level (info, error, warning, debug).
     * @throws InvalidArgumentException If the log level is invalid.
     */
    public static function log(string $message, string $level = 'info'): void
    {
        $level = strtolower($level);
        if (!in_array($level, self::VALID_LEVELS, true)) {
            throw new InvalidArgumentException("[Logger::log] Invalid log level: $level. Must be one of: " . implode(', ', self::VALID_LEVELS));
        }

        $formattedMessage = sprintf(
            '[%s] %s: %s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message
        );

        if (self::$isProductionMode && self::$logFile) {
            error_log($formattedMessage . PHP_EOL, 3, self::$logFile);
        } else {
            if (SAPI === 'cli') {
                $formattedMessage = sprintf("\033[0;32m%s\033[0m", $formattedMessage);
            } elseif (SAPI === 'http') {
                $formattedMessage = sprintf("<pre>%s</pre>", htmlspecialchars($formattedMessage, ENT_QUOTES, 'UTF-8'));
            }

            echo $formattedMessage . PHP_EOL;
        }
    }

    /**
     * Logs an info message.
     *
     * @param string $message The message to log.
     */
    public static function info(string $message): void
    {
        self::log($message, 'info');
    }

    /**
     * Logs an error message.
     *
     * @param string $message The message to log.
     */
    public static function error(string $message): void
    {
        self::log($message, 'error');
    }

    /**
     * Logs a warning message.
     *
     * @param string $message The message to log.
     */
    public static function warning(string $message): void
    {
        self::log($message, 'warning');
    }

    /**
     * Logs a debug message.
     *
     * @param string $message The message to log.
     */
    public static function debug(string $message): void
    {
        if (!self::$isProductionMode) {
            self::log($message, 'debug');
        }
    }
}
