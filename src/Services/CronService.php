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
        $user = $this->config['user'] ?? null;
        
        try {
            // Get crontab for the specified user or current user
            $crontab = $this->getCrontab($user);
            
            if ($crontab === false) {
                $actualUser = $user ?? 'current user';
                return [
                    'status' => 'error',
                    'message' => "Unable to read crontab for user: {$actualUser}",
                    'jobs' => [],
                    'checked_user' => $actualUser
                ];
            }
            
            $jobs = $this->parseCrontab($crontab['content']);
            $activeJobs = array_filter($jobs, fn($job) => !$job['disabled']);
            
            return [
                'status' => 'ok',
                'user' => $crontab['user'],
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
                'jobs' => [],
                'checked_user' => $user ?? 'current user'
            ];
        }
    }

    /**
     * Get crontab content for specified user
     * 
     * This method tries multiple approaches to read crontab:
     * 1. If user specified: try `crontab -u {user} -l` (requires root on most Linux systems)
     * 2. Fallback: try current user's crontab with `crontab -l`
     * 3. Alternative: try reading from cron spool directories (requires read permissions)
     */
    protected function getCrontab(?string $user): array|false
    {
        // Try different approaches based on user specification and permissions
        $attempts = [];
        
        if ($user) {
            // First try with specified user (may require root privileges on Linux)
            $attempts[] = [
                'command' => "crontab -u {$user} -l 2>/dev/null",
                'user' => $user,
                'description' => "specific user ({$user})"
            ];
        }
        
        // Fallback: try current user's crontab
        $currentUser = get_current_user();
        $attempts[] = [
            'command' => "crontab -l 2>/dev/null",
            'user' => $currentUser,
            'description' => "current user ({$currentUser})"
        ];
        
        // Try to read from cron directory (requires read permissions)
        if ($user && $user !== $currentUser) {
            $attempts[] = [
                'command' => "cat /var/spool/cron/crontabs/{$user} 2>/dev/null || cat /var/spool/cron/{$user} 2>/dev/null",
                'user' => $user,
                'description' => "cron spool directory for {$user}"
            ];
        }
        
        foreach ($attempts as $attempt) {
            $output = shell_exec($attempt['command']);
            
            if ($output !== null && trim($output) !== '') {
                return [
                    'content' => $output,
                    'user' => $attempt['user'],
                    'method' => $attempt['description']
                ];
            }
        }
        
        return false;
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
            'description' => $this->generateJobDescription($schedule, implode(' ', array_slice($parts, 0, 5))),
            'next_run' => $this->calculateNextRun($schedule)
        ];
    }

    /**
     * Generate human-readable description of cron schedule
     */
    protected function generateJobDescription(array $schedule, string $scheduleString): string
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
        return "At {$scheduleString}";
    }

    /**
     * Calculate next run time (simplified)
     */
    protected function calculateNextRun(array $schedule): string|null
    {
        try {
            $now = new \DateTime();
            
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