/**
 * Upload and Metadata Management for eBooks App
 */
(function() {
    'use strict';

    let pendingFiles = [];
    let currentFileIndex = 0;

    // DOM elements
    const dropZone = document.getElementById('upload-drop-zone');
    const fileInput = document.getElementById('file-input');
    const metadataModal = document.getElementById('metadata-modal');
    const uploadProgressModal = document.getElementById('upload-progress-modal');
    const metadataForm = document.getElementById('metadata-form');
    const bookFormatSelect = document.getElementById('book-format');
    const comicFields = document.getElementById('comic-fields');

    // Initialize when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        initializeUpload();
        initializeModal();
        initializeEditButtons();
    });

    function initializeUpload() {
        if (!dropZone || !fileInput) return;

        // File input change handler
        fileInput.addEventListener('change', handleFileSelect);

        // Choose files button
        const chooseFilesBtn = document.getElementById('choose-files-btn');
        if (chooseFilesBtn) {
            chooseFilesBtn.addEventListener('click', () => fileInput.click());
        }

        // Drag and drop handlers
        dropZone.addEventListener('click', (e) => {
            // Only trigger file input if clicking on empty area, not the button
            if (e.target === dropZone || e.target.closest('.upload-icon, h3, p')) {
                fileInput.click();
            }
        });
        dropZone.addEventListener('dragover', handleDragOver);
        dropZone.addEventListener('dragleave', handleDragLeave);
        dropZone.addEventListener('drop', handleDrop);

        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });
    }

    function initializeModal() {
        if (!metadataModal) return;

        // Format selection handler - show/hide comic fields
        if (bookFormatSelect) {
            bookFormatSelect.addEventListener('change', toggleComicFields);
        }

        // Modal close handlers
        const closeModal = document.getElementById('close-modal');
        const cancelButton = document.getElementById('cancel-metadata');
        const closeUploadModal = document.getElementById('close-upload-modal');
        
        if (closeModal) closeModal.addEventListener('click', hideMetadataModal);
        if (cancelButton) cancelButton.addEventListener('click', hideMetadataModal);
        if (closeUploadModal) closeUploadModal.addEventListener('click', hideUploadProgressModal);
        
        // Save button handler
        const saveButton = document.getElementById('save-metadata');
        if (saveButton) saveButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            saveMetadata();
        });

        // Delete button handler
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

    function initializeEditButtons() {
        // Add click handlers to all edit book buttons
        const editButtons = document.querySelectorAll('.edit-book-btn');
        editButtons.forEach(button => {
            button.addEventListener('click', handleEditBook);
        });
    }

    function handleEditBook(e) {
        e.preventDefault();
        
        const button = e.target;
        const bookData = {
            path: button.dataset.path,
            title: button.dataset.title,
            author: button.dataset.author,
            format: button.dataset.format,
            series: button.dataset.series || '',
            issue: button.dataset.issue || '',
            volume: button.dataset.volume || '',
            language: button.dataset.language || 'en',
            publisher: button.dataset.publisher || '',
            publication_date: button.dataset.publicationDate || ''
        };

        showEditMetadataModal(bookData);
    }

    function showEditMetadataModal(bookData) {
        // Populate form with existing book data
        populateFormForEdit(bookData);
        
        // Change the save button text and behavior
        const saveButton = document.getElementById('save-metadata');
        if (saveButton) {
            saveButton.textContent = 'Update Metadata';
            // Remove existing click handlers and add update handler
            saveButton.onclick = null;
            saveButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                updateExistingMetadata(bookData.path);
            });
        }
        
        // Show the modal
        metadataModal.style.display = 'flex';
        
        // SPECIFIC FIX: Ensure publication_date field is populated after modal is shown
        // This handles any timing issues with DOM readiness
        setTimeout(() => {
            const dateField = document.getElementById('book-date');
            if (dateField && bookData.publication_date) {
                let year = bookData.publication_date;
                // Extract year from date if needed  
                const match = year.match(/(\d{4})/);
                if (match) {
                    year = match[1];
                }
                dateField.value = year;
            }
        }, 100);
        
        // Focus on title field
        const titleField = document.getElementById('book-title');
        if (titleField) titleField.focus();
    }

    function populateFormForEdit(bookData) {
        // Store file path for update
        document.getElementById('file-path').value = bookData.path;
        
        // Populate all fields
        Object.keys(bookData).forEach(key => {
            if (key === 'path') return; // Skip path
            
            // Special handling for publication_date field - map to book-date input
            if (key === 'publication_date') {
                const dateElement = document.getElementById('book-date');
                if (dateElement) {
                    let value = bookData[key] || '';
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
                    element.value = bookData[key] || '';
                }
            }
        });

        // Show/hide comic fields based on format
        toggleComicFields();
    }

    function updateExistingMetadata(filePath) {
        const formData = new FormData(metadataForm);
        
        // Create update data as URL-encoded string
        const updateParams = new URLSearchParams();
        
        // Add metadata
        for (let [key, value] of formData.entries()) {
            if (key !== 'file_path') { // Don't include file_path in update data
                updateParams.append(key, value);
            }
        }
        
        const updateData = updateParams.toString();

        // Update via our app's API endpoint
        const xhr = new XMLHttpRequest();
        
        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        showNotification('Metadata updated successfully!', 'success');
                        
                        // Close modal and refresh page
                        setTimeout(() => {
                            hideMetadataModal();
                            window.location.reload();
                        }, 2000);
                    } else {
                        showNotification(`Failed to update metadata: ${response.error}`, 'error');
                    }
                } catch (e) {
                    showNotification('Error parsing response', 'error');
                }
            } else {
                showNotification(`Failed to update metadata (${xhr.status})`, 'error');
            }
        });

        xhr.addEventListener('error', () => {
            showNotification('Network error updating metadata', 'error');
        });

        // Get the file ID from path - safely encode the path for the API
        const fileId = btoa(unescape(encodeURIComponent(filePath)));
        
        // Get the app's base URL using Nextcloud's OC.generateUrl
        const appUrl = OC.generateUrl('/apps/koreader_companion/books/{id}/metadata', {id: fileId});
        
        // Update via our custom endpoint
        xhr.open('PUT', appUrl);
        
        // Set content type for URL-encoded data
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        // Add CSRF token if available
        if (window.OC && window.OC.requestToken) {
            xhr.setRequestHeader('requesttoken', window.OC.requestToken);
        }
        
        // Send the update data
        xhr.send(updateData);
    }

    function confirmDeleteBook() {
        const filePath = document.getElementById('file-path').value;
        if (!filePath) {
            showNotification('No file selected for deletion', 'error');
            return;
        }

        const title = document.getElementById('book-title').value || 'this book';
        
        if (confirm(`Are you sure you want to delete "${title}"? This action cannot be undone.`)) {
            deleteBook(filePath);
        }
    }

    function deleteBook(filePath) {
        // Get the file ID from path - safely encode the path for the API
        const fileId = btoa(unescape(encodeURIComponent(filePath)));
        
        // Get the app's base URL using Nextcloud's OC.generateUrl
        const appUrl = OC.generateUrl('/apps/koreader_companion/books/{id}', {id: fileId});
        
        const xhr = new XMLHttpRequest();
        
        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        showNotification('Book deleted successfully!', 'success');
                        
                        // Close modal and refresh page
                        setTimeout(() => {
                            hideMetadataModal();
                            window.location.reload();
                        }, 1500);
                    } else {
                        showNotification(`Failed to delete book: ${response.error}`, 'error');
                    }
                } catch (e) {
                    showNotification('Error parsing response', 'error');
                }
            } else {
                showNotification(`Failed to delete book (${xhr.status})`, 'error');
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
        
        // Show loading indicator
        const titleField = document.getElementById('book-title');
        if (titleField) {
            titleField.value = 'Extracting metadata...';
            titleField.disabled = true;
        }
        
        // Extract metadata from file content
        if (window.MetadataExtractor) {
            window.MetadataExtractor.extractMetadataFromFile(file).then(metadata => {
                // Populate form with extracted metadata
                populateForm(metadata, file);
                
                // Enable fields and focus on title field
                if (titleField) {
                    titleField.disabled = false;
                    titleField.focus();
                }
            }).catch(error => {
                console.error('Failed to extract metadata from file:', error);
                
                // Fallback to filename parsing
                const metadata = extractMetadataFromFilename(file);
                populateForm(metadata, file);
                
                if (titleField) {
                    titleField.disabled = false;
                    titleField.focus();
                }
                
                showNotification('Could not extract metadata from file, using filename parsing', 'warning');
            });
        } else {
            // Fallback to filename parsing if MetadataExtractor not loaded
            const metadata = extractMetadataFromFilename(file);
            populateForm(metadata, file);
            
            if (titleField) {
                titleField.disabled = false;
                titleField.focus();
            }
        }
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
        
        console.log('Extracting metadata from filename:', filename);
        
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

        console.log('Extracted metadata:', metadata);
        return metadata;
    }

    function populateForm(metadata, file) {
        // Store file reference
        document.getElementById('file-path').value = file.name;
        
        console.log('Populating form with metadata:', metadata);
        
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
        console.log('Upload form data being sent:');
        for (let [key, value] of formData.entries()) {
            console.log(`  ${key}: ${value}`);
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
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        updateUploadStatus(filename, 'completed');
                        
                        // Move to next file after a short delay
                        setTimeout(() => {
                            hideMetadataModal();
                            currentFileIndex++;
                            processNextFile();
                        }, 1000);
                    } else {
                        updateUploadStatus(filename, 'error');
                        showNotification(`Failed to upload ${filename}: ${response.error}`, 'error');
                    }
                } catch (e) {
                    updateUploadStatus(filename, 'error');
                    showNotification(`Error parsing response for ${filename}`, 'error');
                }
            } else {
                updateUploadStatus(filename, 'error');
                showNotification(`Failed to upload ${filename} (${xhr.status})`, 'error');
            }
        });

        xhr.addEventListener('error', () => {
            updateUploadStatus(filename, 'error');
            showNotification(`Network error uploading ${filename}`, 'error');
        });

        // Get the app's base URL
        const appUrl = window.location.pathname.replace(/\/[^\/]*$/, '');
        
        // Upload via our custom endpoint
        xhr.open('POST', `${appUrl}/upload`);
        
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
            <div class="upload-file-icon">📚</div>
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