<?php

namespace MuhamadSelim\FilamentS3Filemanager\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use MuhamadSelim\FilamentS3Filemanager\FilamentS3FilemanagerServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            FilamentS3FilemanagerServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}

