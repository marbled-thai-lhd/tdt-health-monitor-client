<?php

namespace TDT\HealthMonitor;

use TDT\HealthMonitor\Services\SupervisorService;
use TDT\HealthMonitor\Services\CronService;
use TDT\HealthMonitor\Services\QueueHealthService;
use TDT\HealthMonitor\Services\ReportingService;
use Illuminate\Support\Facades\Log;

class HealthMonitor
{
    protected array $config;
    protected SupervisorService $supervisorService;
    protected CronService $cronService;
    protected QueueHealthService $queueHealthService;
    protected ReportingService $reportingService;

    public function __construct(array $config)
    {
        $this->config = $config;
		$configQueues = $config['queue_health_check']['queues'] ?? [];
		$configSupervisor = $config['supervisor'] ?? [];
		$configSupervisor['queues'] = $configQueues;
        $this->supervisorService = new SupervisorService($configSupervisor);
        $this->cronService = new CronService($config['cron'] ?? []);
        $this->queueHealthService = new QueueHealthService($config['queue_health_check'] ?? []);
        $this->reportingService = new ReportingService($config);
    }

    /**
     * Perform complete health check and send report
     */
    public function performHealthCheck(bool $forceCheck = false): array
    {
        if (!$this->config['enabled']) {
            Log::info('Health monitor is disabled');
            return ['status' => 'disabled'];
        }

        $report = [
            'server_name' => $this->config['server_name'],
            'server_ip' => $this->getServerIp(),
            'timestamp' => now()->toISOString(),
            'force_check' => $forceCheck,
            'supervisor' => $this->checkSupervisor(),
            'cron' => $this->checkCron(),
            'queues' => $this->checkQueues(),
        ];

        // Send report to monitoring server
        $result = $this->reportingService->sendReport($report);
        
        $logMessage = $forceCheck ? 'Force health check completed' : 'Health check completed';
        Log::info($logMessage, [
            'report' => $report,
            'sent_successfully' => $result
        ]);

        return $report;
    }

    /**
     * Check supervisor processes
     */
    protected function checkSupervisor(): array
    {
        try {
            return $this->supervisorService->checkProcesses();
        } catch (\Exception $e) {
            Log::error('Supervisor check failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check cron jobs
     */
    protected function checkCron(): array
    {
        try {
            return $this->cronService->checkCronJobs();
        } catch (\Exception $e) {
            Log::error('Cron check failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check queue health
     */
    protected function checkQueues(): array
    {
        if (!($this->config['queue_health_check']['enabled'] ?? true)) {
            return ['status' => 'disabled'];
        }

        try {
            return $this->queueHealthService->checkQueueHealth();
        } catch (\Exception $e) {
            Log::error('Queue health check failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Get server IP address
     */
    protected function getServerIp(): string
    {
        if (!empty($this->config['server_ip'])) {
            return $this->config['server_ip'];
        }

        // Try to get public IP
        try {
            $ip = file_get_contents('http://ipecho.net/plain');
            return trim($ip);
        } catch (\Exception $e) {
            // Fallback to local IP
            return $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
        }
    }
}