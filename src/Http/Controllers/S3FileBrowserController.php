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

