<?php
/**
 * Enhanced Logger for Cacti Device Synchronization
 *
 * Provides structured logging with multiple levels
 */

namespace CactiSync;

class Logger
{
    private $config;
    private $logFile;
    private $logLevel;
    private $consoleOutput;

    const LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
    ];

    public function __construct($config)
    {
        $this->config = $config['logging'] ?? [];
        $this->logFile = $this->config['log_file'] ?? null;
        $this->logLevel = $this->config['level'] ?? 'INFO';
        $this->consoleOutput = $this->config['console_output'] ?? true;

        // Ensure log directory exists
        if ($this->logFile) {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
        }
    }

    /**
     * Log a message
     *
     * @param string $level
     * @param string $message
     * @param string $category
     */
    private function log($level, $message, $category = 'SYNC')
    {
        // Check if this level should be logged
        $currentLevelValue = self::LEVELS[$this->logLevel] ?? 1;
        $messageLevelValue = self::LEVELS[$level] ?? 1;

        if ($messageLevelValue < $currentLevelValue) {
            return; // Skip this message
        }

        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = sprintf(
            "[%s] [%s] [%s] %s",
            $timestamp,
            $level,
            $category,
            $message
        );

        // Write to log file
        if ($this->logFile && ($this->config['enabled'] ?? true)) {
            @file_put_contents(
                $this->logFile,
                $formattedMessage . "\n",
                FILE_APPEND
            );
        }

        // Output to console
        if ($this->consoleOutput) {
            echo $formattedMessage . "\n";
        }

        // Also use Cacti's logging for important messages
        if (function_exists('cacti_log') && in_array($level, ['ERROR', 'WARNING'])) {
            cacti_log($message, false, $category);
        }
    }

    /**
     * Log debug message
     */
    public function debug($message, $category = 'SYNC')
    {
        $this->log('DEBUG', $message, $category);
    }

    /**
     * Log info message
     */
    public function info($message, $category = 'SYNC')
    {
        $this->log('INFO', $message, $category);
    }

    /**
     * Log warning message
     */
    public function warning($message, $category = 'SYNC')
    {
        $this->log('WARNING', $message, $category);
    }

    /**
     * Log error message
     */
    public function error($message, $category = 'SYNC')
    {
        $this->log('ERROR', $message, $category);
    }

    /**
     * Print a section header
     */
    public function section($title, $width = 100)
    {
        if ($this->consoleOutput) {
            echo "\n" . str_repeat("=", $width) . "\n";
            echo $title . "\n";
            echo str_repeat("=", $width) . "\n\n";
        }
    }

    /**
     * Print a progress message
     */
    public function progress($message)
    {
        if ($this->consoleOutput) {
            echo $message;
            flush();
        }
    }

    /**
     * Print result (success/failure)
     */
    public function result($success, $message = '')
    {
        if ($this->consoleOutput) {
            if ($success) {
                echo "✓ " . ($message ?: "Success") . "\n";
            } else {
                echo "✗ " . ($message ?: "Failed") . "\n";
            }
        }
    }
}
