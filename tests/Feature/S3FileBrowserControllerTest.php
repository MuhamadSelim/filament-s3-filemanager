<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->disk = 's3';
    Storage::fake($this->disk);
});

test('admin can get folder contents', function () {
    Storage::disk($this->disk)->put('test-folder/file1.pdf', 'content1');
    Storage::disk($this->disk)->put('test-folder/file2.pdf', 'content2');

    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.folder-contents'), [
            'folder_path' => 'test-folder',
            'disk' => $this->disk,
            'page' => 1,
            'per_page' => 10,
        ]);

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'success',
            'files',
            'pagination' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
                'has_more',
            ],
        ]);

    expect($response->json('files'))->toBeArray()
        ->and($response->json('pagination.total'))->toBeGreaterThanOrEqual(0);
});

test('folder contents endpoint requires authentication', function () {
    $response = $this->postJson(route('filament-s3-filemanager.folder-contents'), [
        'folder_path' => 'test-folder',
        'disk' => $this->disk,
    ]);

    $response->assertUnauthorized();
});

test('folder contents endpoint validates disk parameter', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.folder-contents'), [
            'folder_path' => 'test-folder',
            'disk' => 'invalid-disk',
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error_type' => 'validation',
        ]);
});

test('folder contents endpoint rejects malicious paths', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.folder-contents'), [
            'folder_path' => '../../../etc/passwd',
            'disk' => $this->disk,
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error_type' => 'validation',
        ]);
});

test('admin can generate preview url', function () {
    Storage::disk($this->disk)->put('test-image.jpg', 'fake-image-content');

    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.preview-url'), [
            'file_path' => 'test-image.jpg',
            'disk' => $this->disk,
        ]);

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'type' => 'image',
        ])
        ->assertJsonStructure([
            'success',
            'url',
            'type',
            'metadata',
            'expires_in',
        ]);

    expect($response->json('url'))->toBeString()
        ->and($response->json('url'))->not->toBeEmpty();
});

test('preview url endpoint requires authentication', function () {
    $response = $this->postJson(route('filament-s3-filemanager.preview-url'), [
        'file_path' => 'test.jpg',
        'disk' => $this->disk,
    ]);

    $response->assertUnauthorized();
});

test('preview url endpoint validates file path', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.preview-url'), [
            'file_path' => '',
            'disk' => $this->disk,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['file_path']);
});

test('preview url endpoint rejects malicious file paths', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.preview-url'), [
            'file_path' => '../../../etc/passwd',
            'disk' => $this->disk,
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error_type' => 'validation',
        ]);
});

test('admin can upload file', function () {
    $file = UploadedFile::fake()->create('test-file.pdf', 100);

    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.upload'), [
            'file' => $file,
            'folder_path' => 'test-folder',
            'disk' => $this->disk,
        ]);

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'success',
            'file' => [
                'path',
                'name',
                'size',
                'type',
            ],
            'message',
        ]);

    expect($response->json('file.path'))->toBeString()
        ->and($response->json('file.path'))->toContain('test-folder');
});

test('upload endpoint requires authentication', function () {
    $file = UploadedFile::fake()->create('test-file.pdf', 100);

    $response = $this->postJson(route('filament-s3-filemanager.upload'), [
        'file' => $file,
        'disk' => $this->disk,
    ]);

    $response->assertUnauthorized();
});

test('upload endpoint validates file parameter', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.upload'), [
            'disk' => $this->disk,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('upload endpoint validates file type', function () {
    $file = UploadedFile::fake()->create('test-file.exe', 100);

    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.upload'), [
            'file' => $file,
            'disk' => $this->disk,
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error_type' => 'validation',
        ]);
});

test('upload endpoint validates file size', function () {
    // Create a file larger than the default max (2GB = 2048000 KB)
    $file = UploadedFile::fake()->create('test-file.pdf', 3000000 * 1024); // 3GB

    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.upload'), [
            'file' => $file,
            'disk' => $this->disk,
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error_type' => 'validation',
        ]);
});

test('upload endpoint validates disk parameter', function () {
    $file = UploadedFile::fake()->create('test-file.pdf', 100);

    $response = $this->actingAs($this->admin)
        ->postJson(route('filament-s3-filemanager.upload'), [
            'file' => $file,
            'disk' => 'invalid-disk',
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'error_type' => 'validation',
        ]);
});
