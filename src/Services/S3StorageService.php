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
        // Validate disk configuration first
        $this->validateDiskConfiguration($disk);

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
            $errorMessage = $e->getMessage();
            $isConfigError = str_contains($errorMessage, 'Could not resolve host') ||
                            str_contains($errorMessage, 'Malformed URL') ||
                            str_contains($errorMessage, 'Invalid URL');

            Log::error('S3 upload error', [
                'operation' => 'upload',
                's3_key' => $s3Key,
                'disk' => $disk,
                'error' => $errorMessage,
                'is_config_error' => $isConfigError,
            ]);

            if ($isConfigError) {
                throw new \RuntimeException(
                    "Storage configuration error for disk '{$disk}'. ".
                    "Please check your endpoint configuration in filesystems.php. ".
                    "Error: ".$errorMessage,
                    0,
                    $e
                );
            }

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
     * Delete folder and all its contents from S3.
     *
     * @throws \RuntimeException
     */
    public function deleteFolder(string $folderPath, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Sanitize folder path
            $folderPath = rtrim($folderPath, '/');

            // List all files in the folder (recursive)
            $allFiles = $this->retryOperation(function () use ($storage, $folderPath) {
                return $storage->allFiles($folderPath);
            }, 2, 'list files for folder deletion');

            // Delete all files
            $deleted = true;
            foreach ($allFiles as $filePath) {
                if (!$storage->delete($filePath)) {
                    $deleted = false;
                }
            }

            // Clear cache after deletion
            if ($deleted) {
                $this->clearFileListCache($disk);
            }

            return $deleted;
        } catch (FilesystemException $e) {
            Log::error('S3 connection error deleting folder', [
                'operation' => 'delete_folder',
                'folder_path' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to delete folder. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Failed to delete S3 folder', [
                'folder_path' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Rename a file in S3.
     *
     * @throws \RuntimeException
     */
    public function renameFile(string $oldPath, string $newName, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Get directory path
            $directory = dirname($oldPath);
            $newPath = $directory === '.' || $directory === '' 
                ? $newName 
                : rtrim($directory, '/').'/'.$newName;

            // Check if new path already exists
            if ($storage->exists($newPath)) {
                throw new \RuntimeException('A file with this name already exists.');
            }

            // Move/rename the file
            $result = $this->retryOperation(function () use ($storage, $oldPath, $newPath) {
                return $storage->move($oldPath, $newPath);
            }, 2, 'rename file');

            // Clear cache after rename
            if ($result) {
                $this->clearFileListCache($disk);
            }

            return $result;
        } catch (FilesystemException | UnableToWriteFile $e) {
            Log::error('S3 rename file error', [
                'operation' => 'rename_file',
                'old_path' => $oldPath,
                'new_name' => $newName,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to rename file. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error renaming file', [
                'old_path' => $oldPath,
                'new_name' => $newName,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while renaming the file. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Rename a folder in S3.
     *
     * @throws \RuntimeException
     */
    public function renameFolder(string $oldPath, string $newName, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Sanitize paths
            $oldPath = rtrim($oldPath, '/');
            $parentPath = dirname($oldPath);
            $newPath = ($parentPath === '.' || $parentPath === '') 
                ? $newName 
                : rtrim($parentPath, '/').'/'.$newName;

            // Check if new path already exists
            $existingDirs = $this->retryOperation(function () use ($storage, $parentPath) {
                return $storage->directories($parentPath === '.' ? '' : $parentPath);
            }, 2, 'check existing directories');

            foreach ($existingDirs as $dir) {
                if (basename($dir) === $newName) {
                    throw new \RuntimeException('A folder with this name already exists.');
                }
            }

            // Get all files in the old folder
            $allFiles = $this->retryOperation(function () use ($storage, $oldPath) {
                return $storage->allFiles($oldPath);
            }, 2, 'list files for folder rename');

            // Move all files to new path
            $moved = true;
            foreach ($allFiles as $filePath) {
                $relativePath = str_replace($oldPath.'/', '', $filePath);
                $newFilePath = $newPath.'/'.$relativePath;

                if (!$storage->move($filePath, $newFilePath)) {
                    $moved = false;
                    break;
                }
            }

            // Clear cache after rename
            if ($moved) {
                $this->clearFileListCache($disk);
            }

            return $moved;
        } catch (FilesystemException $e) {
            Log::error('S3 connection error renaming folder', [
                'operation' => 'rename_folder',
                'old_path' => $oldPath,
                'new_name' => $newName,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to rename folder. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error renaming folder', [
                'old_path' => $oldPath,
                'new_name' => $newName,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while renaming the folder. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Move a file to a new location.
     *
     * @throws \RuntimeException
     */
    public function moveFile(string $sourcePath, string $destinationPath, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Ensure destination path doesn't have trailing slash if it's a file
            $destinationPath = rtrim($destinationPath, '/');

            // Check if destination already exists
            if ($storage->exists($destinationPath)) {
                throw new \RuntimeException('A file with this name already exists at the destination.');
            }

            // Move the file
            $result = $this->retryOperation(function () use ($storage, $sourcePath, $destinationPath) {
                return $storage->move($sourcePath, $destinationPath);
            }, 2, 'move file');

            // Clear cache after move
            if ($result) {
                $this->clearFileListCache($disk);
            }

            return $result;
        } catch (FilesystemException | UnableToWriteFile $e) {
            Log::error('S3 move file error', [
                'operation' => 'move_file',
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to move file. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error moving file', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while moving the file. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Move a folder to a new location.
     *
     * @throws \RuntimeException
     */
    public function moveFolder(string $sourcePath, string $destinationPath, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Sanitize paths
            $sourcePath = rtrim($sourcePath, '/');
            $destinationPath = rtrim($destinationPath, '/');

            // Check if destination already exists
            $parentPath = dirname($destinationPath);
            $folderName = basename($destinationPath);
            $existingDirs = $this->retryOperation(function () use ($storage, $parentPath) {
                return $storage->directories($parentPath === '.' ? '' : $parentPath);
            }, 2, 'check existing directories');

            foreach ($existingDirs as $dir) {
                if (basename($dir) === $folderName) {
                    throw new \RuntimeException('A folder with this name already exists at the destination.');
                }
            }

            // Get all files in the source folder
            $allFiles = $this->retryOperation(function () use ($storage, $sourcePath) {
                return $storage->allFiles($sourcePath);
            }, 2, 'list files for folder move');

            // Move all files to new path
            $moved = true;
            foreach ($allFiles as $filePath) {
                $relativePath = str_replace($sourcePath.'/', '', $filePath);
                $newFilePath = $destinationPath.'/'.$relativePath;

                if (!$storage->move($filePath, $newFilePath)) {
                    $moved = false;
                    break;
                }
            }

            // Clear cache after move
            if ($moved) {
                $this->clearFileListCache($disk);
            }

            return $moved;
        } catch (FilesystemException $e) {
            Log::error('S3 connection error moving folder', [
                'operation' => 'move_folder',
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to move folder. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error moving folder', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while moving the folder. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Copy a file to a new location.
     *
     * @throws \RuntimeException
     */
    public function copyFile(string $sourcePath, string $destinationPath, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Ensure destination path doesn't have trailing slash
            $destinationPath = rtrim($destinationPath, '/');

            // Check if destination already exists
            if ($storage->exists($destinationPath)) {
                throw new \RuntimeException('A file with this name already exists at the destination.');
            }

            // Copy the file
            $result = $this->retryOperation(function () use ($storage, $sourcePath, $destinationPath) {
                return $storage->copy($sourcePath, $destinationPath);
            }, 2, 'copy file');

            // Clear cache after copy
            if ($result) {
                $this->clearFileListCache($disk);
            }

            return $result;
        } catch (FilesystemException | UnableToWriteFile $e) {
            Log::error('S3 copy file error', [
                'operation' => 'copy_file',
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to copy file. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error copying file', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while copying the file. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Copy a folder and all its contents to a new location.
     *
     * @throws \RuntimeException
     */
    public function copyFolder(string $sourcePath, string $destinationPath, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Sanitize paths
            $sourcePath = rtrim($sourcePath, '/');
            $destinationPath = rtrim($destinationPath, '/');

            // Check if destination already exists
            $parentPath = dirname($destinationPath);
            $folderName = basename($destinationPath);
            $existingDirs = $this->retryOperation(function () use ($storage, $parentPath) {
                return $storage->directories($parentPath === '.' ? '' : $parentPath);
            }, 2, 'check existing directories');

            foreach ($existingDirs as $dir) {
                if (basename($dir) === $folderName) {
                    throw new \RuntimeException('A folder with this name already exists at the destination.');
                }
            }

            // Get all files in the source folder
            $allFiles = $this->retryOperation(function () use ($storage, $sourcePath) {
                return $storage->allFiles($sourcePath);
            }, 2, 'list files for folder copy');

            // Copy all files to new path
            $copied = true;
            foreach ($allFiles as $filePath) {
                $relativePath = str_replace($sourcePath.'/', '', $filePath);
                $newFilePath = $destinationPath.'/'.$relativePath;

                if (!$storage->copy($filePath, $newFilePath)) {
                    $copied = false;
                    break;
                }
            }

            // Clear cache after copy
            if ($copied) {
                $this->clearFileListCache($disk);
            }

            return $copied;
        } catch (FilesystemException $e) {
            Log::error('S3 connection error copying folder', [
                'operation' => 'copy_folder',
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to copy folder. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error copying folder', [
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while copying the folder. Please try again.',
                0,
                $e
            );
        }
    }

    /**
     * Create a new folder in S3.
     *
     * @throws \RuntimeException
     */
    public function createFolder(string $folderPath, string $disk): bool
    {
        try {
            $storage = Storage::disk($disk);

            // Sanitize folder path
            $folderPath = rtrim($folderPath, '/');

            // Check if folder already exists
            $parentPath = dirname($folderPath);
            $folderName = basename($folderPath);

            if ($parentPath === '.' || $parentPath === '') {
                $parentPath = '';
            } else {
                $parentPath = rtrim($parentPath, '/');
            }

            $existingDirs = $this->retryOperation(function () use ($storage, $parentPath) {
                return $storage->directories($parentPath);
            }, 2, 'check existing directories');

            foreach ($existingDirs as $dir) {
                if (basename($dir) === $folderName) {
                    throw new \RuntimeException('A folder with this name already exists.');
                }
            }

            // Create folder by creating a placeholder file (S3 doesn't have true folders)
            // We'll create an empty file with a special marker
            $placeholderPath = $folderPath.'/.folder';
            $result = $this->retryOperation(function () use ($storage, $placeholderPath) {
                return $storage->put($placeholderPath, '');
            }, 2, 'create folder');

            // Clear cache after creation
            if ($result) {
                $this->clearFileListCache($disk);
            }

            return $result;
        } catch (FilesystemException | UnableToWriteFile $e) {
            Log::error('S3 create folder error', [
                'operation' => 'create_folder',
                'folder_path' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Unable to create folder. Please check your connection and try again.',
                0,
                $e
            );
        } catch (\Exception $e) {
            Log::error('Unexpected error creating folder', [
                'folder_path' => $folderPath,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'An unexpected error occurred while creating the folder. Please try again.',
                0,
                $e
            );
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
        // Validate disk configuration first
        $this->validateDiskConfiguration($disk);

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
                $errorMessage = $e->getMessage();
                $isConfigError = str_contains($errorMessage, 'Could not resolve host') ||
                                str_contains($errorMessage, 'Malformed URL');

                Log::error('S3 connection error listing files with folders', [
                    'operation' => 'list_files_with_folders',
                    'disk' => $disk,
                    'error' => $errorMessage,
                    'is_config_error' => $isConfigError,
                ]);

                // Don't cache configuration errors
                if ($isConfigError) {
                    Cache::forget($cacheKey);
                    throw new \RuntimeException(
                        "Storage configuration error for disk '{$disk}'. ".
                        "Please check your endpoint configuration. Error: ".$errorMessage,
                        0,
                        $e
                    );
                }

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
        // Validate disk configuration first
        $this->validateDiskConfiguration($disk);

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
                $errorMessage = $e->getMessage();
                $isConfigError = str_contains($errorMessage, 'Could not resolve host') ||
                                str_contains($errorMessage, 'Malformed URL');

                Log::error('S3 connection error building folder tree', [
                    'operation' => 'get_folder_tree',
                    'disk' => $disk,
                    'error' => $errorMessage,
                    'is_config_error' => $isConfigError,
                ]);

                // Don't cache configuration errors
                if ($isConfigError) {
                    Cache::forget($cacheKey);
                    throw new \RuntimeException(
                        "Storage configuration error for disk '{$disk}'. ".
                        "Please check your endpoint configuration. Error: ".$errorMessage,
                        0,
                        $e
                    );
                }

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
     * List files and directories in a specific folder (non-recursive) with pagination.
     *
     * @param  string  $folderPath  Folder path
     * @param  string  $disk  Disk name
     * @param  int  $page  Page number (1-indexed)
     * @param  int  $perPage  Items per page
     * @return array ['files' => [...], 'directories' => [...], 'pagination' => [...]]
     */
    public function listFilesInFolder(
        string $folderPath,
        string $disk,
        int $page = 1,
        int $perPage = 50
    ): array {
        // Validate disk configuration first
        $this->validateDiskConfiguration($disk);

        try {
            $storage = Storage::disk($disk);

            // Retry listing files and directories up to 2 times for transient errors
            $allFiles = $this->retryOperation(function () use ($storage, $folderPath) {
                return $storage->files($folderPath);
            }, 2, 'list files in folder');

            $allDirectories = $this->retryOperation(function () use ($storage, $folderPath) {
                return $storage->directories($folderPath);
            }, 2, 'list directories in folder');

            // Format directories
            $formattedDirectories = [];
            foreach ($allDirectories as $dirPath) {
                $formattedDirectories[] = [
                    'path' => $dirPath,
                    'name' => basename($dirPath),
                    'type' => 'directory',
                ];
            }

            // Format files
            $formattedFiles = [];
            foreach ($allFiles as $filePath) {
                $formattedFiles[] = $this->formatFileInfo($filePath, $storage);
            }

            // Calculate pagination for combined items (directories first, then files)
            $allItems = array_merge($formattedDirectories, $formattedFiles);
            $total = count($allItems);
            $offset = ($page - 1) * $perPage;
            $paginatedItems = array_slice($allItems, $offset, $perPage);

            // Separate paginated items back into directories and files
            $resultDirectories = [];
            $resultFiles = [];

            foreach ($paginatedItems as $item) {
                if (is_array($item) && isset($item['type']) && $item['type'] === 'directory') {
                    $resultDirectories[] = $item;
                } else {
                    $resultFiles[] = $item;
                }
            }

            return [
                'files' => $resultFiles,
                'directories' => $resultDirectories,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                    'has_more' => $page < ceil($total / $perPage),
                ],
            ];
            } catch (FilesystemException $e) {
                $errorMessage = $e->getMessage();
                $isConfigError = str_contains($errorMessage, 'Could not resolve host') ||
                                str_contains($errorMessage, 'Malformed URL');

                Log::error('S3 connection error listing files in folder', [
                    'operation' => 'list_files_in_folder',
                    'folder' => $folderPath,
                    'disk' => $disk,
                    'error' => $errorMessage,
                    'is_config_error' => $isConfigError,
                ]);

                // Throw configuration errors instead of returning empty
                if ($isConfigError) {
                    throw new \RuntimeException(
                        "Storage configuration error for disk '{$disk}'. ".
                        "Please check your endpoint configuration. Error: ".$errorMessage,
                        0,
                        $e
                    );
                }

                return [
                    'files' => [],
                    'directories' => [],
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
                    'directories' => [],
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
        $errorMessage = $exception->getMessage();

        // DNS resolution errors are not retryable - configuration issue
        if (str_contains($errorMessage, 'Could not resolve host') ||
            str_contains($errorMessage, 'Name or service not known') ||
            str_contains($errorMessage, 'getaddrinfo failed')) {
            return false;
        }

        // Malformed URL errors are not retryable - configuration issue
        if (str_contains($errorMessage, 'Malformed URL') ||
            str_contains($errorMessage, 'Invalid URL')) {
            return false;
        }

        // Connection refused/timeout errors might be retryable (network issues)
        if (str_contains($errorMessage, 'Connection refused') ||
            str_contains($errorMessage, 'Connection timed out') ||
            str_contains($errorMessage, 'Operation timed out')) {
            return true;
        }

        // HTTP 5xx errors are retryable (server errors)
        if (preg_match('/HTTP error: (\d{3})/', $errorMessage, $matches)) {
            $statusCode = (int) $matches[1];
            if ($statusCode >= 500 && $statusCode < 600) {
                return true;
            }
        }

        // cURL errors that are retryable
        if (preg_match('/cURL error (\d+):/', $errorMessage, $matches)) {
            $curlError = (int) $matches[1];
            // cURL error 6 = Could not resolve host (not retryable)
            // cURL error 7 = Failed to connect (might be retryable)
            // cURL error 28 = Timeout (retryable)
            return in_array($curlError, [7, 28, 35, 52, 55], true);
        }

        // Default: retry for transient errors
        return true;
    }

    /**
     * Validate disk configuration before operations.
     *
     * @throws \RuntimeException
     */
    protected function validateDiskConfiguration(string $disk): void
    {
        $config = config("filesystems.disks.{$disk}");

        if (! $config) {
            throw new \RuntimeException(
                "Storage disk '{$disk}' is not configured. Please check your filesystems.php configuration."
            );
        }

        // Check for S3-compatible storage
        if ($config['driver'] !== 's3') {
            throw new \RuntimeException(
                "Storage disk '{$disk}' must use the 's3' driver for S3-compatible storage."
            );
        }

        // Validate endpoint if provided
        if (isset($config['endpoint']) && ! empty($config['endpoint'])) {
            $endpoint = $config['endpoint'];

            // Check for malformed endpoint URLs
            if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException(
                    "Invalid endpoint URL for disk '{$disk}': '{$endpoint}'. ".
                    "Please check your AWS_ENDPOINT or DIGITALOCEAN_SPACES_ENDPOINT environment variable."
                );
            }

            // Check if endpoint is DigitalOcean Spaces format
            $isDigitalOcean = str_contains($endpoint, 'digitaloceanspaces.com');

            // For DigitalOcean Spaces, use_path_style_endpoint MUST be true
            // Otherwise, the S3 client will try to construct virtual-hosted URLs incorrectly
            // This causes URLs like: https://s3.fra1.amazonaws.com/fra1.digitaloceanspaces.com/ (WRONG)
            // Instead of: https://fra1.digitaloceanspaces.com/bucket-name/ (CORRECT)
            $usePathStyle = $config['use_path_style_endpoint'] ?? false;

            if ($isDigitalOcean && ! $usePathStyle) {
                throw new \RuntimeException(
                    "Storage disk '{$disk}' is configured for DigitalOcean Spaces but 'use_path_style_endpoint' is not set to true. ".
                    "This causes the S3 client to incorrectly construct URLs. ".
                    "Please add 'use_path_style_endpoint' => true to your disk configuration in config/filesystems.php. ".
                    "Example:\n".
                    "'{$disk}' => [\n".
                    "    'driver' => 's3',\n".
                    "    'endpoint' => env('DIGITALOCEAN_SPACES_ENDPOINT'),\n".
                    "    'use_path_style_endpoint' => true, // Required for DigitalOcean Spaces\n".
                    "    // ... other config\n".
                    "],"
                );
            }

            // Warn about common endpoint misconfigurations
            if (str_contains($endpoint, 's3.') && str_contains($endpoint, 'digitaloceanspaces.com')) {
                Log::warning('Potential endpoint misconfiguration detected', [
                    'disk' => $disk,
                    'endpoint' => $endpoint,
                    'message' => 'Endpoint appears to mix AWS S3 and DigitalOcean Spaces formats. '.
                                 'For DigitalOcean Spaces, use: https://{region}.digitaloceanspaces.com',
                ]);
            }

            // Validate endpoint format for DigitalOcean Spaces
            if ($isDigitalOcean) {
                // DigitalOcean Spaces endpoint should be: https://{region}.digitaloceanspaces.com
                if (! preg_match('/^https:\/\/([a-z0-9-]+)\.digitaloceanspaces\.com\/?$/', $endpoint)) {
                    Log::warning('DigitalOcean Spaces endpoint format may be incorrect', [
                        'disk' => $disk,
                        'endpoint' => $endpoint,
                        'expected_format' => 'https://{region}.digitaloceanspaces.com',
                        'example' => 'https://fra1.digitaloceanspaces.com',
                    ]);
                }
            }
        }
    }
}

