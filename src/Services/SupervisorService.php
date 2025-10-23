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
     * Get current Laravel application path for filtering
     */
    protected function getApplicationPath(): string
    {
        // Get Laravel base path
        if (function_exists('base_path')) {
            return realpath(base_path()) ?: base_path();
        }
        
        // Fallback: try to detect from current working directory
        $cwd = getcwd();
        if ($cwd && file_exists($cwd . '/artisan')) {
            return $cwd;
        }
        
        // Another fallback: check parent directories for Laravel
        $path = __DIR__;
        while ($path !== '/' && $path !== '') {
            if (file_exists($path . '/artisan') || file_exists($path . '/bootstrap/app.php')) {
                return $path;
            }
            $path = dirname($path);
        }
        
        return '';
    }

    /**
     * Check if a process belongs to this project
     */
    protected function belongsToProject(array $processConfig, string $configFile): bool
    {
        $projectPath = $this->getApplicationPath();
        if (!$projectPath) {
            return true; // Can't detect project path, include all
        }

        // Check command for project path
        if (stripos($processConfig['command'] ?? '', $projectPath) !== false) {
            return true;
        }

        // Check directory for project path
        if (stripos($processConfig['directory'] ?? '', $projectPath) !== false) {
            return true;
        }

        // Check if process name contains project folder name
        $projectName = basename($projectPath);
        if (stripos($processConfig['name'], $projectName) !== false) {
            return true;
        }

        // Check config file name for project name
        if (stripos($configFile, $projectName) !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check supervisor processes by parsing config files
     */
    public function checkProcesses(): array
    {
        $configPath = $this->config['config_path'] ?? '/etc/supervisor/conf.d';
        $processes = [];
        $errors = [];

        if (!is_dir($configPath)) {
            return [
                'status' => 'error',
                'message' => "Supervisor config directory not found: {$configPath}",
                'processes' => []
            ];
        }

        // Parse supervisor config files, accepting .conf .ini
        $configFiles = glob($configPath . '/*.{conf,ini}', GLOB_BRACE);
        
        if (empty($configFiles)) {
            return [
                'status' => 'warning',
                'message' => "No supervisor config files found in: {$configPath}",
                'processes' => []
            ];
        }
        
        foreach ($configFiles as $configFile) {
            try {
                $processConfigs = $this->parseConfigFile($configFile);
                foreach ($processConfigs as $processConfig) {
                    // Filter processes by project if enabled
                    if (!$this->belongsToProject($processConfig, $configFile)) {
                        continue; // Skip processes that don't belong to this project
                    }

                    $status = $this->checkProcessStatus($processConfig['name']);
                    
                    $processInfo = [
                        'name' => $processConfig['name'],
                        'command' => $processConfig['command'] ?? '',
                        'status' => $status['status'],
                        'config_file' => basename($configFile),
                        'directory' => $processConfig['directory'] ?? '',
                        'project_filtered' => true
                    ];
                    
                    // Add process-specific information
                    if (isset($status['pid'])) {
                        $processInfo['pid'] = $status['pid'];
                    }
                    
                    if (isset($status['uptime'])) {
                        $processInfo['uptime'] = $status['uptime'];
                    }
                    
                    // Add process group information if available
                    if (isset($status['process_group'])) {
                        $processInfo['process_group'] = $status['process_group'];
                        $processInfo['running_count'] = $status['running_count'];
                        $processInfo['total_count'] = $status['total_count'];
                        
                        if (isset($status['pids'])) {
                            $processInfo['pids'] = $status['pids'];
                        }
                        
                        if (isset($status['uptimes'])) {
                            $processInfo['uptimes'] = $status['uptimes'];
                        }
                    }
                    
                    $processes[] = $processInfo;
                }
            } catch (\Exception $e) {
                $errors[] = "Error processing " . basename($configFile) . ": " . $e->getMessage();
            }
        }

        $totalProcesses = count($processes);
        $runningProcesses = count(array_filter($processes, function($p) {
            return $p['status'] === 'RUNNING' || $p['status'] === 'PARTIAL';
        }));
        $fullyRunningProcesses = count(array_filter($processes, fn($p) => $p['status'] === 'RUNNING'));

        // Get queues that need to be checked
        $requiredQueues = $this->getQueues();
        
        // Filter running processes and get unique queue names
        $runningProcessNames = array_filter($processes, function($p) {
            return $p['status'] === 'RUNNING' || $p['status'] === 'PARTIAL' || $p['status'] === 'UNKNOWN';
        });
        $runningQueueNames = array_unique(array_map(fn($p) => $p['name'], $runningProcessNames));
        
        // Check if all required queues are running
        $runningQueueCount = count($runningQueueNames);
        $requiredQueueCount = count($requiredQueues);
        
        // Determine status based on queue comparison
        if ($runningQueueCount < $requiredQueueCount) {
            $status = 'error';
            $missingQueues = array_diff($requiredQueues, $runningQueueNames);
        } else if (array_filter($runningProcessNames, fn($p) => $p['status'] === 'UNKNOWN')) {
            $status = 'warning';
        } else {
            $status = 'ok';
        }
        
        if (!empty($errors)) {
            $status = 'warning';
        }

        $result = [
            'status' => $status,
            'total_processes' => $totalProcesses,
            'running_processes' => $runningProcesses,
            'fully_running_processes' => $fullyRunningProcesses,
            'stopped_processes' => $totalProcesses - $runningProcesses,
            'required_queues' => $requiredQueues,
            'running_queues' => array_values($runningQueueNames),
            'running_queue_count' => $runningQueueCount,
            'required_queue_count' => $requiredQueueCount,
            'processes' => $processes,
            'project_path' => $this->getApplicationPath(),
            'project_filter_enabled' => !empty($this->getApplicationPath())
        ];
        
        // Add specific error message for missing queues
        if (isset($missingQueues) && !empty($missingQueues)) {
            $result['missing_queues'] = array_values($missingQueues);
            $result['message'] = 'Required queues not running: ' . implode(', ', $missingQueues);
        }
        
        if (!empty($errors)) {
            $result['errors'] = $errors;
            if (isset($result['message'])) {
                $result['message'] .= '; Config parsing errors: ' . implode('; ', $errors);
            } else {
                $result['message'] = 'Some config files had parsing errors: ' . implode('; ', $errors);
            }
        }
        
        return $result;
    }

	protected function getQueues(): array
    {
        $queuesConfig = $this->config['queues'] ?? 'default';
        
        if (is_string($queuesConfig)) {
            return array_map('trim', explode(',', $queuesConfig));
        }
        
        return is_array($queuesConfig) ? $queuesConfig : ['default'];
    }

    /**
     * Parse supervisor config file
     */
    protected function parseConfigFile(string $filePath): array
    {
        try {
            $content = file_get_contents($filePath);
            
            if ($content === false) {
                return [];
            }
            
            $processes = [];
            
            // Parse INI-style config with error handling
            $sections = @parse_ini_string($content, true);
            
            if ($sections === false) {
                // If parse_ini_string fails, try manual parsing
                return $this->manualParseConfig($content, $filePath);
            }
            
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
            
        } catch (\Exception $e) {
            // Log error and return empty array to continue processing other files
            error_log("Error parsing supervisor config file {$filePath}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check individual process status using supervisorctl
     */
    protected function checkProcessStatus(string $processName): array
    {
        try {
            $socketPath = $this->config['socket_path'] ?? null;
            
            // Auto-detect socket path if not specified
            if ($socketPath === null) {
                $socketPath = $this->findSocketPath();
            }
            
            // Find supervisorctl executable path
            $supervisorctl = $this->findSupervisorctl();
            
            // Try multiple commands to get process status
            $commands = [];
            
            if ($socketPath && file_exists($socketPath)) {
                $commands[] = "{$supervisorctl} -s unix://{$socketPath} status {$processName}:* 2>&1";
                $commands[] = "{$supervisorctl} -s unix://{$socketPath} status {$processName} 2>&1";
            } else {
                $commands[] = "{$supervisorctl} status {$processName}:* 2>&1";
                $commands[] = "{$supervisorctl} status {$processName} 2>&1";
            }
            
            foreach ($commands as $command) {
                $output = [];
                $returnCode = 0;
                exec($command, $output, $returnCode);
                $outputString = implode("\n", $output);
                
                if (!empty($outputString)) {
                    $trimmedOutput = trim($outputString);
                    
                    // Skip if it's an error about no such process
                    if (stripos($trimmedOutput, 'no such process') !== false || 
                        stripos($trimmedOutput, 'no such file') !== false) {
                        continue;
                    }
                    
                    // Check for other error messages
                    if (stripos($trimmedOutput, 'error') !== false ||
                        stripos($trimmedOutput, 'failed') !== false) {
                        continue;
                    }
                    
                    return $this->parseStatusOutput($trimmedOutput);
                }
            }
            
            return ['status' => 'UNKNOWN', 'error' => 'Unable to execute supervisorctl or no output returned'];
            
        } catch (\Exception $e) {
            return ['status' => 'ERROR', 'error' => $e->getMessage()];
        }
    }

    /**
     * Parse supervisorctl status output
     */
    protected function parseStatusOutput(string $output): array
    {
        // Handle multiple processes (when using :* syntax)
        $lines = array_filter(explode("\n", $output), fn($line) => !empty(trim($line)));
        
        if (empty($lines)) {
            return ['status' => 'UNKNOWN'];
        }
        
        // If multiple processes, aggregate the status
        if (count($lines) > 1) {
            return $this->parseMultipleProcessStatus($lines);
        }
        
        // Single process
        return $this->parseSingleProcessStatus($lines[0]);
    }
    
    /**
     * Parse single process status line
     */
    protected function parseSingleProcessStatus(string $line): array
    {
        // Example output: "process_name RUNNING pid 1234, uptime 1:23:45"
        // Real output: aripla-mail-queue                RUNNING   pid 88456, uptime 0:17:21
        // Group output: aripla-queue-update-status-worker:aripla-queue-update-status-worker_00   RUNNING   pid 627681, uptime 0:45:46
        
        $parts = preg_split('/\s+/', trim($line));
        
        if (count($parts) < 2) {
            return ['status' => 'UNKNOWN'];
        }
        
        $status = [
            'status' => $parts[1] ?? 'UNKNOWN',
            'process_line' => $line
        ];
        
        // Extract PID if running
        if (preg_match('/pid (\d+)/', $line, $matches)) {
            $status['pid'] = (int)$matches[1];
        }
        
        // Extract uptime if running
        if (preg_match('/uptime ([0-9:]+)/', $line, $matches)) {
            $status['uptime'] = $matches[1];
        }
        
        return $status;
    }
    
    /**
     * Parse multiple process status lines (for process groups)
     */
    protected function parseMultipleProcessStatus(array $lines): array
    {
        $runningCount = 0;
        $totalCount = count($lines);
        $statuses = [];
        $pids = [];
        $uptimes = [];
        
        foreach ($lines as $line) {
            $processStatus = $this->parseSingleProcessStatus($line);
            $statuses[] = $processStatus['status'];
            
            if ($processStatus['status'] === 'RUNNING') {
                $runningCount++;
                
                if (isset($processStatus['pid'])) {
                    $pids[] = $processStatus['pid'];
                }
                
                if (isset($processStatus['uptime'])) {
                    $uptimes[] = $processStatus['uptime'];
                }
            }
        }
        
        // Determine overall status
        if ($runningCount === $totalCount) {
            $overallStatus = 'RUNNING';
        } elseif ($runningCount > 0) {
            $overallStatus = 'PARTIAL'; // Some running, some not
        } else {
            $overallStatus = 'STOPPED';
        }
        
        $result = [
            'status' => $overallStatus,
            'running_count' => $runningCount,
            'total_count' => $totalCount,
            'process_group' => true
        ];
        
        if (!empty($pids)) {
            $result['pids'] = $pids;
        }
        
        if (!empty($uptimes)) {
            $result['uptimes'] = $uptimes;
            $result['uptime'] = $uptimes[0]; // Use first process uptime for backward compatibility
        }
        
        return $result;
    }

    /**
     * Manual parsing fallback for problematic config files
     */
    protected function manualParseConfig(string $content, string $filePath): array
    {
        $processes = [];
        $lines = explode("\n", $content);
        $currentSection = null;
        $currentData = [];
        
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            
            // Check for section headers
            if (preg_match('/^\[(.*)\]$/', $line, $matches)) {
                // Save previous section if it was a program
                if ($currentSection && strpos($currentSection, 'program:') === 0) {
                    $processName = str_replace('program:', '', $currentSection);
                    $processes[] = [
                        'name' => $processName,
                        'command' => $currentData['command'] ?? '',
                        'directory' => $currentData['directory'] ?? '',
                        'user' => $currentData['user'] ?? '',
                        'autostart' => $currentData['autostart'] ?? 'true',
                        'autorestart' => $currentData['autorestart'] ?? 'true',
                    ];
                }
                
                $currentSection = $matches[1];
                $currentData = [];
                continue;
            }
            
            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (strlen($value) >= 2 && 
                    (($value[0] === '"' && $value[-1] === '"') || 
                     ($value[0] === "'" && $value[-1] === "'"))) {
                    $value = substr($value, 1, -1);
                }
                
                $currentData[$key] = $value;
            }
        }
        
        // Don't forget the last section
        if ($currentSection && strpos($currentSection, 'program:') === 0) {
            $processName = str_replace('program:', '', $currentSection);
            $processes[] = [
                'name' => $processName,
                'command' => $currentData['command'] ?? '',
                'directory' => $currentData['directory'] ?? '',
                'user' => $currentData['user'] ?? '',
                'autostart' => $currentData['autostart'] ?? 'true',
                'autorestart' => $currentData['autorestart'] ?? 'true',
            ];
        }
        
        return $processes;
    }

    /**
     * Auto-detect supervisor socket path
     */
    protected function findSocketPath(): ?string
    {
        $possiblePaths = [
            '/opt/homebrew/var/run/supervisor.sock',  // Homebrew on macOS
            '/usr/local/var/run/supervisor.sock',     // MacPorts or manual install
            '/var/run/supervisor.sock',               // Standard Linux
            '/tmp/supervisor.sock',                   // Alternative location
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find supervisorctl executable path
     */
    protected function findSupervisorctl(): string
    {
        // Try to find supervisorctl in common locations
        $possiblePaths = [
            '/usr/local/bin/supervisorctl',           // Common Linux location
            '/usr/bin/supervisorctl',                 // Alternative Linux location
            '/opt/homebrew/bin/supervisorctl',        // Homebrew on Apple Silicon
            '/usr/local/opt/supervisor/bin/supervisorctl', // Homebrew Intel Mac
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try to find via which command
        $which = trim(shell_exec('which supervisorctl 2>/dev/null') ?? '');
        if (!empty($which) && file_exists($which)) {
            return $which;
        }

        // Fallback to just 'supervisorctl' and hope it's in PATH
        return 'supervisorctl';
    }
}