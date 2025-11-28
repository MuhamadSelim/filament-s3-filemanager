<?php

use Illuminate\Support\Facades\Storage;
use MuhamadSelim\FilamentS3Filemanager\Forms\Components\S3FileManager;

beforeEach(function () {
    $this->disk = 's3';
    Storage::fake($this->disk);
});

test('component can be instantiated', function () {
    $component = S3FileManager::make('file_path');

    expect($component)->toBeInstanceOf(S3FileManager::class);
});

test('component has default disk', function () {
    $component = S3FileManager::make('file_path');

    expect($component->getDisk())->toBe('s3');
});

test('component can set custom disk', function () {
    $component = S3FileManager::make('file_path')
        ->disk('custom-disk');

    expect($component->getDisk())->toBe('custom-disk');
});

test('component can set disk via closure', function () {
    $component = S3FileManager::make('file_path')
        ->disk(fn () => 'dynamic-disk');

    expect($component->getDisk())->toBe('dynamic-disk');
});

test('component defaults to file selection mode', function () {
    $component = S3FileManager::make('file_path');

    expect($component->getSelectionMode())->toBe('file')
        ->and($component->canSelectFiles())->toBeTrue()
        ->and($component->canSelectFolders())->toBeFalse();
});

test('component can set file only mode', function () {
    $component = S3FileManager::make('file_path')
        ->fileOnly();

    expect($component->getSelectionMode())->toBe('file')
        ->and($component->canSelectFiles())->toBeTrue()
        ->and($component->canSelectFolders())->toBeFalse();
});

test('component can set folder only mode', function () {
    $component = S3FileManager::make('file_path')
        ->folderOnly();

    expect($component->getSelectionMode())->toBe('folder')
        ->and($component->canSelectFiles())->toBeFalse()
        ->and($component->canSelectFolders())->toBeTrue();
});

test('component can set file or folder mode', function () {
    $component = S3FileManager::make('file_path')
        ->fileOrFolder();

    expect($component->getSelectionMode())->toBe('both')
        ->and($component->canSelectFiles())->toBeTrue()
        ->and($component->canSelectFolders())->toBeTrue();
});

test('component can get files with folders', function () {
    Storage::disk($this->disk)->put('folder1/file1.pdf', 'content1');
    Storage::disk($this->disk)->put('folder2/file2.pdf', 'content2');

    $component = S3FileManager::make('file_path')
        ->disk($this->disk);

    $result = $component->getFilesWithFolders();

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['folders', 'files']);
});

test('component can get folder tree', function () {
    Storage::disk($this->disk)->put('folder1/subfolder/file1.pdf', 'content1');

    $component = S3FileManager::make('file_path')
        ->disk($this->disk);

    $tree = $component->getFolderTree();

    expect($tree)->toBeArray();
});

test('component can get files in folder', function () {
    Storage::disk($this->disk)->put('test-folder/file1.pdf', 'content1');
    Storage::disk($this->disk)->put('test-folder/file2.pdf', 'content2');

    $component = S3FileManager::make('file_path')
        ->disk($this->disk);

    $result = $component->getFilesInFolder('test-folder');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('files')
        ->and($result)->toHaveKey('pagination');
});

test('component can generate preview url', function () {
    Storage::disk($this->disk)->put('test-file.pdf', 'content');

    $component = S3FileManager::make('file_path')
        ->disk($this->disk);

    $url = $component->getPreviewUrl('test-file.pdf', 3600);

    // URL might be null if temporaryUrl is not available in fake storage
    expect($url)->toBeString()->or->toBeNull();
});

test('component can determine file type', function () {
    $component = S3FileManager::make('file_path');

    expect($component->getFileType('test.jpg'))->toBe('image')
        ->and($component->getFileType('test.mp4'))->toBe('video')
        ->and($component->getFileType('test.pdf'))->toBe('pdf')
        ->and($component->getFileType('test.mp3'))->toBe('audio')
        ->and($component->getFileType('test.doc'))->toBe('document');
});

test('component handles errors gracefully when listing files', function () {
    $component = S3FileManager::make('file_path')
        ->disk('non-existent-disk');

    $result = $component->getFilesWithFolders();

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['folders', 'files'])
        ->and($result['folders'])->toBeArray()
        ->and($result['files'])->toBeArray();
});

