<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use MuhamadSelim\FilamentS3Filemanager\Services\S3StorageService;

beforeEach(function () {
    $this->service = app(S3StorageService::class);
    $this->disk = 's3';
    Storage::fake($this->disk);
    Cache::flush();
});

test('can upload file to s3', function () {
    $file = UploadedFile::fake()->create('test-file.pdf', 100);
    $folderPath = 'test-folder';

    $result = $this->service->uploadFile($file, $this->disk, $folderPath);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['s3_key', 's3_bucket', 's3_region', 'file_size', 'file_mime_type'])
        ->and($result['s3_key'])->toContain('test-folder')
        ->and($result['file_size'])->toBe(100);

    Storage::disk($this->disk)->assertExists($result['s3_key']);
});

test('can upload file to root directory', function () {
    $file = UploadedFile::fake()->create('test-file.pdf', 100);

    $result = $this->service->uploadFile($file, $this->disk);

    expect($result)->toBeArray()
        ->and(Storage::disk($this->disk)->exists($result['s3_key']))->toBeTrue();
});

test('can generate presigned url', function () {
    Storage::disk($this->disk)->put('test-file.pdf', 'test content');

    $url = $this->service->generatePresignedUrl('test-file.pdf', $this->disk, 3600);

    expect($url)->toBeString()
        ->and($url)->not->toBeEmpty();
});

test('can delete file from s3', function () {
    Storage::disk($this->disk)->put('test-file.pdf', 'test content');

    $result = $this->service->deleteFile('test-file.pdf', $this->disk);

    expect($result)->toBeTrue()
        ->and(Storage::disk($this->disk)->exists('test-file.pdf'))->toBeFalse();
});

test('can check if file exists', function () {
    Storage::disk($this->disk)->put('test-file.pdf', 'test content');

    expect($this->service->fileExists('test-file.pdf', $this->disk))->toBeTrue()
        ->and($this->service->fileExists('non-existent.pdf', $this->disk))->toBeFalse();
});

test('can get file metadata', function () {
    Storage::disk($this->disk)->put('test-file.pdf', 'test content');

    $metadata = $this->service->getFileMetadata('test-file.pdf', $this->disk);

    expect($metadata)->toBeArray()
        ->and($metadata)->toHaveKeys(['exists', 'size', 'last_modified', 'mime_type'])
        ->and($metadata['exists'])->toBeTrue()
        ->and($metadata['size'])->toBeGreaterThan(0);
});

test('can list files with folders structure', function () {
    Storage::disk($this->disk)->put('folder1/file1.pdf', 'content1');
    Storage::disk($this->disk)->put('folder1/file2.pdf', 'content2');
    Storage::disk($this->disk)->put('folder2/file3.pdf', 'content3');
    Storage::disk($this->disk)->put('root-file.pdf', 'content');

    $result = $this->service->listFilesWithFolders($this->disk);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['folders', 'files'])
        ->and($result['folders'])->toBeArray()
        ->and($result['files'])->toBeArray()
        ->and(collect($result['folders'])->pluck('path'))->toContain('folder1')
        ->and(collect($result['folders'])->pluck('path'))->toContain('folder2');
});

test('can get folder tree structure', function () {
    Storage::disk($this->disk)->put('folder1/subfolder/file1.pdf', 'content1');
    Storage::disk($this->disk)->put('folder2/file2.pdf', 'content2');

    $tree = $this->service->getFolderTree($this->disk);

    expect($tree)->toBeArray();
});

test('can list files in folder with pagination', function () {
    // Create multiple files
    for ($i = 1; $i <= 10; $i++) {
        Storage::disk($this->disk)->put("test-folder/file{$i}.pdf", "content{$i}");
    }

    $result = $this->service->listFilesInFolder('test-folder', $this->disk, 1, 5);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['files', 'pagination'])
        ->and($result['files'])->toHaveCount(5)
        ->and($result['pagination'])->toHaveKeys(['current_page', 'per_page', 'total', 'last_page', 'has_more'])
        ->and($result['pagination']['total'])->toBe(10)
        ->and($result['pagination']['has_more'])->toBeTrue();
});

test('can list directories', function () {
    Storage::disk($this->disk)->put('folder1/file1.pdf', 'content1');
    Storage::disk($this->disk)->put('folder2/file2.pdf', 'content2');

    $directories = $this->service->listDirectories('', $this->disk);

    expect($directories)->toBeArray()
        ->and($directories)->toContain('folder1')
        ->and($directories)->toContain('folder2');
});

test('can clear file list cache', function () {
    Cache::put('s3_files_'.$this->disk.'_test', 'cached-data', 300);
    Cache::put('s3_tree_'.$this->disk.'_test', 'cached-tree', 300);

    $this->service->clearFileListCache($this->disk);

    expect(Cache::has('s3_files_'.$this->disk.'_test'))->toBeFalse()
        ->and(Cache::has('s3_tree_'.$this->disk.'_test'))->toBeFalse();
});

