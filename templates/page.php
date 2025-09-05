<?php
style('koreader_companion', 'books');
script('koreader_companion', 'koreader');
script('koreader_companion', 'metadata-extractor');
script('koreader_companion', 'upload');

/** @var \OCP\IL10N $l */
/** @var array $_ */

?>
<div id="app">
    <div class="ebooks-header">
        <h1><?php p($l->t('KOReader Library')); ?></h1>
        <p class="ebooks-subtitle"><?php p($l->t('Your personal ebook collection with sync support')); ?></p>
        
        <!-- File Upload Area -->
        <div class="ebooks-upload-area">
            <div class="upload-drop-zone" id="upload-drop-zone">
                <div class="upload-icon">📚</div>
                <h3><?php p($l->t('Upload Books')); ?></h3>
                <p><?php p($l->t('Drag and drop EPUB, PDF, CBR, or MOBI files here, or click to browse')); ?></p>
                <input type="file" id="file-input" accept=".epub,.pdf,.cbr,.mobi" multiple style="display: none;">
                <button class="btn primary" id="choose-files-btn">
                    <?php p($l->t('Choose Files')); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content Area (flexbox container for remaining space) -->
    <div class="ebooks-main-content">
        <!-- Connection Info Section -->
        <div class="ebooks-connection-info">
            <details>
                <summary><strong><?php p($l->t('📱 KOReader & eReader Setup')); ?></strong></summary>
            <div class="connection-details">
                
                <!-- OPDS Section -->
                <div class="connection-section">
                    <h3><?php p($l->t('📚 OPDS Catalog Access')); ?></h3>
                    <p><?php p($l->t('Access your book library from any OPDS-compatible app using your Nextcloud credentials.')); ?></p>
                    
                    <div class="setting-row">
                        <label><?php p($l->t('OPDS URL:')); ?></label>
                        <div class="url-display">
                            <input type="text" readonly value="<?php p($_['connection_info']['opds_url']); ?>" class="selectable-input">
                            <button data-copy-text="<?php p($_['connection_info']['opds_url']); ?>" class="copy-btn">📋</button>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <label><?php p($l->t('Username:')); ?></label>
                        <div class="url-display">
                            <input type="text" readonly value="<?php p($_['user_id']); ?>" class="selectable-input">
                            <button data-copy-text="<?php p($_['user_id']); ?>" class="copy-btn">📋</button>
                        </div>
                        <small class="form-help"><?php p($l->t('Use your Nextcloud username')); ?></small>
                    </div>
                    
                    <div class="setting-row">
                        <label><?php p($l->t('Password:')); ?></label>
                        <div class="info-display">
                            <span class="info-text"><?php p($l->t('Use your Nextcloud password')); ?></span>
                        </div>
                        <small class="form-help"><?php p($l->t('Same password you use to log into Nextcloud')); ?></small>
                    </div>
                </div>

                <!-- KOReader Section -->
                <div class="connection-section">
                    <h3><?php p($l->t('🔄 KOReader Sync Setup')); ?></h3>
                    <p><?php p($l->t('Set up a custom password for KOReader reading progress synchronization.')); ?></p>
                    
                    <div class="setting-row">
                        <label><?php p($l->t('Sync URL:')); ?></label>
                        <div class="url-display">
                            <input type="text" readonly value="<?php p($_['connection_info']['koreader_sync_url']); ?>" class="selectable-input">
                            <button data-copy-text="<?php p($_['connection_info']['koreader_sync_url']); ?>" class="copy-btn">📋</button>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <label><?php p($l->t('Username:')); ?></label>
                        <div class="url-display">
                            <input type="text" readonly value="<?php p($_['user_id']); ?>" class="selectable-input">
                            <button data-copy-text="<?php p($_['user_id']); ?>" class="copy-btn">📋</button>
                        </div>
                        <small class="form-help"><?php p($l->t('Use your Nextcloud username')); ?></small>
                    </div>
                    
                    <!-- KOReader Password Management -->
                    <div id="koreader-password-section">
                        <div id="no-koreader-password" style="display: <?php p($_['connection_info']['has_koreader_password'] ? 'none' : 'block'); ?>;">
                            <div class="setting-row">
                                <label for="koreader-password-create"><?php p($l->t('Set Sync Password:')); ?></label>
                                <div class="password-input-group">
                                    <input type="password" id="koreader-password-create" placeholder="<?php p($l->t('Enter password for KOReader sync')); ?>">
                                    <button type="button" class="password-toggle" data-target="koreader-password-create" title="<?php p($l->t('Show/Hide password')); ?>">👁️</button>
                                </div>
                                <small class="form-help"><?php p($l->t('This password is only for KOReader sync (separate from your Nextcloud password)')); ?></small>
                            </div>
                            <button id="set-koreader-password-btn" class="btn primary" disabled><?php p($l->t('Set Sync Password')); ?></button>
                        </div>

                        <div id="has-koreader-password" style="display: <?php p($_['connection_info']['has_koreader_password'] ? 'block' : 'none'); ?>;">
                            <div class="setting-row">
                                <label><?php p($l->t('Sync Password:')); ?></label>
                                <div class="password-display-group">
                                    <input type="password" readonly id="koreader-password-display" class="selectable-input">
                                    <button type="button" class="password-toggle" data-target="koreader-password-display" title="<?php p($l->t('Show/Hide password')); ?>">👁️</button>
                                    <button data-copy-target="koreader-password-display" class="copy-btn">📋</button>
                                    <button id="show-koreader-password-reset-btn" class="reset-btn"><?php p($l->t('Change Password')); ?></button>
                                </div>
                                <small class="form-help"><?php p($l->t('Use this password in KOReader sync settings')); ?></small>
                            </div>
                            
                            <!-- Password reset form (hidden by default) -->
                            <div id="koreader-password-reset-form" class="setting-row" style="display: none;">
                                <label><?php p($l->t('New Sync Password:')); ?></label>
                                <div class="password-input-group">
                                    <input type="password" id="new-koreader-password" placeholder="<?php p($l->t('Enter new sync password')); ?>">
                                    <button type="button" class="password-toggle" data-target="new-koreader-password" title="<?php p($l->t('Show/Hide password')); ?>">👁️</button>
                                </div>
                                <div class="form-actions">
                                    <button id="reset-koreader-password-btn" class="btn primary" disabled><?php p($l->t('Update Password')); ?></button>
                                    <button id="cancel-koreader-password-reset-btn" class="btn secondary"><?php p($l->t('Cancel')); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Setup Instructions -->
                <div class="connection-section">
                    <h3><?php p($l->t('📖 Setup Instructions')); ?></h3>
                    <div class="setup-instructions">
                        <h4><?php p($l->t('For OPDS-compatible readers:')); ?></h4>
                        <ol>
                            <li><?php p($l->t('Add a new OPDS catalog in your reading app')); ?></li>
                            <li><?php p($l->t('Use the OPDS URL from above')); ?></li>
                            <li><?php p($l->t('Enter your Nextcloud username and password')); ?></li>
                        </ol>
                        
                        <h4><?php p($l->t('For KOReader:')); ?></h4>
                        <ol>
                            <li><?php p($l->t('Set up a sync password above if you haven\'t already')); ?></li>
                            <li><?php p($l->t('In KOReader: go to Tools → More tools → Progress sync')); ?></li>
                            <li><?php p($l->t('Enable progress sync and select "Custom server"')); ?></li>
                            <li><?php p($l->t('Enter the sync URL, your Nextcloud username, and the sync password from above')); ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </details>
        </div>

        <?php if (empty($_['books'])): ?>
            <div class="ebooks-empty">
                <div class="icon-book"></div>
                <h2><?php p($l->t('No books found')); ?></h2>
                <p><?php p($l->t('Add some EPUB, PDF, CBR, or MOBI files to your eBooks folder to get started.')); ?></p>
            </div>
        <?php else: ?>
            
            <!-- Search Bar -->
            <div class="ebooks-search-container">
                <div class="search-wrapper">
                    <input type="search" 
                           id="books-search" 
                           placeholder="<?php p($l->t('Search by title or author...')); ?>" 
                           class="search-input">
                    <div class="search-icon">🔍</div>
                </div>
                <div class="search-results-info" id="search-results-info" style="display: none;"></div>
            </div>
            
            <div class="ebooks-table-container" id="books-container">
                <div class="ebooks-table-wrapper">
                    <table class="ebooks-table">
                <thead>
                    <tr>
                        <th class="col-icon"></th>
                        <th class="col-title"><?php p($l->t('Title')); ?></th>
                        <th class="col-author"><?php p($l->t('Author')); ?></th>
                        <th class="col-year"><?php p($l->t('Year')); ?></th>
                        <th class="col-language"><?php p($l->t('Language')); ?></th>
                        <th class="col-publisher"><?php p($l->t('Publisher')); ?></th>
                        <th class="col-format"><?php p($l->t('Format')); ?></th>
                        <th class="col-size"><?php p($l->t('Size')); ?></th>
                        <th class="col-progress"><?php p($l->t('Progress')); ?></th>
                        <th class="col-last-sync"><?php p($l->t('Last Sync')); ?></th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($_['books'] as $book): ?>
                        <tr class="book-row" data-format="<?php p($book['format']); ?>">
                            <td class="book-icon-cell">
                                <span class="book-icon-table"><?php p($book['format'] === 'cbr' ? '📚' : ($book['format'] === 'pdf' ? '📄' : ($book['format'] === 'mobi' ? '📱' : '📖'))); ?></span>
                            </td>
                            <td class="book-title-cell">
                                <div class="book-title-wrapper">
                                    <span class="book-title" title="<?php p($book['title']); ?>"><?php p($book['title']); ?></span>
                                    <?php if ($book['format'] === 'cbr' && (!empty($book['series']) || !empty($book['issue']))): ?>
                                        <div class="comic-info">
                                            <?php if (!empty($book['series'])): ?>
                                                <span class="comic-series-small"><?php p($book['series']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($book['issue'])): ?>
                                                <span class="comic-issue-small">#<?php p($book['issue']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="book-author-cell">
                                <span class="book-author"><?php p($book['author'] ?: '-'); ?></span>
                            </td>
                            <td class="book-year-cell">
                                <span class="book-year"><?php 
                                    $yearDisplay = '-';
                                    if ($book['publication_date']) {
                                        // Extract year from publication_date (YYYY-MM-DD format)
                                        if (preg_match('/^(\d{4})-/', $book['publication_date'], $matches)) {
                                            $yearDisplay = $matches[1];
                                        } else {
                                            $yearDisplay = $book['publication_date'];
                                        }
                                    }
                                    p($yearDisplay);
                                ?></span>
                            </td>
                            <td class="book-language-cell">
                                <span class="book-language"><?php p($book['language'] ?: '-'); ?></span>
                            </td>
                            <td class="book-publisher-cell">
                                <span class="book-publisher"><?php p($book['publisher'] ?: '-'); ?></span>
                            </td>
                            <td class="book-format-cell">
                                <span class="format-badge format-<?php p($book['format']); ?>"><?php p(strtoupper($book['format'])); ?></span>
                            </td>
                            <td class="book-size-cell">
                                <span class="book-size"><?php p(\OCP\Util::humanFileSize($book['size'])); ?></span>
                            </td>
                            <td class="book-progress-cell">
                                <?php if (isset($book['progress']) && $book['progress']): ?>
                                    <?php
                                        $progressTooltip = number_format($book['progress']['percentage'], 1) . '% complete on ' . $book['progress']['device'];
                                        if (!empty($book['progress']['updated_at'])) {
                                            $utcTime = new DateTime($book['progress']['updated_at'], new DateTimeZone('UTC'));
                                            $userTimezone = \OC::$server->getConfig()->getUserValue(\OC::$server->getUserSession()->getUser()->getUID(), 'core', 'timezone', date_default_timezone_get());
                                            $userTime = clone $utcTime;
                                            $userTime->setTimezone(new DateTimeZone($userTimezone));
                                            $progressTooltip .= ' (last sync: ' . $userTime->format('M j, Y H:i') . ')';
                                        }
                                    ?>
                                    <div class="progress-compact" 
                                         title="<?php p($progressTooltip); ?>">
                                        <div class="progress-bar-small">
                                            <div class="progress-fill" style="width: <?php p($book['progress']['percentage']); ?>%"></div>
                                        </div>
                                        <div class="progress-text">
                                            <span class="progress-percentage"><?php p(number_format($book['progress']['percentage'], 0)); ?>%</span>
                                            <?php if (!empty($book['progress']['device'])): ?>
                                                <span class="progress-device"><?php p(substr($book['progress']['device'], 0, 8)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="no-progress">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="book-last-sync-cell">
                                <?php if (isset($book['progress']) && $book['progress'] && !empty($book['progress']['updated_at'])): ?>
                                    <?php
                                        $utcTime = new DateTime($book['progress']['updated_at'], new DateTimeZone('UTC'));
                                        $userTimezone = \OC::$server->getConfig()->getUserValue(\OC::$server->getUserSession()->getUser()->getUID(), 'core', 'timezone', date_default_timezone_get());
                                        $userTime = clone $utcTime;
                                        $userTime->setTimezone(new DateTimeZone($userTimezone));
                                    ?>
                                    <span class="last-sync-time" title="<?php p($userTime->format('Y-m-d H:i:s T')); ?>">
                                        <?php p($userTime->format('M j, Y')); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="no-sync">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="book-actions-cell">
                                <button class="action-btn edit-book-btn" 
                                        data-path="<?php p($book['path']); ?>"
                                        data-title="<?php p($book['title']); ?>"
                                        data-author="<?php p($book['author']); ?>"
                                        data-format="<?php p($book['format']); ?>"
                                        data-series="<?php p($book['series'] ?? ''); ?>"
                                        data-issue="<?php p($book['issue'] ?? ''); ?>"
                                        data-volume="<?php p($book['volume'] ?? ''); ?>"
                                        data-publication-date="<?php 
                                            $dateForEdit = $book['publication_date'] ?? '';
                                            // Extract year from publication_date for editing (YYYY-MM-DD -> YYYY)
                                            if ($dateForEdit && preg_match('/^(\d{4})-/', $dateForEdit, $matches)) {
                                                $dateForEdit = $matches[1];
                                            }
                                            p($dateForEdit);
                                        ?>"
                                        data-language="<?php p($book['language'] ?? ''); ?>"
                                        data-publisher="<?php p($book['publisher'] ?? ''); ?>"
                                        title="<?php p($l->t('Edit metadata')); ?>">⚙️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div> <!-- Close ebooks-main-content -->

    <!-- Metadata Edit Modal -->
    <div id="metadata-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php p($l->t('Edit Book Metadata')); ?></h2>
                <button class="modal-close" id="close-modal">&times;</button>
            </div>
            
            <div class="modal-body">
                <form id="metadata-form">
                    <input type="hidden" id="file-path" name="file_path">
                    
                    <!-- Essential Information -->
                    <div class="form-section">
                        <div class="form-group full-width">
                            <label for="book-title"><?php p($l->t('Title')); ?> *</label>
                            <input type="text" id="book-title" name="title" required>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="book-author"><?php p($l->t('Author')); ?></label>
                            <input type="text" id="book-author" name="author">
                        </div>
                    </div>
                    
                    <!-- Publication Details -->
                    <div class="form-section">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="book-date"><?php p($l->t('Year')); ?></label>
                                <input type="number" id="book-date" name="publication_date" min="1000" max="2099" placeholder="YYYY">
                            </div>
                            
                            <div class="form-group">
                                <label for="book-language"><?php p($l->t('Language')); ?></label>
                                <input type="text" id="book-language" name="language" placeholder="en">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="book-publisher"><?php p($l->t('Publisher')); ?></label>
                                <input type="text" id="book-publisher" name="publisher">
                            </div>
                            
                            <div class="form-group">
                                <label for="book-format"><?php p($l->t('Format')); ?></label>
                                <input type="text" id="book-format" name="format" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comic-specific fields -->
                    <div id="comic-fields" class="comic-metadata-fields" style="display: none;">
                        <div class="form-section">
                            <h3><?php p($l->t('Comic Details')); ?></h3>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="comic-series"><?php p($l->t('Series')); ?></label>
                                    <input type="text" id="comic-series" name="series">
                                </div>
                                
                                <div class="form-group form-group-small">
                                    <label for="comic-issue"><?php p($l->t('Issue')); ?></label>
                                    <input type="number" id="comic-issue" name="issue">
                                </div>
                                
                                <div class="form-group form-group-small">
                                    <label for="comic-volume"><?php p($l->t('Volume')); ?></label>
                                    <input type="number" id="comic-volume" name="volume">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn danger" id="delete-metadata"><?php p($l->t('Delete')); ?></button>
                <div class="modal-footer-right">
                    <button type="button" class="btn secondary" id="cancel-metadata"><?php p($l->t('Cancel')); ?></button>
                    <button type="button" class="btn primary" id="save-metadata"><?php p($l->t('Save & Add to Library')); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Progress Modal -->
    <div id="upload-progress-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php p($l->t('Uploading Books')); ?></h2>
                <button class="modal-close" id="close-upload-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="upload-progress-list"></div>
            </div>
        </div>
    </div>
</div>