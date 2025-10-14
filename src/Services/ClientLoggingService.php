<?php

namespace TDT\HealthMonitor\Services;

use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class ClientLoggingService
{
    protected array $config;
    protected ?Logger $healthLogger = null;
    protected ?Logger $backupLogger = null;

    public function __construct(array $config = [])
    {
        $this->config = $config['logging'] ?? [];
    }

    /**
     * Get or create health check logger
     */
    public function getHealthLogger(): Logger
    {
        if ($this->healthLogger === null) {
            $this->healthLogger = $this->createLogger('health-check', $this->config['health_check'] ?? []);
        }

        return $this->healthLogger;
    }

    /**
     * Get or create database backup logger
     */
    public function getBackupLogger(): Logger
    {
        if ($this->backupLogger === null) {
            $this->backupLogger = $this->createLogger('database-backup', $this->config['database_backup'] ?? []);
        }

        return $this->backupLogger;
    }

    /**
     * Create a logger instance
     */
    protected function createLogger(string $name, array $config): Logger
    {
        $logger = new Logger($name);

        if (!($config['enabled'] ?? true)) {
            return $logger;
        }

        $path = $config['path'] ?? storage_path("logs/{$name}.log");
        $level = $this->getLogLevel($config['level'] ?? 'info');

        // Ensure log directory exists
        $this->ensureDirectoryExists(dirname($path));

        // Choose handler based on daily rotation setting
        if ($config['daily'] ?? true) {
            $handler = new RotatingFileHandler(
                $path,
                $config['days'] ?? 30,
                $level
            );
        } else {
            $handler = new StreamHandler($path, $level);
        }

        // Set custom formatter
        $formatter = new LineFormatter(
            "[%datetime%] %level_name%: %message% %context%\n",
            'Y-m-d H:i:s'
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Get Monolog log level
     */
    protected function getLogLevel(string $level): int
    {
        return match (strtolower($level)) {
            'debug' => Logger::DEBUG,
            'info' => Logger::INFO,
            'notice' => Logger::NOTICE,
            'warning' => Logger::WARNING,
            'error' => Logger::ERROR,
            'critical' => Logger::CRITICAL,
            'alert' => Logger::ALERT,
            'emergency' => Logger::EMERGENCY,
            default => Logger::INFO,
        };
    }

    /**
     * Ensure directory exists
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Log health check start
     */
    public function logHealthCheckStart(array $options = []): void
    {
        if (!($this->config['health_check']['enabled'] ?? true)) {
            return;
        }

        $this->getHealthLogger()->info('Health check command started', [
            'timestamp' => now()->toISOString(),
            'server_name' => config('health-monitor.server_name'),
            'server_ip' => config('health-monitor.server_ip'),
            'force' => $options['force'] ?? false,
            'output_format' => $options['output'] ?? 'json',
            'monitoring_url' => config('health-monitor.monitoring_url'),
        ]);
    }

    /**
     * Log health check completion
     */
    public function logHealthCheckComplete(array $report, int $duration, bool $success = true): void
    {
        if (!($this->config['health_check']['enabled'] ?? true)) {
            return;
        }

        $logData = [
            'timestamp' => now()->toISOString(),
            'duration_ms' => $duration,
            'success' => $success,
            'server_name' => $report['server_name'] ?? config('health-monitor.server_name'),
            'server_ip' => $report['server_ip'] ?? config('health-monitor.server_ip'),
        ];

        // Add component statuses
        if (isset($report['supervisor'])) {
            $logData['supervisor'] = [
                'status' => $report['supervisor']['status'],
                'total_processes' => $report['supervisor']['total_processes'] ?? 0,
                'running_processes' => $report['supervisor']['running_processes'] ?? 0,
            ];
        }

        if (isset($report['cron'])) {
            $logData['cron'] = [
                'status' => $report['cron']['status'],
                'total_jobs' => $report['cron']['total_jobs'] ?? 0,
                'active_jobs' => $report['cron']['active_jobs'] ?? 0,
            ];
        }

        if (isset($report['queues'])) {
            $logData['queues'] = [
                'status' => $report['queues']['status'],
                'ok_queues' => $report['queues']['ok_queues'] ?? 0,
                'total_queues' => $report['queues']['total_queues'] ?? 0,
            ];
        }

        if ($success) {
            $this->getHealthLogger()->info('Health check completed successfully', $logData);
        } else {
            $this->getHealthLogger()->error('Health check failed', $logData);
        }
    }

    /**
     * Log health check error
     */
    public function logHealthCheckError(\Exception $e, int $duration): void
    {
        if (!($this->config['health_check']['enabled'] ?? true)) {
            return;
        }

        $this->getHealthLogger()->error('Health check command failed', [
            'timestamp' => now()->toISOString(),
            'duration_ms' => $duration,
            'server_name' => config('health-monitor.server_name'),
            'server_ip' => config('health-monitor.server_ip'),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Log database backup start
     */
    public function logBackupStart(array $options = []): void
    {
        if (!($this->config['database_backup']['enabled'] ?? true)) {
            return;
        }

        $this->getBackupLogger()->info('Database backup command started', [
            'timestamp' => now()->toISOString(),
            'server_name' => config('health-monitor.server_name'),
            'server_ip' => config('health-monitor.server_ip'),
            'force' => $options['force'] ?? false,
            'upload' => $options['upload'] ?? false,
            'config' => config('health-monitor.backup'),
        ]);
    }

    /**
     * Log database backup completion
     */
    public function logBackupComplete(array $result, int $duration, bool $success = true): void
    {
        if (!($this->config['database_backup']['enabled'] ?? true)) {
            return;
        }

        $logData = [
            'timestamp' => now()->toISOString(),
            'duration_ms' => $duration,
            'success' => $success,
            'server_name' => config('health-monitor.server_name'),
            'server_ip' => config('health-monitor.server_ip'),
        ];

        if ($success && isset($result['file_path'])) {
            $logData['file_path'] = $result['file_path'];
            $logData['file_size_bytes'] = $result['file_size'] ?? 0;
            $logData['file_size_mb'] = round(($result['file_size'] ?? 0) / 1024 / 1024, 2);
            $logData['uploaded'] = $result['uploaded'] ?? false;
            
            if ($result['uploaded'] ?? false) {
                $logData['s3_path'] = $result['s3_path'] ?? null;
            }
        }

        if ($success) {
            $this->getBackupLogger()->info('Database backup completed successfully', $logData);
        } else {
            $this->getBackupLogger()->error('Database backup failed', $logData);
        }
    }

    /**
     * Log database backup error
     */
    public function logBackupError(\Exception $e, int $duration, array $options = []): void
    {
        if (!($this->config['database_backup']['enabled'] ?? true)) {
            return;
        }

        $this->getBackupLogger()->error('Database backup command failed', [
            'timestamp' => now()->toISOString(),
            'duration_ms' => $duration,
            'server_name' => config('health-monitor.server_name'),
            'server_ip' => config('health-monitor.server_ip'),
            'force' => $options['force'] ?? false,
            'upload' => $options['upload'] ?? false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}