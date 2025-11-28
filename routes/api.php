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

        // Delete file
        Route::delete('file', [S3FileBrowserController::class, 'deleteFile'])
            ->middleware(['throttle:60,1'])
            ->name('delete-file');

        // Delete folder
        Route::delete('folder', [S3FileBrowserController::class, 'deleteFolder'])
            ->middleware(['throttle:60,1'])
            ->name('delete-folder');

        // Rename file
        Route::post('rename-file', [S3FileBrowserController::class, 'renameFile'])
            ->middleware(['throttle:60,1'])
            ->name('rename-file');

        // Rename folder
        Route::post('rename-folder', [S3FileBrowserController::class, 'renameFolder'])
            ->middleware(['throttle:60,1'])
            ->name('rename-folder');

        // Move file
        Route::post('move-file', [S3FileBrowserController::class, 'moveFile'])
            ->middleware(['throttle:60,1'])
            ->name('move-file');

        // Move folder
        Route::post('move-folder', [S3FileBrowserController::class, 'moveFolder'])
            ->middleware(['throttle:60,1'])
            ->name('move-folder');

        // Copy file
        Route::post('copy-file', [S3FileBrowserController::class, 'copyFile'])
            ->middleware(['throttle:60,1'])
            ->name('copy-file');

        // Copy folder
        Route::post('copy-folder', [S3FileBrowserController::class, 'copyFolder'])
            ->middleware(['throttle:60,1'])
            ->name('copy-folder');

        // Create folder
        Route::post('create-folder', [S3FileBrowserController::class, 'createFolder'])
            ->middleware(['throttle:60,1'])
            ->name('create-folder');
    });

