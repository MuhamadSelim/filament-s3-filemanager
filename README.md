# Filament S3 File Manager

A Filament v3 plugin for S3-compatible file management with file and folder selection support, built with Flysystem integration.

## Features

- 🗂️ **File and Folder Selection**: Select files, folders, or both
- 📁 **Folder Navigation**: Browse through S3 storage with breadcrumb navigation
- 🔍 **Search**: Search files across your S3 storage
- 👁️ **File Preview**: Preview images, videos, PDFs, and audio files
- 📤 **File Upload**: Upload files directly to S3 with progress tracking
- 🎨 **Grid & List Views**: Switch between grid and list view modes
- 🔒 **Secure**: Presigned URLs for secure file access
- ⚡ **Caching**: Built-in caching for improved performance
- 🎯 **Flysystem Integration**: Works with any S3-compatible storage (AWS S3, DigitalOcean Spaces, etc.)

## Installation

### Via Composer

```bash
composer require muhamad-selim/filament-s3-filemanager
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=filament-s3-filemanager-config
```

### Publish Views (Optional)

If you want to customize the views:

```bash
php artisan vendor:publish --tag=filament-s3-filemanager-views
```

## Configuration

### 1. Configure S3 Disk

Make sure you have an S3-compatible disk configured in `config/filesystems.php`:

```php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    ],
],
```

### 2. Environment Variables

Add these to your `.env` file:

```env
FILAMENT_S3_FILEMANAGER_DISK=s3
FILAMENT_S3_FILEMANAGER_SELECTION_MODE=file
FILAMENT_S3_FILEMANAGER_URL_EXPIRATION=3600
FILAMENT_S3_FILEMANAGER_MAX_SIZE=2048000
FILAMENT_S3_FILEMANAGER_CACHE_ENABLED=true
FILAMENT_S3_FILEMANAGER_CACHE_TTL=300
```

## Usage

### Basic Usage

```php
use MuhamadSelim\FilamentS3Filemanager\Forms\Components\S3FileManager;

S3FileManager::make('file_path')
    ->label('Select File')
    ->disk('s3')
```

### File Selection Only

```php
S3FileManager::make('file_path')
    ->label('Select File')
    ->disk('s3')
    ->fileOnly()
```

### Folder Selection Only

```php
S3FileManager::make('folder_path')
    ->label('Select Folder')
    ->disk('s3')
    ->folderOnly()
```

### Both File and Folder Selection

```php
S3FileManager::make('path')
    ->label('Select File or Folder')
    ->disk('s3')
    ->fileOrFolder()
```

### Dynamic Disk Selection

```php
S3FileManager::make('file_path')
    ->label('Select File')
    ->disk(fn (Get $get) => $get('storage_disk') ?? 's3')
```

### Complete Example

```php
use Filament\Forms\Form;
use MuhamadSelim\FilamentS3Filemanager\Forms\Components\S3FileManager;

public function form(Form $form): Form
{
    return $form
        ->schema([
            S3FileManager::make('file_path')
                ->label('Select File')
                ->disk('s3')
                ->fileOnly()
                ->required()
                ->afterStateUpdated(function ($state, $set) {
                    if ($state) {
                        // Handle file selection
                        $set('file_name', basename($state));
                    }
                }),
        ]);
}
```

## Selection Modes

The component supports three selection modes:

1. **File Only** (`fileOnly()`): Users can only select individual files
2. **Folder Only** (`folderOnly()`): Users can only select directories
3. **Both** (`fileOrFolder()`): Users can toggle between selecting files or folders

## API Routes

The package registers the following API routes:

- `POST /api/s3-files/folder-contents` - List files in a folder with pagination
- `POST /api/s3-files/preview-url` - Generate presigned URL for file preview
- `POST /api/s3-files/upload` - Upload file to S3

All routes are protected by authentication middleware and rate limiting.

## Configuration Options

### Default Disk

Set the default S3 disk to use:

```php
'default_disk' => 's3',
```

### Selection Mode

Set the default selection mode:

```php
'default_selection_mode' => 'file', // 'file', 'folder', or 'both'
```

### Presigned URL Expiration

Set how long presigned URLs remain valid (in seconds):

```php
'presigned_url_expiration' => 3600, // 1 hour
```

### Maximum File Size

Set the maximum file size for uploads (in KB):

```php
'max_file_size' => 2048000, // 2GB
```

### Allowed Extensions

Configure which file extensions are allowed:

```php
'allowed_extensions' => [
    'mp4', 'pdf', 'jpg', 'png', // etc.
],
```

## Requirements

- PHP 8.3+
- Laravel 11.0+
- Filament 3.2+
- League Flysystem 3.0+
- League Flysystem AWS S3 V3 3.0+

## License

MIT

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/muhamad-selim/filament-s3-filemanager).

