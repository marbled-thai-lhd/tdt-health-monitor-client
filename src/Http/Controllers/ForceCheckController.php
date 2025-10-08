<?php

namespace TDT\HealthMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use TDT\HealthMonitor\HealthMonitor;
use Illuminate\Support\Facades\Log;

class ForceCheckController
{
    protected HealthMonitor $healthMonitor;

    public function __construct(HealthMonitor $healthMonitor)
    {
        $this->healthMonitor = $healthMonitor;
    }

    /**
     * Handle force health check request
     */
    public function forceCheck(Request $request): JsonResponse
    {
        try {
            // Validate API key
            $apiKey = $request->header('X-API-Key');
            $configApiKey = config('health-monitor.api_key');
            
            if (!$apiKey || $apiKey !== $configApiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid API key'
                ], 401);
            }

            // Validate request
            $request->validate([
                'timestamp' => 'required|integer',
                'force_check' => 'required|boolean'
            ]);

            // Check timestamp (within 5 minutes)
            $timestamp = $request->input('timestamp');
            if (abs(time() - $timestamp) > 300) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request timestamp is too old'
                ], 400);
            }

            Log::info('Force health check triggered', [
                'timestamp' => $timestamp,
                'remote_ip' => $request->ip()
            ]);

            // Trigger immediate health check
            $this->healthMonitor->performHealthCheck(true);

            return response()->json([
                'success' => true,
                'message' => 'Health check triggered successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Force health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to trigger health check: ' . $e->getMessage()
            ], 500);
        }
    }
}