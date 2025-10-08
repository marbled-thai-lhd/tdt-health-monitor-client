<?php

namespace TDT\HealthMonitor\Commands;

use Illuminate\Console\Command;

class SetupScheduleCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'health:setup-schedule';

    /**
     * The console command description.
     */
    protected $description = 'Setup cron schedule for health monitoring';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Setting up health monitoring schedule...');

        if (!config('health-monitor.enabled')) {
            $this->warn('Health monitor is disabled in configuration.');
            return self::FAILURE;
        }

        // Show current configuration
        $this->displayConfiguration();

        // Check if Laravel scheduler is running
        $this->checkSchedulerStatus();

        // Provide setup instructions
        $this->displaySetupInstructions();

        return self::SUCCESS;
    }

    /**
     * Display current configuration
     */
    protected function displayConfiguration(): void
    {
        $config = config('health-monitor');
        
        $this->info('Current Configuration:');
        $this->table(['Setting', 'Value'], [
            ['Enabled', $config['enabled'] ? 'Yes' : 'No'],
            ['Server Name', $config['server_name']],
            ['Monitoring URL', $config['monitoring_url'] ?: 'Not configured'],
            ['Check Interval', $config['check_interval'] . ' minutes'],
            ['Backup Enabled', $config['backup']['enabled'] ? 'Yes' : 'No'],
            ['S3 Bucket', $config['backup']['s3']['bucket'] ?: 'Not configured'],
        ]);
    }

    /**
     * Check if Laravel scheduler is running
     */
    protected function checkSchedulerStatus(): void
    {
        $this->info('Checking Laravel Scheduler...');
        
        $currentProjectPath = base_path();
        
        // Check if schedule:run is in crontab for THIS specific project
        $crontab = shell_exec('crontab -l 2>/dev/null');
        
        if ($crontab) {
            // Check if this specific project path is in crontab with schedule:run
            $lines = explode("\n", $crontab);
            $foundCurrentProject = false;
            
            foreach ($lines as $line) {
                if (strpos($line, 'schedule:run') !== false && strpos($line, $currentProjectPath) !== false) {
                    $foundCurrentProject = true;
                    break;
                }
            }
            
            if ($foundCurrentProject) {
                $this->info('✓ Laravel scheduler is configured for this project');
            } else {
                $this->warn('⚠ Laravel scheduler not found for this specific project');
                $this->line('You need to add the Laravel scheduler for this project to your crontab.');
                $this->line('Expected cron entry:');
                $this->line("* * * * * cd {$currentProjectPath} && php artisan schedule:run >> /dev/null 2>&1");
                
                // Show existing schedule:run entries for reference
                $otherProjects = [];
                foreach ($lines as $line) {
                    if (strpos($line, 'schedule:run') !== false && !empty(trim($line))) {
                        $otherProjects[] = trim($line);
                    }
                }
                
                if (!empty($otherProjects)) {
                    $this->newLine();
                    $this->comment('Found other Laravel scheduler entries:');
                    foreach ($otherProjects as $entry) {
                        $this->line('  ' . $entry);
                    }
                }
            }
        } else {
            $this->warn('⚠ No crontab found for current user');
            $this->line('You need to add the Laravel scheduler to your crontab.');
        }
    }

    /**
     * Display setup instructions
     */
    protected function displaySetupInstructions(): void
    {
        $this->newLine();
        $this->info('Setup Instructions:');
        
        $this->line('1. Configure your .env file with the required settings:');
        $this->line('   HEALTH_MONITOR_ENABLED=true');
        $this->line('   HEALTH_MONITOR_URL=https://your-monitoring-server.com/api/health-report');
        $this->line('   HEALTH_MONITOR_API_KEY=your-secret-key');
        $this->line('   HEALTH_MONITOR_SERVER_NAME=' . gethostname());
        
        $this->newLine();
        $this->line('2. Add Laravel scheduler to your crontab:');
        $this->line('   * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1');
        
        $this->newLine();
        $this->line('3. For database backups, configure S3 settings:');
        $this->line('   DB_BACKUP_ENABLED=true');
        $this->line('   DB_BACKUP_S3_BUCKET=your-backup-bucket');
        $this->line('   AWS_ACCESS_KEY_ID=your-access-key');
        $this->line('   AWS_SECRET_ACCESS_KEY=your-secret-key');
        
        $this->newLine();
        $this->line('4. Test the health check manually:');
        $this->line('   php artisan health:check --force');
        
        $this->newLine();
        $this->line('5. Test database backup manually:');
        $this->line('   php artisan health:backup-database --force');
        
        $this->newLine();
        $this->info('For more information, check the package documentation.');
    }
}