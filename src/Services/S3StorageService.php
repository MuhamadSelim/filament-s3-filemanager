<?php

namespace MuhamadSelim\FilamentS3Filemanager\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

class S3StorageService
{
    /**
     * Upload file to S3 with encryption.
     *
     * @return array ['s3_key' => string, 's3_bucket' => string, 's3_region' => string, 'file_size' => int, 'file_mime_type' => string]
     *
     * @throws \RuntimeException
     */
    public function uploadFile(
        UploadedFile $file,
        string $disk,
        ?string $folderPath = null
    ): array {
        $filename = $this->generateUniqueFilename($file);
        $s3Key = $folderPath ? rtrim($folderPath, '/').'/'.$filename : $filename;

        try {
            $storage = Storage::disk($disk);
            $bucket = config("filesystems.disks.{$disk}.bucket");

            // Retry upload up to 3 times for transient errors
            $uploaded = $this->retryOperation(function () use ($storage, $s3Key, $file) {
                return $storage->put($s3Key, file_get_contents($file->getRealPath()), 'private');
            }, 3, 'upload file');

            if (! $uploaded) {
                throw new \RuntimeException('Failed to upload file after multiple attempts');
            }

            // Clear cache after upload
            $this->clearFileListCache($disk);

            return [
                's3_key' => $s3Key,
                's3_bucket' => $bucket,
                's3_region' => config("filesystems.disks.{$disk}.region"),
                'file_size' => $file->getSize(),
                'file_mime_type' => $file->getMimeType(),
            ];
        } catch (FilesystemException | UnableToWriteFile $e) {
            Log::error('S3 upload error', [
                'operation' => 'upload',
                's3_key' => $s3Key,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to upload file to storage. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error uploading file to S3', [
                'operation' => 'upload',
                's3_key' => $s3Key,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while uploading the file. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Generate pre-signed URL for secure access.
     *
     * @param  int  $expiresIn  Expiration time in seconds
     *
     * @throws \RuntimeException
     */
    public function generatePresignedUrl(
        string $s3Key,
        string $disk,
        int $expiresIn = 3600
    ): string {
        try {
            $storage = Storage::disk($disk);

            // Retry URL generation up to 2 times for transient errors
            return $this->retryOperation(function () use ($storage, $s3Key, $expiresIn) {
                return $storage->temporaryUrl(
                    $s3Key,
                    now()->addSeconds($expiresIn)
                );
            }, 2, 'generate presigned URL');
        } catch (FilesystemException | UnableToReadFile $e) {
            Log::error('S3 presigned URL generation error', [
                'operation' => 'generate_presigned_url',
                's3_key' => $s3Key,
                'disk' => $disk,
                'expires_in' => $expiresIn,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to generate file preview URL. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error generating presigned URL', [
                'operation' => 'generate_presigned_url',
                's3_key' => $s3Key,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while generating the preview URL. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Delete file from S3.
     */
    public function deleteFile(string $s3Key, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);
            $result = $storage->delete($s3Key);

            // Clear cache after deletion
            if ($result) {
                $this->clearFileListCache($disk);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to delete S3 file', [
                's3_key' => $s3Key,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get file metadata.
     */
    public function getFileMetadata(string $s3Key, string $disk): array
    {
        try {
            $storage = Storage::disk($disk);

            return [
                'exists' => $storage->exists($s3Key),
                'size' => $storage->size($s3Key),
                'last_modified' => $storage->lastModified($s3Key),
                'mime_type' => $storage->mimeType($s3Key),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get S3 file metadata', [
                's3_key' => $s3Key,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return [
                'exists' => false,
                'size' => 0,
                'last_modified' => null,
                'mime_type' => null,
            ];
        }
    }

    /**
     * Check if file exists.
     */
    public function fileExists(string $s3Key, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            return $storage->exists($s3Key);
        } catch (\Exception $e) {
            Log::error('Failed to check S3 file existence', [
                's3_key' => $s3Key,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * List files organized by folder structure.
     *
     * @return array ['folders' => [...], 'files' => [...]]
     */
    public function listFilesWithFolders(string $disk): array
    {
        $cacheKey = 's3_files_'.$disk.'_'.md5($disk);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($disk) {
            try {
                $storage = Storage::disk($disk);

                // Retry listing files up to 2 times for transient errors
                $allFiles = $this->retryOperation(function () use ($storage) {
                    return $storage->allFiles();
                }, 2, 'list files with folders');

                return $this->buildFolderStructure($allFiles, $storage);
            } catch (FilesystemException $e) {
                Log::error('S3 connection error listing files with folders', [
                    'operation' => 'list_files_with_folders',
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);

                return ['folders' => [], 'files' => []];
            } catch (\Exception $e) {
                Log::error('Unexpected error listing files with folders', [
                    'operation' => 'list_files_with_folders',
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);

                return ['folders' => [], 'files' => []];
            }
        });
    }

    /**
     * Get folder tree structure.
     *
     * @return array Nested folder structure
     */
    public function getFolderTree(string $disk): array
    {
        $cacheKey = 's3_tree_'.$disk.'_'.md5($disk);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($disk) {
            try {
                $storage = Storage::disk($disk);

                // Retry listing files up to 2 times for transient errors
                $allFiles = $this->retryOperation(function () use ($storage) {
                    return $storage->allFiles();
                }, 2, 'get folder tree');

                return $this->buildFolderTree($allFiles);
            } catch (FilesystemException $e) {
                Log::error('S3 connection error building folder tree', [
                    'operation' => 'get_folder_tree',
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);

                return [];
            } catch (\Exception $e) {
                Log::error('Unexpected error building folder tree', [
                    'operation' => 'get_folder_tree',
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * List files in a specific folder (non-recursive) with pagination.
     *
     * @param  string  $folderPath  Folder path
     * @param  int  $page  Page number (1-indexed)
     * @param  int  $perPage  Items per page
     * @return array ['files' => [...], 'pagination' => [...]]
     */
    public function listFilesInFolder(
        string $folderPath,
        string $disk,
        int $page = 1,
        int $perPage = 50
    ): array {
        try {
            $storage = Storage::disk($disk);

            // Retry listing files up to 2 times for transient errors
            $allFiles = $this->retryOperation(function () use ($storage, $folderPath) {
                return $storage->files($folderPath);
            }, 2, 'list files in folder');

            $total = count($allFiles);
            $offset = ($page - 1) * $perPage;
            $files = array_slice($allFiles, $offset, $perPage);

            $result = [];
            foreach ($files as $filePath) {
                $result[] = $this->formatFileInfo($filePath, $storage);
            }

            return [
                'files' => $result,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                    'has_more' => $page < ceil($total / $perPage),
                ],
            ];
        } catch (FilesystemException $e) {
            Log::error('S3 connection error listing files in folder', [
                'operation' => 'list_files_in_folder',
                'folder' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return [
                'files' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 0,
                    'has_more' => false,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Unexpected error listing files in folder', [
                'operation' => 'list_files_in_folder',
                'folder' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return [
                'files' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 0,
                    'has_more' => false,
                ],
            ];
        }
    }

    /**
     * List directories in a specific folder.
     *
     * @return array Array of directory paths
     */
    public function listDirectories(string $folderPath, string $disk): array
    {
        try {
            $storage = Storage::disk($disk);

            return $this->retryOperation(function () use ($storage, $folderPath) {
                return $storage->directories($folderPath);
            }, 2, 'list directories');
        } catch (FilesystemException $e) {
            Log::error('S3 connection error listing directories', [
                'operation' => 'list_directories',
                'folder' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('Unexpected error listing directories', [
                'operation' => 'list_directories',
                'folder' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Clear file list cache.
     */
    public function clearFileListCache(string $disk): void
    {
        $cacheKey = 's3_files_'.$disk.'_'.md5($disk);
        $treeCacheKey = 's3_tree_'.$disk.'_'.md5($disk);

        Cache::forget($cacheKey);
        Cache::forget($treeCacheKey);
    }

    /**
     * Build folder structure from flat file list.
     *
     * @param  array  $allFiles  Flat array of file paths
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $storage  Storage disk
     * @return array ['folders' => [...], 'files' => [...]]
     */
    protected function buildFolderStructure(array $allFiles, $storage): array
    {
        $folders = [];
        $files = [];

        foreach ($allFiles as $filePath) {
            $parts = explode('/', $filePath);

            // If file is in root, add to files
            if (count($parts) === 1) {
                $files[] = $this->formatFileInfo($filePath, $storage);

                continue;
            }

            // Build folder entries for ALL levels of the path
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $folderPath = implode('/', array_slice($parts, 0, $i + 1));

                if (! isset($folders[$folderPath])) {
                    $folders[$folderPath] = [
                        'name' => basename($folderPath),
                        'path' => $folderPath,
                        'file_count' => 0,
                    ];
                }

                // Only count files in the immediate parent folder
                if ($i === count($parts) - 2) {
                    $folders[$folderPath]['file_count']++;
                }
            }
        }

        return [
            'folders' => array_values($folders),
            'files' => $files,
        ];
    }

    /**
     * Build folder tree from flat file list.
     *
     * @param  array  $allFiles  Flat array of file paths
     * @return array Nested folder structure
     */
    protected function buildFolderTree(array $allFiles): array
    {
        $tree = [];
        $folderCounts = [];

        // First pass: count files in each folder
        foreach ($allFiles as $filePath) {
            $parts = explode('/', $filePath);

            // Count files for each level of the path
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $folderPath = implode('/', array_slice($parts, 0, $i + 1));
                if (! isset($folderCounts[$folderPath])) {
                    $folderCounts[$folderPath] = 0;
                }
                $folderCounts[$folderPath]++;
            }
        }

        // Second pass: build tree structure
        foreach ($allFiles as $filePath) {
            $parts = explode('/', $filePath);

            if (count($parts) === 1) {
                continue; // Skip root files
            }

            $current = &$tree;

            // Build folder structure
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $folder = $parts[$i];
                $folderPath = implode('/', array_slice($parts, 0, $i + 1));

                if (! isset($current[$folder])) {
                    $current[$folder] = [
                        'name' => $folder,
                        'path' => $folderPath,
                        'children' => [],
                        'file_count' => $folderCounts[$folderPath] ?? 0,
                    ];
                }

                $current = &$current[$folder]['children'];
            }
        }

        return array_values($tree);
    }

    /**
     * Format file information.
     *
     * @param  string  $filePath  File path
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $storage  Storage disk
     */
    protected function formatFileInfo(string $filePath, $storage): array
    {
        try {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            return [
                'path' => $filePath,
                'name' => basename($filePath),
                'size' => $storage->size($filePath),
                'type' => $this->getFileTypeFromExtension($extension),
                'last_modified' => date('Y-m-d H:i:s', $storage->lastModified($filePath)),
            ];
        } catch (\Exception $e) {
            return [
                'path' => $filePath,
                'name' => basename($filePath),
                'size' => 0,
                'type' => 'unknown',
                'last_modified' => null,
            ];
        }
    }

    /**
     * Get file type from extension.
     *
     * @param  string  $extension  File extension
     */
    protected function getFileTypeFromExtension(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' => 'image',
            'pdf' => 'pdf',
            'mp4', 'webm', 'ogg', 'mov', 'avi' => 'video',
            'mp3', 'wav', 'ogg', 'm4a' => 'audio',
            'doc', 'docx', 'txt' => 'document',
            'ppt', 'pptx' => 'presentation',
            default => 'file',
        };
    }

    /**
     * Generate unique filename for uploaded file.
     */
    protected function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $sanitizedName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $timestamp = now()->timestamp;

        return "{$sanitizedName}-{$timestamp}.{$extension}";
    }

    /**
     * Retry an operation with exponential backoff for transient errors.
     *
     * @param  callable  $operation  The operation to retry
     * @param  int  $maxAttempts  Maximum number of attempts
     * @param  string  $operationName  Name of the operation for logging
     * @return mixed The result of the operation
     *
     * @throws \Exception
     */
    protected function retryOperation(callable $operation, int $maxAttempts, string $operationName)
    {
        $attempt = 1;
        $lastException = null;

        while ($attempt <= $maxAttempts) {
            try {
                return $operation();
            } catch (FilesystemException $e) {
                $lastException = $e;

                // Check if error is retryable
                $isRetryable = $this->isRetryableError($e);

                if (! $isRetryable || $attempt >= $maxAttempts) {
                    throw $e;
                }

                // Exponential backoff: 100ms, 200ms, 400ms, etc.
                $delay = 100 * pow(2, $attempt - 1);

                Log::warning("S3 operation failed, retrying ({$attempt}/{$maxAttempts})", [
                    'operation' => $operationName,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $e->getMessage(),
                ]);

                usleep($delay * 1000); // Convert to microseconds
                $attempt++;
            } catch (\Exception $e) {
                // Non-Flysystem exceptions are not retried
                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Determine if an error is retryable.
     */
    protected function isRetryableError(FilesystemException $exception): bool
    {
        // Most FilesystemException errors are transient and retryable
        // You can add more specific checks here if needed
        return true;
    }
}

