<?php
namespace OCA\KoreaderCompanion\Controller;

use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Service\DocumentHashGenerator;
use OCA\KoreaderCompanion\Service\FilenameService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\IDBConnection;

class PageController extends Controller {

    private $bookService;
    private $config;
    private $userSession;
    private $urlGenerator;
    private $db;
    private $rootFolder;
    private $hashGenerator;
    private $filenameService;

    public function __construct(
        IRequest $request,
        $appName,
        BookService $bookService,
        FilenameService $filenameService,
        IConfig $config,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        IRootFolder $rootFolder,
        DocumentHashGenerator $hashGenerator
    ) {
        parent::__construct($appName, $request);
        $this->bookService = $bookService;

        if (!$filenameService) {
            throw new \Exception('FilenameService not available - required for file operations');
        }
        $this->filenameService = $filenameService;

        $this->config = $config;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->hashGenerator = $hashGenerator;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index() {
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 50; // Fixed page size of 50 books
        $query = $this->request->getParam('q', '');
        
        $user = $this->userSession->getUser();
        
        $acceptHeader = $this->request->getHeader('Accept');
        $isAjax = (strpos($acceptHeader, 'application/json') !== false) || 
                  ($this->request->getHeader('X-Requested-With') === 'XMLHttpRequest');
        
        if ($isAjax) {
            // For AJAX requests, use searchBooks for queries, getBooks for empty/no query
            // This ensures consistent behavior between initial page load and AJAX calls
            if (empty($query)) {
                $books = $this->bookService->getBooks($page, $perPage);
            } else {
                $books = $this->bookService->searchBooks($query, $page, $perPage);
            }
            return new JSONResponse($books);
        }
        
        $books = $this->bookService->getBooks($page, $perPage);
        
        $baseUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->getWebroot());
        $opdsUrl = $baseUrl . 'apps/koreader_companion/opds';
        $koreaderSyncUrl = $baseUrl . 'apps/koreader_companion/sync';
        
        $hasKoreaderPassword = false;
        if ($user) {
            $hasKoreaderPassword = !empty($this->config->getUserValue($user->getUID(), 'koreader_companion', 'koreader_sync_password', ''));
        }
        
        return new TemplateResponse($this->appName, 'page', [
            'books' => $books,
            'user_id' => $user ? $user->getUID() : '',
            'connection_info' => [
                'opds_url' => $opdsUrl,
                'koreader_sync_url' => $koreaderSyncUrl,
                'username' => $user ? $user->getUID() : '',
                'has_koreader_password' => $hasKoreaderPassword
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage
            ]
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setKoreaderPassword() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        
        $password = $this->request->getParam('password', '');
        if (empty($password)) {
            return new DataResponse(['error' => 'Password is required'], 400);
        }
        
        // Store MD5 hash for KOReader authentication compatibility
        // KOReader protocol requires MD5, so we store MD5 hash (not plain password)
        $md5Hash = md5($password);
        $this->config->setUserValue($user->getUID(), 'koreader_companion', 'koreader_sync_password', $md5Hash);

        return new DataResponse([]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getKoreaderPassword() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Not logged in'], 401);
        }
        
        $hashedPassword = $this->config->getUserValue($user->getUID(), 'koreader_companion', 'koreader_sync_password', '');
        
        return new DataResponse([
            'password' => '', // Never return actual password for security
            'has_password' => !empty($hashedPassword)
        ]);
    }



    private function addProgressToBooks($books, $userId) {
        // Get all progress data for this user
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('koreader_sync_progress')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeQuery();

        $progressData = [];
        while ($row = $result->fetch()) {
            $progressData[$row['document_hash']] = $row;
        }
        $result->closeCursor();

        // Add progress to each book
        return array_map(function($book) use ($progressData) {
            $book['progress'] = null;
            
            // Check if this is a special KOReader hash mapping
            if (strpos($book['path'], 'KOREADER_HASH_') === 0) {
                $koreaderHash = substr($book['path'], strlen('KOREADER_HASH_'));
                if (isset($progressData[$koreaderHash])) {
                    $progress = $progressData[$koreaderHash];
                    $book['progress'] = [
                        'percentage' => floatval($progress['percentage']) * 100, // Convert decimal to percentage
                        'device' => $progress['device'],
                        'device_id' => $progress['device_id'],
                        'updated_at' => $progress['updated_at']
                    ];
                    return $book;
                }
            }
            
            // Try standard hash strategies to match progress data
            $hashCandidates = [
                md5($book['path']), // Current path
                md5(basename($book['path'])), // Just filename
                md5('/' . basename($book['path'])), // Filename with leading slash
            ];
            
            // Also try variations based on book title
            if (isset($book['title'])) {
                $filename = $book['title'] . '.epub';
                $hashCandidates[] = md5($filename);
                $hashCandidates[] = md5('/' . $filename);
            }
            
            foreach ($hashCandidates as $hash) {
                if (isset($progressData[$hash])) {
                    $progress = $progressData[$hash];
                    $book['progress'] = [
                        'percentage' => floatval($progress['percentage']) * 100, // Convert decimal to percentage
                        'device' => $progress['device'],
                        'device_id' => $progress['device_id'],
                        'updated_at' => $progress['updated_at']
                    ];
                    break; // Found a match, stop trying
                }
            }
            
            return $book;
        }, $books);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function uploadBook() {
        try {
            // Get the uploaded file
            $uploadedFiles = $this->request->getUploadedFile('file');
            if (!$uploadedFiles || !isset($uploadedFiles['tmp_name'])) {
                return new JSONResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            // Get the user's configured eBooks folder
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Use the configured folder name
            $folderName = $this->config->getUserValue($user->getUID(), 'koreader_companion', 'folder', 'eBooks');

            try {
                $booksFolder = $userFolder->get($folderName);
            } catch (\OCP\Files\NotFoundException $e) {
                // Create folder if it doesn't exist
                $booksFolder = $userFolder->newFolder($folderName);
            }

            // Upload the file first
            $originalFilename = $uploadedFiles['name'];
            $tempFilename = 'temp_' . time() . '_' . $originalFilename;
            $tempFile = $booksFolder->newFile($tempFilename);
            $tempFile->putContent(file_get_contents($uploadedFiles['tmp_name']));

            // Extract metadata from the uploaded file
            $extractedMetadata = $this->bookService->extractMetadataForUpload($tempFile);

            // Get user-provided metadata from request
            $publicationYear = $this->request->getParam('publication_date', '');

            // Validate publication year (must be 4-digit year or empty)
            if (!empty($publicationYear) && (!is_numeric($publicationYear) || strlen($publicationYear) !== 4 || intval($publicationYear) < 1000 || intval($publicationYear) > 2099)) {
                $tempFile->delete(); // Clean up temp file
                return new JSONResponse(['error' => 'Publication year must be a 4-digit year (1000-2099)'], Http::STATUS_BAD_REQUEST);
            }

            // Convert year to publication_date format (YYYY-MM-DD)
            $publicationDate = null;
            if (!empty($publicationYear)) {
                $publicationDate = $publicationYear . '-01-01'; // Default to January 1st
            }

            // Merge extracted metadata with user-provided metadata (user data takes precedence)
            $finalMetadata = array_merge($extractedMetadata, array_filter([
                'title' => $this->request->getParam('title', ''),
                'author' => $this->request->getParam('author', ''),
                'language' => $this->request->getParam('language', ''),
                'publisher' => $this->request->getParam('publisher', ''),
                'publication_date' => $publicationDate,
                'description' => $this->request->getParam('description', ''),
                'tags' => $this->request->getParam('tags', ''),
                'series' => $this->request->getParam('series', ''),
                'issue' => $this->request->getParam('issue', ''),
                'volume' => $this->request->getParam('volume', '')
            ], function($value) { return $value !== ''; }));

            // Check if auto-rename is enabled for this user
            $autoRename = $this->config->getUserValue($user->getUID(), 'koreader_companion', 'auto_rename', 'no');

            if ($autoRename === 'yes') {
                // Generate standardized filename based on final metadata
                $finalFilename = $this->filenameService->generateStandardFilename($finalMetadata, $originalFilename);
            } else {
                // Keep original filename
                $finalFilename = $originalFilename;
            }

            // Check for conflicts and resolve with auto-renaming
            $finalFilename = $this->filenameService->resolveFilenameConflict($booksFolder, $finalFilename);

            // Move temp file to final location with final filename
            $finalPath = $booksFolder->getPath() . '/' . $finalFilename;
            $tempFile->move($finalPath);

            // Get the final file reference
            $finalFile = $booksFolder->get($finalFilename);

            // Store final metadata in database
            $this->storeBookMetadata($finalFile, $finalMetadata);

            return new JSONResponse([
                'filename' => $finalFilename,
                'path' => $finalPath,
                'extracted_metadata' => $extractedMetadata
            ]);

        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function extractMetadata() {
        try {
            // Get the uploaded file
            $uploadedFiles = $this->request->getUploadedFile('file');
            if (!$uploadedFiles || !isset($uploadedFiles['tmp_name'])) {
                return new JSONResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            // Get user and folder
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Create temporary file for metadata extraction
            $tempFilename = 'temp_extract_' . time() . '_' . $uploadedFiles['name'];
            $tempFile = $userFolder->newFile($tempFilename);
            $tempFile->putContent(file_get_contents($uploadedFiles['tmp_name']));

            // Extract metadata
            $metadata = $this->bookService->extractMetadataForUpload($tempFile);

            // Clean up temporary file
            $tempFile->delete();

            return new JSONResponse([
                'metadata' => $metadata
            ]);

        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function updateMetadata($id) {
        try {
            // Try to parse raw input if request params are empty
            $rawInput = file_get_contents('php://input');
            $parsedData = [];
            if (!empty($rawInput)) {
                parse_str($rawInput, $parsedData);
            }

            // Get metadata from request, using parsed data as fallback
            $publicationYear = $this->request->getParam('publication_date', $parsedData['publication_date'] ?? '');

            // Validate publication year (must be 4-digit year or empty)
            if (!empty($publicationYear) && (!is_numeric($publicationYear) || strlen($publicationYear) !== 4 || intval($publicationYear) < 1000 || intval($publicationYear) > 2099)) {
                return new JSONResponse(['error' => 'Publication year must be a 4-digit year (1000-2099)'], Http::STATUS_BAD_REQUEST);
            }

            // Convert year to publication_date format (YYYY-MM-DD)
            $publicationDate = null;
            if (!empty($publicationYear)) {
                $publicationDate = $publicationYear . '-01-01'; // Default to January 1st
            }

            $metadata = [
                'title' => $this->request->getParam('title', $parsedData['title'] ?? ''),
                'author' => $this->request->getParam('author', $parsedData['author'] ?? ''),
                'format' => $this->request->getParam('format', $parsedData['format'] ?? ''),
                'language' => $this->request->getParam('language', $parsedData['language'] ?? ''),
                'publisher' => $this->request->getParam('publisher', $parsedData['publisher'] ?? ''),
                'publication_date' => $publicationDate,
                'description' => $this->request->getParam('description', $parsedData['description'] ?? ''),
                'tags' => $this->request->getParam('tags', $parsedData['tags'] ?? ''),
                'series' => $this->request->getParam('series', $parsedData['series'] ?? ''),
                'issue' => $this->request->getParam('issue', $parsedData['issue'] ?? ''),
                'volume' => $this->request->getParam('volume', $parsedData['volume'] ?? '')
            ];

            // Get the book file by ID
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Validate file ID
            if (!is_numeric($id)) {
                return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
            }

            // Find file by file ID
            try {
                $targetFile = $userFolder->getById((int)$id);
                if (empty($targetFile)) {
                    return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
                }
                $targetFile = $targetFile[0]; // getById returns an array
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
            }

            // Update metadata
            $this->storeBookMetadata($targetFile, $metadata);

            // Check if auto-rename is enabled before renaming based on updated metadata
            $autoRename = $this->config->getUserValue($user->getUID(), 'koreader_companion', 'auto_rename', 'no');
            $currentName = $targetFile->getName();

            if ($autoRename === 'yes') {
                $newName = $this->filenameService->generateStandardFilename($metadata, $currentName);
            } else {
                $newName = $currentName; // Keep current filename
            }

            if ($newName !== $currentName) {
                // Check for conflicts and resolve
                $parentFolder = $targetFile->getParent();
                $newName = $this->filenameService->resolveFilenameConflict($parentFolder, $newName);

                // Rename the file
                $targetFile->move($parentFolder->getPath() . '/' . $newName);

                // CRITICAL: Update hash mappings after rename to maintain KOReader sync
                $this->updateHashMappingAfterRename($targetFile, $user->getUID());
            }

            return new JSONResponse([]);

        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a book from the library
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function deleteBook($id) {
        try {
            // Get the book file by ID
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());

            // Validate file ID
            if (!is_numeric($id)) {
                return new JSONResponse(['error' => 'Invalid book ID'], Http::STATUS_BAD_REQUEST);
            }

            // Find file by file ID
            try {
                $targetFile = $userFolder->getById((int)$id);
                if (empty($targetFile)) {
                    return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
                }
                $targetFile = $targetFile[0]; // getById returns an array
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['error' => 'Book not found'], Http::STATUS_NOT_FOUND);
            }

            // Remove from database first
            $this->bookService->removeBookFromDatabase($targetFile);

            // Delete the file
            $targetFile->delete();

            return new JSONResponse([]);

        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store book metadata in database
     */
    private function storeBookMetadata($file, $metadata) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return;
        }

        $userId = $user->getUID();
        $fileId = $file->getId();
        $filePath = $file->getPath();
        $currentTime = date('Y-m-d H:i:s');

        try {
            // Check if metadata already exists
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $existingId = $result->fetchOne();
            $result->closeCursor();

            if ($existingId) {
                // Update existing metadata
                $updateQb = $this->db->getQueryBuilder();
                $updateQb->update('koreader_metadata')
                    ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($existingId)));

                // Update all provided metadata fields
                foreach ($metadata as $key => $value) {
                    if (in_array($key, ['title', 'author', 'description', 'language', 'publisher', 'publication_date', 'subject', 'series', 'issue', 'volume', 'tags'])) {
                        $updateQb->set($key, $updateQb->createNamedParameter($value ?: null));
                    }
                }
                
                $updateQb->set('file_path', $updateQb->createNamedParameter($filePath))
                    ->set('updated_at', $updateQb->createNamedParameter($currentTime));

                $updateQb->executeStatement();
            } else {
                // Insert new metadata
                $insertQb = $this->db->getQueryBuilder();
                $insertQb->insert('koreader_metadata')
                    ->values([
                        'user_id' => $insertQb->createNamedParameter($userId),
                        'file_id' => $insertQb->createNamedParameter($fileId),
                        'file_path' => $insertQb->createNamedParameter($filePath),
                        'title' => $insertQb->createNamedParameter($metadata['title'] ?? null),
                        'author' => $insertQb->createNamedParameter($metadata['author'] ?? null),
                        'description' => $insertQb->createNamedParameter($metadata['description'] ?? null),
                        'language' => $insertQb->createNamedParameter($metadata['language'] ?? null),
                        'publisher' => $insertQb->createNamedParameter($metadata['publisher'] ?? null),
                        'publication_date' => $insertQb->createNamedParameter($metadata['publication_date'] ?? null),
                        'subject' => $insertQb->createNamedParameter($metadata['subject'] ?? null),
                        'series' => $insertQb->createNamedParameter($metadata['series'] ?? null),
                        'issue' => $insertQb->createNamedParameter($metadata['issue'] ?? null),
                        'volume' => $insertQb->createNamedParameter($metadata['volume'] ?? null),
                        'tags' => $insertQb->createNamedParameter($metadata['tags'] ?? null),
                        'created_at' => $insertQb->createNamedParameter($currentTime),
                        'updated_at' => $insertQb->createNamedParameter($currentTime),
                    ]);

                $insertQb->executeStatement();
            }
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to store metadata for file ' . $filePath . ': ' . $e->getMessage());
        }
    }



    /**
     * Find a file by its path within a folder (recursive search)
     */
    private function findFileByPath($folder, $targetPath) {
        $files = $folder->getDirectoryListing();

        foreach ($files as $file) {
            if ($file->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                // Recursively search subfolders
                $found = $this->findFileByPath($file, $targetPath);
                if ($found) {
                    return $found;
                }
            } else {
                // Check if this is the file we're looking for
                $relativePath = $folder->getRelativePath($file->getPath());
                $fileName = $file->getName();
                $targetBasename = basename($targetPath);

                // Primary checks
                if ($relativePath === $targetPath || $fileName === $targetBasename || $file->getPath() === $targetPath) {
                    return $file;
                }

                // Fallback: normalize paths for comparison (handle encoding issues)
                $normalizedRelativePath = $this->normalizePath($relativePath);
                $normalizedTargetPath = $this->normalizePath($targetPath);
                $normalizedFileName = $this->normalizePath($fileName);
                $normalizedTargetBasename = $this->normalizePath($targetBasename);

                if ($normalizedRelativePath === $normalizedTargetPath ||
                    $normalizedFileName === $normalizedTargetBasename) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Normalize file path for comparison (handle common encoding issues)
     */
    private function normalizePath($path) {
        // Replace backticks with spaces (common encoding issue)
        $path = str_replace('`', ' ', $path);
        // Normalize multiple spaces to single space
        $path = preg_replace('/\s+/', ' ', $path);
        // Trim whitespace
        return trim($path);
    }

    /**
     * Update filename hash mapping when a file is renamed
     *
     * This is critical for KOReader sync - when files are renamed, the filename hash changes
     * and KOReader sync will break unless we update the hash mapping table.
     */
    private function updateHashMappingAfterRename($file, $userId) {
        try {
            $fileId = $file->getId();

            // Get the metadata ID for this file
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $metadataId = $result->fetchOne();
            $result->closeCursor();

            if (!$metadataId) {
                error_log('eBooks app: No metadata found for file after rename: ' . $file->getName());
                return;
            }

            // Generate new filename hash
            $newFilenameHash = $this->hashGenerator->generateFilenameHashFromNode($file);
            if (!$newFilenameHash) {
                error_log('eBooks app: Failed to generate new filename hash after rename: ' . $file->getName());
                return;
            }

            // Update the filename hash mapping (if it exists)
            $updateQb = $this->db->getQueryBuilder();
            $updatedRows = $updateQb->update('koreader_hash_mapping')
                ->set('document_hash', $updateQb->createNamedParameter($newFilenameHash))
                ->where($updateQb->expr()->eq('user_id', $updateQb->createNamedParameter($userId)))
                ->andWhere($updateQb->expr()->eq('metadata_id', $updateQb->createNamedParameter($metadataId)))
                ->andWhere($updateQb->expr()->eq('hash_type', $updateQb->createNamedParameter('filename')))
                ->executeStatement();

            if ($updatedRows > 0) {
                error_log('eBooks app: Updated filename hash mapping after rename - File: ' . $file->getName() . ', New hash: ' . $newFilenameHash);
            } else {
                // No existing filename mapping found - this is normal if KOReader hasn't synced this file yet
                error_log('eBooks app: No filename hash mapping to update after rename - File: ' . $file->getName());
            }

        } catch (\Exception $e) {
            error_log('eBooks app: Failed to update hash mapping after rename: ' . $e->getMessage());
        }
    }
}
