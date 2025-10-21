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

        // Bind HealthMonitor as singleton with config
        $this->app->singleton(HealthMonitor::class, function ($app) {
            return new HealthMonitor($app['config']['health-monitor']);
        });

        // Also bind with string key for backward compatibility
        $this->app->singleton('health-monitor', function ($app) {
            return $app->make(HealthMonitor::class);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

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

        // Health check every X minutes (configurable interval)
        $interval = config('health-monitor.check_interval', 5);
        
        if ($interval === 1) {
            $schedule->command('health:check')
                     ->everyMinute()
                     ->withoutOverlapping()
                     ->runInBackground();
        } else {
            // For intervals > 1 minute, use cron expression
            $schedule->command('health:check')
                     ->cron("*/{$interval} * * * *")
                     ->withoutOverlapping()
                     ->runInBackground();
        }

        // Database backup if enabled
        if (config('health-monitor.backup.enabled')) {
            $backupSchedule = config('health-monitor.backup.schedule', '0 2 * * *');
            $schedule->command('health:backup-database --upload')
                     ->cron($backupSchedule)
                     ->withoutOverlapping();
        }
    }
}