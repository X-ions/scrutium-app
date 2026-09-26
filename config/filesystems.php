<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', env('AWS_BUCKET') || env('S3_BUCKET') ? 's3' : 'local'),

    /*
    |--------------------------------------------------------------------------
    | Evidence Disk
    |--------------------------------------------------------------------------
    |
    | Deliverable evidence must outlive a single serverless cold start, so it
    | is pinned to a named disk rather than the default. In production Vercel
    | env values should already define a durable S3-compatible disk; if not,
    | we fall back to the same disk configured by FILESYSTEM_DISK.
    |
    */

    'evidence_disk' => env('EVIDENCE_DISK', env('FILESYSTEM_DISK', env('AWS_BUCKET') || env('S3_BUCKET') ? 's3' : 'public')),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID', env('S3_KEY')),
            'secret' => env('AWS_SECRET_ACCESS_KEY', env('S3_SECRET')),
            'region' => env('AWS_DEFAULT_REGION', env('S3_REGION', 'us-east-1')),
            'bucket' => env('AWS_BUCKET', env('S3_BUCKET')),
            'url' => env('AWS_URL', env('S3_URL')),
            'endpoint' => env('AWS_ENDPOINT', env('S3_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', env('S3_FORCE_PATH_STYLE', true)),
            'throw' => true,
            'report' => false,
            'visibility' => 'public',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
