<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | The default S3-compatible disk to use when no disk is specified.
    | This should match a disk configured in your filesystems.php config.
    |
    */
    'default_disk' => env('FILAMENT_S3_FILEMANAGER_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Default Selection Mode
    |--------------------------------------------------------------------------
    |
    | The default selection mode for the file manager:
    | - 'file': Only allow file selection
    | - 'folder': Only allow folder selection
    | - 'both': Allow both file and folder selection
    |
    */
    'default_selection_mode' => env('FILAMENT_S3_FILEMANAGER_SELECTION_MODE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Presigned URL Expiration
    |--------------------------------------------------------------------------
    |
    | The expiration time in seconds for presigned URLs used for file previews.
    | Default is 1 hour (3600 seconds).
    |
    */
    'presigned_url_expiration' => env('FILAMENT_S3_FILEMANAGER_URL_EXPIRATION', 3600),

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Maximum file size in kilobytes (KB) for uploads.
    | Default is 2GB (2048000 KB).
    |
    */
    'max_file_size' => env('FILAMENT_S3_FILEMANAGER_MAX_SIZE', 2048000),

    /*
    |--------------------------------------------------------------------------
    | Allowed File Extensions
    |--------------------------------------------------------------------------
    |
    | List of allowed file extensions for uploads.
    |
    */
    'allowed_extensions' => [
        // Videos
        'mp4', 'webm', 'ogg', 'mov', 'avi', 'quicktime',
        // PDFs
        'pdf',
        // Documents
        'doc', 'docx', 'txt',
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        // Presentations
        'ppt', 'pptx',
        // Audio
        'mp3', 'wav', 'ogg', 'm4a',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Cache configuration for file listings to improve performance.
    |
    */
    'cache' => [
        'enabled' => env('FILAMENT_S3_FILEMANAGER_CACHE_ENABLED', true),
        'ttl' => env('FILAMENT_S3_FILEMANAGER_CACHE_TTL', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for API routes.
    |
    */
    'routes' => [
        'prefix' => env('FILAMENT_S3_FILEMANAGER_ROUTE_PREFIX', 'api/s3-files'),
        'middleware' => [
            'web',
            'auth',
        ],
    ],
];

