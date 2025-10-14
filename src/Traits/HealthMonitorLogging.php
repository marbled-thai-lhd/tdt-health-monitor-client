<?php

namespace TDT\HealthMonitor\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

trait HealthMonitorLogging
{
    /**
     * Ensure log directories exist
     */
    protected function ensureLogDirectoriesExist(): void
    {
        $logPaths = [
            storage_path('logs'),
            dirname(storage_path('logs/health-check.log')),
            dirname(storage_path('logs/database-backup.log')),
        ];

        foreach ($logPaths as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Log health check summary
     */
    protected function logHealthCheckSummary(array $report, int $duration, bool $success = true): void
    {
        $this->ensureLogDirectoriesExist();

        $logData = [
            'timestamp' => now()->toISOString(),
            'duration_ms' => $duration,
            'success' => $success,
            'server_name' => $report['server_name'] ?? 'unknown',
            'server_ip' => $report['server_ip'] ?? 'unknown',
        ];

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
            Log::channel('health-check')->info('Health check summary', $logData);
        } else {
            Log::channel('health-check')->error('Health check failed summary', $logData);
        }
    }

    /**
     * Log backup summary
     */
    protected function logBackupSummary(array $result, int $duration, bool $success = true): void
    {
        $this->ensureLogDirectoriesExist();

        $logData = [
            'timestamp' => now()->toISOString(),
            'duration_ms' => $duration,
            'success' => $success,
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
            Log::channel('database-backup')->info('Database backup summary', $logData);
        } else {
            Log::channel('database-backup')->error('Database backup failed summary', $logData);
        }
    }
}