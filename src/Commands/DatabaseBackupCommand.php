<?php

namespace TDT\HealthMonitor\Commands;

use TDT\HealthMonitor\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use TDT\HealthMonitor\Traits\HealthMonitorLogging;

class DatabaseBackupCommand extends Command
{
    use HealthMonitorLogging;
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'health:backup-database
                           {--force : Force backup even if disabled}
                           {--upload : Upload to S3 after backup}';

    /**
     * The console command description.
     */
    protected $description = 'Backup database and optionally upload to S3';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        
        if (!config('health-monitor.backup.enabled') && !$this->option('force')) {
            $this->warn('Database backup is disabled. Use --force to run anyway.');
            
            Log::channel('database-backup')->warning('Database backup skipped - disabled', [
                'timestamp' => now()->toISOString(),
                'forced' => false,
                'enabled' => false,
                'upload_requested' => $this->option('upload')
            ]);
            
            return self::FAILURE;
        }

        $this->info('Starting database backup...');
        
        Log::channel('database-backup')->info('Database backup started', [
            'timestamp' => now()->toISOString(),
            'forced' => $this->option('force'),
            'upload_requested' => $this->option('upload'),
            'config' => config('health-monitor.backup')
        ]);

        try {
            $backupService = new DatabaseBackupService(config('health-monitor.backup'));
            $result = $backupService->performBackup($this->option('upload'));
            
            $duration = round((microtime(true) - $startTime) * 1000);
            
            $this->logBackupSummary($result, $duration, true);
            
            $this->info('Database backup completed successfully.');
            $this->line("Backup file: {$result['file_path']}");
            $this->line("File size: " . number_format($result['file_size'] / 1024 / 1024, 2) . " MB");
            
            if ($result['uploaded'] ?? false) {
                $this->info("Uploaded to S3: {$result['s3_path']}");
                Log::channel('database-backup')->info('Database backup completed and uploaded to S3', [
                    'timestamp' => now()->toISOString(),
                    'duration_ms' => $duration,
                    'file_path' => $result['file_path'],
                    'file_size_bytes' => $result['file_size'],
                    'file_size_mb' => round($result['file_size'] / 1024 / 1024, 2),
                    'uploaded' => true,
                    's3_path' => $result['s3_path']
                ]);
            } else {
                Log::channel('database-backup')->info('Database backup completed (local only)', [
                    'timestamp' => now()->toISOString(),
                    'duration_ms' => $duration,
                    'file_path' => $result['file_path'],
                    'file_size_bytes' => $result['file_size'],
                    'file_size_mb' => round($result['file_size'] / 1024 / 1024, 2),
                    'uploaded' => false
                ]);
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            
            $this->logBackupSummary([], $duration, false);
            
            $this->error('Database backup failed: ' . $e->getMessage());
            
            Log::channel('database-backup')->error('Database backup failed with exception', [
                'timestamp' => now()->toISOString(),
                'duration_ms' => $duration,
                'forced' => $this->option('force'),
                'upload_requested' => $this->option('upload'),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            
            return self::FAILURE;
        }
    }
}