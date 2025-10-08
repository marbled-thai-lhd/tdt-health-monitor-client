<?php

namespace TDT\HealthMonitor\Commands;

use TDT\HealthMonitor\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class DatabaseBackupCommand extends Command
{
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
        if (!config('health-monitor.backup.enabled') && !$this->option('force')) {
            $this->warn('Database backup is disabled. Use --force to run anyway.');
            return self::FAILURE;
        }

        $this->info('Starting database backup...');

        try {
            $backupService = new DatabaseBackupService(config('health-monitor.backup'));
            
            $result = $backupService->performBackup($this->option('upload'));
            
            $this->info('Database backup completed successfully.');
            $this->line("Backup file: {$result['file_path']}");
            $this->line("File size: " . number_format($result['file_size'] / 1024 / 1024, 2) . " MB");
            
            if ($result['uploaded'] ?? false) {
                $this->info("Uploaded to S3: {$result['s3_path']}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Database backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}