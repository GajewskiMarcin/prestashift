<?php
/**
 * PrestaShift Migration Module
 *
 * @author    marcingajewski.pl <kontakt@marcin.gajewski.pl>
 * @copyright 2026 marcingajewski.pl
 * @version   1.0.0
 */
namespace PrestaShift\Service;

class LogService
{
    private static $instance;
    private $logFile;
    private $enabled = false;

    private function __construct()
    {
        $logDir = _PS_ROOT_DIR_ . '/var/logs/';
        if (!is_dir($logDir)) {
            $logDir = _PS_ROOT_DIR_ . '/log/';
        }
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . 'prestashift.log';
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = (bool)$enabled;
    }

    public function info($message)
    {
        $this->write('INFO', $message);
    }

    public function error($message)
    {
        $this->write('ERROR', $message);
    }

    public function warning($message)
    {
        $this->write('WARN', $message);
    }

    private function write($level, $message)
    {
        if (!$this->enabled) {
            return;
        }
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get log file path
     */
    public function getLogFile()
    {
        return $this->logFile;
    }

    /**
     * Get last N lines from log
     */
    public function getLastLines($n = 100)
    {
        if (!file_exists($this->logFile)) {
            return '';
        }
        $lines = file($this->logFile);
        return implode('', array_slice($lines, -$n));
    }

    /**
     * Clear log file
     */
    public function clear()
    {
        if (file_exists($this->logFile)) {
            @file_put_contents($this->logFile, '');
        }
    }
}
