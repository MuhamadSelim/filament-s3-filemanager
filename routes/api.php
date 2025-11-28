<?php

use Illuminate\Support\Facades\Route;
use MuhamadSelim\FilamentS3Filemanager\Http\Controllers\S3FileBrowserController;

$prefix = config('filament-s3-filemanager.routes.prefix', 'api/s3-files');
$middleware = config('filament-s3-filemanager.routes.middleware', ['web', 'auth']);

Route::middleware($middleware)
    ->prefix($prefix)
    ->name('filament-s3-filemanager.')
    ->group(function () {
        // Get folder contents with pagination
        Route::post('folder-contents', [S3FileBrowserController::class, 'getFolderContents'])
            ->middleware(['throttle:60,1'])
            ->name('folder-contents');

        // Generate presigned URL for file preview
        Route::post('preview-url', [S3FileBrowserController::class, 'generatePreviewUrl'])
            ->middleware(['throttle:60,1'])
            ->name('preview-url');

        // Upload file to S3
        Route::post('upload', [S3FileBrowserController::class, 'uploadFile'])
            ->middleware(['throttle:10,1'])
            ->name('upload');
    });

