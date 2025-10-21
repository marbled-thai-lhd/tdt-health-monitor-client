<?php

namespace TDT\HealthMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Aws\S3\S3Client;

class BackupController
{
    /**
     * Generate a temporary download URL for backup files
     */
    public function generateDownloadUrl(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'filename' => 'required|string',
                's3_bucket' => 'required|string',
                's3_path' => 'required|string',
                'expires_in' => 'integer|min:60|max:3600', // 1 minute to 1 hour
            ]);

            $filename = $request->input('filename');
            $bucket = $request->input('s3_bucket');
            $key = $request->input('s3_path');
            $expiresIn = $request->input('expires_in', 300); // Default 5 minutes

            // Verify this server has access to the S3 bucket
            if (!$this->canAccessS3Bucket($bucket)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to S3 bucket'
                ], 403);
            }

            // Generate pre-signed URL
            $downloadUrl = $this->generateS3PresignedUrl($bucket, $key, $expiresIn);

            if (!$downloadUrl) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate download URL'
                ], 500);
            }

            Log::info('Download URL generated', [
                'filename' => $filename,
                'bucket' => $bucket,
                'expires_in' => $expiresIn
            ]);

            return response()->json([
                'success' => true,
                'download_url' => $downloadUrl,
                'expires_at' => now()->addSeconds($expiresIn)->toISOString(),
                'filename' => $filename
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate download URL', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check if this server can access the S3 bucket
     */
    private function canAccessS3Bucket(string $bucket): bool
    {
        try {
            $s3Client = $this->getS3Client();
            
            // Try to check if bucket exists and we have access
            $result = $s3Client->headBucket(['Bucket' => $bucket]);
            
            return true;
        } catch (\Exception $e) {
            Log::warning('Cannot access S3 bucket', [
                'bucket' => $bucket,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generate S3 pre-signed URL
     */
    private function generateS3PresignedUrl(string $bucket, string $key, int $expiresIn): ?string
    {
        try {
            $s3Client = $this->getS3Client();

            $command = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $key,
                'ResponseContentDisposition' => 'attachment; filename="' . basename($key) . '"',
            ]);

            $presignedUrl = $s3Client->createPresignedRequest($command, "+{$expiresIn} seconds");

            return (string) $presignedUrl->getUri();

        } catch (\Exception $e) {
            Log::error('Failed to generate S3 pre-signed URL', [
                'bucket' => $bucket,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get S3 client instance
     */
    private function getS3Client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region', 'us-east-1'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
    }
}