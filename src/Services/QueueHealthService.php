<?php

namespace TDT\HealthMonitor\Services;

use TDT\HealthMonitor\Jobs\QueueHealthCheckJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QueueHealthService
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Check queue health by dispatching test jobs
     */
    public function checkQueueHealth(): array
    {
        $queues = $this->getQueues();
        $timeout = $this->config['timeout'] ?? 30;
        $results = [];

        foreach ($queues as $queueName) {
            $results[$queueName] = $this->checkSingleQueue($queueName, $timeout);
        }

        $healthyQueues = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $totalQueues = count($results);

        return [
            'status' => $healthyQueues === $totalQueues ? 'ok' : 'error',
            'ok_queues' => $healthyQueues,
            'total_queues' => $totalQueues,
            'queues' => $results,
            'checked_at' => now()->toISOString()
        ];
    }

    /**
     * Check health of a single queue
     */
    protected function checkSingleQueue(string $queueName, int $timeout): array
    {
        $testId = uniqid('health_check_', true);
        $cacheKey = "queue_health_check_{$testId}";
        
        try {
            // Dispatch test job
            Queue::pushOn($queueName, new QueueHealthCheckJob($testId));
            
            // Wait for job to complete
            $startTime = time();
            while (time() - $startTime < $timeout) {
                if (Cache::has($cacheKey)) {
                    $result = Cache::get($cacheKey);
                    Cache::forget($cacheKey);
                    
                    return [
                        'status' => 'ok',
                        'response_time' => $result['processed_at'] - $result['dispatched_at'],
                        'processed_at' => $result['processed_at'],
                        'test_id' => $testId
                    ];
                }
                
                sleep(1);
            }
            
            // Timeout - get queue size for diagnostics
            $queueSize = $this->getQueueSize($queueName);
            
            return [
                'status' => 'timeout',
                'message' => "Queue did not process test job within {$timeout} seconds",
                'test_id' => $testId,
                'queue_size' => $queueSize,
                'pending_jobs' => $queueSize
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'test_id' => $testId
            ];
        }
    }

    /**
     * Get list of queues to check
     */
    protected function getQueues(): array
    {
        $queuesConfig = $this->config['queues'] ?? 'default';
        
        if (is_string($queuesConfig)) {
            return array_map('trim', explode(',', $queuesConfig));
        }
        
        return is_array($queuesConfig) ? $queuesConfig : ['default'];
    }

    /**
     * Get queue size and statistics (if supported by queue driver)
     */
    public function getQueueStats(): array
    {
        $queues = $this->getQueues();
        $stats = [];

        foreach ($queues as $queueName) {
            try {
                // This is driver-dependent - Redis example
                $size = $this->getQueueSize($queueName);
                $stats[$queueName] = [
                    'size' => $size,
                    'status' => $size !== null ? 'available' : 'unavailable'
                ];
            } catch (\Exception $e) {
                $stats[$queueName] = [
                    'size' => null,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $stats;
    }

    /**
     * Get queue size (implementation depends on queue driver)
     * Excludes health check jobs from count
     */
    protected function getQueueSize(string $queueName): ?int
    {
        try {
            // For Redis driver
            if (config('queue.default') === 'redis') {
                $redis = app('redis')->connection(config('queue.connections.redis.connection', 'default'));
                $key = config('queue.connections.redis.queue', 'default') . ':' . $queueName;
                
                // Get all jobs in the queue
                $jobs = $redis->lrange($key, 0, -1);
                $count = 0;
                
                // Count only non-health-check jobs
                foreach ($jobs as $job) {
                    $payload = json_decode($job, true);
                    if (isset($payload['data']['commandName'])) {
                        // Skip if it's a QueueHealthCheckJob
                        if (strpos($payload['data']['commandName'], 'QueueHealthCheckJob') === false) {
                            $count++;
                        }
                    } else {
                        // If no commandName, count it (for safety)
                        $count++;
                    }
                }
                
                return $count;
            }
            
            // For database driver - use SQL query to filter
            if (config('queue.default') === 'database') {
                return DB::table(config('queue.connections.database.table', 'jobs'))
                    ->where('queue', $queueName)
                    ->where('reserved_at', null)
                    ->where('payload', 'NOT LIKE', '%QueueHealthCheckJob%')
                    ->count();
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}