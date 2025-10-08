<?php

namespace TDT\HealthMonitor\Services;

class CronService
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Check cron jobs for specified user
     */
    public function checkCronJobs(): array
    {
        $user = $this->config['user'] ?? 'ec2-user';
        
        try {
            // Get crontab for the specified user
            $crontab = $this->getCrontab($user);
            
            if ($crontab === false) {
                return [
                    'status' => 'error',
                    'message' => "Unable to read crontab for user: {$user}",
                    'jobs' => []
                ];
            }
            
            $jobs = $this->parseCrontab($crontab);
            $activeJobs = array_filter($jobs, fn($job) => !$job['disabled']);
            
            return [
                'status' => 'ok',
                'user' => $user,
                'total_jobs' => count($jobs),
                'active_jobs' => count($activeJobs),
                'disabled_jobs' => count($jobs) - count($activeJobs),
                'jobs' => $jobs,
                'last_checked' => date('Y-m-d H:i:s')
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'jobs' => []
            ];
        }
    }

    /**
     * Get crontab content for specified user
     */
    protected function getCrontab(string $user): string|false
    {
        $command = "crontab -u {$user} -l 2>/dev/null";
        $output = shell_exec($command);
        
        return $output !== null ? $output : false;
    }

    /**
     * Parse crontab content
     */
    protected function parseCrontab(string $crontab): array
    {
        $lines = explode("\n", $crontab);
        $jobs = [];
        
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            
            // Skip empty lines and comments (unless it's a disabled job)
            if (empty($line)) {
                continue;
            }
            
            // Check if it's a disabled job (commented out)
            $disabled = false;
            if (str_starts_with($line, '#')) {
                $disabled = true;
                $line = ltrim($line, '# ');
                
                // Skip real comments (not disabled jobs)
                if (empty($line) || !$this->looksLikeCronJob($line)) {
                    continue;
                }
            }
            
            // Parse cron job
            $job = $this->parseCronLine($line);
            if ($job) {
                $job['line_number'] = $lineNumber + 1;
                $job['disabled'] = $disabled;
                $job['original_line'] = $line;
                $jobs[] = $job;
            }
        }
        
        return $jobs;
    }

    /**
     * Check if a line looks like a cron job
     */
    protected function looksLikeCronJob(string $line): bool
    {
        // Basic check: should have at least 6 parts (5 time fields + command)
        $parts = preg_split('/\s+/', $line, 6);
        return count($parts) >= 6;
    }

    /**
     * Parse individual cron line
     */
    protected function parseCronLine(string $line): array|null
    {
        // Split into maximum 6 parts (minute hour day month weekday command)
        $parts = preg_split('/\s+/', $line, 6);
        
        if (count($parts) < 6) {
            return null;
        }
        
        $schedule = [
            'minute' => $parts[0],
            'hour' => $parts[1],
            'day' => $parts[2],
            'month' => $parts[3],
            'weekday' => $parts[4]
        ];
        
        $command = $parts[5];
        
        return [
            'schedule' => $schedule,
            'schedule_string' => implode(' ', array_slice($parts, 0, 5)),
            'command' => $command,
            'description' => $this->generateJobDescription($schedule),
            'next_run' => $this->calculateNextRun($schedule)
        ];
    }

    /**
     * Generate human-readable description of cron schedule
     */
    protected function generateJobDescription(array $schedule): string
    {
        $minute = $schedule['minute'];
        $hour = $schedule['hour'];
        $day = $schedule['day'];
        $month = $schedule['month'];
        $weekday = $schedule['weekday'];
        
        // Simple patterns
        if ($minute === '0' && $hour === '0' && $day === '*' && $month === '*' && $weekday === '*') {
            return 'Daily at midnight';
        }
        
        if ($minute === '0' && $hour !== '*' && $day === '*' && $month === '*' && $weekday === '*') {
            return "Daily at {$hour}:00";
        }
        
        if ($minute !== '*' && $hour !== '*' && $day === '*' && $month === '*' && $weekday === '*') {
            return "Daily at {$hour}:{$minute}";
        }
        
        if ($day === '*' && $month === '*' && $weekday === '0') {
            return "Weekly on Sunday at {$hour}:{$minute}";
        }
        
        // Fallback to schedule string
        return "At {$schedule['schedule_string']}";
    }

    /**
     * Calculate next run time (simplified)
     */
    protected function calculateNextRun(array $schedule): string|null
    {
        // This is a simplified version - in production you'd want a more robust cron parser
        try {
            $now = new \DateTime();
            
            // For simplicity, just return "next hour" for most cases
            if ($schedule['minute'] !== '*') {
                $nextRun = clone $now;
                $nextRun->setTime($nextRun->format('H'), (int)$schedule['minute'], 0);
                
                if ($nextRun <= $now) {
                    $nextRun->modify('+1 hour');
                }
                
                return $nextRun->format('Y-m-d H:i:s');
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}