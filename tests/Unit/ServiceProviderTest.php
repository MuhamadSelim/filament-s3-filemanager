<?php

use Illuminate\Support\Facades\Config;
use MuhamadSelim\FilamentS3Filemanager\FilamentS3FilemanagerServiceProvider;

test('service provider can be instantiated', function () {
    $provider = new FilamentS3FilemanagerServiceProvider(app());
    expect($provider)->toBeInstanceOf(FilamentS3FilemanagerServiceProvider::class);
});

test('service provider registers configuration', function () {
    $config = config('filament-s3-filemanager');
    expect($config)->toBeArray()
        ->and($config)->toHaveKey('default_disk')
        ->and($config)->toHaveKey('presigned_url_expiration')
        ->and($config)->toHaveKey('max_file_sizes')
        ->and($config)->toHaveKey('allowed_extensions');
});

test('service provider publishes configuration', function () {
    $provider = new FilamentS3FilemanagerServiceProvider(app());
    $provider->boot();

    $published = $provider->publishes(
        FilamentS3FilemanagerServiceProvider::class,
        'filament-s3-filemanager-config'
    );

    expect($published)->toBeArray();
});

test('service provider loads views', function () {
    $view = view('filament-s3-filemanager::components.s3-file-manager');
    expect($view)->toBeInstanceOf(\Illuminate\Contracts\View\View::class);
});

test('service provider registers routes', function () {
    $routes = \Illuminate\Support\Facades\Route::getRoutes();
    $routeNames = collect($routes)->map(fn ($route) => $route->getName())->filter();

    expect($routeNames)->toContain('filament-s3-filemanager.folder-contents')
        ->and($routeNames)->toContain('filament-s3-filemanager.preview-url')
        ->and($routeNames)->toContain('filament-s3-filemanager.upload');
});

