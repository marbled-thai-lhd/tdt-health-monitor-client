# Laravel Health Monitor Package

# Laravel Health Monitor

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tdt/health-monitor.svg?style=flat-square)](https://packagist.org/packages/tdt/health-monitor)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

A comprehensive Laravel package for monitoring supervisor processes, cron jobs, queue health, and automated database backups with reporting to a central monitoring server.

## Features

- 🔍 **Supervisor Process Monitoring**: Parse supervisor config files and check process status
- ⏰ **Cron Job Monitoring**: Check crontab entries for specified users
- 🚀 **Queue Health Checks**: Test queue functionality by dispatching test jobs
- 💾 **Database Backup**: Automated MySQL dumps with S3 upload support
- 📊 **Central Reporting**: Send monitoring data to a central server with HMAC authentication
- 🔐 **Secure Authentication**: Time-based HMAC authentication for API calls
- 📧 **Notification System**: Automated alerts for backup completion and health status

## Installation

Install the package via Composer:

```bash
composer require tdt/health-monitor
```

### Laravel Auto-Discovery

Laravel will automatically discover the service provider. If you need to register it manually, add the service provider to your `config/app.php`:

```php
'providers' => [
    // Other Service Providers
    TDT\HealthMonitor\HealthMonitorServiceProvider::class,
],
```

### Publish Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="TDT\HealthMonitor\HealthMonitorServiceProvider" --tag="health-monitor-config"
```

## Configuration

Add the following environment variables to your `.env` file:

```env
# Basic Configuration
HEALTH_MONITOR_ENABLED=true
HEALTH_MONITOR_URL=https://your-monitoring-server.com/api/health/report
HEALTH_MONITOR_API_KEY=your-secret-api-key
HEALTH_MONITOR_SERVER_NAME=production-web-01
HEALTH_MONITOR_SERVER_IP=1.2.3.4
HEALTH_MONITOR_INTERVAL=5

# Optional: Separate URL for backup notifications
# If not set, backup notifications will use the same URL as health reports
# HEALTH_MONITOR_BACKUP_URL=https://your-monitoring-server.com/api/health/backup-notification

# Supervisor Configuration
SUPERVISOR_CONFIG_PATH=/etc/supervisor/conf.d
SUPERVISOR_SOCKET_PATH=/var/run/supervisor.sock

# Cron Configuration
CRON_USER=ec2-user

# Queue Health Check
QUEUE_HEALTH_CHECK_ENABLED=true
QUEUE_HEALTH_CHECK_QUEUES=mails,summaries,payments
QUEUE_HEALTH_CHECK_TIMEOUT=30

# Database Backup
DB_BACKUP_ENABLED=true
DB_BACKUP_SCHEDULE="0 2 * * *"
DB_BACKUP_S3_BUCKET=my-backup-bucket
DB_BACKUP_S3_REGION=ap-northeast-1
DB_BACKUP_S3_PATH=database-backups
DB_BACKUP_RETENTION_DAYS=30

# AWS S3 Credentials
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
```

## Usage

### Automatic Monitoring

The package automatically registers scheduled tasks when enabled:

1. **Health Check**: Runs every 5 minutes (configurable)
2. **Database Backup**: Runs according to the configured schedule (default: daily at 2 AM)

### Manual Commands

#### Run Health Check

```bash
# Basic health check
php artisan health:check

# Force health check even if disabled
php artisan health:check --force

# Output in table format
php artisan health:check --output=table
```

#### Database Backup

```bash
# Basic backup
php artisan health:backup-database

# Force backup even if disabled
php artisan health:backup-database --force

# Backup with S3 upload
php artisan health:backup-database --upload
```

#### Setup Assistant

```bash
# Display setup instructions and current configuration
php artisan health:setup-schedule
```

### Laravel Scheduler Setup

Add the Laravel scheduler to your crontab:

```bash
crontab -e
```

Add this line:
```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Monitoring Components

### 1. Supervisor Process Monitoring

The package parses supervisor configuration files and checks process status:

- Reads `.conf` files from the configured directory
- Executes `supervisorctl status` to get real-time process information
- Reports process count, status, PID, and uptime

### 2. Cron Job Monitoring

Monitors crontab entries for specified users:

- Parses crontab using `crontab -l` or `crontab -u {user} -l`
- Identifies active and disabled jobs
- Provides human-readable schedule descriptions
- Calculates next run times

#### Cron User Configuration

Set the user whose crontab you want to monitor:

```bash
# Check current user's crontab (recommended)
CRON_USER=

# Check specific user's crontab (requires root privileges on Linux)
CRON_USER=www-data
```

**Important Notes:**
- Leave `CRON_USER` empty (or set to null) to check the current user's crontab
- Specifying a different user requires root privileges on most Linux systems
- On macOS, user switching may work without root privileges
- The package automatically falls back to current user if permission is denied

**Permission Examples:**
```bash
# ✅ Works: Current user
CRON_USER=

# ⚠️  May require root: Specific user
CRON_USER=www-data

# 🔧 Alternative: Run as root or use sudo
sudo php artisan health:check
```

### 3. Queue Health Monitoring

Tests queue functionality by dispatching test jobs:

- Dispatches `QueueHealthCheckJob` to configured queues
- Measures response time and success rate
- Supports multiple queue drivers (Redis, Database, etc.)
- Configurable timeout and queue list

### 4. Database Backup

Automated MySQL database backups:

- Uses `mysqldump` for consistent backups
- Compresses backups using ZIP compression
- Uploads to S3 with server-side encryption
- Automatic cleanup of old backup files
- Sends completion notifications to monitoring server

## API Endpoints

The package sends different types of data to different endpoints:

### Health Reports
- **Endpoint**: `HEALTH_MONITOR_URL` (e.g., `/api/health/report`)
- **Content**: Supervisor status, cron jobs, queue health
- **Frequency**: Every 5 minutes (configurable)
- **Method**: POST with JSON payload

### Backup Notifications  
- **Endpoint**: `HEALTH_MONITOR_BACKUP_URL` or falls back to `HEALTH_MONITOR_URL`
- **Content**: Database backup results, S3 upload status
- **Frequency**: After each backup operation
- **Method**: POST with JSON payload

### Configuration Flexibility
```env
# Basic setup - both types go to same endpoint
HEALTH_MONITOR_URL=https://monitor.example.com/api/health/report

# Advanced setup - separate endpoints
HEALTH_MONITOR_URL=https://monitor.example.com/api/health/report
HEALTH_MONITOR_BACKUP_URL=https://monitor.example.com/api/health/backup-notification

# Custom routing - different servers
HEALTH_MONITOR_URL=https://health-api.example.com/report
HEALTH_MONITOR_BACKUP_URL=https://backup-api.example.com/notifications
```

## API Authentication

The package uses HMAC-SHA256 authentication with embedded timestamp for secure communication:

### How it works:
```php
// 1. Generate timestamp
$timestamp = time();

// 2. Create payload
$payload = "{$serverName}:{$timestamp}";

// 3. Generate HMAC signature
$signature = hash_hmac('sha256', $payload, $apiKey);

// 4. Create token with embedded timestamp
$token = base64_encode("{$timestamp}.{$signature}");

// 5. Send in Authorization header
// Authorization: Bearer {$token}
```

### Security Features:
- **Self-contained tokens**: Timestamp is embedded in the token, not sent separately
- **Replay protection**: Tokens expire after 5 minutes automatically
- **Signature validation**: Server recreates and compares HMAC signatures
- **No token reuse**: Each request generates a fresh token with current timestamp

This prevents the security vulnerability where an attacker could reuse an old Authorization header with a new timestamp.

## Monitoring Server Integration

### Health Report Payload

```json
{
  "report": {
    "server_name": "production-web-01",
    "server_ip": "1.2.3.4",
    "timestamp": "2023-10-07T14:30:00Z",
    "supervisor": {
      "status": "ok",
      "total_processes": 5,
      "running_processes": 5,
      "stopped_processes": 0,
      "processes": [...]
    },
    "cron": {
      "status": "ok",
      "user": "ec2-user",
      "total_jobs": 3,
      "active_jobs": 3,
      "jobs": [...]
    },
    "queues": {
      "status": "ok",
      "ok_queues": 3,
      "total_queues": 3,
      "queues": {
        "mails": {"status": "ok", "response_time": 1.2},
        "summaries": {"status": "ok", "response_time": 0.8},
        "payments": {"status": "ok", "response_time": 1.5}
      }
    }
  },
  "metadata": {
    "package_version": "1.0.0",
    "php_version": "8.1.0",
    "laravel_version": "10.0.0",
    "server_time": "2023-10-07T14:30:00+00:00",
    "timezone": "UTC"
  }
}
```

### Backup Notification Payload

```json
{
  "type": "backup_notification",
  "server_name": "production-web-01",
  "server_ip": "1.2.3.4",
  "backup_info": {
    "file_path": "/path/to/backup.zip",
    "file_size": 1048576,
    "duration": 15.2,
    "timestamp": "2023-10-07T02:00:00Z",
    "uploaded": true,
    "s3_bucket": "my-backup-bucket",
    "s3_path": "database-backups/backup_production-web-01_2023-10-07_02-00-00.sql.zip"
  },
  "timestamp": "2023-10-07T02:00:15Z"
}
```

## Error Handling

The package includes comprehensive error handling:

- Graceful degradation when services are unavailable
- Detailed error logging
- Retry mechanisms for network requests
- Timeout protection for long-running operations

## Security Considerations

- API keys should be kept secure and rotated regularly
- HMAC authentication prevents replay attacks
- S3 uploads use server-side encryption
- Database credentials are never transmitted
- All communications use HTTPS

## Requirements

- PHP 8.0 or higher
- Laravel 8.0 or higher
- MySQL/MariaDB (for database backups)
- Supervisor (for process monitoring)
- AWS S3 (optional, for backup storage)

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Your Name](https://github.com/yourusername)
- [All Contributors](../../contributors)