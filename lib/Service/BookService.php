<?php
namespace OCA\KoreaderCompanion\Service;

use OCA\KoreaderCompanion\Service\FileTrackingService;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\DataResponse;

class BookService {

    private $rootFolder;
    private $config;
    private $userSession;
    private $fileTrackingService;
    private $db;
    private $pdfExtractor;

    public function __construct(
        IRootFolder $rootFolder, 
        IConfig $config, 
        IUserSession $userSession,
        FileTrackingService $fileTrackingService,
        IDBConnection $db,
        PdfMetadataExtractor $pdfExtractor
    ) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->userSession = $userSession;
        $this->fileTrackingService = $fileTrackingService;
        $this->db = $db;
        $this->pdfExtractor = $pdfExtractor;
    }


    /**
     * Get paginated books from database with optional sorting
     */
    public function getBooks($page = null, $perPage = null, $sort = 'title') {
        // If pagination parameters are provided, use database-based pagination
        if ($page !== null && $perPage !== null) {
            return $this->getPaginatedBooks($page, $perPage, $sort);
        }
        
        // Otherwise, maintain backward compatibility with file-system scanning
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $folderName = $this->config->getUserValue($user->getUID(), 'koreader_companion', 'folder', 'eBooks');
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        
        try {
            $booksFolder = $userFolder->get($folderName);
        } catch (\Exception $e) {
            // Folder doesn't exist, return empty array
            return [];
        }

        if (!$booksFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
            return [];
        }

        $books = [];
        $this->scanFolder($booksFolder, $books);
        
        // Sort books by title (case-insensitive)
        usort($books, function($a, $b) {
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });
        
        return $books;
    }

    /**
     * Get paginated books from database and file system
     */
    private function getPaginatedBooks($page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // First, ensure metadata is up to date by scanning for new files
        $this->ensureMetadataUpToDate($userId);
        
        // Now query database for paginated results
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);

            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'author':
                    $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
               
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get paginated books: ' . $e->getMessage());
            // Fallback to filesystem scanning
            return $this->getBooks();
        }
    }

    /**
     * Get total count of books for pagination
     */
    public function getTotalBookCount() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();

        // Ensure metadata is up to date first
        $this->ensureMetadataUpToDate($userId);

        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'total_count'))
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

            $result = $qb->executeQuery();
                
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            
            return $count;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get total book count: ' . $e->getMessage());
            // Fallback to counting filesystem results
            return count($this->getBooks());
        }
    }


    /**
     * Ensure metadata database is up to date by scanning filesystem
     */
    private function ensureMetadataUpToDate($userId) {
        try {
            $folderName = $this->config->getUserValue($userId, 'koreader_companion', 'folder', 'eBooks');
            $userFolder = $this->rootFolder->getUserFolder($userId);
            
            try {
                $booksFolder = $userFolder->get($folderName);
            } catch (\Exception $e) {
                // Folder doesn't exist, nothing to sync
                return;
            }

            if (!$booksFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                return;
            }

            // Scan files and update database metadata
            $this->syncFolderToDatabase($booksFolder, $userId);

            // Clean up orphaned database entries (files that no longer exist)
            $this->cleanupOrphanedMetadata($userId);
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to update metadata: ' . $e->getMessage());
        }
    }

    /**
     * Sync folder contents to database metadata
     */
    private function syncFolderToDatabase(Node $folder, $userId) {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $this->syncFolderToDatabase($node, $userId);
            } else {
                $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
                if (in_array($extension, ['epub', 'pdf', 'cbr', 'mobi'])) {
                    if ($this->shouldIncludeFile($node)) {
                        $this->ensureFileInDatabase($node, $userId);
                    }
                }
            }
        }
    }

    /**
     * Ensure a file is recorded in the database metadata table
     */
    private function ensureFileInDatabase(Node $file, $userId) {
        try {
            // Check if file already exists in database
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id', 'updated_at')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
                ->executeQuery();
                
            $existingRow = $result->fetch();
            $result->closeCursor();
            
            $fileModTime = $file->getMTime();
            
            if ($existingRow) {
                // Check if file has been modified since last metadata extraction
                $lastUpdated = new \DateTime($existingRow['updated_at']);
                if ($fileModTime <= $lastUpdated->getTimestamp()) {
                    // File hasn't changed, no need to update
                    return;
                }
                // Update existing record
                $this->updateFileMetadata($file, $userId, $existingRow['id']);
            } else {
                // Insert new record
                $this->insertFileMetadata($file, $userId);
            }
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to ensure file in database ' . $file->getPath() . ': ' . $e->getMessage());
        }
    }

    /**
     * Insert new file metadata into database
     */
    private function insertFileMetadata(Node $file, $userId) {
        try {
            $metadata = $this->extractMetadata($file);
            
            $qb = $this->db->getQueryBuilder();
            $qb->insert('koreader_metadata')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'file_id' => $qb->createNamedParameter($file->getId()),
                    'file_path' => $qb->createNamedParameter($file->getPath()),
                    'title' => $qb->createNamedParameter($metadata['title']),
                    'author' => $qb->createNamedParameter($metadata['author']),
                    'description' => $qb->createNamedParameter($metadata['description']),
                    'publisher' => $qb->createNamedParameter($metadata['publisher']),
                    'publication_date' => $qb->createNamedParameter($metadata['publication_date'] ?: null),
                    'language' => $qb->createNamedParameter($metadata['language']),
                    'series' => $qb->createNamedParameter($metadata['series']),
                    'series_index' => $qb->createNamedParameter($metadata['issue'] ? floatval($metadata['issue']) : null),
                    'subject' => $qb->createNamedParameter($metadata['subject']),
                    'tags' => $qb->createNamedParameter($metadata['tags']),
                    'file_format' => $qb->createNamedParameter($metadata['format']),
                    'issue' => $qb->createNamedParameter($metadata['issue']),
                    'volume' => $qb->createNamedParameter($metadata['volume']),
                    'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                    'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s'))
                ])
                ->executeStatement();
                
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to insert file metadata for ' . $file->getPath() . ': ' . $e->getMessage());
        }
    }

    /**
     * Update existing file metadata in database
     */
    private function updateFileMetadata(Node $file, $userId, $metadataId) {
        try {
            $metadata = $this->extractMetadata($file);
            
            $qb = $this->db->getQueryBuilder();
            $qb->update('koreader_metadata')
                ->set('file_path', $qb->createNamedParameter($file->getPath()))
                ->set('title', $qb->createNamedParameter($metadata['title']))
                ->set('author', $qb->createNamedParameter($metadata['author']))
                ->set('description', $qb->createNamedParameter($metadata['description']))
                ->set('publisher', $qb->createNamedParameter($metadata['publisher']))
                ->set('publication_date', $qb->createNamedParameter($metadata['publication_date'] ?: null))
                ->set('language', $qb->createNamedParameter($metadata['language']))
                ->set('series', $qb->createNamedParameter($metadata['series']))
                ->set('series_index', $qb->createNamedParameter($metadata['issue'] ? floatval($metadata['issue']) : null))
                ->set('subject', $qb->createNamedParameter($metadata['subject']))
                ->set('tags', $qb->createNamedParameter($metadata['tags']))
                ->set('file_format', $qb->createNamedParameter($metadata['format']))
                ->set('issue', $qb->createNamedParameter($metadata['issue']))
                ->set('volume', $qb->createNamedParameter($metadata['volume']))
                ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($metadataId)))
                ->executeStatement();
                
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to update file metadata for ' . $file->getPath() . ': ' . $e->getMessage());
        }
    }

    /**
     * Convert database row to book array format
     */
    private function convertDatabaseRowToBookArray($row, $userId) {
        try {
            // Get the file from Nextcloud filesystem
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $files = $userFolder->getById($row['file_id']);
            
            if (empty($files)) {
                // File no longer exists, should be cleaned up
                return null;
            }
            
            $file = $files[0];
            
            // Build book array in the same format as extractMetadata
            $book = [
                'id' => $row['file_id'],
                'name' => $file->getName(),
                'path' => $file->getPath(),
                'size' => $file->getSize(),
                'modified_time' => $file->getMTime(),
                'format' => $row['file_format'] ?? strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)),
                'title' => $row['title'] ?? pathinfo($file->getName(), PATHINFO_FILENAME),
                'author' => $row['author'] ?? 'Unknown',
                'description' => $row['description'] ?? '',
                'language' => $row['language'] ?? '',
                'publisher' => $row['publisher'] ?? '',
                'subject' => $row['subject'] ?? '',
                'publication_date' => $row['publication_date'] ?? '',
                'identifier' => '', // Not stored in current schema
                'cover' => null, // Handled dynamically
                'series' => $row['series'] ?? '',
                'issue' => $row['issue'] ?? '',
                'volume' => $row['volume'] ?? '',
                'tags' => $row['tags'] ?? ''
            ];
            
            // Add sync progress if available
            $this->addSyncProgressToMetadata($file, $book);
            
            return $book;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to convert database row to book array: ' . $e->getMessage());
            return null;
        }
    }

    private function scanFolder(Node $folder, &$books) {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $this->scanFolder($node, $books);
            } else {
                $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
                if (in_array($extension, ['epub', 'pdf', 'cbr', 'mobi'])) {
                    // Check if this file should be included based on upload restrictions
                    if ($this->shouldIncludeFile($node)) {
                        $books[] = $this->extractMetadata($node);
                    }
                }
            }
        }
    }

    private function extractMetadata(Node $file) {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        
        $metadata = [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'path' => $file->getPath(),
            'size' => $file->getSize(),
            'modified_time' => $file->getMTime(),
            'format' => $extension,
            'title' => pathinfo($file->getName(), PATHINFO_FILENAME),
            'author' => 'Unknown',
            'description' => '',
            'language' => '',
            'publisher' => '',
            'subject' => '',
            'publication_date' => '',
            'identifier' => '',
            'cover' => null,
            // Add comic book specific fields
            'series' => '',
            'issue' => '',
            'volume' => '',
            'tags' => ''
        ];

        // Check for stored metadata first (prioritize user-provided metadata from upload)
        $storedMetadata = $this->getStoredMetadata($file);
        if ($storedMetadata) {
            // Use stored metadata as primary source
            $fieldsToOverride = ['title', 'author', 'description', 'language', 'publisher', 'publication_date', 'subject', 'series', 'issue', 'volume', 'tags'];
            foreach ($fieldsToOverride as $field) {
                if (!empty($storedMetadata[$field])) {
                    $metadata[$field] = $storedMetadata[$field];
                }
            }
        } else {
            // Fallback to server-side metadata extraction only if no stored metadata exists
            // This handles files that were uploaded before the client-side extraction was implemented
            if ($extension === 'epub') {
                $this->extractEpubMetadata($file, $metadata);
            } elseif ($extension === 'pdf') {
                $this->extractPdfMetadata($file, $metadata);
            } elseif ($extension === 'cbr') {
                // Only process CBR if Archive class is available
                if (class_exists('Kiwilan\Archive\Archive')) {
                    $this->extractCbrMetadata($file, $metadata);
                } else {
                    // Fallback: basic filename metadata for CBR
                    $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
                    $metadata['title'] = $filename;
                    $metadata['format'] = 'cbr';
                }
            } elseif ($extension === 'mobi') {
                $this->extractMobiMetadata($file, $metadata);
            }
        }

        // Add KOReader sync progress information
        $this->addSyncProgressToMetadata($file, $metadata);

        return $metadata;
    }

    /**
     * Add KOReader sync progress information to book metadata
     */
    private function addSyncProgressToMetadata(Node $file, &$metadata) {
        try {
            $user = $this->userSession->getUser();
            $userId = null;
            
            if ($user) {
                $userId = $user->getUID();
            } else {
                // For API calls, try to extract user from file path
                $filePath = $file->getPath();
                if (preg_match('/^\/([^\/]+)\/files\//', $filePath, $matches)) {
                    $userId = $matches[1];
                } else {
                    return;
                }
            }

            // Get all document hashes for this file from hash mappings
            $documentHashes = $this->getDocumentHashesForFile($file->getId(), $userId);
            
            if (empty($documentHashes)) {
                $metadata['progress'] = null;
                return;
            }

            // Find the most recent sync progress for any of the document hashes
            $progress = $this->getMostRecentSyncProgress($documentHashes, $userId);
            
            if ($progress) {
                $metadata['progress'] = [
                    'percentage' => floatval($progress['percentage'] ?? 0) * 100,
                    'device' => $progress['device'] ?? 'Unknown',
                    'device_id' => $progress['device_id'] ?? '',
                    'updated_at' => $progress['updated_at'] ?? '',
                    'progress_data' => $progress['progress'] ?? ''
                ];
            } else {
                $metadata['progress'] = null;
            }
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to add sync progress for file ' . $file->getPath() . ': ' . $e->getMessage());
            $metadata['progress'] = null;
        }
    }

    /**
     * Get all document hashes associated with a file
     */
    private function getDocumentHashesForFile(int $fileId, string $userId): array {
        try {
            // First get the metadata ID for this file
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $metadataId = $result->fetchOne();
            $result->closeCursor();

            if (!$metadataId) {
                return [];
            }

            // Now get all document hashes for this metadata
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('document_hash')
                ->from('koreader_hash_mapping')
                ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            $hashes = [];
            while ($row = $result->fetch()) {
                $hashes[] = $row['document_hash'];
            }
            $result->closeCursor();

            return $hashes;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get document hashes for file ID ' . $fileId . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the most recent sync progress for a set of document hashes
     */
    private function getMostRecentSyncProgress(array $documentHashes, string $userId): ?array {
        if (empty($documentHashes)) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from('koreader_sync_progress')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('document_hash', $qb->createNamedParameter($documentHashes, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
                ->orderBy('updated_at', 'DESC')
                ->setMaxResults(1)
                ->executeQuery();

            $progress = $result->fetch();
            $result->closeCursor();

            return $progress ?: null;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get sync progress for hashes: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get stored custom metadata from database for a file
     */
    private function getStoredMetadata(Node $file): ?array {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                // For API calls, try to extract user from file path
                $filePath = $file->getPath();
                if (preg_match('/^\/([^\/]+)\/files\//', $filePath, $matches)) {
                    $userId = $matches[1];
                } else {
                    return null;
                }
            } else {
                $userId = $user->getUID();
            }

            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
                ->executeQuery();

            $storedMetadata = $result->fetch();
            $result->closeCursor();

            return $storedMetadata ?: null;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to retrieve stored metadata for file ' . $file->getPath() . ': ' . $e->getMessage());
            return null;
        }
    }

    private function extractEpubMetadata(Node $file, &$metadata) {
        try {
            // Read the EPUB file content
            $content = $file->getContent();
            
            // Create temporary file to work with ZipArchive
            $tempFile = tempnam(sys_get_temp_dir(), 'epub_meta_');
            file_put_contents($tempFile, $content);
            
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === TRUE) {
                $epubMetadata = $this->parseEpubOPF($zip);
                $zip->close();
                
                // Merge extracted metadata
                if ($epubMetadata) {
                    if (!empty($epubMetadata['title'])) {
                        $metadata['title'] = $epubMetadata['title'];
                    }
                    if (!empty($epubMetadata['author'])) {
                        $metadata['author'] = $epubMetadata['author'];
                    }
                    if (!empty($epubMetadata['description'])) {
                        $metadata['description'] = $epubMetadata['description'];
                    }
                    if (!empty($epubMetadata['language'])) {
                        $metadata['language'] = $epubMetadata['language'];
                    }
                    if (!empty($epubMetadata['publisher'])) {
                        $metadata['publisher'] = $epubMetadata['publisher'];
                    }
                    if (!empty($epubMetadata['subject'])) {
                        $metadata['subject'] = $epubMetadata['subject'];
                    }
                    if (!empty($epubMetadata['date'])) {
                        $metadata['publication_date'] = $this->parsePublicationDate($epubMetadata['date']);
                    }
                }
            }
            
            unlink($tempFile);
            
        } catch (\Exception $e) {
            // If extraction fails, fall back to filename parsing
            $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
            
            // Try to extract author and title from filename patterns like "Author - Title"
            if (strpos($filename, ' - ') !== false) {
                $parts = explode(' - ', $filename, 2);
                $metadata['author'] = trim($parts[0]);
                $metadata['title'] = trim($parts[1]);
            }
        }
    }

    private function extractPdfMetadata(Node $file, &$metadata) {
        try {
            $pdfMetadata = $this->pdfExtractor->extractMetadata($file);
            
            // Merge PDF metadata into existing metadata array
            foreach ($pdfMetadata as $key => $value) {
                if (!empty($value) || $key === 'title') {
                    $metadata[$key] = $value;
                }
            }
        } catch (\Exception $e) {
            // If extraction fails, keep defaults
        }
    }

    private function extractCbrMetadata(Node $file, &$metadata) {
        try {
            $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
            
            // Set basic metadata from filename
            $metadata['title'] = $filename;
            $metadata['format'] = 'cbr';
            
            // Try to parse comic book information from filename
            // Common patterns: "Series Name #001 (Year)", "Series Name 001", etc.
            if (preg_match('/(.*?)\s*#?(\d+).*?\((\d{4})\)/', $filename, $matches)) {
                $metadata['title'] = trim($matches[1]) . ' #' . $matches[2];
                $metadata['series'] = trim($matches[1]);
                $metadata['issue'] = $matches[2];
                $metadata['publication_date'] = $this->parsePublicationDate($matches[3]);
            } elseif (preg_match('/(.*?)\s*(\d+)/', $filename, $matches)) {
                $metadata['title'] = trim($matches[1]) . ' #' . $matches[2];
                $metadata['series'] = trim($matches[1]);
                $metadata['issue'] = $matches[2];
            }
            
            // Extract ComicInfo.xml metadata if present
            $this->extractComicInfoMetadata($file, $metadata);
            
        } catch (\Exception $e) {
            // If extraction fails, keep defaults
            error_log('CBR metadata extraction failed: ' . $e->getMessage());
        }
    }

    private function extractComicInfoMetadata(Node $file, &$metadata) {
        try {
            $content = $file->getContent();
            $tempFile = tempnam(sys_get_temp_dir(), 'cbr_info_');
            file_put_contents($tempFile, $content);
            
            $archive = \Kiwilan\Archive\Archive::make($tempFile);
            if (!$archive) {
                unlink($tempFile);
                return;
            }
            
            // Look for ComicInfo.xml in the archive
            $files = $archive->getFiles();
            $comicInfoXml = null;
            
            foreach ($files as $archiveFile) {
                if (strtolower($archiveFile->getName()) === 'comicinfo.xml') {
                    $comicInfoXml = $archiveFile->getContent();
                    break;
                }
            }
            
            unlink($tempFile);
            
            if ($comicInfoXml) {
                $this->parseComicInfoXml($comicInfoXml, $metadata);
            }
            
        } catch (\Throwable $e) {
            error_log('ComicInfo.xml extraction failed: ' . $e->getMessage());
        }
    }
    
    private function parseComicInfoXml($xmlContent, &$metadata) {
        try {
            $xml = simplexml_load_string($xmlContent);
            if (!$xml) {
                return;
            }
            
            // Extract comic-specific metadata
            if (!empty($xml->Title)) {
                $metadata['title'] = (string)$xml->Title;
            }
            
            if (!empty($xml->Series)) {
                $metadata['series'] = (string)$xml->Series;
                // Combine series and issue number for title if available
                if (!empty($xml->Number)) {
                    $metadata['title'] = $metadata['series'] . ' #' . (string)$xml->Number;
                }
            }
            
            if (!empty($xml->Number)) {
                $metadata['issue'] = (string)$xml->Number;
            }
            
            if (!empty($xml->Writer)) {
                $metadata['author'] = (string)$xml->Writer;
            }
            
            if (!empty($xml->Summary)) {
                $metadata['description'] = (string)$xml->Summary;
            }
            
            if (!empty($xml->Publisher)) {
                $metadata['publisher'] = (string)$xml->Publisher;
            }
            
            if (!empty($xml->Year)) {
                $metadata['publication_date'] = $this->parsePublicationDate((string)$xml->Year);
            }
            
            if (!empty($xml->Genre)) {
                $metadata['subject'] = (string)$xml->Genre;
            }
            
            if (!empty($xml->LanguageISO)) {
                $metadata['language'] = (string)$xml->LanguageISO;
            }
            
            // Additional comic-specific fields
            if (!empty($xml->Volume)) {
                $metadata['volume'] = (string)$xml->Volume;
            }
            
            if (!empty($xml->Web)) {
                $metadata['web'] = (string)$xml->Web;
            }
            
        } catch (\Exception $e) {
            error_log('ComicInfo.xml parsing failed: ' . $e->getMessage());
        }
    }

    private function extractMobiMetadata(Node $file, &$metadata) {
        try {
            $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
            $metadata['title'] = $filename;
            $metadata['format'] = 'mobi';
            
            // Basic MOBI metadata extraction from filename patterns
            // Pattern: "Title - Author"
            if (strpos($filename, ' - ') !== false) {
                $parts = explode(' - ', $filename, 2);
                $metadata['title'] = trim($parts[0]);
                $metadata['author'] = trim($parts[1]);
            }
            // Pattern: "Author - Title"
            elseif (preg_match('/^(.+?)\s-\s(.+)$/', $filename, $matches)) {
                $metadata['author'] = trim($matches[1]);
                $metadata['title'] = trim($matches[2]);
            }
            
            // Try to extract MOBI file header metadata
            $this->extractMobiHeader($file, $metadata);
            
        } catch (\Exception $e) {
            error_log('MOBI metadata extraction failed: ' . $e->getMessage());
        }
    }

    private function extractMobiHeader(Node $file, &$metadata) {
        try {
            // Read first 1KB of MOBI file to check header
            $content = $file->fopen('r');
            if (!$content) {
                return;
            }
            
            $header = fread($content, 1024);
            fclose($content);
            
            // MOBI files have "BOOKMOBI" or "TPZ" magic bytes at offset 60
            if (substr($header, 60, 8) === 'BOOKMOBI' || substr($header, 60, 3) === 'TPZ') {
                $metadata['format'] = 'mobi';
                
                // Basic validation that it's a real MOBI file
                // Full MOBI parsing would require a dedicated library
                // For now, we'll rely on filename-based extraction
            }
            
        } catch (\Exception $e) {
            // If header reading fails, keep filename-based metadata
            error_log('MOBI header reading failed: ' . $e->getMessage());
        }
    }

    public function searchBooks($query, $page = null, $perPage = null) {
        // If pagination parameters are provided, use database-based search
        if ($page !== null && $perPage !== null) {
            return $this->getPaginatedSearchResults($query, $page, $perPage);
        }
        
        // Otherwise, maintain backward compatibility with in-memory search
        $allBooks = $this->getBooks();
        
        if (empty($query)) {
            return $allBooks;
        }
        
        $query = strtolower($query);
        $results = [];
        
        foreach ($allBooks as $book) {
            if (stripos($book['title'], $query) !== false || 
                stripos($book['author'], $query) !== false ||
                stripos($book['description'], $query) !== false) {
                $results[] = $book;
            }
        }
        
        return $results;
    }

    /**
     * Get paginated search results from database
     */
    private function getPaginatedSearchResults($query, $page = 1, $perPage = 20) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);

        if (empty($query)) {
            return $this->getPaginatedBooks($page, $perPage);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

            // Add search conditions
            $qb->andWhere($qb->expr()->orX(
                   $qb->expr()->iLike('title', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('author', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('description', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('series', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('subject', $qb->createNamedParameter('%' . $query . '%')),
                   $qb->expr()->iLike('tags', $qb->createNamedParameter('%' . $query . '%'))
               ))
               ->orderBy('title', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
               
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get paginated search results: ' . $e->getMessage());
            // Fallback to in-memory search
            return $this->searchBooks($query);
        }
    }

    /**
     * Get total count of search results for pagination
     */
    public function getSearchResultCount($query) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        if (empty($query)) {
            return $this->getTotalBookCount();
        }
        
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'total_count'))
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->iLike('title', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('author', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('description', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('series', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('subject', $qb->createNamedParameter('%' . $query . '%')),
                    $qb->expr()->iLike('tags', $qb->createNamedParameter('%' . $query . '%'))
                ))
                ->executeQuery();
                
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            
            return $count;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get search result count: ' . $e->getMessage());
            // Fallback to in-memory search count
            return count($this->searchBooks($query));
        }
    }

    private function shouldIncludeFile(Node $file) {
        // Get user context - for OPDS/API calls, extract from file path
        $user = $this->userSession->getUser();
        $userId = null;

        if ($user) {
            $userId = $user->getUID();
        } else {
            // Extract user ID from file path for API contexts
            $path = $file->getPath();
            if (preg_match('/^\/([^\/]+)\/files\//', $path, $matches)) {
                $userId = $matches[1];
            }
        }

        if (!$userId) {
            return true; // Default to including files if we can't determine user
        }

        // Check if upload restrictions are enabled for this user
        $restrictUploads = $this->config->getUserValue($userId, 'koreader_companion', 'restrict_uploads', 'no');
        if ($restrictUploads !== 'yes') {
            return true; // No restrictions, include all files
        }

        // Check if this file was uploaded through the app using database tracking
        $isAppUploaded = $this->fileTrackingService->isAppUploadedFile($file, $userId);
        
        // If file is not tracked, check if it might be a legacy file or use fallback
        if (!$isAppUploaded) {
            $uploadMethod = $this->fileTrackingService->getFileUploadMethod($file, $userId);
            if ($uploadMethod === null) {
                // File not tracked - check file creation context and legacy preferences
                $isAppUploaded = $this->checkFileOriginWithFallback($file, $userId);
            }
        }

        return $isAppUploaded; // Only include app-uploaded files when restrictions are enabled
    }

    /**
     * Check file origin when tracking data is unavailable
     * This provides a fallback mechanism for determining if files should be included
     */
    private function checkFileOriginWithFallback(Node $file, string $userId) {
        try {
            // First, check legacy JSON tracking in user preferences
            $legacyTracking = $this->checkLegacyTracking($file, $userId);
            if ($legacyTracking !== null) {
                // Migrate to database if found in legacy tracking
                if ($legacyTracking) {
                    $this->fileTrackingService->markFileAsAppUploaded($file, $userId);
                }
                return $legacyTracking;
            }

            // Check if file was created very recently (within last 5 minutes)
            // Recent files are likely from current session and should be included
            $createTime = $file->getMTime();
            $currentTime = time();
            if (($currentTime - $createTime) < 300) { // 5 minutes
                // Mark recent files as external uploads for future reference
                $this->fileTrackingService->markFileAsExternalUpload($file, $userId);
                return true;
            }

            // For older files, be conservative and exclude them when restrictions are enabled
            // This prevents processing of files that were uploaded before restrictions were activated
            $this->fileTrackingService->markFileAsExternalUpload($file, $userId);
            return false;
            
        } catch (\Exception $e) {
            // If we can't determine file age, default to including it
            return true;
        }
    }

    /**
     * Check legacy JSON tracking in user preferences
     */
    private function checkLegacyTracking(Node $file, string $userId): ?bool {
        try {
            $appUploadedFiles = $this->config->getUserValue(
                $userId,
                'koreader_companion',
                'app_uploaded_files',
                ''
            );

            if (empty($appUploadedFiles)) {
                return null; // No legacy data
            }

            $uploadedList = json_decode($appUploadedFiles, true);
            if (!is_array($uploadedList)) {
                return null; // Invalid legacy data
            }

            $fileId = $file->getId();
            return in_array($fileId, $uploadedList);
            
        } catch (\Exception $e) {
            return null; // Error reading legacy data
        }
    }

    public function getBookById($id) {
        $allBooks = $this->getBooks();
        
        foreach ($allBooks as $book) {
            if ($book['id'] == intval($id)) {
                return $book;
            }
        }
        
        return null;
    }

    public function downloadBook($book, $format) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Unauthorized'], 401);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($book['id']);
            
            if (empty($files)) {
                return new DataResponse(['error' => 'File not found'], 404);
            }
            
            $file = $files[0];
            
            $response = new StreamResponse($file->fopen('r'));
            $response->addHeader('Content-Type', $this->getMimeType($format));
            $response->addHeader('Content-Disposition', 'attachment; filename="' . $book['name'] . '"');
            $response->addHeader('Content-Length', $file->getSize());
            
            return $response;
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'File not found'], 404);
        }
    }

    public function getThumbnail($book) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Unauthorized'], 401);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $files = $userFolder->getById($book['id']);
            
            if (empty($files)) {
                return new DataResponse(['error' => 'File not found'], 404);
            }
            
            $file = $files[0];
            
            if (strtolower($book['format']) === 'epub') {
                return $this->extractEpubThumbnail($file);
            } elseif (strtolower($book['format']) === 'cbr') {
                if (class_exists('Kiwilan\Archive\Archive')) {
                    return $this->extractCbrThumbnail($file);
                } else {
                    return new DataResponse(['message' => 'CBR thumbnail not available - Archive library not loaded'], 501);
                }
            } else {
                // For other formats, return a placeholder
                return new DataResponse(['message' => 'Thumbnail not available for this format'], 501);
            }
            
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Thumbnail extraction failed'], 500);
        }
    }

    private function extractEpubThumbnail($file) {
        try {
            // Read the EPUB file content
            $content = $file->getContent();
            
            // Create temporary file to work with ZipArchive
            $tempFile = tempnam(sys_get_temp_dir(), 'epub_thumb_');
            file_put_contents($tempFile, $content);
            
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) !== TRUE) {
                unlink($tempFile);
                return new DataResponse(['error' => 'Could not read EPUB file'], 500);
            }
            
            // Find cover image
            $coverImage = $this->findEpubCover($zip);
            
            if ($coverImage) {
                $imageContent = $zip->getFromName($coverImage);
                $zip->close();
                unlink($tempFile);
                
                if ($imageContent) {
                    // Create thumbnail
                    $thumbnail = $this->createThumbnail($imageContent);
                    
                    if ($thumbnail) {
                        // Write thumbnail to temporary file and stream it
                        $tempThumb = tempnam(sys_get_temp_dir(), 'thumb_');
                        file_put_contents($tempThumb, $thumbnail);
                        
                        $response = new StreamResponse(fopen($tempThumb, 'r'));
                        $response->addHeader('Content-Type', 'image/jpeg');
                        $response->addHeader('Content-Length', strlen($thumbnail));
                        
                        // Clean up temp file after response (Note: this might not work as expected)
                        register_shutdown_function(function() use ($tempThumb) {
                            if (file_exists($tempThumb)) {
                                unlink($tempThumb);
                            }
                        });
                        
                        return $response;
                    }
                }
            }
            
            $zip->close();
            unlink($tempFile);
            
            return new DataResponse(['message' => 'No cover image found'], 404);
            
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Thumbnail extraction failed: ' . $e->getMessage()], 500);
        }
    }

    private function findEpubCover($zip) {
        // Strategy 1: Look for container.xml to find OPF file
        $containerXml = $zip->getFromName('META-INF/container.xml');
        if ($containerXml) {
            $container = simplexml_load_string($containerXml);
            if ($container) {
                $opfPath = (string)$container->rootfiles->rootfile['full-path'];
                
                // Parse OPF file to find cover
                $opfContent = $zip->getFromName($opfPath);
                if ($opfContent) {
                    $opf = simplexml_load_string($opfContent);
                    if ($opf) {
                        // Look for cover in metadata
                        foreach ($opf->metadata->meta as $meta) {
                            if ((string)$meta['name'] === 'cover' && isset($meta['content'])) {
                                $coverId = (string)$meta['content'];
                                
                                // Find the item with this ID
                                foreach ($opf->manifest->item as $item) {
                                    if ((string)$item['id'] === $coverId) {
                                        $coverPath = dirname($opfPath) . '/' . (string)$item['href'];
                                        return $coverPath;
                                    }
                                }
                            }
                        }
                        
                        // Alternative: look for items with properties="cover-image"
                        foreach ($opf->manifest->item as $item) {
                            if ((string)$item['properties'] === 'cover-image') {
                                $coverPath = dirname($opfPath) . '/' . (string)$item['href'];
                                return $coverPath;
                            }
                        }
                    }
                }
            }
        }
        
        // Strategy 2: Common cover image filenames
        $commonCoverNames = [
            'cover.jpg', 'cover.jpeg', 'cover.png',
            'Cover.jpg', 'Cover.jpeg', 'Cover.png',
            'images/cover.jpg', 'images/cover.jpeg', 'images/cover.png',
            'OEBPS/images/cover.jpg', 'OEBPS/images/cover.jpeg', 'OEBPS/images/cover.png'
        ];
        
        foreach ($commonCoverNames as $coverName) {
            if ($zip->locateName($coverName) !== false) {
                return $coverName;
            }
        }
        
        return null;
    }

    private function createThumbnail($imageContent) {
        try {
            $image = imagecreatefromstring($imageContent);
            if (!$image) {
                return null;
            }
            
            $width = imagesx($image);
            $height = imagesy($image);
            
            // Calculate thumbnail dimensions (max 200x300, maintain aspect ratio)
            $maxWidth = 200;
            $maxHeight = 300;
            
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $thumbWidth = intval($width * $ratio);
            $thumbHeight = intval($height * $ratio);
            
            $thumbnail = imagecreatetruecolor($thumbWidth, $thumbHeight);
            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
            
            ob_start();
            imagejpeg($thumbnail, null, 85);
            $thumbnailContent = ob_get_clean();
            
            imagedestroy($image);
            imagedestroy($thumbnail);
            
            return $thumbnailContent;
            
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractCbrThumbnail($file) {
        try {
            // Read the CBR file content
            $content = $file->getContent();
            
            // Create temporary file to work with Archive library
            $tempFile = tempnam(sys_get_temp_dir(), 'cbr_thumb_');
            file_put_contents($tempFile, $content);
            
            $archive = \Kiwilan\Archive\Archive::make($tempFile);
            if (!$archive) {
                unlink($tempFile);
                return new DataResponse(['error' => 'Could not read CBR file'], 500);
            }
            
            // Get all files from the archive and find the first image
            $files = $archive->getFiles();
            $firstImage = null;
            
            foreach ($files as $archiveFile) {
                $filename = $archiveFile->getName();
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                // Look for image files (cover is usually first alphabetically)
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
                    $firstImage = $archiveFile;
                    break;
                }
            }
            
            if ($firstImage) {
                $imageContent = $firstImage->getContent();
                
                if ($imageContent) {
                    // Create thumbnail
                    $thumbnail = $this->createThumbnail($imageContent);
                    
                    if ($thumbnail) {
                        // Write thumbnail to temporary file and stream it
                        $tempThumb = tempnam(sys_get_temp_dir(), 'cbr_thumb_');
                        file_put_contents($tempThumb, $thumbnail);
                        
                        $response = new StreamResponse(fopen($tempThumb, 'r'));
                        $response->addHeader('Content-Type', 'image/jpeg');
                        $response->addHeader('Content-Length', strlen($thumbnail));
                        
                        // Clean up temp files after response
                        register_shutdown_function(function() use ($tempFile, $tempThumb) {
                            if (file_exists($tempFile)) {
                                unlink($tempFile);
                            }
                            if (file_exists($tempThumb)) {
                                unlink($tempThumb);
                            }
                        });
                        
                        return $response;
                    }
                }
            }
            
            unlink($tempFile);
            return new DataResponse(['message' => 'No cover image found'], 404);
            
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'CBR thumbnail extraction failed: ' . $e->getMessage()], 500);
        }
    }

    private function parseEpubOPF($zip) {
        try {
            // Find the OPF file location from container.xml
            $containerXml = $zip->getFromName('META-INF/container.xml');
            if (!$containerXml) {
                return null;
            }
            
            $container = simplexml_load_string($containerXml);
            if (!$container) {
                return null;
            }
            
            $opfPath = (string)$container->rootfiles->rootfile['full-path'];
            if (!$opfPath) {
                return null;
            }
            
            // Parse the OPF file
            $opfContent = $zip->getFromName($opfPath);
            if (!$opfContent) {
                return null;
            }
            
            $opf = simplexml_load_string($opfContent);
            if (!$opf) {
                return null;
            }
            
            // Register namespaces
            $opf->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $opf->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');
            
            $metadata = [];
            
            // Extract title
            $titles = $opf->xpath('//dc:title');
            if (!empty($titles)) {
                $metadata['title'] = (string)$titles[0];
            }
            
            // Extract author(s)
            $authors = $opf->xpath('//dc:creator[@opf:role="aut"] | //dc:creator[not(@opf:role)] | //dc:creator');
            if (!empty($authors)) {
                $authorList = [];
                foreach ($authors as $author) {
                    $authorList[] = (string)$author;
                }
                $metadata['author'] = implode(', ', $authorList);
            }
            
            // Extract description
            $descriptions = $opf->xpath('//dc:description');
            if (!empty($descriptions)) {
                $metadata['description'] = (string)$descriptions[0];
            }
            
            // Extract language
            $languages = $opf->xpath('//dc:language');
            if (!empty($languages)) {
                $metadata['language'] = (string)$languages[0];
            }
            
            // Extract publisher
            $publishers = $opf->xpath('//dc:publisher');
            if (!empty($publishers)) {
                $metadata['publisher'] = (string)$publishers[0];
            }
            
            // Extract subject/genre
            $subjects = $opf->xpath('//dc:subject');
            if (!empty($subjects)) {
                $subjectList = [];
                foreach ($subjects as $subject) {
                    $subjectList[] = (string)$subject;
                }
                $metadata['subject'] = implode(', ', $subjectList);
            }
            
            // Extract date (extract year only)
            $dates = $opf->xpath('//dc:date');
            if (!empty($dates)) {
                $fullDate = (string)$dates[0];
                // Extract year from various date formats
                if (preg_match('/(\d{4})/', $fullDate, $matches)) {
                    $metadata['publication_date'] = $this->parsePublicationDate($matches[1]);
                } else {
                    $metadata['publication_date'] = $this->parsePublicationDate($fullDate); // fallback to original if no year found
                }
            }
            
            // Extract identifier (ISBN, etc.)
            $identifiers = $opf->xpath('//dc:identifier[@opf:scheme="ISBN"] | //dc:identifier');
            if (!empty($identifiers)) {
                $metadata['identifier'] = (string)$identifiers[0];
            }
            
            return $metadata;
            
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getMimeType($format) {
        $mimeTypes = [
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'cbr' => 'application/vnd.comicbook-rar',
            'mobi' => 'application/x-mobipocket-ebook',
            'txt' => 'text/plain'
        ];
        
        return $mimeTypes[$format] ?? 'application/octet-stream';
    }

    /**
     * Parse various date formats into YYYY-MM-DD format for publication_date field
     */
    private function parsePublicationDate(?string $dateValue): ?string {
        if (empty($dateValue)) {
            return null;
        }

        $dateValue = trim($dateValue);

        // Handle 4-digit year format (most common case)
        if (preg_match('/^\d{4}$/', $dateValue)) {
            return $dateValue . '-01-01'; // Default to January 1st
        }

        // Handle YYYY-MM format
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            return "$year-$month-01"; // Default to 1st of month
        }

        // Handle YYYY-MM-DD format (already correct)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            $day = sprintf('%02d', intval($matches[3]));
            
            // Validate the date
            if (checkdate(intval($month), intval($day), intval($year))) {
                return "$year-$month-$day";
            }
        }

        // Extract year from any string containing a 4-digit year
        if (preg_match('/(\d{4})/', $dateValue, $matches)) {
            $year = $matches[1];
            // Validate year range
            if (intval($year) >= 1000 && intval($year) <= 2099) {
                return "$year-01-01"; // Default to January 1st
            }
        }

        // Could not parse the date
        return null;
    }

    /**
     * Remove book metadata from database
     */
    public function removeBookFromDatabase(Node $file) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return;
        }

        $userId = $user->getUID();
        $fileId = $file->getId();
        
        // Get metadata ID before deletion for cleanup
        $metadataId = $this->getMetadataId($userId, $fileId);
        
        if ($metadataId) {
            // Clean up all related records
            $this->cleanupBookReferences($metadataId, $userId);
        }

        // Clean up file tracking
        $this->fileTrackingService->removeFileTracking($fileId, $userId);

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->executeStatement();
            
            error_log("eBooks app: Successfully removed book metadata and related records for file: " . $file->getPath());
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to remove metadata for file ' . $file->getPath() . ': ' . $e->getMessage());
        }
    }

    /**
     * Get metadata ID for a user's file
     */
    private function getMetadataId(string $userId, int $fileId): ?int {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
                ->executeQuery();

            $metadataId = $result->fetchOne();
            $result->closeCursor();

            return $metadataId ? (int)$metadataId : null;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to retrieve metadata ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up all related records when a book is deleted
     */
    public function cleanupBookReferences(int $metadataId, string $userId) {
        try {
            // Begin transaction to ensure atomic cleanup
            $this->db->beginTransaction();

            // Step 1: Get all document hashes for this book from hash mappings
            $documentHashes = $this->getDocumentHashesForBook($metadataId, $userId);
            
            if (!empty($documentHashes)) {
                // Step 2: Remove sync progress for all hashes of this book
                $this->removeSyncProgressForHashes($documentHashes, $userId);
                
                error_log("eBooks app: Removed sync progress for " . count($documentHashes) . " document hashes for metadata_id $metadataId");
            }

            // Step 3: Remove all hash mappings for this book
            $removedMappings = $this->removeHashMappings($metadataId, $userId);
            
            if ($removedMappings > 0) {
                error_log("eBooks app: Removed $removedMappings hash mappings for metadata_id $metadataId");
            }

            $this->db->commit();
            
            error_log("eBooks app: Successfully cleaned up all references for book metadata_id $metadataId (user: $userId)");
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('eBooks app: Failed to cleanup book references for metadata_id ' . $metadataId . ': ' . $e->getMessage());
        }
    }

    /**
     * Clean up orphaned metadata entries (files that no longer exist in filesystem)
     */
    private function cleanupOrphanedMetadata(string $userId): int {
        try {
            $cleanedCount = 0;
            $userFolder = $this->rootFolder->getUserFolder($userId);

            // Get all metadata entries for the user
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id', 'file_id')
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            while ($row = $result->fetch()) {
                $metadataId = $row['id'];
                $fileId = $row['file_id'];

                // Check if file still exists in filesystem
                $files = $userFolder->getById($fileId);
                if (empty($files)) {
                    // File no longer exists, clean up all references
                    $this->cleanupBookReferences($metadataId, $userId);

                    // Remove the metadata entry itself
                    $deleteQb = $this->db->getQueryBuilder();
                    $deleteQb->delete('koreader_metadata')
                        ->where($deleteQb->expr()->eq('id', $deleteQb->createNamedParameter($metadataId)))
                        ->executeStatement();

                    $cleanedCount++;
                    error_log("eBooks app: Cleaned up orphaned metadata entry for file_id $fileId (metadata_id $metadataId)");
                }
            }
            $result->closeCursor();

            if ($cleanedCount > 0) {
                error_log("eBooks app: Cleaned up $cleanedCount orphaned metadata entries for user $userId");
            }

            return $cleanedCount;

        } catch (\Exception $e) {
            error_log('eBooks app: Failed to cleanup orphaned metadata for user ' . $userId . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get all document hashes associated with a book
     */
    private function getDocumentHashesForBook(int $metadataId, string $userId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('document_hash')
                ->from('koreader_hash_mapping')
                ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            $hashes = [];
            while ($row = $result->fetch()) {
                $hashes[] = $row['document_hash'];
            }
            $result->closeCursor();

            return $hashes;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get document hashes for metadata_id ' . $metadataId . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Remove sync progress for multiple document hashes
     */
    private function removeSyncProgressForHashes(array $documentHashes, string $userId): int {
        if (empty($documentHashes)) {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_sync_progress')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->in('document_hash', $qb->createNamedParameter($documentHashes, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to remove sync progress for hashes: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove all hash mappings for a book
     */
    private function removeHashMappings(int $metadataId, string $userId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_hash_mapping')
               ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to remove hash mappings for metadata_id ' . $metadataId . ': ' . $e->getMessage());
            return 0;
        }
    }

    // ====================== FACETED BROWSING METHODS ======================

    /**
     * Get authors with book counts for faceted browsing
     */
    public function getAuthors($page = 1, $perPage = 50) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['author', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('author'))
               ->andWhere($qb->expr()->neq('author', $qb->createNamedParameter('')))
               ->groupBy('author')
               ->orderBy('author', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get authors: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of unique authors
     */
    public function getAuthorsCount() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $subQuery = $this->db->getQueryBuilder();
            
            $subQuery->selectDistinct('author')
                     ->from('koreader_metadata')
                     ->where($subQuery->expr()->eq('user_id', $subQuery->createNamedParameter($userId)))
                     ->andWhere($subQuery->expr()->isNotNull('author'))
                     ->andWhere($subQuery->expr()->neq('author', $subQuery->createNamedParameter('')));
            
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('(' . $subQuery->getSQL() . ')', 'authors_count');
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get authors count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get books by specific author with pagination
     */
    public function getBooksByAuthor($author, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('author', $qb->createNamedParameter($author)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);

            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by author: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get count of books by specific author
     */
    public function getBooksByAuthorCount($author) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('author', $qb->createNamedParameter($author)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by author count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get series with book counts for faceted browsing
     */
    public function getSeries($page = 1, $perPage = 50) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['series', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('series'))
               ->andWhere($qb->expr()->neq('series', $qb->createNamedParameter('')))
               ->groupBy('series')
               ->orderBy('series', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get series: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of unique series
     */
    public function getSeriesCount() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $subQuery = $this->db->getQueryBuilder();
            
            $subQuery->selectDistinct('series')
                     ->from('koreader_metadata')
                     ->where($subQuery->expr()->eq('user_id', $subQuery->createNamedParameter($userId)))
                     ->andWhere($subQuery->expr()->isNotNull('series'))
                     ->andWhere($subQuery->expr()->neq('series', $subQuery->createNamedParameter('')));
            
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('(' . $subQuery->getSQL() . ')', 'series_count');
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get series count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get books by specific series with pagination (ordered by series_index)
     */
    public function getBooksBySeries($seriesName, $page = 1, $perPage = 20) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('series', $qb->createNamedParameter($seriesName)))
               ->orderBy('series_index', 'ASC')
               ->addOrderBy('title', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by series: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get count of books by specific series
     */
    public function getBooksBySeriesCount($seriesName) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('series', $qb->createNamedParameter($seriesName)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by series count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get genres/subjects with book counts for faceted browsing
     */
    public function getGenres($page = 1, $perPage = 50) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['subject', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('subject'))
               ->andWhere($qb->expr()->neq('subject', $qb->createNamedParameter('')))
               ->groupBy('subject')
               ->orderBy('subject', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get genres: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of unique genres/subjects
     */
    public function getGenresCount() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $subQuery = $this->db->getQueryBuilder();
            
            $subQuery->selectDistinct('subject')
                     ->from('koreader_metadata')
                     ->where($subQuery->expr()->eq('user_id', $subQuery->createNamedParameter($userId)))
                     ->andWhere($subQuery->expr()->isNotNull('subject'))
                     ->andWhere($subQuery->expr()->neq('subject', $subQuery->createNamedParameter('')));
            
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('(' . $subQuery->getSQL() . ')', 'genres_count');
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get genres count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get books by specific genre/subject with pagination
     */
    public function getBooksByGenre($genre, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($genre)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'author':
                    $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by genre: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get count of books by specific genre/subject
     */
    public function getBooksByGenreCount($genre) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($genre)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by genre count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get formats with book counts for faceted browsing
     */
    public function getFormats($page = 1, $perPage = 50) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['file_format', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('file_format'))
               ->andWhere($qb->expr()->neq('file_format', $qb->createNamedParameter('')))
               ->groupBy('file_format')
               ->orderBy('file_format', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get formats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of unique formats
     */
    public function getFormatsCount() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $subQuery = $this->db->getQueryBuilder();
            
            $subQuery->selectDistinct('file_format')
                     ->from('koreader_metadata')
                     ->where($subQuery->expr()->eq('user_id', $subQuery->createNamedParameter($userId)))
                     ->andWhere($subQuery->expr()->isNotNull('file_format'))
                     ->andWhere($subQuery->expr()->neq('file_format', $subQuery->createNamedParameter('')));
            
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('(' . $subQuery->getSQL() . ')', 'formats_count');
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get formats count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get books by specific format with pagination
     */
    public function getBooksByFormat($format, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_format', $qb->createNamedParameter($format)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'author':
                    $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by format: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get count of books by specific format
     */
    public function getBooksByFormatCount($format) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_format', $qb->createNamedParameter($format)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by format count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get languages with book counts for faceted browsing
     */
    public function getLanguages($page = 1, $perPage = 50) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(['language', $qb->func()->count('*', 'book_count')])
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('language'))
               ->andWhere($qb->expr()->neq('language', $qb->createNamedParameter('')))
               ->groupBy('language')
               ->orderBy('language', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            return $qb->executeQuery()->fetchAll();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get languages: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total count of unique languages
     */
    public function getLanguagesCount() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $subQuery = $this->db->getQueryBuilder();
            
            $subQuery->selectDistinct('language')
                     ->from('koreader_metadata')
                     ->where($subQuery->expr()->eq('user_id', $subQuery->createNamedParameter($userId)))
                     ->andWhere($subQuery->expr()->isNotNull('language'))
                     ->andWhere($subQuery->expr()->neq('language', $subQuery->createNamedParameter('')));
            
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('(' . $subQuery->getSQL() . ')', 'languages_count');
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get languages count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get books by specific language with pagination
     */
    public function getBooksByLanguage($language, $page = 1, $perPage = 20, $sort = 'title') {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }

        $userId = $user->getUID();
        $offset = ($page - 1) * $perPage;
        
        // Ensure metadata is up to date
        $this->ensureMetadataUpToDate($userId);
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)))
               ->setFirstResult($offset)
               ->setMaxResults($perPage);
            
            // Apply sorting
            switch ($sort) {
                case 'recent':
                    $qb->orderBy('created_at', 'DESC');
                    break;
                case 'author':
                    $qb->orderBy('author', 'ASC')->addOrderBy('title', 'ASC');
                    break;
                case 'publication_date':
                    $qb->orderBy('publication_date', 'DESC');
                    break;
                case 'title':
                default:
                    $qb->orderBy('title', 'ASC');
                    break;
            }
            
            $result = $qb->executeQuery();
            $books = [];
            
            while ($row = $result->fetch()) {
                $book = $this->convertDatabaseRowToBookArray($row, $userId);
                if ($book !== null) {
                    $books[] = $book;
                }
            }
            
            $result->closeCursor();
            return $books;
            
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by language: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get count of books by specific language
     */
    public function getBooksByLanguageCount($language) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }

        $userId = $user->getUID();
        
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total_count'))
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));
            
            return (int)$qb->executeQuery()->fetchOne();
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to get books by language count: ' . $e->getMessage());
            return 0;
        }
    }
}