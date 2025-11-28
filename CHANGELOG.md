# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2025-11-29

### Added
- Initial release
- File and folder selection support
- Folder navigation with breadcrumbs
- File search functionality
- File preview (images, videos, PDFs, audio)
- File upload with progress tracking
- Grid and list view modes
- Presigned URLs for secure file access
- Caching for improved performance
- Flysystem integration for S3-compatible storage
- Delete file and folder functionality
- Rename file and folder operations
- Move file and folder operations
- Copy file and folder operations
- Create folder functionality
- Directories now displayed in folder contents
- Action buttons for file operations (delete, rename, move, copy)
- Modals for rename, move, copy, and create folder operations
- Create Folder button in file browser toolbar
- Comprehensive error handling for all operations
- Cache clearing after file operations

### Fixed
- Fixed bug where `getFolderContents` was not passing folder path correctly
- Fixed folder contents endpoint to return both files and directories
- Improved folder path handling and sanitization

### Changed
- Updated folder contents response to include directories array
- Enhanced file browser UI with directory support
- Improved error messages and user feedback

