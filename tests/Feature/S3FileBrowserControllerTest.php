<?php

namespace MuhamadSelim\FilamentS3Filemanager\Tests\Feature;

use MuhamadSelim\FilamentS3Filemanager\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class S3FileBrowserControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Configure a test disk
        config(['filesystems.disks.test_s3' => [
            'driver' => 'local',
            'root' => storage_path('app/test-s3'),
        ]]);

        // Mock authentication - create a simple user object
        $user = new class {
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
        };
        Auth::shouldReceive('id')->andReturn(1);
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (Storage::disk('test_s3')->exists('')) {
            Storage::disk('test_s3')->deleteDirectory('');
        }

        parent::tearDown();
    }

    public function test_can_get_folder_contents(): void
    {
        Storage::disk('test_s3')->put('test-file.txt', 'test content');
        Storage::disk('test_s3')->makeDirectory('test-folder');

        $response = $this->postJson(route('filament-s3-filemanager.folder-contents'), [
            'folder_path' => '',
            'disk' => 'test_s3',
            'page' => 1,
            'per_page' => 50,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'files',
                'directories',
                'pagination',
            ]);
    }

    public function test_can_generate_preview_url(): void
    {
        Storage::disk('test_s3')->put('test-file.txt', 'test content');

        $response = $this->postJson(route('filament-s3-filemanager.preview-url'), [
            'file_path' => 'test-file.txt',
            'disk' => 'test_s3',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'url',
                'type',
            ]);
    }

    public function test_can_delete_file(): void
    {
        Storage::disk('test_s3')->put('test-file.txt', 'test content');

        $response = $this->deleteJson(route('filament-s3-filemanager.delete-file'), [
            'file_path' => 'test-file.txt',
            'disk' => 'test_s3',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertFalse(Storage::disk('test_s3')->exists('test-file.txt'));
    }

    public function test_can_create_folder(): void
    {
        $response = $this->postJson(route('filament-s3-filemanager.create-folder'), [
            'folder_path' => 'new-folder',
            'disk' => 'test_s3',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue(Storage::disk('test_s3')->exists('new-folder/.folder'));
    }

    public function test_validates_malicious_paths(): void
    {
        $response = $this->postJson(route('filament-s3-filemanager.folder-contents'), [
            'folder_path' => '../../../etc/passwd',
            'disk' => 'test_s3',
        ]);

        $response->assertStatus(400)
            ->assertJson(['success' => false]);
    }
}

