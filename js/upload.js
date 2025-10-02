/**
 * Upload and Metadata Management for KOReader Companion
 */
(function() {
    'use strict';

    let pendingFiles = [];
    let currentFileIndex = 0;

    const dropZone = document.getElementById('upload-drop-zone');
    const fileInput = document.getElementById('file-input');
    const metadataModal = document.getElementById('metadata-modal');
    const uploadProgressModal = document.getElementById('upload-progress-modal');
    const metadataForm = document.getElementById('metadata-form');
    const bookFormatSelect = document.getElementById('book-format');
    const comicFields = document.getElementById('comic-fields');

    document.addEventListener('DOMContentLoaded', function() {
        initializeUpload();
        initializeModal();
        initializeEditButtons();
    });

    function initializeUpload() {
        if (!dropZone || !fileInput) return;

        fileInput.addEventListener('change', handleFileSelect);

        const chooseFilesBtn = document.getElementById('choose-files-btn');
        if (chooseFilesBtn) {
            chooseFilesBtn.addEventListener('click', () => fileInput.click());
        }

        dropZone.addEventListener('click', (e) => {
            if (e.target === dropZone || e.target.closest('.upload-icon, h3, p')) {
                fileInput.click();
            }
        });
        dropZone.addEventListener('dragover', handleDragOver);
        dropZone.addEventListener('dragleave', handleDragLeave);
        dropZone.addEventListener('drop', handleDrop);

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });
    }

    function initializeModal() {
        if (!metadataModal) return;

        if (bookFormatSelect) {
            bookFormatSelect.addEventListener('change', toggleComicFields);
        }

        const closeModal = document.getElementById('close-modal');
        const cancelButton = document.getElementById('cancel-metadata');
        const closeUploadModal = document.getElementById('close-upload-modal');
        
        if (closeModal) closeModal.addEventListener('click', function() {
            if (window.hideEditMetadataModal && document.getElementById('delete-metadata').style.display !== 'none') {
                window.hideEditMetadataModal();
            } else {
                hideMetadataModal();
            }
        });
        if (cancelButton) cancelButton.addEventListener('click', function() {
            if (window.hideEditMetadataModal && document.getElementById('delete-metadata').style.display !== 'none') {
                window.hideEditMetadataModal();
            } else {
                hideMetadataModal();
            }
        });
        if (closeUploadModal) closeUploadModal.addEventListener('click', hideUploadProgressModal);
        
        const saveButton = document.getElementById('save-metadata');
        if (saveButton) saveButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            saveMetadata();
        });

        const deleteButton = document.getElementById('delete-metadata');
        if (deleteButton) deleteButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            confirmDeleteBook();
        });

        // Close modal when clicking outside
        metadataModal.addEventListener('click', (e) => {
            if (e.target === metadataModal) {
                hideMetadataModal();
            }
        });

        // Close upload progress modal when clicking outside
        if (uploadProgressModal) {
            uploadProgressModal.addEventListener('click', (e) => {
                if (e.target === uploadProgressModal) {
                    hideUploadProgressModal();
                }
            });
        }
    }

    function confirmDeleteBook() {
        const bookId = document.getElementById('file-path').value;
        if (!bookId) {
            showNotification('No book selected for deletion', 'error');
            return;
        }

        const title = document.getElementById('book-title').value || 'this book';

        if (confirm(`Are you sure you want to delete "${title}"? This action cannot be undone.`)) {
            deleteBook(bookId);
        }
    }

    function deleteBook(bookId) {
        // Use the file ID directly (no encoding needed)
        const appUrl = OC.generateUrl('/apps/koreader_companion/books/{id}', {id: bookId});
        
        const xhr = new XMLHttpRequest();
        
        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                showNotification('Book deleted successfully!', 'success');

                // Close modal and refresh page
                setTimeout(() => {
                    hideMetadataModal();
                    window.location.reload();
                }, 1500);
            } else {
                try {
                    const response = JSON.parse(xhr.responseText);
                    showNotification(`Failed to delete book: ${response.error}`, 'error');
                } catch (e) {
                    showNotification(`Failed to delete book (${xhr.status})`, 'error');
                }
            }
        });

        xhr.addEventListener('error', () => {
            showNotification('Network error deleting book', 'error');
        });

        xhr.open('DELETE', appUrl);
        
        // Add CSRF token if available
        if (window.OC && window.OC.requestToken) {
            xhr.setRequestHeader('requesttoken', window.OC.requestToken);
        }
        
        xhr.send();
    }

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    function handleDragOver(e) {
        dropZone.classList.add('dragover');
    }

    function handleDragLeave(e) {
        dropZone.classList.remove('dragover');
    }

    function handleDrop(e) {
        dropZone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        processFiles(files);
    }

    function handleFileSelect(e) {
        const files = e.target.files;
        processFiles(files);
    }

    function processFiles(files) {
        // Filter valid book files
        const validFiles = Array.from(files).filter(file => {
            const extension = file.name.toLowerCase().split('.').pop();
            return ['epub', 'pdf', 'cbr', 'mobi'].includes(extension);
        });

        if (validFiles.length === 0) {
            showNotification('No valid book files selected. Please choose EPUB, PDF, CBR, or MOBI files.', 'error');
            return;
        }

        // Store files for processing
        pendingFiles = validFiles;
        currentFileIndex = 0;

        // Start processing the first file
        processNextFile();
    }

    function processNextFile() {
        if (currentFileIndex >= pendingFiles.length) {
            // All files processed - no notification here, individual files already show success
            pendingFiles = [];
            currentFileIndex = 0;
            
            // Refresh the page to show new books
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            return;
        }

        const file = pendingFiles[currentFileIndex];
        showMetadataModal(file);
    }

    function showMetadataModal(file) {
        // Show the modal first with loading state
        metadataModal.style.display = 'flex';

        // Hide delete button for new entries (only show for editing existing books)
        const deleteButton = document.getElementById('delete-metadata');
        if (deleteButton) {
            deleteButton.style.display = 'none';
        }

        // Show loading indicator
        const titleField = document.getElementById('book-title');
        if (titleField) {
            titleField.value = 'Extracting metadata...';
            titleField.disabled = true;
        }

        // Extract metadata using server-side endpoint
        extractMetadataFromServer(file).then(metadata => {
            // Populate form with extracted metadata
            populateForm(metadata, file);

            // Enable fields and focus on title field
            if (titleField) {
                titleField.disabled = false;
                titleField.focus();
            }
        }).catch(error => {
            // Fallback to filename parsing
            const metadata = extractMetadataFromFilename(file);
            populateForm(metadata, file);

            if (titleField) {
                titleField.disabled = false;
                titleField.focus();
            }

            showNotification('Could not extract metadata from file, using filename parsing', 'warning');
        });
    }

    function extractMetadataFromServer(file) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('file', file);

            const xhr = new XMLHttpRequest();

            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.metadata) {
                            resolve(response.metadata);
                        } else {
                            reject(new Error('Failed to extract metadata: No metadata in response'));
                        }
                    } catch (e) {
                        reject(new Error('Error parsing metadata response'));
                    }
                } else {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        reject(new Error('Failed to extract metadata: ' + (response.error || `Server error: ${xhr.status}`)));
                    } catch (e) {
                        reject(new Error(`Server error: ${xhr.status}`));
                    }
                }
            });

            xhr.addEventListener('error', () => {
                reject(new Error('Network error extracting metadata'));
            });

            // Use the extract metadata endpoint
            const appUrl = OC.generateUrl('/apps/koreader_companion/extract-metadata');
            xhr.open('POST', appUrl);

            // Add CSRF token if available
            if (window.OC && window.OC.requestToken) {
                xhr.setRequestHeader('requesttoken', window.OC.requestToken);
            }

            xhr.send(formData);
        });
    }

    function hideMetadataModal() {
        metadataModal.style.display = 'none';
        
        // Reset save button text and handler for new uploads
        const saveButton = document.getElementById('save-metadata');
        if (saveButton) {
            saveButton.textContent = 'Save & Add to Library';
            saveButton.onclick = null;
            // Re-add the upload handler
            saveButton.addEventListener('click', saveMetadata);
        }
        
        // Skip current file if cancelled
        if (pendingFiles.length > 0) {
            currentFileIndex++;
            processNextFile();
        }
    }

    function hideUploadProgressModal() {
        uploadProgressModal.style.display = 'none';
        
        // Clear the progress list
        const progressList = document.getElementById('upload-progress-list');
        if (progressList) {
            progressList.innerHTML = '';
        }
    }

    function extractMetadataFromFilename(file) {
        const filename = file.name.replace(/\.[^/.]+$/, ""); // Remove extension
        const extension = file.name.toLowerCase().split('.').pop();
        
        
        let metadata = {
            title: filename,
            author: '',
            format: extension,
            language: 'en',
            publisher: '',
            date: '',
            series: '',
            issue: '',
            volume: ''
        };

        // Try to extract more info from filename patterns
        // Pattern: "Title - Author (Year)"
        let match = filename.match(/^(.+?)\s*-\s*(.+?)\s*\((\d{4})\)/);
        if (match) {
            metadata.title = match[1].trim();
            metadata.author = match[2].trim();
            metadata.date = match[3];
        }
        // Pattern: "Title - Author"
        else if (filename.includes(' - ')) {
            const parts = filename.split(' - ');
            if (parts.length >= 2) {
                metadata.title = parts[0].trim();
                metadata.author = parts.slice(1).join(' - ').trim();
            }
        }
        
        // Additional year extraction patterns - try to find any 4-digit year in parentheses
        if (!metadata.date) {
            const yearMatch = filename.match(/\((\d{4})\)/);
            if (yearMatch) {
                metadata.date = yearMatch[1];
            }
        }
        
        // Last resort: look for any standalone 4-digit number that could be a year
        if (!metadata.date) {
            const standAloneYear = filename.match(/\b(19\d{2}|20\d{2})\b/);
            if (standAloneYear) {
                metadata.date = standAloneYear[1];
            }
        }

        // Comic book patterns for CBR files
        if (extension === 'cbr') {
            // Pattern: "Series Name #001 (Year)"
            match = filename.match(/^(.+?)\s*#?(\d+).*?\((\d{4})\)/);
            if (match) {
                metadata.series = match[1].trim();
                metadata.issue = match[2];
                metadata.title = `${metadata.series} #${metadata.issue}`;
                metadata.date = match[3];
            }
            // Pattern: "Series Name 001"
            else {
                match = filename.match(/^(.+?)\s+(\d+)/);
                if (match) {
                    metadata.series = match[1].trim();
                    metadata.issue = match[2];
                    metadata.title = `${metadata.series} #${metadata.issue}`;
                }
            }
        }

        return metadata;
    }

    function populateForm(metadata, file) {
        // Store file reference
        document.getElementById('file-path').value = file.name;
        
        
        // Populate all fields
        Object.keys(metadata).forEach(key => {
            // Special handling for date field - map to publication_date form field
            if (key === 'date') {
                const dateElement = document.getElementById('book-date');
                if (dateElement) {
                    let value = metadata[key] || '';
                    // Extract year from various date formats
                    if (value) {
                        const yearMatch = value.match(/(\d{4})/);
                        if (yearMatch) {
                            value = yearMatch[1];
                        }
                    }
                    dateElement.value = value;
                }
            } else {
                const element = document.getElementById(`book-${key}`) || document.getElementById(`comic-${key}`);
                if (element) {
                    element.value = metadata[key] || '';
                }
            }
        });

        // Show/hide comic fields based on format
        toggleComicFields();
    }

    function toggleComicFields() {
        if (!bookFormatSelect || !comicFields) return;
        
        const format = bookFormatSelect.value;
        comicFields.style.display = format === 'cbr' ? 'block' : 'none';
    }

    function saveMetadata() {
        const formData = new FormData(metadataForm);
        const file = pendingFiles[currentFileIndex];
        
        if (!file) return;

        // Create upload data
        const uploadData = new FormData();
        uploadData.append('file', file);
        
        // Add metadata
        for (let [key, value] of formData.entries()) {
            uploadData.append(key, value);
        }

        // Upload file with metadata
        uploadFileWithMetadata(uploadData, file.name);
    }

    function uploadFileWithMetadata(formData, filename) {
        // Show progress modal
        showUploadProgress(filename);

        // Upload via our app's API endpoint
        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;
                updateUploadProgress(filename, percentComplete);
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status === 200 || xhr.status === 201) {
                updateUploadStatus(filename, 'completed');

                // Move to next file after a short delay
                setTimeout(() => {
                    hideMetadataModal();
                    currentFileIndex++;
                    processNextFile();
                }, 1000);
            } else {
                updateUploadStatus(filename, 'error');
                try {
                    const response = JSON.parse(xhr.responseText);
                    showNotification(`Failed to upload ${filename}: ${response.error}`, 'error');
                } catch (e) {
                    showNotification(`Failed to upload ${filename} (${xhr.status})`, 'error');
                }
            }
        });

        xhr.addEventListener('error', () => {
            updateUploadStatus(filename, 'error');
            showNotification(`Network error uploading ${filename}`, 'error');
        });

        // Use the upload endpoint
        const appUrl = OC.generateUrl('/apps/koreader_companion/upload');
        xhr.open('POST', appUrl);
        
        // Add CSRF token if available
        if (window.OC && window.OC.requestToken) {
            xhr.setRequestHeader('requesttoken', window.OC.requestToken);
        }
        
        // Send the form data with file and metadata
        xhr.send(formData);
    }

    function showUploadProgress(filename) {
        const progressList = document.getElementById('upload-progress-list');
        if (!progressList) return;

        const progressItem = document.createElement('div');
        progressItem.className = 'upload-progress-item';
        progressItem.id = `progress-${encodeURIComponent(filename)}`;
        
        progressItem.innerHTML = `
            <div class="upload-file-icon">
                <span class="icon icon-file"></span>
            </div>
            <div class="upload-file-info">
                <div class="upload-file-name">${filename}</div>
                <div class="upload-file-status">Uploading...</div>
                <div class="upload-progress-bar">
                    <div class="upload-progress-fill" style="width: 0%"></div>
                </div>
            </div>
        `;
        
        progressList.appendChild(progressItem);
        uploadProgressModal.style.display = 'flex';
    }

    function updateUploadProgress(filename, percentage) {
        const progressItem = document.getElementById(`progress-${encodeURIComponent(filename)}`);
        if (!progressItem) return;

        const progressFill = progressItem.querySelector('.upload-progress-fill');
        const statusText = progressItem.querySelector('.upload-file-status');
        
        if (progressFill) progressFill.style.width = `${percentage}%`;
        if (statusText) statusText.textContent = `Uploading... ${Math.round(percentage)}%`;
    }

    function updateUploadStatus(filename, status) {
        const progressItem = document.getElementById(`progress-${encodeURIComponent(filename)}`);
        if (!progressItem) return;

        const statusText = progressItem.querySelector('.upload-file-status');
        const progressFill = progressItem.querySelector('.upload-progress-fill');
        
        if (status === 'completed') {
            if (statusText) statusText.textContent = 'Upload complete!';
            if (progressFill) progressFill.style.width = '100%';
        } else if (status === 'error') {
            if (statusText) statusText.textContent = 'Upload failed';
            if (progressFill) progressFill.style.backgroundColor = 'var(--color-error)';
        }
    }

    function showNotification(message, type) {
        // Use Nextcloud's notification system if available
        if (window.OC && window.OC.Notification) {
            if (type === 'error') {
                OC.Notification.showTemporary(message);
            } else if (type === 'warning') {
                OC.Notification.showTemporary(message);
            } else {
                OC.Notification.showTemporary(message);
            }
        } else {
            // Fallback to alert
            alert(message);
        }
    }

})();