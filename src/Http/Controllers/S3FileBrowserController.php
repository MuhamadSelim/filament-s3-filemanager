<?php

namespace MuhamadSelim\FilamentS3Filemanager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MuhamadSelim\FilamentS3Filemanager\Services\S3StorageService;

class S3FileBrowserController extends Controller
{
    public function __construct(
        protected S3StorageService $storageService
    ) {}

    /**
     * Get folder contents with pagination.
     */
    public function getFolderContents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_path' => 'nullable|string|max:500',
            'disk' => 'required|string',
            'page' => 'nullable|integer|min:1|max:1000',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate folder path for malicious patterns
        if (! empty($validated['folder_path']) && $this->containsMaliciousPatterns($validated['folder_path'])) {
            \Log::warning('Malicious folder path detected', [
                'original_path' => $validated['folder_path'],
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid folder path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize folder path
        $folderPath = $this->sanitizeFilePath($validated['folder_path'] ?? '');
        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 50;

        try {
            $result = $this->storageService->listFilesInFolder(
                $folderPath,
                $validated['disk'],
                $page,
                $perPage
            );

            return response()->json([
                'success' => true,
                'files' => $result['files'],
                'directories' => $result['directories'] ?? [],
                'pagination' => $result['pagination'],
            ]);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to get folder contents', [
                'folder_path' => $folderPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error getting folder contents', [
                'folder_path' => $folderPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while loading folder contents. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Generate a presigned URL for file preview.
     */
    public function generatePreviewUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate file path for malicious patterns
        if ($this->containsMaliciousPatterns($validated['file_path'])) {
            \Log::warning('Malicious file path detected', [
                'original_path' => $validated['file_path'],
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid file path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize file path
        $filePath = $this->sanitizeFilePath($validated['file_path']);

        if (empty($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid file path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        try {
            $expiration = config('filament-s3-filemanager.presigned_url_expiration', 3600);

            $url = $this->storageService->generatePresignedUrl(
                $filePath,
                $validated['disk'],
                $expiration
            );

            $type = $this->getFileType($filePath);
            $metadata = $this->storageService->getFileMetadata($filePath, $validated['disk']);

            return response()->json([
                'success' => true,
                'url' => $url,
                'type' => $type,
                'metadata' => $metadata,
                'expires_in' => $expiration,
            ]);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to generate preview URL', [
                'file_path' => $filePath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error generating preview URL', [
                'file_path' => $filePath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Upload a file to S3.
     */
    public function uploadFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file',
            'folder_path' => 'nullable|string|max:500',
            'disk' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $folderPath = $this->sanitizeFilePath($validated['folder_path'] ?? '');
        $disk = $validated['disk'] ?? config('filament-s3-filemanager.default_disk', 's3');

        // Validate disk exists in config
        if (! config("filesystems.disks.{$disk}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate file type
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = config('filament-s3-filemanager.allowed_extensions', [
            'mp4', 'webm', 'ogg', 'mov', 'avi', 'pdf', 'doc', 'docx', 'txt',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ppt', 'pptx',
            'mp3', 'wav', 'ogg', 'm4a',
        ]);

        if (! in_array($extension, $allowedExtensions)) {
            return response()->json([
                'success' => false,
                'message' => 'File type not allowed. Allowed types: '.implode(', ', $allowedExtensions),
                'error_type' => 'validation',
            ], 400);
        }

        // Validate file size
        $fileSize = $file->getSize();
        $maxSize = config('filament-s3-filemanager.max_file_size', 2048000) * 1024; // Convert KB to bytes

        if ($fileSize > $maxSize) {
            $maxSizeMB = round($maxSize / 1024 / 1024, 2);

            return response()->json([
                'success' => false,
                'message' => "File size exceeds maximum allowed size of {$maxSizeMB}MB.",
                'error_type' => 'validation',
            ], 400);
        }

        try {
            $result = $this->storageService->uploadFile($file, $disk, $folderPath);

            return response()->json([
                'success' => true,
                'file' => [
                    'path' => $result['s3_key'],
                    'name' => basename($result['s3_key']),
                    'size' => $result['file_size'],
                    'type' => $this->getFileType($result['s3_key']),
                ],
                'message' => 'File uploaded successfully',
            ]);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to upload file', [
                'file' => $file->getClientOriginalName(),
                'folder' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'upload_failed',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error uploading file', [
                'file' => $file->getClientOriginalName(),
                'folder' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while uploading the file. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Delete a file from S3.
     */
    public function deleteFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate file path for malicious patterns
        if ($this->containsMaliciousPatterns($validated['file_path'])) {
            \Log::warning('Malicious file path detected', [
                'original_path' => $validated['file_path'],
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid file path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize file path
        $filePath = $this->sanitizeFilePath($validated['file_path']);

        try {
            $result = $this->storageService->deleteFile($filePath, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'File deleted successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete file.',
                'error_type' => 'delete_failed',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error deleting file', [
                'file_path' => $filePath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while deleting the file. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Delete a folder from S3.
     */
    public function deleteFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate folder path for malicious patterns
        if ($this->containsMaliciousPatterns($validated['folder_path'])) {
            \Log::warning('Malicious folder path detected', [
                'original_path' => $validated['folder_path'],
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid folder path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize folder path
        $folderPath = $this->sanitizeFilePath($validated['folder_path']);

        try {
            $result = $this->storageService->deleteFolder($folderPath, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Folder deleted successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete folder.',
                'error_type' => 'delete_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to delete folder', [
                'folder_path' => $folderPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error deleting folder', [
                'folder_path' => $folderPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while deleting the folder. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Rename a file in S3.
     */
    public function renameFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path' => 'required|string|max:500',
            'new_name' => 'required|string|max:255',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate paths for malicious patterns
        if ($this->containsMaliciousPatterns($validated['file_path']) || 
            $this->containsMaliciousPatterns($validated['new_name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize paths
        $filePath = $this->sanitizeFilePath($validated['file_path']);
        $newName = $this->sanitizeFilePath($validated['new_name']);

        // Validate new name doesn't contain path separators
        if (str_contains($newName, '/')) {
            return response()->json([
                'success' => false,
                'message' => 'New name cannot contain path separators.',
                'error_type' => 'validation',
            ], 400);
        }

        try {
            $result = $this->storageService->renameFile($filePath, $newName, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'File renamed successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to rename file.',
                'error_type' => 'rename_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to rename file', [
                'file_path' => $filePath,
                'new_name' => $newName,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error renaming file', [
                'file_path' => $filePath,
                'new_name' => $newName,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while renaming the file. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Rename a folder in S3.
     */
    public function renameFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_path' => 'required|string|max:500',
            'new_name' => 'required|string|max:255',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate paths for malicious patterns
        if ($this->containsMaliciousPatterns($validated['folder_path']) || 
            $this->containsMaliciousPatterns($validated['new_name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize paths
        $folderPath = $this->sanitizeFilePath($validated['folder_path']);
        $newName = $this->sanitizeFilePath($validated['new_name']);

        // Validate new name doesn't contain path separators
        if (str_contains($newName, '/')) {
            return response()->json([
                'success' => false,
                'message' => 'New name cannot contain path separators.',
                'error_type' => 'validation',
            ], 400);
        }

        try {
            $result = $this->storageService->renameFolder($folderPath, $newName, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Folder renamed successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to rename folder.',
                'error_type' => 'rename_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to rename folder', [
                'folder_path' => $folderPath,
                'new_name' => $newName,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error renaming folder', [
                'folder_path' => $folderPath,
                'new_name' => $newName,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while renaming the folder. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Move a file to a new location.
     */
    public function moveFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_path' => 'required|string|max:500',
            'destination_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate paths for malicious patterns
        if ($this->containsMaliciousPatterns($validated['source_path']) || 
            $this->containsMaliciousPatterns($validated['destination_path'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize paths
        $sourcePath = $this->sanitizeFilePath($validated['source_path']);
        $destinationPath = $this->sanitizeFilePath($validated['destination_path']);

        try {
            $result = $this->storageService->moveFile($sourcePath, $destinationPath, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'File moved successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to move file.',
                'error_type' => 'move_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to move file', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error moving file', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while moving the file. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Move a folder to a new location.
     */
    public function moveFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_path' => 'required|string|max:500',
            'destination_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate paths for malicious patterns
        if ($this->containsMaliciousPatterns($validated['source_path']) || 
            $this->containsMaliciousPatterns($validated['destination_path'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize paths
        $sourcePath = $this->sanitizeFilePath($validated['source_path']);
        $destinationPath = $this->sanitizeFilePath($validated['destination_path']);

        try {
            $result = $this->storageService->moveFolder($sourcePath, $destinationPath, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Folder moved successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to move folder.',
                'error_type' => 'move_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to move folder', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error moving folder', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while moving the folder. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Copy a file to a new location.
     */
    public function copyFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_path' => 'required|string|max:500',
            'destination_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate paths for malicious patterns
        if ($this->containsMaliciousPatterns($validated['source_path']) || 
            $this->containsMaliciousPatterns($validated['destination_path'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize paths
        $sourcePath = $this->sanitizeFilePath($validated['source_path']);
        $destinationPath = $this->sanitizeFilePath($validated['destination_path']);

        try {
            $result = $this->storageService->copyFile($sourcePath, $destinationPath, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'File copied successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to copy file.',
                'error_type' => 'copy_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to copy file', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error copying file', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while copying the file. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Copy a folder to a new location.
     */
    public function copyFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_path' => 'required|string|max:500',
            'destination_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate paths for malicious patterns
        if ($this->containsMaliciousPatterns($validated['source_path']) || 
            $this->containsMaliciousPatterns($validated['destination_path'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize paths
        $sourcePath = $this->sanitizeFilePath($validated['source_path']);
        $destinationPath = $this->sanitizeFilePath($validated['destination_path']);

        try {
            $result = $this->storageService->copyFolder($sourcePath, $destinationPath, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Folder copied successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to copy folder.',
                'error_type' => 'copy_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to copy folder', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error copying folder', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while copying the folder. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Create a new folder in S3.
     */
    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_path' => 'required|string|max:500',
            'disk' => 'required|string',
        ]);

        // Validate disk exists in config
        if (! config("filesystems.disks.{$validated['disk']}")) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid storage disk specified.',
                'error_type' => 'validation',
            ], 400);
        }

        // Validate folder path for malicious patterns
        if ($this->containsMaliciousPatterns($validated['folder_path'])) {
            \Log::warning('Malicious folder path detected', [
                'original_path' => $validated['folder_path'],
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid folder path provided.',
                'error_type' => 'validation',
            ], 400);
        }

        // Sanitize folder path
        $folderPath = $this->sanitizeFilePath($validated['folder_path']);

        try {
            $result = $this->storageService->createFolder($folderPath, $validated['disk']);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Folder created successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create folder.',
                'error_type' => 'create_failed',
            ], 500);
        } catch (\RuntimeException $e) {
            \Log::error('Failed to create folder', [
                'folder_path' => $folderPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 's3_connection',
                'retryable' => true,
            ], 503);
        } catch (\Exception $e) {
            \Log::error('Unexpected error creating folder', [
                'folder_path' => $folderPath,
                'disk' => $validated['disk'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while creating the folder. Please try again.',
                'error_type' => 'unexpected',
                'retryable' => true,
            ], 500);
        }
    }

    /**
     * Check if path contains malicious patterns.
     */
    protected function containsMaliciousPatterns(string $path): bool
    {
        // Check for directory traversal patterns
        if (str_contains($path, '..')) {
            return true;
        }

        // Check for null bytes
        if (str_contains($path, "\0") || str_contains($path, '%00')) {
            return true;
        }

        // Check for absolute paths (Unix and Windows)
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\\\\/', $path)) {
            return true;
        }

        // Check for backslashes (Windows path separators)
        if (str_contains($path, '\\')) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize file path to prevent directory traversal attacks.
     */
    protected function sanitizeFilePath(string $path): string
    {
        // Remove null bytes (security vulnerability)
        $path = str_replace("\0", '', $path);

        // Remove any directory traversal attempts
        $path = str_replace(['..', '\\'], '', $path);

        // Remove leading slashes (prevent absolute paths)
        $path = ltrim($path, '/');

        // Normalize multiple slashes
        $path = preg_replace('#/+#', '/', $path);

        // Remove any remaining suspicious patterns
        $path = preg_replace('#\.\./#', '', $path);
        $path = preg_replace('#\.\.$#', '', $path);

        // Trim whitespace
        $path = trim($path);

        // Allow empty string for root folder
        if ($path === '') {
            return '';
        }

        // Additional validation: path should not start with special characters
        if (preg_match('/^[^a-zA-Z0-9]/', $path)) {
            return '';
        }

        // Validate path doesn't contain suspicious sequences
        $suspiciousPatterns = [
            '#\.\./#',  // Directory traversal
            '#\.\.\\\\#', // Windows directory traversal
            '#\0#',     // Null byte
            '#%00#',    // URL encoded null byte
            '#%2e%2e#', // URL encoded ..
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return '';
            }
        }

        return $path;
    }

    /**
     * Get file type from path.
     */
    protected function getFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' => 'image',
            'pdf' => 'pdf',
            'mp4', 'webm', 'ogg', 'mov', 'avi' => 'video',
            'mp3', 'wav', 'ogg', 'm4a' => 'audio',
            default => 'document',
        };
    }
}

