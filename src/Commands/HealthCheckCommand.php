<?php

namespace TDT\HealthMonitor\Commands;

use TDT\HealthMonitor\HealthMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use TDT\HealthMonitor\Traits\HealthMonitorLogging;
use TDT\HealthMonitor\Services\ClientLoggingService;

class HealthCheckCommand extends Command
{
    use HealthMonitorLogging;

    protected ClientLoggingService $logger;
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'health:check
                           {--force : Force health check even if disabled}
                           {--output=json : Output format (json|table)}';

    /**
     * The console command description.
     */
    protected $description = 'Perform health check and send report to monitoring server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        
        // Initialize client logging service
        $this->logger = new ClientLoggingService(config('health-monitor'));

        try {
            // Log command start
            $this->logger->logHealthCheckStart([
                'force' => $this->option('force'),
                'output' => $this->option('output'),
            ]);

            if (!config('health-monitor.enabled') && !$this->option('force')) {
                $this->warn('Health monitor is disabled. Use --force to run anyway.');
                return self::FAILURE;
            }

            $config = config('health-monitor');
            if (!$config) {
                $this->error('Health monitor configuration not found. Please publish the config first.');
                return self::FAILURE;
            }

            $healthMonitor = new HealthMonitor($config);
            $report = $healthMonitor->performHealthCheck();

            $duration = round((microtime(true) - $startTime) * 1000);

            // Log successful completion
			$this->logger->getHealthLogger()->info('FullLog', $report);
            $this->logger->logHealthCheckComplete($report, $duration, true);

            if ($this->option('output') === 'table') {
                $this->displayTableOutput($report);
            } else {
                $this->displayJsonOutput($report);
            }

            $this->info('Health check completed successfully.');
            return self::SUCCESS;

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);

            // Log error
            $this->logger->logHealthCheckError($e, $duration);

            $this->error("Health check failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Display report in table format
     */
    protected function displayTableOutput(array $report): void
    {
        $this->info("Server: {$report['server_name']} ({$report['server_ip']})");
        $this->info("Timestamp: {$report['timestamp']}");
        $this->newLine();

        // Supervisor status
        if (isset($report['supervisor'])) {
            $supervisor = $report['supervisor'];
            $this->info('Supervisor Status:');
            
            if ($supervisor['status'] === 'ok') {
                $this->line("  Total Processes: {$supervisor['total_processes']}");
                $this->line("  Running: {$supervisor['running_processes']}");
                $this->line("  Stopped: {$supervisor['stopped_processes']}");
                
                if (!empty($supervisor['processes'])) {
                    $processTable = [];
                    foreach ($supervisor['processes'] as $process) {
                        $processTable[] = [
                            $process['name'],
                            $process['status'],
                            $process['pid'] ?? 'N/A',
                            $process['uptime'] ?? 'N/A'
                        ];
                    }
                    $this->table(['Process', 'Status', 'PID', 'Uptime'], $processTable);
                }
            } else {
                $this->error("  Status: {$supervisor['status']}");
                if (isset($supervisor['message'])) {
                    $this->line("  Message: {$supervisor['message']}");
                }
            }
        }

        // Cron status
        if (isset($report['cron'])) {
            $cron = $report['cron'];
            $this->newLine();
            $this->info('Cron Status:');
            
            if ($cron['status'] === 'ok') {
                $this->line("  User: {$cron['user']}");
                $this->line("  Total Jobs: {$cron['total_jobs']}");
                $this->line("  Active Jobs: {$cron['active_jobs']}");
                $this->line("  Disabled Jobs: {$cron['disabled_jobs']}");
            } else {
                $this->error("  Status: {$cron['status']}");
                if (isset($cron['message'])) {
                    $this->line("  Message: {$cron['message']}");
                }
            }
        }

        // Queue status
        if (isset($report['queues'])) {
            $queues = $report['queues'];
            $this->newLine();
            $this->info('Queue Health:');
            
            if ($queues['status'] === 'ok') {
                $this->line("  Status: OK");
                $this->line("  OK Queues: {$queues['ok_queues']}/{$queues['total_queues']}");
            } else {
                $this->error("  Status: {$queues['status']}");
                $this->line("  OK Queues: {$queues['ok_queues']}/{$queues['total_queues']}");
            }
        }
    }

    /**
     * Display report in JSON format
     */
    protected function displayJsonOutput(array $report): void
    {
        $this->line(json_encode($report, JSON_PRETTY_PRINT));
    }
}