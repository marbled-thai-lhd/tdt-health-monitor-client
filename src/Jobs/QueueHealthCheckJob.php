<?php

namespace TDT\HealthMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class QueueHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $testId;
    protected float $dispatchedAt;

    /**
     * Create a new job instance.
     */
    public function __construct(string $testId)
    {
        $this->testId = $testId;
        $this->dispatchedAt = microtime(true);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processedAt = microtime(true);
        
        // Store the result in cache for the health check service to pick up
        Cache::put("queue_health_check_{$this->testId}", [
            'test_id' => $this->testId,
            'dispatched_at' => $this->dispatchedAt,
            'processed_at' => $processedAt,
            'queue' => $this->queue,
            'status' => 'processed'
        ], 60); // Keep for 1 minute
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Cache::put("queue_health_check_{$this->testId}", [
            'test_id' => $this->testId,
            'dispatched_at' => $this->dispatchedAt,
            'processed_at' => microtime(true),
            'queue' => $this->queue,
            'status' => 'failed',
            'error' => $exception->getMessage()
        ], 60);
    }
}