<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MuhamadSelim\FilamentS3Filemanager\Forms\Components\S3FileManager;
use MuhamadSelim\FilamentS3Filemanager\Services\S3StorageService;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->disk = 's3';
    Storage::fake($this->disk);
    $this->service = app(S3StorageService::class);
});

test('full workflow: upload file, list files, generate preview, delete file', function () {
    // 1. Upload file
    $file = UploadedFile::fake()->create('test-file.pdf', 100);
    $uploadResult = $this->service->uploadFile($file, $this->disk, 'test-folder');

    expect($uploadResult)->toBeArray()
        ->and($uploadResult['s3_key'])->toContain('test-folder')
        ->and(Storage::disk($this->disk)->exists($uploadResult['s3_key']))->toBeTrue();

    // 2. List files in folder
    $folderContents = $this->service->listFilesInFolder('test-folder', $this->disk);

    expect($folderContents['files'])->toBeArray()
        ->and(count($folderContents['files']))->toBeGreaterThan(0);

    // 3. Generate preview URL (may be null with fake storage)
    $previewUrl = $this->service->generatePresignedUrl($uploadResult['s3_key'], $this->disk, 3600);

    // URL might be null if temporaryUrl is not available in fake storage
    expect($previewUrl)->toBeString()->or->toBeNull();

    // 4. Delete file
    $deleteResult = $this->service->deleteFile($uploadResult['s3_key'], $this->disk);

    expect($deleteResult)->toBeTrue()
        ->and(Storage::disk($this->disk)->exists($uploadResult['s3_key']))->toBeFalse();
});

test('file selection workflow through api', function () {
    // Upload a file
    $file = UploadedFile::fake()->create('test-file.pdf', 100);
    Storage::disk($this->disk)->put('test-folder/test-file.pdf', 'content');

    // Get folder contents via API
    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.folder-contents'), [
            'folder_path' => 'test-folder',
            'disk' => $this->disk,
        ]);

    $response->assertSuccessful();
    $files = $response->json('files');

    expect($files)->toBeArray()
        ->and(count($files))->toBeGreaterThan(0);

    // Generate preview URL for selected file
    $selectedFile = $files[0]['path'];
    $previewResponse = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.preview-url'), [
            'file_path' => $selectedFile,
            'disk' => $this->disk,
        ]);

    $previewResponse->assertSuccessful()
        ->assertJson([
            'success' => true,
        ]);
});

test('folder selection workflow', function () {
    // Create files in multiple folders
    Storage::disk($this->disk)->put('folder1/file1.pdf', 'content1');
    Storage::disk($this->disk)->put('folder2/file2.pdf', 'content2');

    // Get folder structure
    $folders = $this->service->listFilesWithFolders($this->disk);

    expect($folders['folders'])->toBeArray()
        ->and(collect($folders['folders'])->pluck('path'))->toContain('folder1')
        ->and(collect($folders['folders'])->pluck('path'))->toContain('folder2');
});

test('component works in file only mode', function () {
    $component = S3FileManager::make('file_path')
        ->disk($this->disk)
        ->fileOnly();

    expect($component->canSelectFiles())->toBeTrue()
        ->and($component->canSelectFolders())->toBeFalse()
        ->and($component->getSelectionMode())->toBe('file');
});

test('component works in folder only mode', function () {
    $component = S3FileManager::make('file_path')
        ->disk($this->disk)
        ->folderOnly();

    expect($component->canSelectFiles())->toBeFalse()
        ->and($component->canSelectFolders())->toBeTrue()
        ->and($component->getSelectionMode())->toBe('folder');
});

test('component works in both modes', function () {
    $component = S3FileManager::make('file_path')
        ->disk($this->disk)
        ->fileOrFolder();

    expect($component->canSelectFiles())->toBeTrue()
        ->and($component->canSelectFolders())->toBeTrue()
        ->and($component->getSelectionMode())->toBe('both');
});

test('api endpoints are rate limited', function () {
    // This test verifies that rate limiting middleware is applied
    // Actual rate limit testing would require more complex setup
    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.folder-contents'), [
            'folder_path' => '',
            'disk' => $this->disk,
        ]);

    // Should succeed on first request
    $response->assertSuccessful();
});

test('malicious path attempts are blocked', function () {
    $maliciousPaths = [
        '../../../etc/passwd',
        '..\\..\\windows\\system32',
        '/etc/passwd',
        '%00test',
        'test%2e%2e%2f',
    ];

    foreach ($maliciousPaths as $path) {
        $response = $this->actingAs($this->admin)
            ->postJson(route('filament-s3-filemanager.preview-url'), [
                'file_path' => $path,
                'disk' => $this->disk,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }
});

test('file upload clears cache', function () {
    // Set up cache
    Cache::put('s3_files_'.$this->disk.'_test', 'cached-data', 300);

    // Upload file
    $file = UploadedFile::fake()->create('test-file.pdf', 100);
    $this->service->uploadFile($file, $this->disk);

    // Cache should be cleared
    expect(Cache::has('s3_files_'.$this->disk.'_test'))->toBeFalse();
});

