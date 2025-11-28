<?php

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use MuhamadSelim\FilamentS3Filemanager\FilamentS3FilemanagerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up fake storage
        $this->app['config']->set('filesystems.disks.s3', [
            'driver' => 'local',
            'root' => storage_path('app/testing'),
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            FilamentS3FilemanagerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up test environment
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
