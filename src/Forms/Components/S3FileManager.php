<?php

namespace MuhamadSelim\FilamentS3Filemanager\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use MuhamadSelim\FilamentS3Filemanager\Services\S3StorageService;

class S3FileManager extends Field
{
    protected string $view = 'filament-s3-filemanager::components.s3-file-manager';

    protected string|\Closure $disk = 's3';

    /**
     * Selection mode: 'file', 'folder', or 'both'
     */
    protected string $selectionMode = 'file';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrated(false);
    }

    /**
     * Set the disk to use for S3 operations.
     */
    public function disk(string|\Closure $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Get the disk name.
     */
    public function getDisk(): string
    {
        if ($this->disk instanceof Closure) {
            return $this->evaluate($this->disk) ?? 's3';
        }

        return $this->disk;
    }

    /**
     * Set selection mode to file only.
     */
    public function fileOnly(): static
    {
        $this->selectionMode = 'file';

        return $this;
    }

    /**
     * Set selection mode to folder only.
     */
    public function folderOnly(): static
    {
        $this->selectionMode = 'folder';

        return $this;
    }

    /**
     * Set selection mode to both file and folder.
     */
    public function fileOrFolder(): static
    {
        $this->selectionMode = 'both';

        return $this;
    }

    /**
     * Get the current selection mode.
     */
    public function getSelectionMode(): string
    {
        return $this->selectionMode;
    }

    /**
     * Check if file selection is enabled.
     */
    public function canSelectFiles(): bool
    {
        return in_array($this->selectionMode, ['file', 'both']);
    }

    /**
     * Check if folder selection is enabled.
     */
    public function canSelectFolders(): bool
    {
        return in_array($this->selectionMode, ['folder', 'both']);
    }

    /**
     * Get files organized by folder structure.
     *
     * @return array ['folders' => [...], 'files' => [...]]
     */
    public function getFilesWithFolders(): array
    {
        try {
            $disk = $this->getDisk();

            return app(S3StorageService::class)->listFilesWithFolders($disk);
        } catch (\Exception $e) {
            \Log::error('Failed to list files with folders in S3FileManager', [
                'disk' => $this->getDisk(),
                'error' => $e->getMessage(),
            ]);

            return ['folders' => [], 'files' => []];
        }
    }

    /**
     * Get folder tree structure.
     *
     * @return array Nested folder structure
     */
    public function getFolderTree(): array
    {
        try {
            $disk = $this->getDisk();

            return app(S3StorageService::class)->getFolderTree($disk);
        } catch (\Exception $e) {
            \Log::error('Failed to get folder tree in S3FileManager', [
                'disk' => $this->getDisk(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get files in a specific folder.
     *
     * @param  string  $folderPath  Folder path
     */
    public function getFilesInFolder(string $folderPath): array
    {
        try {
            $disk = $this->getDisk();

            return app(S3StorageService::class)->listFilesInFolder($folderPath, $disk);
        } catch (\Exception $e) {
            \Log::error('Failed to list files in folder in S3FileManager', [
                'disk' => $this->getDisk(),
                'folder' => $folderPath,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Generate a signed URL for file preview.
     */
    public function getPreviewUrl(string $filePath, int $expiresIn = 3600): ?string
    {
        try {
            $disk = $this->getDisk();
            $storageService = app(S3StorageService::class);

            return $storageService->generatePresignedUrl($filePath, $disk, $expiresIn);
        } catch (\Exception $e) {
            \Log::error('Failed to generate preview URL in S3FileManager', [
                'file_path' => $filePath,
                'disk' => $this->getDisk(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get file type for preview handling.
     */
    public function getFileType(string $filePath): string
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

