<?php

namespace TDT\HealthMonitor\Services;

class SupervisorService
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Check supervisor processes by parsing config files
     */
    public function checkProcesses(): array
    {
        $configPath = $this->config['config_path'] ?? '/etc/supervisor/conf.d';
        $processes = [];

        if (!is_dir($configPath)) {
            return [
                'status' => 'error',
                'message' => "Supervisor config directory not found: {$configPath}",
                'processes' => []
            ];
        }

        // Parse supervisor config files
        $configFiles = glob($configPath . '/*.conf');
        
        foreach ($configFiles as $configFile) {
            $processConfigs = $this->parseConfigFile($configFile);
            foreach ($processConfigs as $processConfig) {
                $status = $this->checkProcessStatus($processConfig['name']);
                $processes[] = [
                    'name' => $processConfig['name'],
                    'command' => $processConfig['command'] ?? '',
                    'status' => $status['status'],
                    'pid' => $status['pid'] ?? null,
                    'uptime' => $status['uptime'] ?? null,
                    'config_file' => basename($configFile)
                ];
            }
        }

        $totalProcesses = count($processes);
        $runningProcesses = count(array_filter($processes, fn($p) => $p['status'] === 'RUNNING'));

        return [
            'status' => $totalProcesses > 0 ? 'ok' : 'no_processes',
            'total_processes' => $totalProcesses,
            'running_processes' => $runningProcesses,
            'stopped_processes' => $totalProcesses - $runningProcesses,
            'processes' => $processes
        ];
    }

    /**
     * Parse supervisor config file
     */
    protected function parseConfigFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $processes = [];
        
        // Parse INI-style config
        $sections = parse_ini_string($content, true);
        
        foreach ($sections as $sectionName => $sectionData) {
            if (strpos($sectionName, 'program:') === 0) {
                $processName = str_replace('program:', '', $sectionName);
                $processes[] = [
                    'name' => $processName,
                    'command' => $sectionData['command'] ?? '',
                    'directory' => $sectionData['directory'] ?? '',
                    'user' => $sectionData['user'] ?? '',
                    'autostart' => $sectionData['autostart'] ?? 'true',
                    'autorestart' => $sectionData['autorestart'] ?? 'true',
                ];
            }
        }
        
        return $processes;
    }

    /**
     * Check individual process status using supervisorctl
     */
    protected function checkProcessStatus(string $processName): array
    {
        try {
            $socketPath = $this->config['socket_path'] ?? '/var/run/supervisor.sock';
            $command = "supervisorctl -s unix://{$socketPath} status {$processName}";
            
            $output = shell_exec($command);
            
            if ($output === null) {
                return ['status' => 'UNKNOWN', 'error' => 'Unable to execute supervisorctl'];
            }
            
            return $this->parseStatusOutput(trim($output));
            
        } catch (\Exception $e) {
            return ['status' => 'ERROR', 'error' => $e->getMessage()];
        }
    }

    /**
     * Parse supervisorctl status output
     */
    protected function parseStatusOutput(string $output): array
    {
        // Example output: "process_name RUNNING pid 1234, uptime 1:23:45"
        $parts = preg_split('/\s+/', $output);
        
        if (count($parts) < 2) {
            return ['status' => 'UNKNOWN'];
        }
        
        $status = [
            'status' => $parts[1] ?? 'UNKNOWN'
        ];
        
        // Extract PID if running
        if (preg_match('/pid (\d+)/', $output, $matches)) {
            $status['pid'] = (int)$matches[1];
        }
        
        // Extract uptime if running
        if (preg_match('/uptime ([0-9:]+)/', $output, $matches)) {
            $status['uptime'] = $matches[1];
        }
        
        return $status;
    }
}