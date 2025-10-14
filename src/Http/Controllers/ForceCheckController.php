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
		$response = fn($result, $message, $status) => response()->json([
			'success' => $result,
			'message' => $message
		], $status);

        try {
            if (!$this->authenticateRequest($request)) {
                return $response(false, 'Authentication failed', 401);
            }

			$this->healthMonitor->performHealthCheck(true);
            return $response(true, 'Health check triggered successfully', 200);

        } catch (\Exception $e) {
            Log::error('Force health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $response(false, 'Failed to trigger health check: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Authenticate request using HMAC (same method as HealthReportController)
     */
    protected function authenticateRequest(Request $request): bool
    {
        $serverName = $request->header('X-Server-Name');
        $authHeader = $request->header('Authorization');

        if (!$serverName || !$authHeader) {
            return false;
        }

        // Extract Bearer token
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        $token = substr($authHeader, 7);
        $configApiKey = config('health-monitor.api_key');
        $configServerName = config('health-monitor.server_name');

        // Validate server name matches config
        if ($serverName !== $configServerName) {
            return false;
        }

        // Validate HMAC token
        return $this->validateHmacToken($token, $serverName, $configApiKey);
    }

    /**
     * Validate HMAC token (same method as HealthReportController)
     */
    protected function validateHmacToken(string $token, string $serverName, string $apiKey): bool
    {
        try {
            $decodedToken = base64_decode($token);
            [$tokenTimestamp, $signature] = explode('.', $decodedToken, 2);

            $timestamp = (int)$tokenTimestamp;

            // Check timestamp to prevent replay attacks (allow 5 minutes window)
            if (abs(time() - $timestamp) > 300) {
                return false;
            }

            $payload = "{$serverName}:{$timestamp}";
            $expectedSignature = hash_hmac('sha256', $payload, $apiKey);
            
            return hash_equals($expectedSignature, $signature);
        } catch (\Exception $e) {
            Log::error('HMAC token validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}