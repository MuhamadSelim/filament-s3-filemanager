<?php

namespace MuhamadSelim\FilamentS3Filemanager\Tests\Unit;

use MuhamadSelim\FilamentS3Filemanager\Services\S3StorageService;
use MuhamadSelim\FilamentS3Filemanager\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class S3StorageServiceTest extends TestCase
{
    protected S3StorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure a test disk
        config(['filesystems.disks.test_s3' => [
            'driver' => 'local',
            'root' => storage_path('app/test-s3'),
        ]]);

        $this->service = new S3StorageService();
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (Storage::disk('test_s3')->exists('')) {
            Storage::disk('test_s3')->deleteDirectory('');
        }

        parent::tearDown();
    }

    public function test_can_list_files_in_folder(): void
    {
        Storage::disk('test_s3')->put('test-file.txt', 'test content');
        Storage::disk('test_s3')->put('folder/sub-file.txt', 'sub content');

        $result = $this->service->listFilesInFolder('', 'test_s3', 1, 50);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('directories', $result);
        $this->assertArrayHasKey('pagination', $result);
    }

    public function test_can_get_file_metadata(): void
    {
        Storage::disk('test_s3')->put('test-file.txt', 'test content');

        $metadata = $this->service->getFileMetadata('test-file.txt', 'test_s3');

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('exists', $metadata);
        $this->assertArrayHasKey('size', $metadata);
        $this->assertTrue($metadata['exists']);
    }

    public function test_can_check_file_exists(): void
    {
        Storage::disk('test_s3')->put('test-file.txt', 'test content');

        $this->assertTrue($this->service->fileExists('test-file.txt', 'test_s3'));
        $this->assertFalse($this->service->fileExists('non-existent.txt', 'test_s3'));
    }

    public function test_can_delete_file(): void
    {
        Storage::disk('test_s3')->put('test-file.txt', 'test content');

        $result = $this->service->deleteFile('test-file.txt', 'test_s3');

        $this->assertTrue($result);
        $this->assertFalse(Storage::disk('test_s3')->exists('test-file.txt'));
    }

    public function test_can_create_folder(): void
    {
        $result = $this->service->createFolder('new-folder', 'test_s3');

        $this->assertTrue($result);
        $this->assertTrue(Storage::disk('test_s3')->exists('new-folder/.folder'));
    }
}

