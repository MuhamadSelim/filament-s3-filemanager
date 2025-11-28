<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $folderData = $getFilesWithFolders();
        $folderTree = $getFolderTree();
        $record = $getRecord();
        $selectedValue = $getState() ?? null;
        $statePath = $getStatePath() ?? '';
        $componentId = $getId();
        $disk = $getDisk();
        $selectionMode = $getSelectionMode();
        $canSelectFiles = $canSelectFiles();
        $canSelectFolders = $canSelectFolders();

        // Sanitize componentId for use as JavaScript identifier
        $safeComponentId = preg_replace('/[^a-zA-Z0-9_]/', '_', $componentId);
    @endphp

    <div class="space-y-4"
         x-data="{
             // Navigation state
             currentFolder: '',
             folderTree: @js($folderTree),
             breadcrumbs: [],
             expandedFolders: {},
             loadedFolders: {},

             // File state
             filesInCurrentFolder: [],
             allFolders: @js($folderData['folders'] ?? []),
             selectedFile: null,
             selectedFolder: null,
             currentState: @js($selectedValue),
             selectionMode: @js($selectionMode),
             canSelectFiles: @js($canSelectFiles),
             canSelectFolders: @js($canSelectFolders),

             // Pagination state
             currentPage: 1,
             perPage: 50,
             totalFiles: 0,
             lastPage: 1,
             hasMore: false,
             loadingMore: false,

             // Search state
             searchQuery: '',
             searchResults: [],
             isSearching: false,
             searchDebounceTimer: null,

             // Preview state
             previewUrl: null,
             previewType: null,
             previewLoading: false,
             previewFile: null,
             imageZoom: 1,
             imageZoomMin: 0.5,
             imageZoomMax: 3,

             // View state
             viewMode: 'grid',

             // Upload state
             uploading: false,
             uploadProgress: 0,
             uploadError: null,
             uploadFile: null,

             // Component state
             statePath: @js($statePath),
             componentId: @js($componentId),
             disk: @js($disk),
             error: null,
             errorMessage: '',
             loading: false,

             // Computed
             get filteredFolders() {
                 if (!this.searchQuery) {
                     return this.allFolders.filter(f => {
                         if (!this.currentFolder) {
                             return !f.path.includes('/');
                         }
                         return f.path.startsWith(this.currentFolder + '/') &&
                                f.path.split('/').length === this.currentFolder.split('/').length + 1;
                     });
                 }
                 return [];
             },

             get displayFiles() {
                 if (this.isSearching && this.searchQuery) {
                     return this.searchResults;
                 }
                 return this.filesInCurrentFolder;
             },

             get hasSelection() {
                 return this.selectedFile !== null || this.selectedFolder !== null;
             },

             get selectionType() {
                 if (this.selectedFile) return 'file';
                 if (this.selectedFolder) return 'folder';
                 return null;
             },

             // Methods
             init() {
                 this.loadFolderContents('');
                 this.buildBreadcrumbs();
                 window['fileBrowser_{{ $safeComponentId }}'] = this;
             },

             navigateToFolder(path) {
                 this.currentFolder = path;
                 this.buildBreadcrumbs();
                 this.currentPage = 1;
                 this.filesInCurrentFolder = [];
                 this.loadFolderContents(path);
                 this.searchQuery = '';
                 this.isSearching = false;
             },

             navigateToBreadcrumb(index) {
                 if (index === -1) {
                     this.navigateToFolder('');
                 } else {
                     const path = this.breadcrumbs.slice(0, index + 1).map(b => b.name).join('/');
                     this.navigateToFolder(path);
                 }
             },

             buildBreadcrumbs() {
                 if (!this.currentFolder) {
                     this.breadcrumbs = [];
                     return;
                 }
                 const parts = this.currentFolder.split('/');
                 this.breadcrumbs = parts.map((name, index) => ({
                     name: name,
                     path: parts.slice(0, index + 1).join('/')
                 }));
             },

             async loadFolderContents(path, append = false) {
                 if (append) {
                     this.loadingMore = true;
                 } else {
                     this.loading = true;
                 }
                 this.error = null;
                 this.errorMessage = '';

                 try {
                     const response = await fetch(@js(route('filament-s3-filemanager.folder-contents')), {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': @js(csrf_token()),
                             'Accept': 'application/json',
                         },
                         credentials: 'same-origin',
                         body: JSON.stringify({
                             folder_path: path,
                             disk: this.disk,
                             page: this.currentPage,
                             per_page: this.perPage
                         }),
                     });

                     if (!response.ok) {
                         const data = await response.json().catch(() => ({}));
                         throw new Error(data.message || `Server error: ${response.status}`);
                     }

                     const data = await response.json();

                     if (data.success) {
                         if (append) {
                             this.filesInCurrentFolder = [...this.filesInCurrentFolder, ...data.files];
                         } else {
                             this.filesInCurrentFolder = data.files;
                         }

                         if (data.pagination) {
                             this.currentPage = data.pagination.current_page;
                             this.totalFiles = data.pagination.total;
                             this.lastPage = data.pagination.last_page;
                             this.hasMore = data.pagination.has_more;
                         }

                         this.loadedFolders[path] = true;
                         this.error = null;
                         this.errorMessage = '';
                     } else {
                         throw new Error(data.message || 'Failed to load folder contents');
                     }
                 } catch (error) {
                     console.error('Error loading folder contents:', error);
                     this.error = true;
                     if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                         this.errorMessage = 'Unable to connect to storage. Please check your internet connection and try again.';
                     } else if (error.message.includes('503') || error.message.includes('connection')) {
                         this.errorMessage = 'Storage service is temporarily unavailable. Please try again in a moment.';
                     } else if (error.message.includes('403') || error.message.includes('Unauthorized')) {
                         this.errorMessage = 'You do not have permission to access these files.';
                     } else {
                         this.errorMessage = error.message || 'Failed to load files. Please try again.';
                     }
                 } finally {
                     this.loading = false;
                     this.loadingMore = false;
                 }
             },

             retryLoadFolderContents() {
                 this.error = null;
                 this.errorMessage = '';
                 this.loadFolderContents(this.currentFolder);
             },

             async loadMoreFiles() {
                 if (this.hasMore && !this.loadingMore) {
                     this.currentPage++;
                     await this.loadFolderContents(this.currentFolder, true);
                 }
             },

             selectFile(path) {
                 if (this.canSelectFiles) {
                     this.selectedFile = path;
                     this.selectedFolder = null;
                 }
             },

             selectFolder(path) {
                 if (this.canSelectFolders) {
                     this.selectedFolder = path;
                     this.selectedFile = null;
                 }
             },

             confirmSelection() {
                 const selected = this.selectedFile || this.selectedFolder;
                 if (!selected) return;

                 this.currentState = selected;
                 this.updateWireState(selected);
                 $dispatch('close-modal', { id: this.componentId + '-file-browser' });
             },

             clearSelection() {
                 this.selectedFile = null;
                 this.selectedFolder = null;
                 this.currentState = null;
                 this.updateWireState(null);
             },

             updateWireState(value) {
                 this.updateWireStateForPath(this.statePath, value);
             },

             updateWireStateForPath(path, value) {
                 try {
                     if (typeof $wire !== 'undefined' && path) {
                         if ($wire.set) {
                             $wire.set(path, value);
                         } else if ($wire.$set) {
                             $wire.$set(path, value);
                         }
                     }
                 } catch (e) {
                     console.error('FileBrowser: Error updating wire state', { path, value, error: e });
                 }
             },

             searchFilesDebounced() {
                 if (this.searchDebounceTimer) {
                     clearTimeout(this.searchDebounceTimer);
                 }
                 this.searchDebounceTimer = setTimeout(() => {
                     this.searchFiles();
                 }, 300);
             },

             async searchFiles() {
                 if (!this.searchQuery || this.searchQuery.length < 2) {
                     this.isSearching = false;
                     this.searchResults = [];
                     return;
                 }

                 this.isSearching = true;
                 this.loading = true;
                 const query = this.searchQuery.toLowerCase();

                 try {
                     this.searchResults = this.filesInCurrentFolder.filter(file =>
                         file.path.toLowerCase().includes(query) ||
                         file.name.toLowerCase().includes(query)
                     );
                 } catch (error) {
                     console.error('Search error:', error);
                     this.searchResults = [];
                 } finally {
                     this.loading = false;
                 }
             },

             async openPreview(path) {
                 if (!path || typeof path !== 'string' || path.trim() === '') {
                     this.previewLoading = false;
                     return;
                 }

                 this.previewLoading = true;
                 this.previewUrl = null;
                 this.imageZoom = 1;

                 const file = this.displayFiles.find(f => f.path === path);
                 this.previewFile = file;

                 try {
                     const response = await fetch(@js(route('filament-s3-filemanager.preview-url')), {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': @js(csrf_token()),
                             'Accept': 'application/json',
                         },
                         credentials: 'same-origin',
                         body: JSON.stringify({
                             file_path: path,
                             disk: this.disk
                         }),
                     });

                     if (!response.ok) {
                         const data = await response.json().catch(() => ({}));
                         throw new Error(data.message || `Server error: ${response.status}`);
                     }

                     const data = await response.json();

                     if (data.success && data.url) {
                         this.previewUrl = data.url;
                         this.previewType = data.type;
                         $dispatch('open-modal', { id: this.componentId + '-preview-modal' });
                     } else {
                         throw new Error(data.message || 'Failed to generate preview URL');
                     }
                 } catch (error) {
                     console.error('Preview error:', error);
                     alert('Failed to generate preview URL. Please try again.');
                 } finally {
                     this.previewLoading = false;
                 }
             },

             zoomIn() {
                 if (this.imageZoom < this.imageZoomMax) {
                     this.imageZoom = Math.min(this.imageZoom + 0.25, this.imageZoomMax);
                 }
             },

             zoomOut() {
                 if (this.imageZoom > this.imageZoomMin) {
                     this.imageZoom = Math.max(this.imageZoom - 0.25, this.imageZoomMin);
                 }
             },

             resetZoom() {
                 this.imageZoom = 1;
             },

             selectAndClosePreview() {
                 if (this.previewFile) {
                     this.selectFile(this.previewFile.path);
                     this.confirmSelection();
                 }
                 $dispatch('close-modal', { id: this.componentId + '-preview-modal' });
             },

             toggleViewMode() {
                 this.viewMode = this.viewMode === 'grid' ? 'list' : 'grid';
             },

             formatFileSize(bytes) {
                 if (bytes === 0) return '0 Bytes';
                 const k = 1024;
                 const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                 const i = Math.floor(Math.log(bytes) / Math.log(k));
                 return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
             },

             getFileIcon(type) {
                 const icons = {
                     'image': 'heroicon-o-photo',
                     'video': 'heroicon-o-film',
                     'pdf': 'heroicon-o-document-text',
                     'audio': 'heroicon-o-musical-note',
                     'document': 'heroicon-o-document',
                     'presentation': 'heroicon-o-presentation-chart-bar',
                     'file': 'heroicon-o-document'
                 };
                 return icons[type] || icons['file'];
             },

             openUploadModal() {
                 this.uploadFile = null;
                 this.uploadError = null;
                 this.uploadProgress = 0;
                 $dispatch('open-modal', { id: this.componentId + '-upload-modal' });
             },

             handleFileSelect(event) {
                 const file = event.target.files[0];
                 if (file) {
                     this.uploadFile = file;
                     this.uploadError = null;
                 }
             },

             async uploadFileToStorage() {
                 if (!this.uploadFile) {
                     this.uploadError = 'Please select a file to upload';
                     return;
                 }

                 this.uploading = true;
                 this.uploadProgress = 0;
                 this.uploadError = null;

                 try {
                     const formData = new FormData();
                     formData.append('file', this.uploadFile);
                     formData.append('folder_path', this.currentFolder || '');
                     formData.append('disk', this.disk);

                     const xhr = new XMLHttpRequest();

                     xhr.upload.addEventListener('progress', (e) => {
                         if (e.lengthComputable) {
                             this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                         }
                     });

                     xhr.addEventListener('load', () => {
                         try {
                             if (xhr.status === 200) {
                                 const response = JSON.parse(xhr.responseText);
                                 if (response.success) {
                                     this.loadFolderContents(this.currentFolder);
                                     $dispatch('close-modal', { id: this.componentId + '-upload-modal' });
                                     this.uploadFile = null;
                                     this.uploadProgress = 0;
                                     alert('File uploaded successfully!');
                                 } else {
                                     this.uploadError = response.message || 'Upload failed';
                                 }
                             } else {
                                 let response = {};
                                 try {
                                     response = JSON.parse(xhr.responseText);
                                 } catch (e) {}
                                 this.uploadError = response.message || `Upload failed with status ${xhr.status}`;
                             }
                         } catch (error) {
                             console.error('Error parsing upload response:', error);
                             this.uploadError = 'An error occurred while processing the upload response';
                         } finally {
                             this.uploading = false;
                         }
                     });

                     xhr.addEventListener('error', () => {
                         this.uploadError = 'Network error occurred during upload';
                         this.uploading = false;
                     });

                     xhr.open('POST', @js(route('filament-s3-filemanager.upload')));
                     xhr.setRequestHeader('X-CSRF-TOKEN', @js(csrf_token()));
                     xhr.send(formData);
                 } catch (error) {
                     console.error('Upload error:', error);
                     this.uploadError = error.message || 'An unexpected error occurred';
                     this.uploading = false;
                 }
             }
         }"
         x-init="init()"
         data-file-browser-id="{{ $componentId }}">

        <!-- Selected File/Folder Display -->
        <div x-show="currentState" class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        <span x-show="selectionType === 'file'">Selected File</span>
                        <span x-show="selectionType === 'folder'">Selected Folder</span>
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-1" x-text="currentState"></p>
                </div>
                <button
                    type="button"
                    @click="clearSelection()"
                    class="ml-4 text-sm text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                >
                    Clear
                </button>
            </div>
        </div>

        <!-- Browse Button -->
        <div>
            <x-filament::button
                type="button"
                x-on:click="$dispatch('open-modal', { id: '{{ $getId() }}-file-browser' })"
                color="gray"
                outlined
            >
                <x-slot name="icon">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                    </svg>
                </x-slot>
                Browse Files
            </x-filament::button>
        </div>

        <!-- File Browser Modal -->
        <x-filament::modal
            id="{{ $getId() }}-file-browser"
            width="7xl"
            :close-by-clicking-away="false"
        >
            <x-slot name="heading">
                Browse S3 Files
            </x-slot>

            <x-slot name="description">
                <div class="flex items-center gap-2">
                    <span>Select a file or folder from your S3 storage</span>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="px-2 py-1 rounded" :class="canSelectFiles ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-gray-100 text-gray-500'">
                            Files
                        </span>
                        <span class="px-2 py-1 rounded" :class="canSelectFolders ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-gray-100 text-gray-500'">
                            Folders
                        </span>
                    </div>
                </div>
            </x-slot>

            <div class="space-y-4">
                <!-- Search Bar -->
                <div class="flex items-center gap-4">
                    <div class="flex-1 relative">
                        <input
                            type="text"
                            x-model="searchQuery"
                            @input="searchFilesDebounced()"
                            placeholder="Search files..."
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                        <div x-show="isSearching && loading" class="absolute right-3 top-1/2 -translate-y-1/2">
                            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-primary-600"></div>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="toggleViewMode()"
                        class="px-3 py-2 text-sm border rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
                    >
                        <span x-show="viewMode === 'grid'">List View</span>
                        <span x-show="viewMode === 'list'">Grid View</span>
                    </button>
                    <button
                        type="button"
                        @click="openUploadModal()"
                        class="px-4 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 flex items-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Upload File
                    </button>
                </div>

                <!-- Error Display -->
                <div x-show="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="ml-3 flex-1">
                            <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                                Error Loading Files
                            </h3>
                            <div class="mt-2 text-sm text-red-700 dark:text-red-300" x-text="errorMessage"></div>
                            <div class="mt-4 flex gap-3">
                                <button
                                    type="button"
                                    @click="retryLoadFolderContents()"
                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-red-600 hover:bg-red-700"
                                >
                                    Retry
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Breadcrumb Navigation -->
                <div x-show="!isSearching && breadcrumbs.length > 0" class="flex items-center gap-2 text-sm">
                    <button
                        @click="navigateToBreadcrumb(-1)"
                        class="text-primary-600 hover:text-primary-700 dark:text-primary-400"
                    >
                        Home
                    </button>
                    <template x-for="(crumb, index) in breadcrumbs" :key="index">
                        <div class="flex items-center gap-2">
                            <span class="text-gray-400">/</span>
                            <button
                                @click="navigateToBreadcrumb(index)"
                                class="text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                x-text="crumb.name"
                            ></button>
                        </div>
                    </template>
                </div>

                <!-- Loading State -->
                <div x-show="loading" class="flex items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div>
                </div>

                <!-- Main Content Area -->
                <div x-show="!loading" class="grid grid-cols-12 gap-4 min-h-[400px]">
                    <!-- Folder Navigation (Left Panel) -->
                    <div x-show="!isSearching" class="col-span-3 border-r border-gray-200 dark:border-gray-700 pr-4">
                        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-3">
                            Folders
                        </h3>
                        <div class="space-y-1">
                            <template x-for="folder in filteredFolders" :key="folder.path">
                                <button
                                    @click="canSelectFolders ? selectFolder(folder.path) : navigateToFolder(folder.path)"
                                    class="w-full flex items-center gap-2 px-3 py-2 text-sm rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-left"
                                    :class="{ 
                                        'bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400': canSelectFolders && selectedFolder === folder.path,
                                        'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400': !canSelectFolders && currentFolder === folder.path
                                    }"
                                >
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                    </svg>
                                    <span class="flex-1 truncate" x-text="folder.name"></span>
                                    <span class="text-xs text-gray-500" x-text="folder.file_count"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- File List (Right Panel) -->
                    <div :class="isSearching ? 'col-span-12' : 'col-span-9'">
                        <div x-show="displayFiles.length === 0 && !loading" class="text-center py-12 text-gray-500">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <p class="mt-2" x-text="isSearching ? 'No files found' : 'No files in this folder'"></p>
                        </div>

                        <!-- Grid View -->
                        <div x-show="viewMode === 'grid' && displayFiles.length > 0" class="space-y-4">
                            <div class="grid grid-cols-3 gap-4">
                                <template x-for="file in displayFiles" :key="file.path">
                                    <div
                                        @click="canSelectFiles ? selectFile(file.path) : null"
                                        class="border rounded-lg p-4 cursor-pointer hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition"
                                        :class="{ 
                                            'border-primary-500 bg-primary-50 dark:bg-primary-900/20': canSelectFiles && selectedFile === file.path,
                                            'cursor-default': !canSelectFiles
                                        }"
                                    >
                                        <div class="flex flex-col items-center text-center">
                                            <svg class="w-12 h-12 text-gray-400 mb-2" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                            </svg>
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate w-full" x-text="file.name"></p>
                                            <p class="text-xs text-gray-500 mt-1" x-text="formatFileSize(file.size)"></p>
                                            <div class="mt-2 flex gap-2">
                                                <button
                                                    @click.stop="openPreview(file.path)"
                                                    class="text-xs text-primary-600 hover:text-primary-700"
                                                >
                                                    Preview
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div x-show="!isSearching && hasMore" class="flex justify-center pt-4">
                                <button
                                    @click="loadMoreFiles()"
                                    :disabled="loadingMore"
                                    class="px-6 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
                                >
                                    <span x-show="!loadingMore">Load More Files</span>
                                    <span x-show="loadingMore" class="flex items-center gap-2">
                                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-primary-600"></div>
                                        Loading...
                                    </span>
                                </button>
                            </div>
                        </div>

                        <!-- List View -->
                        <div x-show="viewMode === 'list' && displayFiles.length > 0" class="space-y-2">
                            <template x-for="file in displayFiles" :key="file.path">
                                <div
                                    @click="canSelectFiles ? selectFile(file.path) : null"
                                    class="flex items-center gap-4 p-3 border rounded-lg transition"
                                    :class="{ 
                                        'border-primary-500 bg-primary-50 dark:bg-primary-900/20 cursor-pointer hover:border-primary-500': canSelectFiles && selectedFile === file.path,
                                        'cursor-default': !canSelectFiles
                                    }"
                                >
                                    <svg class="w-8 h-8 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                    </svg>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="file.name"></p>
                                        <p class="text-xs text-gray-500" x-text="file.path"></p>
                                    </div>
                                    <div class="text-sm text-gray-500" x-text="formatFileSize(file.size)"></div>
                                    <button
                                        @click.stop="openPreview(file.path)"
                                        class="text-sm text-primary-600 hover:text-primary-700"
                                    >
                                        Preview
                                    </button>
                                </div>
                            </template>

                            <div x-show="!isSearching && hasMore" class="flex justify-center pt-4">
                                <button
                                    @click="loadMoreFiles()"
                                    :disabled="loadingMore"
                                    class="px-6 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
                                >
                                    <span x-show="!loadingMore">Load More Files</span>
                                    <span x-show="loadingMore" class="flex items-center gap-2">
                                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-primary-600"></div>
                                        Loading...
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <x-slot name="footerActions">
                <x-filament::button
                    @click="confirmSelection()"
                    x-bind:disabled="!hasSelection"
                >
                    Select
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    @click="$dispatch('close-modal', { id: '{{ $getId() }}-file-browser' })"
                >
                    Cancel
                </x-filament::button>
            </x-slot>
        </x-filament::modal>

        <!-- Preview Modal -->
        <x-filament::modal
            id="{{ $getId() }}-preview-modal"
            width="7xl"
        >
            <x-slot name="heading">
                <div class="flex items-center justify-between">
                    <span>File Preview</span>
                    <div x-show="previewFile" class="text-sm font-normal text-gray-500 dark:text-gray-400">
                        <span x-text="previewFile?.name"></span>
                    </div>
                </div>
            </x-slot>

            <div class="space-y-4">
                <div x-show="previewLoading" class="flex flex-col items-center justify-center py-16">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600 mb-4"></div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Loading preview...</p>
                </div>

                <div x-show="!previewLoading && previewUrl" class="space-y-4">
                    <!-- Image Preview -->
                    <div x-show="previewType === 'image'" class="space-y-4">
                        <div class="flex items-center justify-center gap-2 pb-2 border-b border-gray-200 dark:border-gray-700">
                            <button @click="zoomOut()" type="button" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" :disabled="imageZoom <= imageZoomMin">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7" />
                                </svg>
                            </button>
                            <span class="text-sm font-medium min-w-[60px] text-center" x-text="Math.round(imageZoom * 100) + '%'"></span>
                            <button @click="zoomIn()" type="button" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" :disabled="imageZoom >= imageZoomMax">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                </svg>
                            </button>
                            <button @click="resetZoom()" type="button" class="px-3 py-2 text-sm rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                Reset
                            </button>
                        </div>
                        <div class="overflow-auto max-h-[600px] bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                            <div class="flex items-center justify-center min-h-[400px]">
                                <img :src="previewUrl || ''" :style="'transform: scale(' + imageZoom + '); transition: transform 0.2s;'" class="max-w-full h-auto rounded-lg shadow-lg" alt="Preview" />
                            </div>
                        </div>
                    </div>

                    <!-- Video Preview -->
                    <div x-show="previewType === 'video'" class="space-y-4">
                        <div class="bg-black rounded-lg overflow-hidden">
                            <video :src="previewUrl || ''" controls controlsList="nodownload" class="w-full max-h-[600px]" preload="metadata">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    </div>

                    <!-- PDF Preview -->
                    <div x-show="previewType === 'pdf'" class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                            <iframe :src="previewUrl ? previewUrl + '#toolbar=1&navpanes=1&scrollbar=1' : ''" class="w-full h-[700px]" frameborder="0">
                                <p>Your browser does not support PDFs. <a :href="previewUrl" target="_blank" class="text-primary-600">Download the PDF</a>.</p>
                            </iframe>
                        </div>
                    </div>

                    <!-- Audio Preview -->
                    <div x-show="previewType === 'audio'" class="space-y-4">
                        <div class="flex flex-col items-center justify-center py-12 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3" />
                            </svg>
                            <audio :src="previewUrl || ''" controls controlsList="nodownload" class="w-full max-w-md" preload="metadata">
                                Your browser does not support the audio tag.
                            </audio>
                        </div>
                    </div>
                </div>
            </div>

            <x-slot name="footerActions">
                <x-filament::button
                    @click="selectAndClosePreview()"
                    x-show="previewFile && canSelectFiles"
                >
                    Select This File
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    @click="$dispatch('close-modal', { id: '{{ $getId() }}-preview-modal' })"
                >
                    Close
                </x-filament::button>
            </x-slot>
        </x-filament::modal>

        <!-- Upload Modal -->
        <x-filament::modal
            id="{{ $getId() }}-upload-modal"
            width="2xl"
        >
            <x-slot name="heading">
                Upload File to S3
            </x-slot>

            <x-slot name="description">
                Upload a file to the current folder: <span x-text="currentFolder || '(root)'" class="font-medium"></span>
            </x-slot>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Select File
                    </label>
                    <input
                        type="file"
                        @change="handleFileSelect($event)"
                        class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-900 dark:file:text-primary-300"
                    />
                </div>

                <div x-show="uploadFile" class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="uploadFile?.name"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="formatFileSize(uploadFile?.size)"></p>
                        </div>
                    </div>
                </div>

                <div x-show="uploading" class="space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-400">Uploading...</span>
                        <span class="text-gray-600 dark:text-gray-400" x-text="uploadProgress + '%'"></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2 dark:bg-gray-700">
                        <div
                            class="bg-primary-600 h-2 rounded-full transition-all duration-300"
                            :style="'width: ' + uploadProgress + '%'"
                        ></div>
                    </div>
                </div>

                <div x-show="uploadError" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                    <p class="text-sm text-red-800 dark:text-red-200" x-text="uploadError"></p>
                </div>
            </div>

            <x-slot name="footerActions">
                <x-filament::button
                    @click="uploadFileToStorage()"
                    x-bind:disabled="!uploadFile || uploading"
                >
                    <span x-show="!uploading">Upload</span>
                    <span x-show="uploading" class="flex items-center gap-2">
                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                        Uploading...
                    </span>
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    @click="$dispatch('close-modal', { id: '{{ $getId() }}-upload-modal' })"
                    x-bind:disabled="uploading"
                >
                    Cancel
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-dynamic-component>

