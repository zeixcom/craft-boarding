<?php

namespace zeix\boarding\utils;

use Craft;

class Logger
{
    private const LOG_FILE_PREFIX = 'boarding-';
    private const LOG_FILE_SUFFIX = '.log';
    private const MAX_LOG_DAYS = 30;

    /**
     * Write a message to the daily log file
     *
     * @param string $message The message to log
     * @param string $level The log level (info, error, warning, debug)
     * @param mixed $data Additional data to log
     * @param string $category The log category
     */
    public static function log(
        string $message,
        string $level = 'info',
        mixed $data = null,
        string $category = 'boarding'
    ): void {
        try {
            $logEntry = self::formatLogEntry(
                $message,
                $level,
                $data,
                $category
            );
            $logPath = self::getLogFilePath();

            $logDir = dirname($logPath);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            self::rotateLogs();


            file_put_contents(
                $logPath,
                $logEntry . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            self::logToCraft($message, $level, $data, $category);
        } catch (\Exception $e) {
            error_log("Custom boarding logging failed: {$e->getMessage()}. Original message: {$message}");
        }
    }

    /**
     * Format log entry with timestamp and structured data
     */
    private static function formatLogEntry(
        string $message,
        string $level,
        mixed $data = null,
        string $category = 'boarding'
    ): string {
        $timestamp = date('Y-m-d H:i:s');
        $logData = [
            'timestamp' => $timestamp,
            'level' => strtoupper($level),
            'category' => $category,
            'message' => $message,
        ];

        if ($data !== null) {
            $logData['data'] = $data;
        }

        return json_encode(
            $logData,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Get the full path to the daily log file
     */
    public static function getLogFilePath(): string
    {
        $logsPath = Craft::getAlias('@storage/logs');
        $date = date('Y-m-d');
        return $logsPath .
            DIRECTORY_SEPARATOR .
            self::LOG_FILE_PREFIX .
            $date .
            self::LOG_FILE_SUFFIX;
    }

    /**
     * Rotate log files, keeping only the most recent MAX_LOG_DAYS
     */
    private static function rotateLogs(): void
    {
        $logsPath = Craft::getAlias('@storage/logs');
        $pattern =
            $logsPath .
            DIRECTORY_SEPARATOR .
            self::LOG_FILE_PREFIX .
            '*' .
            self::LOG_FILE_SUFFIX;
        $files = glob($pattern);
        if ($files === false) {
            return;
        }
        // Sort by filename (date)
        usort($files, function ($a, $b) {
            return strcmp($b, $a);
        });
        // Keep only the most recent MAX_LOG_DAYS
        $filesToDelete = array_slice($files, self::MAX_LOG_DAYS);
        foreach ($filesToDelete as $file) {
            @unlink($file);
        }
    }

    /**
     * Log to Craft's built-in system as well
     */
    private static function logToCraft(
        string $message,
        string $level,
        mixed $data = null,
        string $category = 'boarding'
    ): void {
        $logMessage = $message;
        if ($data !== null) {
            $logMessage .= ' Data: ' . json_encode($data, JSON_PRETTY_PRINT);
        }

        // Only log to Craft if it's available and not in a circular dependency situation
        if (class_exists('Craft') && !defined('BOARDING_LOGGER_DISABLE_CRAFT')) {
            switch ($level) {
                case 'error':
                    Craft::error($logMessage, $category);
                    break;
                case 'warning':
                    Craft::warning($logMessage, $category);
                    break;
                case 'info':
                default:
                    Craft::info($logMessage, $category);
                    break;
            }
        }
    }

    /**
     * Convenience methods for different log levels
     */
    public static function info(
        string $message,
        mixed $data = null,
        string $category = 'boarding'
    ): void {
        self::log($message, 'info', $data, $category);
    }

    public static function warning(
        string $message,
        mixed $data = null,
        string $category = 'boarding'
    ): void {
        self::log($message, 'warning', $data, $category);
    }

    public static function error(
        string $message,
        mixed $data = null,
        string $category = 'boarding'
    ): void {
        self::log($message, 'error', $data, $category);
    }

    public static function debug(
        string $message,
        mixed $data = null,
        string $category = 'boarding'
    ): void {
        self::log($message, 'debug', $data, $category);
    }
}
