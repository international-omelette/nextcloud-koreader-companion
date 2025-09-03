/**
 * KOReader password management functionality
 * 
 * Simplified approach:
 * - OPDS uses Nextcloud credentials (no setup required)
 * - KOReader uses custom password stored in user preferences
 * - Clean separation between the two authentication systems
 */

$(document).ready(function() {
    checkKoreaderPassword();
    initEventListeners();
    initSearchFunctionality();
    initTableSorting();
});

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
        toggleBtn.innerHTML = '🙈';
        toggleBtn.title = t('koreader_companion', 'Hide password');
    } else {
        input.type = 'password';
        toggleBtn.innerHTML = '👁️';
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
function initSearchFunctionality() {
    const searchInput = document.getElementById('books-search');
    const searchResultsInfo = document.getElementById('search-results-info');
    const booksTable = document.querySelector('.ebooks-table tbody');
    
    if (!searchInput || !booksTable) {
        return;
    }
    
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            performSearch(this.value.trim().toLowerCase());
        }, 300);
    });
    
    function performSearch(searchTerm) {
        const bookRows = booksTable.querySelectorAll('.book-row');
        let visibleCount = 0;
        let totalCount = bookRows.length;
        
        bookRows.forEach(row => {
            if (searchTerm === '') {
                row.style.display = '';
                visibleCount++;
                return;
            }
            
            const titleElement = row.querySelector('.book-title');
            const authorElement = row.querySelector('.book-author');
            
            const title = titleElement ? titleElement.textContent.toLowerCase() : '';
            const author = authorElement ? authorElement.textContent.toLowerCase() : '';
            
            const matchesTitle = title.includes(searchTerm);
            const matchesAuthor = author.includes(searchTerm);
            
            if (matchesTitle || matchesAuthor) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        updateSearchResultsInfo(searchTerm, visibleCount, totalCount);
    }
    
    function updateSearchResultsInfo(searchTerm, visibleCount, totalCount) {
        if (searchTerm === '') {
            searchResultsInfo.style.display = 'none';
            return;
        }
        
        let message;
        if (visibleCount === 0) {
            message = t('koreader_companion', 'No books found matching "{searchTerm}"').replace('{searchTerm}', searchTerm);
        } else if (visibleCount === totalCount) {
            message = t('koreader_companion', 'Showing all {count} books').replace('{count}', totalCount);
        } else {
            message = t('koreader_companion', 'Showing {visible} of {total} books').replace('{visible}', visibleCount).replace('{total}', totalCount);
        }
        
        searchResultsInfo.textContent = message;
        searchResultsInfo.style.display = 'block';
    }
}

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