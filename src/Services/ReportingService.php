<?php

namespace TDT\HealthMonitor\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class ReportingService
{
    protected array $config;
    protected Client $httpClient;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->httpClient = new Client([
            'timeout' => $config['timeout'] ?? 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'HealthMonitor/1.0'
            ]
        ]);
    }

    /**
     * Send health report to monitoring server
     */
    public function sendReport(array $report): bool
    {
        if (empty($this->config['monitoring_url']) || empty($this->config['api_key'])) {
            Log::warning('Health monitor: Missing monitoring URL or API key');
            return false;
        }

        try {
            $payload = $this->preparePayload($report);
            
            $response = $this->httpClient->post($this->config['monitoring_url'], [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->generateAuthToken(),
                    'X-Server-Name' => $this->config['server_name']
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                Log::info('Health report sent successfully');
                return true;
            } else {
                Log::error('Health report failed', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getBody()->getContents()
                ]);
                return false;
            }

        } catch (RequestException $e) {
            Log::error('Health report request failed', [
                'error' => $e->getMessage(),
                'url' => $this->config['monitoring_url']
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Health report unexpected error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Prepare payload with additional metadata
     */
    protected function preparePayload(array $report): array
    {
        return [
            'report' => $report,
            'metadata' => [
                'package_version' => '1.0.0',
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'server_time' => date('c'),
                'timezone' => date_default_timezone_get()
            ]
        ];
    }

    /**
     * Generate HMAC authentication token
     */
    protected function generateAuthToken(): string
    {
        $timestamp = time();
        $serverName = $this->config['server_name'];
        $apiKey = $this->config['api_key'];
        
        // Create payload for HMAC
        $payload = "{$serverName}:{$timestamp}";
        
        // Generate HMAC signature
        $signature = hash_hmac('sha256', $payload, $apiKey);
        
        // Return token in format: timestamp.signature
        return base64_encode("{$timestamp}.{$signature}");
    }

    /**
     * Send backup notification
     */
    public function sendBackupNotification(array $backupInfo): bool
    {
        // Use backup-specific URL or fall back to main monitoring URL
        $backupUrl = $this->config['backup_notification_url'] ?? $this->config['monitoring_url'];
        
        if (empty($backupUrl)) {
            Log::warning('Health monitor: Missing backup notification URL');
            return false;
        }

        try {
            $payload = [
                'type' => 'backup_notification',
                'server_name' => $this->config['server_name'],
                'server_ip' => $this->getServerIp(),
                'backup_info' => $backupInfo,
                'timestamp' => date('c')
            ];

            $response = $this->httpClient->post($backupUrl, [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->generateAuthToken(),
                    'X-Server-Name' => $this->config['server_name'],
                    'X-Message-Type' => 'backup'
                ]
            ]);

            return $response->getStatusCode() === 200;

        } catch (\Exception $e) {
            Log::error('Backup notification failed', ['error' => $e->getMessage()]);
            return false;
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

        try {
            $ip = file_get_contents('http://ipecho.net/plain');
            return trim($ip);
        } catch (\Exception $e) {
            return $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
        }
    }
}