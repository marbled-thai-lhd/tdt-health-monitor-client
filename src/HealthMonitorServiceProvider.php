<?php

namespace TDT\HealthMonitor;

use TDT\HealthMonitor\Commands\HealthCheckCommand;
use TDT\HealthMonitor\Commands\DatabaseBackupCommand;
use TDT\HealthMonitor\Commands\SetupScheduleCommand;
use Illuminate\Support\ServiceProvider;

class HealthMonitorServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/health-monitor.php',
            'health-monitor'
        );

        $this->app->singleton('health-monitor', function ($app) {
            return new HealthMonitor($app['config']['health-monitor']);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/health-monitor.php' => config_path('health-monitor.php'),
            ], 'health-monitor-config');

            // Register commands
            $this->commands([
                HealthCheckCommand::class,
                DatabaseBackupCommand::class,
                SetupScheduleCommand::class,
            ]);
        }

        // Auto-register scheduled tasks if enabled
        if (config('health-monitor.enabled')) {
            $this->registerScheduledTasks();
        }
    }

    /**
     * Register scheduled tasks in the application's schedule.
     */
    protected function registerScheduledTasks(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        // Health check every 5 minutes (or configured interval)
        $interval = config('health-monitor.check_interval', 5);
        $schedule->command('health:check')
                 ->everyMinutes($interval)
                 ->withoutOverlapping()
                 ->runInBackground();

        // Database backup if enabled
        if (config('health-monitor.backup.enabled')) {
            $backupSchedule = config('health-monitor.backup.schedule', '0 2 * * *');
            $schedule->command('health:backup-database')
                     ->cron($backupSchedule)
                     ->withoutOverlapping();
        }
    }
}