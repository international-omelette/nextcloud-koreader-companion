// Unicode-safe base64 encoding for KOReader compatibility
function safeEncode(input, context = 'unknown') {
    try {
        if (!input) {
            return '';
        }

        return btoa(unescape(encodeURIComponent(input)));
    } catch (error) {
        // Return fallback - use timestamp as unique identifier
        return `error_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }
}

// Make safeEncode globally available
window.safeEncode = safeEncode;


$(document).ready(function() {
    initSidePaneNavigation();
    initUploadModal();
    checkKoreaderPassword();
    initEventListeners();
    initTableSorting();
    initInfiniteScroll();
    initResponsiveHandling();
    initHamburgerMenu();
    initSettings();
});

// Handle responsive layout transitions smoothly
function initResponsiveHandling() {
    let resizeTimeout;
    
    // Handle window resize with debouncing
    $(window).on('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            // Add a class during resize to prevent transition artifacts
            $('body').addClass('resizing');
            
            setTimeout(function() {
                $('body').removeClass('resizing');
            }, 300);
        }, 100);
    });
}

// Navigation management for side pane
function initSidePaneNavigation() {
    $('.nav-entry').on('click', function(e) {
        e.preventDefault();
        const section = $(this).data('section');
        switchToSection(section);
    });
}

function switchToSection(sectionName) {
    // Remove active class from all sections and nav items
    $('.content-section').removeClass('active');
    $('.nav-entry').removeClass('active');
    
    // Use a small delay to ensure smooth transitions
    setTimeout(() => {
        // Show target section with smooth transition
        const targetSection = $(`#${sectionName}-section`);
        const targetNav = $(`[data-section="${sectionName}"]`);
        
        if (targetSection.length) {
            targetSection.addClass('active');
        }
        
        if (targetNav.length) {
            targetNav.addClass('active');
        }
        
        // Update URL hash for bookmarking
        window.location.hash = sectionName;
    }, 50);
}

// Upload modal management
function initUploadModal() {
    // Open upload modal when clicking the primary action button
    $('#new-book-btn').on('click', function() {
        $('#upload-modal').show();
    });
    
    // Close upload modal
    $('#close-upload-modal').on('click', function() {
        $('#upload-modal').hide();
    });
    
    // Close modal when clicking outside
    $('#upload-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // ESC key closes modal
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('.koreader-modal:visible').hide();
        }
    });
    
    // Initialize from URL hash if present
    const hash = window.location.hash.substring(1);
    if (hash && ['books', 'sync', 'opds'].includes(hash)) {
        switchToSection(hash);
    }
}

function initEventListeners() {
    // KOReader password management
    $('#set-koreader-password-btn').on('click', setKoreaderPassword);
    $('#koreader-password-create').on('input', validateSetButton);
    
    // Password reset functionality
    $('#show-koreader-password-reset-btn').on('click', showKoreaderPasswordResetForm);
    $('#reset-koreader-password-btn').on('click', resetKoreaderPassword);
    $('#cancel-koreader-password-reset-btn').on('click', hideKoreaderPasswordResetForm);
    $('#new-koreader-password').on('input', validateResetButton);
    
    // Password visibility toggles
    $('.password-toggle').on('click', function() {
        const target = $(this).data('target');
        togglePasswordVisibility(target);
    });
    
    // Copy functionality
    $('.copy-btn').on('click', function() {
        const copyTarget = $(this).data('copy-target');
        const copyText = $(this).data('copy-text');
        
        if (copyTarget) {
            copyToClipboardFromId(copyTarget);
        } else if (copyText) {
            copyToClipboard(copyText);
        }
    });
    
    // Click-to-select for easier copying
    $('.selectable-input').on('click', function() {
        this.select();
    });
    
    // Edit metadata button handler (using event delegation for dynamically added buttons)
    $(document).on('click', '.edit-metadata-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const bookData = {
            bookId: $(this).data('book-id'),
            title: $(this).data('title'),
            author: $(this).data('author'),
            format: $(this).data('format'),
            language: $(this).data('language'),
            publisher: $(this).data('publisher'),
            publicationDate: $(this).data('publication-date'),
            description: $(this).data('description'),
            tags: $(this).data('tags'),
            series: $(this).data('series'),
            issue: $(this).data('issue'),
            volume: $(this).data('volume')
        };
        
        showEditMetadataModal(bookData);
    });
    
    // Initial button validation
    validateSetButton();
    validateResetButton();
}

function checkKoreaderPassword() {
    const baseUrl = OC.generateUrl('/apps/koreader_companion');
    
    $.ajax({
        url: baseUrl + '/settings/koreader-password',
        type: 'GET',
        headers: {
            'requesttoken': OC.requestToken
        },
        success: function(response) {
            if (response.has_password) {
                // For security, we show a placeholder instead of the actual password
                showKoreaderPassword('••••••••');
            } else {
                showNoKoreaderPassword();
            }
        },
        error: function() {
            showNoKoreaderPassword();
        }
    });
}

function validateSetButton() {
    const password = $('#koreader-password-create').val().trim();
    const setBtn = $('#set-koreader-password-btn');
    
    if (password.length > 0) {
        setBtn.prop('disabled', false).removeClass('disabled');
    } else {
        setBtn.prop('disabled', true).addClass('disabled');
    }
}

function validateResetButton() {
    const password = $('#new-koreader-password').val().trim();
    const resetBtn = $('#reset-koreader-password-btn');
    
    if (password.length > 0) {
        resetBtn.prop('disabled', false).removeClass('disabled');
    } else {
        resetBtn.prop('disabled', true).addClass('disabled');
    }
}

function showNoKoreaderPassword() {
    document.getElementById('no-koreader-password').style.display = 'block';
    document.getElementById('has-koreader-password').style.display = 'none';
    setTimeout(validateSetButton, 0);
}

function showKoreaderPassword(password) {
    document.getElementById('no-koreader-password').style.display = 'none';
    document.getElementById('has-koreader-password').style.display = 'block';
    
    document.getElementById('koreader-password-display').value = password;
    window.currentKoreaderPassword = password;
    
    // If showing placeholder, hide copy button and toggle button
    const isPlaceholder = password === '••••••••';
    const copyBtn = document.querySelector('[data-copy-target="koreader-password-display"]');
    const toggleBtn = document.querySelector('[data-target="koreader-password-display"]');
    
    if (isPlaceholder) {
        // Hide buttons for placeholder
        if (copyBtn) {
            copyBtn.style.display = 'none';
        }
        
        if (toggleBtn) {
            toggleBtn.style.display = 'none';
        }
    } else {
        // Show buttons for actual password
        if (copyBtn) {
            copyBtn.style.display = 'inline-block';
            copyBtn.title = 'Copy to clipboard';
        }
        
        if (toggleBtn) {
            toggleBtn.style.display = 'inline-block';
            toggleBtn.title = 'Show/Hide password';
        }
    }
}

function setKoreaderPassword() {
    const password = document.getElementById('koreader-password-create').value.trim();
    
    if (!password) {
        OC.Notification.showTemporary(t('koreader_companion', 'Please enter a password'));
        return;
    }
    
    const baseUrl = OC.generateUrl('/apps/koreader_companion');
    
    $.ajax({
        url: baseUrl + '/settings/koreader-password',
        type: 'PUT',
        headers: {
            'requesttoken': OC.requestToken
        },
        data: {
            password: password
        },
        success: function(response) {
            if (response.success) {
                OC.Notification.showTemporary(t('koreader_companion', 'KOReader sync password set successfully'));
                
                // Store password for display
                window.currentKoreaderPassword = password;
                
                // Clear form
                document.getElementById('koreader-password-create').value = '';
                
                // Show password section
                showKoreaderPassword(password);
            } else {
                OC.Notification.showTemporary(response.error || t('koreader_companion', 'Failed to set sync password'));
            }
        },
        error: function(xhr) {
            const response = xhr.responseJSON;
            OC.Notification.showTemporary(response?.error || t('koreader_companion', 'Failed to set sync password'));
        }
    });
}

function showKoreaderPasswordResetForm() {
    document.getElementById('koreader-password-reset-form').style.display = 'block';
    document.getElementById('new-koreader-password').focus();
    setTimeout(validateResetButton, 0);
}

function hideKoreaderPasswordResetForm() {
    document.getElementById('koreader-password-reset-form').style.display = 'none';
    document.getElementById('new-koreader-password').value = '';
}

function resetKoreaderPassword() {
    const newPassword = document.getElementById('new-koreader-password').value.trim();
    
    if (!newPassword) {
        OC.Notification.showTemporary(t('koreader_companion', 'Please enter a new password'));
        return;
    }
    
    const baseUrl = OC.generateUrl('/apps/koreader_companion');
    
    $.ajax({
        url: baseUrl + '/settings/koreader-password',
        type: 'PUT',
        headers: {
            'requesttoken': OC.requestToken
        },
        data: {
            password: newPassword
        },
        success: function(response) {
            if (response.success) {
                OC.Notification.showTemporary(t('koreader_companion', 'KOReader sync password updated successfully'));
                
                // Update stored password
                window.currentKoreaderPassword = newPassword;
                document.getElementById('koreader-password-display').value = newPassword;
                
                // Hide form
                hideKoreaderPasswordResetForm();
            } else {
                OC.Notification.showTemporary(response.error || t('koreader_companion', 'Failed to update password'));
            }
        },
        error: function(xhr) {
            const response = xhr.responseJSON;
            OC.Notification.showTemporary(response?.error || t('koreader_companion', 'Failed to update password'));
        }
    });
}

function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const toggleBtn = input.nextElementSibling;
    
    if (input.type === 'password') {
        input.type = 'text';
        toggleBtn.innerHTML = '<span class="icon icon-toggle"></span>';
        toggleBtn.title = t('koreader_companion', 'Hide password');
    } else {
        input.type = 'password';
        toggleBtn.innerHTML = '<span class="icon icon-toggle"></span>';
        toggleBtn.title = t('koreader_companion', 'Show password');
    }
}

function copyToClipboardFromId(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        const text = element.value || element.textContent || element.innerText;
        copyToClipboard(text);
    }
}

function copyToClipboard(text) {
    // Use modern Clipboard API when available
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            OC.Notification.showTemporary(t('koreader_companion', 'Copied to clipboard'));
        }).catch(function() {
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    try {
        const tempInput = document.createElement('textarea');
        tempInput.value = text;
        tempInput.style.position = 'fixed';
        tempInput.style.opacity = '0';
        tempInput.style.left = '-9999px';
        document.body.appendChild(tempInput);
        tempInput.select();
        tempInput.setSelectionRange(0, 99999);
        
        const successful = document.execCommand('copy');
        document.body.removeChild(tempInput);
        
        if (successful) {
            OC.Notification.showTemporary(t('koreader_companion', 'Copied to clipboard'));
        } else {
            OC.Notification.showTemporary(t('koreader_companion', 'Failed to copy to clipboard'));
        }
    } catch (err) {
        OC.Notification.showTemporary(t('koreader_companion', 'Failed to copy to clipboard'));
    }
}

/**
 * Initialize search functionality for the books table
 */
// Search functionality is now handled by initInfiniteScroll()

/**
 * Initialize table sorting functionality for all columns
 */
function initTableSorting() {
    const table = document.querySelector('.ebooks-table');
    if (!table) return;
    
    const headers = table.querySelectorAll('thead th');
    let currentSortColumn = null;
    let currentSortDirection = 'asc';
    
    // Define which columns are sortable and their data extraction methods
    const sortableColumns = {
        'col-title': {
            extract: (row) => row.querySelector('.book-title')?.textContent?.trim() || '',
            type: 'text'
        },
        'col-author': {
            extract: (row) => row.querySelector('.book-author')?.textContent?.trim() || '',
            type: 'text'
        },
        'col-year': {
            extract: (row) => {
                const yearText = row.querySelector('.book-year')?.textContent?.trim() || '';
                return yearText === '-' ? 0 : parseInt(yearText) || 0;
            },
            type: 'number'
        },
        'col-format': {
            extract: (row) => row.querySelector('.format-badge')?.textContent?.trim() || '',
            type: 'text'
        },
        'col-size': {
            extract: (row) => {
                const sizeText = row.querySelector('.book-size')?.textContent?.trim() || '';
                return parseSizeToBytes(sizeText);
            },
            type: 'number'
        },
        'col-progress': {
            extract: (row) => {
                const progressText = row.querySelector('.progress-percentage')?.textContent?.trim();
                if (!progressText) return -1; // No progress comes first
                return parseInt(progressText.replace('%', '')) || 0;
            },
            type: 'number'
        }
    };
    
    // Add click listeners to sortable headers
    headers.forEach(header => {
        const classes = Array.from(header.classList);
        const sortableClass = classes.find(cls => sortableColumns[cls]);
        
        if (sortableClass) {
            header.style.cursor = 'pointer';
            header.classList.add('sortable');
            
            header.addEventListener('click', () => {
                sortTable(sortableClass, header);
            });
        }
    });
    
    function sortTable(columnClass, headerElement) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        const rows = Array.from(tbody.querySelectorAll('.book-row'));
        const sortConfig = sortableColumns[columnClass];
        
        // Determine sort direction
        if (currentSortColumn === columnClass) {
            currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            currentSortDirection = 'asc';
            currentSortColumn = columnClass;
        }
        
        // Update header indicators
        updateSortIndicators(headerElement);
        
        // Sort rows
        rows.sort((a, b) => {
            const valueA = sortConfig.extract(a);
            const valueB = sortConfig.extract(b);
            
            let comparison = 0;
            
            if (sortConfig.type === 'number') {
                comparison = valueA - valueB;
            } else {
                // Text comparison - case insensitive
                const textA = String(valueA).toLowerCase();
                const textB = String(valueB).toLowerCase();
                comparison = textA.localeCompare(textB);
            }
            
            return currentSortDirection === 'desc' ? -comparison : comparison;
        });
        
        // Re-append sorted rows
        rows.forEach(row => tbody.appendChild(row));
    }
    
    function updateSortIndicators(activeHeader) {
        // Remove indicators from all headers
        headers.forEach(header => {
            header.classList.remove('sort-asc', 'sort-desc');
        });
        
        // Add indicator to active header
        activeHeader.classList.add(currentSortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
    }
    
    function parseSizeToBytes(sizeText) {
        if (!sizeText || sizeText === '-') return 0;
        
        const match = sizeText.match(/^([\d.]+)\s*([KMGT]?B?)$/i);
        if (!match) return 0;
        
        const value = parseFloat(match[1]);
        const unit = match[2].toUpperCase();
        
        const multipliers = {
            'B': 1,
            'KB': 1024,
            'MB': 1024 * 1024,
            'GB': 1024 * 1024 * 1024,
            'TB': 1024 * 1024 * 1024 * 1024
        };
        
        return value * (multipliers[unit] || 1);
    }
}

// Infinite Scroll Implementation
function initInfiniteScroll() {
    const tbody = $('.ebooks-table tbody');
    if (tbody.length === 0) {
        return;
    }
    
    let loading = false;
    let currentPage = 2; // Start from page 2 since page 1 is already loaded by template
    let searchQuery = '';
    let hasMore = true;
    const perPage = 50; // Configurable page size - default 50 books per page
    
    // Remove any existing load more button
    $('.load-more-container').remove();
    
    // Add sentinel element after the table
    const sentinel = $('<div id="scroll-sentinel" style="height: 1px; width: 100%;"></div>');
    $('.ebooks-table-wrapper').after(sentinel);
    
    // Create intersection observer for infinite scroll
    const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && !loading && hasMore) {
            loadMoreBooks();
        }
    }, { rootMargin: '50px' });
    
    observer.observe(sentinel[0]);
    
    // Set up search input with server-side search
    $('#books-search').off('input').on('input', debounce((e) => {
        searchQuery = e.target.value.trim();
        resetAndSearch();
    }, 300));
    
    function resetAndSearch() {
        try {
            currentPage = 1;
            hasMore = true;
            $('.ebooks-table tbody').empty();
            loadMoreBooks();
        } catch (error) {
            OC.Notification.showTemporary('Search reset failed: ' + error.message);
        }
    }
    
    async function loadMoreBooks() {
        if (loading) {
            return;
        }
        loading = true;
        
        try {
            const url = OC.generateUrl('/apps/koreader_companion/') + 
                       `?page=${currentPage}&q=${encodeURIComponent(searchQuery)}`;
            
            const response = await fetch(url, {
                headers: { 
                    'Accept': 'application/json', 
                    'X-Requested-With': 'XMLHttpRequest',
                    'requesttoken': OC.requestToken
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const books = await response.json();
            
            if (!Array.isArray(books)) {
                throw new Error('Invalid response format');
            }
            
            if (books.length === 0) {
                hasMore = false;
                return;
            }
            
            books.forEach((book, index) => {
                try {
                    const bookRow = createBookRow(book);
                    $('.ebooks-table tbody').append(bookRow);
                } catch (error) {
                    OC.Notification.showTemporary(`Failed to display book: ${book.title || 'Unknown'}`);
                }
            });
            
            currentPage++;
            // If we got fewer books than requested, we've reached the end
            hasMore = books.length >= perPage;
            
        } catch (error) {
            hasMore = false;
        } finally {
            loading = false;
        }
    }
    
    // Don't do initial load - page template already has first page books
    // Only start infinite scroll after initial books are present
}

// Simple debounce utility
function debounce(func, delay) {
    let timeoutId;
    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
}
    
function createBookRow(book) {
        // Format year from publication_date
        let yearDisplay = '-';
        if (book.publication_date) {
            const yearMatch = book.publication_date.match(/^(\d{4})-/);
            if (yearMatch) {
                yearDisplay = yearMatch[1];
            } else {
                yearDisplay = book.publication_date;
            }
        }
        
        // Format progress percentage
        let progressHtml = '<span class="sync-status-none">-</span>';
        if (book.progress && book.progress.percentage !== undefined) {
            const percentage = Number(book.progress.percentage).toFixed(1);
            const device = book.progress.device || 'Unknown';
            const progressTooltip = `${percentage}% complete on ${device}`;
            
            progressHtml = `
                <div class="progress-container" title="${progressTooltip}">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${percentage}%"></div>
                    </div>
                    <span class="progress-text">${percentage}%</span>
                </div>`;
        }
        
        // Format last sync date
        let lastSyncHtml = '<span class="sync-status-none">-</span>';
        if (book.progress && book.progress.updated_at) {
            const syncDate = new Date(book.progress.updated_at + 'Z'); // Assume UTC
            const formattedDate = syncDate.toLocaleDateString();
            const formattedTime = syncDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            lastSyncHtml = `<span class="sync-date" title="${formattedDate} ${formattedTime}">${formattedDate}</span>`;
        }
        
        // Determine book icon based on format
        let bookIcon = ''; // default
        if (book.format === 'cbr') bookIcon = '';
        else if (book.format === 'pdf') bookIcon = '';
        else if (book.format === 'mobi') bookIcon = '';
        
        // Format file size
        const fileSize = formatFileSize(book.size || 0);
        
        // Create book ID (base64 encoded path) - Unicode safe encoding
        const bookId = safeEncode(book.path, 'book.path');
        
        // Build comic info if applicable
        let comicInfo = '';
        if (book.format === 'cbr' && (book.series || book.issue)) {
            comicInfo = '<div class="comic-info">';
            if (book.series) {
                comicInfo += `<span class="comic-series-small">${escapeHtml(book.series)}</span>`;
            }
            if (book.issue) {
                comicInfo += `<span class="comic-issue-small">#${escapeHtml(book.issue)}</span>`;
            }
            comicInfo += '</div>';
        }
        
        const row = $(`
            <tr class="book-row" data-format="${book.format}">
                <td class="book-icon-cell">
                    <span class="book-icon-table">${bookIcon}</span>
                </td>
                <td class="book-title-cell">
                    <div class="book-title-wrapper">
                        <span class="book-title" title="${escapeHtml(book.title || '')}">${escapeHtml(book.title || '')}</span>
                        ${comicInfo}
                    </div>
                </td>
                <td class="book-author-cell">
                    <span class="book-author">${escapeHtml(book.author || '-')}</span>
                </td>
                <td class="book-year-cell">
                    <span class="book-year">${yearDisplay}</span>
                </td>
                <td class="book-language-cell">
                    <span class="book-language">${escapeHtml(book.language || '-')}</span>
                </td>
                <td class="book-publisher-cell">
                    <span class="book-publisher">${escapeHtml(book.publisher || '-')}</span>
                </td>
                <td class="book-format-cell">
                    <span class="format-badge format-${book.format}">${book.format.toUpperCase()}</span>
                </td>
                <td class="book-size-cell">
                    <span class="book-size">${fileSize}</span>
                </td>
                <td class="book-progress-cell">
                    ${progressHtml}
                </td>
                <td class="book-last-sync-cell">
                    ${lastSyncHtml}
                </td>
                <td class="book-actions-cell">
                    <button class="action-btn edit-metadata-btn"
                            data-book-id="${bookId}"
                            data-title="${escapeHtml(book.title || '')}"
                            data-author="${escapeHtml(book.author || '')}"
                            data-format="${escapeHtml(book.format || '')}"
                            data-language="${escapeHtml(book.language || '')}"
                            data-publisher="${escapeHtml(book.publisher || '')}"
                            data-publication-date="${escapeHtml(book.publication_date || '')}"
                            data-description="${escapeHtml(book.description || '')}"
                            data-tags="${escapeHtml(book.tags || '')}"
                            data-series="${escapeHtml(book.series || '')}"
                            data-issue="${escapeHtml(book.issue || '')}"
                            data-volume="${escapeHtml(book.volume || '')}"
                            title="Edit metadata">
                            <span class="icon icon-edit"></span>
                        </button>
                </td>
            </tr>
        `);
        
        return row;
    }
    
function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    
function escapeHtml(text) {
        try {
            if (!text || typeof text !== 'string') {
                return '';
            }

            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        } catch (error) {
            return String(text || '').replace(/[&<>"']/g, function(match) {
                const escapeMap = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                };
                return escapeMap[match];
            });
        }
    }

// Edit metadata modal functionality
function showEditMetadataModal(bookData) {
    const metadataModal = document.getElementById('metadata-modal');
    const deleteButton = document.getElementById('delete-metadata');
    const saveButton = document.getElementById('save-metadata');
    
    if (!metadataModal) {
        return;
    }
    
    // Show the modal
    metadataModal.style.display = 'flex';
    
    // Show delete button for editing existing books
    if (deleteButton) {
        deleteButton.style.display = 'inline-block';
    }
    
    // Change save button text for editing
    if (saveButton) {
        saveButton.textContent = 'Save Changes';
    }
    
    // Populate form with existing book data
    populateEditForm(bookData);
}

function populateEditForm(bookData) {
    // Populate form fields with existing book data
    const fields = {
        'file-path': bookData.bookId,
        'book-title': bookData.title || '',
        'book-author': bookData.author || '',
        'book-date': bookData.publicationDate || '',
        'book-language': bookData.language || '',
        'book-publisher': bookData.publisher || '',
        'book-format': bookData.format || '',
        'comic-series': bookData.series || '',
        'comic-issue': bookData.issue || '',
        'comic-volume': bookData.volume || ''
    };
    
    // Populate each field
    Object.entries(fields).forEach(([fieldId, value]) => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.value = value;
        }
    });
    
    // Show/hide comic fields based on format
    const comicFields = document.getElementById('comic-fields');
    if (comicFields) {
        const isComic = bookData.format && bookData.format.toLowerCase() === 'cbr';
        comicFields.style.display = isComic ? 'block' : 'none';
    }
    
    // Focus on title field
    const titleField = document.getElementById('book-title');
    if (titleField) {
        titleField.focus();
        titleField.select();
    }
    
    // Update save button handler for editing
    updateSaveButtonForEdit(bookData);
}

function updateSaveButtonForEdit(bookData) {
    const saveButton = document.getElementById('save-metadata');
    if (!saveButton) return;
    
    // Remove any existing event listeners
    const newSaveButton = saveButton.cloneNode(true);
    saveButton.parentNode.replaceChild(newSaveButton, saveButton);
    
    // Add new event listener for editing
    newSaveButton.addEventListener('click', function(e) {
        e.preventDefault();
        saveEditedMetadata(bookData.bookId);
    });
}

function saveEditedMetadata(bookId) {
    const formData = new URLSearchParams();
    
    // Collect form data
    const fields = [
        'book-title', 'book-author', 'book-date', 'book-language', 
        'book-publisher', 'comic-series', 'comic-issue', 'comic-volume'
    ];
    
    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field && field.value) {
            const paramName = fieldId.replace('book-', '').replace('comic-', '').replace('-', '_');
            if (paramName === 'date') {
                formData.append('publication_date', field.value);
            } else {
                formData.append(paramName, field.value);
            }
        }
    });
    
    // Show loading state
    const saveButton = document.getElementById('save-metadata');
    if (saveButton) {
        saveButton.textContent = 'Saving...';
        saveButton.disabled = true;
    }
    
    // Send update request
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', OC.generateUrl('/apps/koreader_companion/books/{id}/metadata', {id: bookId}), true);
    xhr.setRequestHeader('requesttoken', OC.requestToken);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onload = function() {
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = 'Save Changes';
        }
        
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    OC.Notification.showTemporary(t('koreader_companion', 'Book metadata updated successfully'));
                    hideEditMetadataModal();
                    // Refresh the books list
                    if (typeof loadBooks === 'function') {
                        loadBooks();
                    } else {
                        location.reload();
                    }
                } else {
                    OC.Notification.showTemporary(t('koreader_companion', 'Failed to update metadata: {error}', {error: response.error}));
                }
            } catch (e) {
                OC.Notification.showTemporary(t('koreader_companion', 'Failed to update metadata'));
            }
        } else {
            OC.Notification.showTemporary(t('koreader_companion', 'Failed to update metadata ({status})', {status: xhr.status}));
        }
    };
    
    xhr.onerror = function() {
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = 'Save Changes';
        }
        OC.Notification.showTemporary(t('koreader_companion', 'Network error updating metadata'));
    };
    
    xhr.send(formData);
}

function hideEditMetadataModal() {
    const metadataModal = document.getElementById('metadata-modal');
    const deleteButton = document.getElementById('delete-metadata');
    const saveButton = document.getElementById('save-metadata');
    
    if (metadataModal) {
        metadataModal.style.display = 'none';
    }
    
    // Reset buttons for next use
    if (deleteButton) {
        deleteButton.style.display = 'none';
    }
    
    if (saveButton) {
        saveButton.textContent = 'Save & Add to Library';
    }
}

// Make functions globally accessible
window.showEditMetadataModal = showEditMetadataModal;
window.hideEditMetadataModal = hideEditMetadataModal;

/**
 * Initialize hamburger menu functionality for mobile navigation
 * This creates a hamburger toggle button and manages navigation show/hide
 */
function initHamburgerMenu() {
    // Only initialize on mobile screens (matching CSS breakpoint)
    if (window.innerWidth > 1024) {
        return;
    }
    
    // Create hamburger menu button if it doesn't exist
    let hamburgerBtn = document.getElementById('app-navigation-toggle');
    if (!hamburgerBtn) {
        hamburgerBtn = createHamburgerButton();
    }
    
    // Add click handler
    hamburgerBtn.addEventListener('click', toggleNavigation);
    
    // Close navigation when clicking outside
    document.addEventListener('click', handleOutsideClick);
    
    // Close navigation when clicking on menu items
    const navEntries = document.querySelectorAll('.nav-entry a');
    navEntries.forEach(entry => {
        entry.addEventListener('click', closeNavigation);
    });
    
    // Handle window resize with improved debouncing
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            if (window.innerWidth > 1024) {
                // Desktop view - close navigation if open
                navigationOpen = false;
                document.body.classList.remove('navigation-open');
            }
        }, 150);
    });
}

function createHamburgerButton() {
    const hamburgerBtn = document.createElement('button');
    hamburgerBtn.id = 'app-navigation-toggle';
    hamburgerBtn.className = 'hamburger-menu-btn';
    hamburgerBtn.innerHTML = '<span class="icon icon-menu"></span>';
    hamburgerBtn.setAttribute('aria-label', 'Toggle navigation menu');
    hamburgerBtn.style.cssText = `
        position: fixed;
        top: 10px;
        left: 10px;
        z-index: 2001;
        background: var(--color-main-background);
        border: 1px solid var(--color-border);
        border-radius: 6px;
        padding: 8px;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    `;
    
    // Insert at the beginning of the app content
    const appContent = document.getElementById('app-content');
    if (appContent && appContent.parentNode) {
        appContent.parentNode.insertBefore(hamburgerBtn, appContent);
    } else {
        document.body.appendChild(hamburgerBtn);
    }
    
    return hamburgerBtn;
}

// Navigation state management
let navigationOpen = false;

function toggleNavigation(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const body = document.body;
    const nav = document.getElementById('app-navigation');
    
    // Check current state to ensure sync
    const isCurrentlyOpen = body.classList.contains('navigation-open');
    navigationOpen = !isCurrentlyOpen;
    
    if (navigationOpen) {
        // Opening - force immediate start of transition
        nav.style.transition = 'none';
        nav.offsetHeight;
        nav.style.transition = 'transform 0.3s ease';
        body.classList.add('navigation-open');
    } else {
        // Closing - force immediate start of transition
        nav.style.transition = 'none';
        nav.offsetHeight;
        nav.style.transition = 'transform 0.3s ease';
        body.classList.remove('navigation-open');
    }
}

function closeNavigation() {
    const body = document.body;
    const nav = document.getElementById('app-navigation');
    
    if (body.classList.contains('navigation-open')) {
        // Force immediate start of close transition
        nav.style.transition = 'none';
        nav.offsetHeight;
        nav.style.transition = 'transform 0.3s ease';
        
        // Close navigation
        navigationOpen = false;
        body.classList.remove('navigation-open');
    }
}

function handleOutsideClick(e) {
    const nav = document.getElementById('app-navigation');
    const hamburgerBtn = document.getElementById('app-navigation-toggle');
    const body = document.body;
    
    if (!nav || !hamburgerBtn) return;
    
    // Only handle if navigation is actually open
    if (!body.classList.contains('navigation-open')) return;
    
    // Check if click was inside navigation or on hamburger button
    const isClickInsideNav = nav.contains(e.target);
    const isClickOnHamburger = hamburgerBtn.contains(e.target);
    
    if (!isClickInsideNav && !isClickOnHamburger) {
        // Close navigation and sync state
        navigationOpen = false;
        body.classList.remove('navigation-open');
    }
}

// Settings functionality
function initSettings() {
    // Load settings when settings section becomes active
    $(document).on('click', '[data-section="settings"]', function() {
        loadSettings();
    });

    // Save folder button (individual save)
    $('#save-folder-btn').on('click', function() {
        saveFolderSetting();
    });

    // Cancel folder change button
    $('#cancel-folder-btn').on('click', function() {
        cancelFolderChange();
    });

    // Browse folder button
    $('#browse-folder-btn').on('click', function() {
        openFolderPicker();
    });

    // Monitor folder input changes
    $('#ebooks-folder').on('change input', function() {
        checkFolderChange();
    });

    // Auto-rename confirmation handling
    $('#confirm-auto-rename-btn').on('click', function() {
        confirmAutoRename();
    });

    $('#cancel-auto-rename-btn').on('click', function() {
        cancelAutoRename();
    });

    // Monitor auto-rename checkbox changes
    $('#auto-rename').on('change', function() {
        checkAutoRenameChange();
    });
}

// Store original folder value for change detection
let originalFolderValue = '';
let originalAutoRenameValue = false;

function loadSettings() {
    $.get(OC.generateUrl('/apps/koreader_companion/settings'))
        .done(function(data) {
            const folder = data.folder || 'eBooks';
            $('#ebooks-folder').val(folder);
            originalFolderValue = folder; // Store original value

            const autoRename = data.auto_rename === 'yes';
            $('#auto-rename').prop('checked', autoRename);
            originalAutoRenameValue = autoRename; // Store original value
        })
        .fail(function() {
            // Settings failed to load - silent failure
        });
}


function saveFolderSetting() {
    const newFolder = $('#ebooks-folder').val() || 'eBooks';
    saveFolderDirect(newFolder);
}

function saveFolderDirect(folder) {
    const data = { folder: folder };

    $.ajax({ url: OC.generateUrl('/apps/koreader_companion/settings/folder'), method: 'PUT', data: data })
        .done(function(response) {
            originalFolderValue = folder; // Update original value
            $('#folder-change-confirmation').hide(); // Hide confirmation after save

            // If folder changed, suggest reloading the page
            if (response.folder_changed) {
                setTimeout(() => {
                    if (confirm('Library has been cleared. Would you like to reload the page to see the updated library?')) {
                        location.reload();
                    }
                }, 1000);
            }
        })
        .fail(function() {
            $('#ebooks-folder').val(originalFolderValue); // Revert on failure
            // Failed to save folder setting - silent failure
        });
}


function checkFolderChange() {
    const currentValue = $('#ebooks-folder').val() || 'eBooks';
    const isFolderChanging = (originalFolderValue !== currentValue);

    if (isFolderChanging) {
        $('#folder-change-confirmation').show();
    } else {
        $('#folder-change-confirmation').hide();
    }
}

function cancelFolderChange() {
    $('#ebooks-folder').val(originalFolderValue);
    $('#folder-change-confirmation').hide();
}

function openFolderPicker() {
    OC.dialogs.filepicker(
        t('koreader_companion', 'Select eBooks Folder'),
        function(path) {
            // Remove leading slash if present for consistency
            const cleanPath = path.startsWith('/') ? path.substring(1) : path;
            $('#ebooks-folder').val(cleanPath);
            checkFolderChange(); // Check if this triggers the confirmation
        },
        false,                          // multiselect
        'httpd/unix-directory',        // directories only
        true,                          // modal
        OC.dialogs.FILEPICKER_TYPE_CHOOSE
    );
}

// Auto-rename handling functions
function checkAutoRenameChange() {
    const currentValue = $('#auto-rename').is(':checked');

    // Only show confirmation when changing from off to on
    if (!originalAutoRenameValue && currentValue) {
        $('#auto-rename-confirmation').show();
    } else {
        $('#auto-rename-confirmation').hide();
        // If turning off, save immediately without confirmation
        if (originalAutoRenameValue && !currentValue) {
            saveAutoRenameSetting(false);
        }
    }
}

function confirmAutoRename() {
    // Show loading state
    $('#confirm-auto-rename-btn').prop('disabled', true).text('Processing...');

    // Enable auto-rename and trigger batch rename
    $.ajax({
        url: OC.generateUrl('/apps/koreader_companion/settings/batch-rename'),
        method: 'POST',
        data: { auto_rename: 'yes' }
    })
    .done(function(response) {
        originalAutoRenameValue = true;
        $('#auto-rename-confirmation').hide();

        // Show success message and suggest reload
        OC.Notification.showTemporary(
            `Successfully renamed ${response.renamed_count} books to standardized format.`
        );

        setTimeout(() => {
            if (confirm('Books have been renamed. Would you like to reload the page to see the updated filenames?')) {
                location.reload();
            }
        }, 1500);
    })
    .fail(function(xhr) {
        const error = xhr.responseJSON?.error || 'Failed to enable auto-rename';
        OC.Notification.showTemporary(error);

        // Revert checkbox state
        $('#auto-rename').prop('checked', originalAutoRenameValue);
        $('#auto-rename-confirmation').hide();
    })
    .always(function() {
        $('#confirm-auto-rename-btn').prop('disabled', false).text('Enable and rename all books');
    });
}

function cancelAutoRename() {
    // Revert checkbox to original state
    $('#auto-rename').prop('checked', originalAutoRenameValue);
    $('#auto-rename-confirmation').hide();
}

function saveAutoRenameSetting(enabled) {
    $.ajax({
        url: OC.generateUrl('/apps/koreader_companion/settings/auto-rename'),
        method: 'PUT',
        data: { auto_rename: enabled ? 'yes' : 'no' }
    })
    .done(function() {
        originalAutoRenameValue = enabled;
    })
    .fail(function() {
        // Revert on failure
        $('#auto-rename').prop('checked', originalAutoRenameValue);
    });
}