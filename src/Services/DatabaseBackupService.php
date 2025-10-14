<?php

namespace TDT\HealthMonitor\Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use TDT\HealthMonitor\Services\ReportingService;
use Illuminate\Support\Facades\Log;

class DatabaseBackupService
{
    protected array $config;
    protected ?S3Client $s3Client = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        
        if ($this->shouldUploadToS3()) {
            $this->initializeS3Client();
        }
    }

    /**
     * Perform database backup
     */
    public function performBackup(bool $upload = true): array
    {
        $startTime = microtime(true);
        
        $timestamp = date('Y-m-d_H-i-s');
        $serverName = config('health-monitor.server_name', 'server');
        $filename = "backup_{$serverName}_{$timestamp}.sql";
        $tempPath = storage_path("app/backups/{$filename}");
        
        $backupDir = dirname($tempPath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        try {
            $this->createMysqlDump($tempPath);
            $zipPath = $this->compressBackup($tempPath);
            unlink($tempPath);
            
            $fileSize = filesize($zipPath);
            $duration = round(microtime(true) - $startTime, 2);
            
            // Check if backup is password protected
            $dbConfig = config('database.connections.' . config('database.default'));
            $isEncrypted = !empty($dbConfig['password']);
            
            $result = [
                'file_path' => $zipPath,
                'file_size' => $fileSize,
                'duration' => $duration,
                'timestamp' => date('c'),
                'uploaded' => false,
                'encrypted' => $isEncrypted
            ];

            if ($upload && $this->shouldUploadToS3()) {
                $s3Result = $this->uploadToS3($zipPath, $filename . '.zip');
                $result = array_merge($result, $s3Result);
            }

            $this->sendBackupNotification($result);
            $this->cleanupOldBackups();

            return $result;

        } catch (\Exception $e) {
            // Clean up on failure
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            
            Log::error('Database backup failed', [
                'error' => $e->getMessage(),
                'duration' => round(microtime(true) - $startTime, 2)
            ]);
            
            throw $e;
        }
    }

    /**
     * Create MySQL dump
     */
    protected function createMysqlDump(string $outputPath): void
    {
        $dbConfig = config('database.connections.' . config('database.default'));
        
        $host = $dbConfig['host'];
        $port = $dbConfig['port'] ?? 3306;
        $database = $dbConfig['database'];
        $username = $dbConfig['username'];
        $password = $dbConfig['password'];

        // Build mysqldump command
        $command = sprintf(
            'mysqldump --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers %s > %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($outputPath)
        );

        // Execute mysqldump
        exec($command . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('MySQL dump failed: ' . implode("\n", $output));
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \Exception('MySQL dump produced empty or missing file');
        }
    }

    /**
     * Compress backup file with password protection
     */
    protected function compressBackup(string $sqlPath): string
    {
        $zipPath = str_replace('.sql', '.zip', $sqlPath);
        
        // Get database password to use as zip password
        $dbConfig = config('database.connections.' . config('database.default'));
        $password = $dbConfig['password'];
        
        // Use command line zip with password encryption
        if (!empty($password)) {
            $command = sprintf(
                'cd %s && zip -P %s %s %s 2>&1',
                escapeshellarg(dirname($sqlPath)),
                escapeshellarg($password),
                escapeshellarg(basename($zipPath)),
                escapeshellarg(basename($sqlPath))
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \Exception('ZIP compression with password failed: ' . implode("\n", $output));
            }
        } else {
            // Fallback to ZipArchive without password if no DB password
            $zip = new \ZipArchive();
            $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            
            if ($result !== true) {
                throw new \Exception("Failed to create ZIP file: {$result}");
            }
            
            $zip->addFile($sqlPath, basename($sqlPath));
            $zip->close();
        }
        
        if (!file_exists($zipPath)) {
            throw new \Exception('Failed to create compressed backup file');
        }
        
        return $zipPath;
    }

    /**
     * Upload backup to S3
     */
    protected function uploadToS3(string $filePath, string $filename): array
    {
        if (!$this->s3Client) {
            throw new \Exception('S3 client not initialized');
        }

        try {
            $bucket = $this->config['s3']['bucket'];
            $s3Path = trim($this->config['s3']['path'], '/') . '/' . $filename;
            
            $result = $this->s3Client->putObject([
                'Bucket' => $bucket,
                'Key' => $s3Path,
                'SourceFile' => $filePath,
                'ServerSideEncryption' => 'AES256',
                'Metadata' => [
                    'server-name' => config('health-monitor.server_name'),
                    'backup-date' => date('c'),
                    'file-size' => (string)filesize($filePath)
                ]
            ]);

            return [
                'uploaded' => true,
                's3_bucket' => $bucket,
                's3_path' => $s3Path,
                's3_url' => $result['ObjectURL'] ?? null
            ];

        } catch (AwsException $e) {
            Log::error('S3 upload failed', ['error' => $e->getMessage()]);
            
            return [
                'uploaded' => false,
                'upload_error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send backup notification to monitoring server
     */
    protected function sendBackupNotification(array $backupInfo): void
    {
        try {
            $config = config('health-monitor');
            $reportingService = new ReportingService($config);
            $reportingService->sendBackupNotification($backupInfo);
        } catch (\Exception $e) {
            Log::warning('Failed to send backup notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clean up old backup files
     */
    protected function cleanupOldBackups(): void
    {
        $retentionDays = $this->config['retention_days'] ?? 30;
        $backupDir = storage_path('app/backups');
        
        if (!is_dir($backupDir)) {
            return;
        }

        $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
        
        foreach (glob($backupDir . '/*.zip') as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                Log::info('Deleted old backup file', ['file' => basename($file)]);
            }
        }
    }

    /**
     * Check if S3 upload is configured and enabled
     */
    protected function shouldUploadToS3(): bool
    {
        return !empty($this->config['s3']['bucket']);
    }

    /**
     * Initialize S3 client
     */
    protected function initializeS3Client(): void
    {
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $this->config['s3']['region'] ?? 'ap-northeast-1',
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ]
        ]);
    }
}