<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Health Monitor Configuration
    |--------------------------------------------------------------------------
    */

    // Enable/disable monitoring
    'enabled' => env('HEALTH_MONITOR_ENABLED', false),

    // Central monitoring server URL
    'monitoring_url' => env('HEALTH_MONITOR_URL', null),
    'backup_notification_url' => env('HEALTH_MONITOR_BACKUP_URL', null),

    // API key for authentication
    'api_key' => env('HEALTH_MONITOR_API_KEY', ''),

    // Server identification
    'server_name' => env('HEALTH_MONITOR_SERVER_NAME', gethostname()),
    'server_ip' => env('HEALTH_MONITOR_SERVER_IP', ''),

    // Monitoring frequency (in minutes)
    'check_interval' => env('HEALTH_MONITOR_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Supervisor Configuration
    |--------------------------------------------------------------------------
    */
    'supervisor' => [
        'config_path' => env('SUPERVISOR_CONFIG_PATH', '/etc/supervisor/conf.d'),
        'socket_path' => env('SUPERVISOR_SOCKET_PATH', null), // Auto-detect if null
    ],

    /*
    |--------------------------------------------------------------------------
    | Cron Configuration
    |--------------------------------------------------------------------------
    | 
    | User: The user whose crontab to check. Leave null to check current user.
    | Note: Checking other users' crontabs requires root privileges.
    */
    'cron' => [
        'user' => env('CRON_USER', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Health Check Configuration
    |--------------------------------------------------------------------------
    */
    'queue_health_check' => [
        'enabled' => env('QUEUE_HEALTH_CHECK_ENABLED', true),
        'queues' => env('QUEUE_HEALTH_CHECK_QUEUES', ''),
        'timeout' => env('QUEUE_HEALTH_CHECK_TIMEOUT', 30), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Backup Configuration
    |--------------------------------------------------------------------------
    */
    'backup' => [
        'enabled' => env('DB_BACKUP_ENABLED', false),
        'schedule' => env('DB_BACKUP_SCHEDULE', '0 2 * * *'), // Daily at 2 AM
        's3' => [
            'bucket' => env('DB_BACKUP_S3_BUCKET', ''),
            'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
            'path' => env('DB_BACKUP_S3_PATH', 'database-backups'),
        ],
        'retention_days' => env('DB_BACKUP_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('HEALTH_MONITOR_LOGGING_ENABLED', true),
        'health_check' => [
            'enabled' => env('HEALTH_LOG_ENABLED', true),
            'path' => env('HEALTH_LOG_PATH', storage_path('logs/health-check.log')),
            'level' => env('HEALTH_LOG_LEVEL', 'info'),
            'daily' => env('HEALTH_LOG_DAILY', true),
            'days' => env('HEALTH_LOG_DAILY_DAYS', 30),
        ],
        'database_backup' => [
            'enabled' => env('BACKUP_LOG_ENABLED', true),
            'path' => env('BACKUP_LOG_PATH', storage_path('logs/database-backup.log')),
            'level' => env('BACKUP_LOG_LEVEL', 'info'),
            'daily' => env('BACKUP_LOG_DAILY', true),
            'days' => env('BACKUP_LOG_DAILY_DAYS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout and Retry Configuration
    |--------------------------------------------------------------------------
    */
    'timeout' => env('HEALTH_MONITOR_TIMEOUT', 30),
    'retry_attempts' => env('HEALTH_MONITOR_RETRY_ATTEMPTS', 3),
];