<?php

namespace TDT\HealthMonitor\Commands;

use TDT\HealthMonitor\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use TDT\HealthMonitor\Services\ClientLoggingService;

class DatabaseBackupCommand extends Command
{
    protected ClientLoggingService $logger;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'health:backup-database
                           {--force : Force backup even if disabled}
                           {--upload : Upload to S3 after backup}';

    /**
     * The console command description.
     */
    protected $description = 'Create database backup and optionally upload to S3';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        
        // Initialize client logging service
        $this->logger = new ClientLoggingService(config('health-monitor'));
        
        if (!config('health-monitor.backup.enabled') && !$this->option('force')) {
            $this->warn('Database backup is disabled. Use --force to run anyway.');
            return self::FAILURE;
        }

        $this->info('Starting database backup...');
        
        // Log command start
        $this->logger->logBackupStart([
            'force' => $this->option('force'),
            'upload' => $this->option('upload'),
        ]);

        try {
            $backupService = new DatabaseBackupService(config('health-monitor.backup'));
            $result = $backupService->performBackup($this->option('upload'));
            
            $duration = round((microtime(true) - $startTime) * 1000);
            
            // Log successful completion
            $this->logger->logBackupComplete($result, $duration, true);
            
            $this->info('Database backup completed successfully.');
            $this->line("Backup file: {$result['file_path']}");
            $this->line("File size: " . number_format($result['file_size'] / 1024 / 1024, 2) . " MB");
            
            if ($result['uploaded'] ?? false) {
                $this->info("Uploaded to S3: {$result['s3_path']}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            
            // Log error
            $this->logger->logBackupError($e, $duration, [
                'force' => $this->option('force'),
                'upload' => $this->option('upload'),
            ]);
            
            $this->error('Database backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}