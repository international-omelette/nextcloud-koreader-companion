<?php
namespace OCA\KoreaderCompanion\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\DataResponse;
use Psr\Log\LoggerInterface;

class BookService {

    private $rootFolder;
    private $config;
    private $userSession;
    private $db;
    private $pdfExtractor;
    private DocumentHashGenerator $hashGenerator;
    private LoggerInterface $logger;

    public function __construct(
        IRootFolder $rootFolder,
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $db,
        PdfMetadataExtractor $pdfExtractor,
        DocumentHashGenerator $hashGenerator,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->userSession = $userSession;
        $this->db = $db;
        $this->pdfExtractor = $pdfExtractor;
        $this->hashGenerator = $hashGenerator;
        $this->logger = $logger;
    }


    /**
     * Get paginated books from database with optional sorting
     */
    public function getBooks($page = 1, $perPage = 20, $sort = 'title') {
        return $this->getPaginatedBooks($page, $perPage, $sort);
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
            $this->logger->error('Failed to get paginated books', [
                'exception' => $e
            ]);
            return [];
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
            $this->logger->error('Failed to get total book count', [
                'exception' => $e
            ]);
            return 0;
        }
    }


    // ====================== BACKGROUND INDEXING ======================

    public const MAX_INDEX_ATTEMPTS = 3;
    public const RECONCILE_MAX_EXTRACTIONS = 100;
    public const RECONCILE_MAX_SECONDS = 30;

    /**
     * Index a single file. Takes an explicit userId so it can run without a
     * user session (background job / occ command).
     *
     * Returns true when metadata extraction actually ran for a changed/new file.
     */
    public function indexFile(int $fileId, string $userId, ?Node $node = null, ?array $preloadedRow = null, ?bool $hasHashes = null): bool {
        if ($node === null) {
            try {
                $files = $this->rootFolder->getUserFolder($userId)->getById($fileId);
            } catch (\Exception $e) {
                return false;
            }
            if (empty($files)) {
                return false;
            }
            $node = $files[0];
        }

        try {
            $row = $preloadedRow ?? $this->getMetadataRow($userId, $node->getId());
            return $this->indexNode($node, $userId, $row, $hasHashes);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to index file', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'exception' => $e
            ]);
            return false;
        }
    }

    private function indexNode(Node $file, string $userId, ?array $row, ?bool $hasHashes): bool {
        $fileMTime = $file->getMTime();
        $attempts = $row ? (int)$row['attempts'] : 0;
        $status = $row['status'] ?? null;

        if ($row !== null) {
            if ($status === 'ok') {
                $fileChanged = $fileMTime > (new \DateTime($row['updated_at']))->getTimestamp();
                if (!$fileChanged) {
                    // Hash-only repair: never re-extract an unchanged file just
                    // because hash generation failed earlier.
                    if ($hasHashes === null) {
                        $hasHashes = $this->hasHashMappings((int)$row['id']);
                    }
                    if (!$hasHashes) {
                        $this->createHashMappingsForFile($file, $userId, (int)$row['id']);
                    }
                    return false;
                }
                $nextAttempts = 1;
            } elseif ($status === 'failed') {
                $failedAt = $row['failed_at'] ? (new \DateTime($row['failed_at']))->getTimestamp() : 0;
                if ($fileMTime <= $failedAt) {
                    return false;
                }
                $nextAttempts = 1;
            } elseif ($status === 'processing') {
                // A file replaced while stuck at the cap must be able to recover.
                $lastAttempt = (new \DateTime($row['updated_at']))->getTimestamp();
                if ($fileMTime > $lastAttempt) {
                    $nextAttempts = 1;
                } elseif ($attempts >= self::MAX_INDEX_ATTEMPTS) {
                    $this->markMetadataFailed((int)$row['id']);
                    return false;
                } else {
                    $nextAttempts = $attempts + 1;
                }
            } else {
                // pending: fresh upload, always the first attempt
                $nextAttempts = 1;
            }
        } else {
            $nextAttempts = 1;
        }

        // Commit the fatal-safe marker BEFORE extraction. A PHP fatal error
        // leaves the row in `processing` so the reconciler can retry it.
        if ($row !== null) {
            $metadataId = (int)$row['id'];
            $storedMetadata = $row;
            $this->setMetadataProcessing($metadataId, $nextAttempts);
        } else {
            $metadataId = $this->insertPlaceholder($file, $userId, $nextAttempts);
            if ($metadataId === null) {
                // Lost an insert race with another worker; let it finish.
                $row = $this->getMetadataRow($userId, $file->getId());
                if ($row === null) {
                    return false;
                }
                return $this->indexNode($file, $userId, $row, null);
            }
            $storedMetadata = null;
        }

        // Hash before extraction: sample-based, never reads the whole file.
        $this->createHashMappingsForFile($file, $userId, $metadataId);

        try {
            $metadata = $this->extractMetadata($file, $storedMetadata);
        } catch (\Throwable $e) {
            // Leave `processing`; the next run retries until MAX_INDEX_ATTEMPTS.
            // Count the attempt against the reconcile budget.
            $this->logger->error('Metadata extraction failed', [
                'file_id' => $file->getId(),
                'user_id' => $userId,
                'exception' => $e
            ]);
            return true;
        }

        $this->storeExtractedMetadata($file, $metadataId, $metadata);
        return true;
    }

    /**
     * Reconcile a user's library with the filesystem.
     *
     * Budget is counted in extractions (not visited files) plus wall-clock
     * seconds, so a large library makes progress across runs. Returns
     * ['visited','attempted','orphans','complete']; `complete=false` means the
     * caller should schedule another run.
     */
    public function reconcileUser(string $userId, ?int $maxExtractions = null, ?int $maxSeconds = null): array {
        $stats = ['visited' => 0, 'attempted' => 0, 'orphans' => 0, 'complete' => false];

        $folderName = $this->config->getUserValue($userId, 'koreader_companion', 'folder', 'eBooks');
        try {
            $booksFolder = $this->rootFolder->getUserFolder($userId)->get($folderName);
        } catch (\Exception $e) {
            $stats['complete'] = true;
            return $stats;
        }

        if ($booksFolder->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
            $stats['complete'] = true;
            return $stats;
        }

        $existingMetadata = $this->loadExistingMetadata($userId);
        $metadataWithHashes = $this->loadMetadataIdsWithHashes($userId);
        $start = microtime(true);

        foreach ($this->collectEbookFiles($booksFolder) as $file) {
            if ($maxExtractions !== null && $stats['attempted'] >= $maxExtractions) {
                return $stats;
            }
            if ($maxSeconds !== null && (microtime(true) - $start) >= $maxSeconds) {
                return $stats;
            }

            $stats['visited']++;
            $fileId = $file->getId();
            $row = $existingMetadata[$fileId] ?? null;
            $hasHashes = $row !== null ? isset($metadataWithHashes[(int)$row['id']]) : null;

            if ($this->indexFile($fileId, $userId, $file, $row, $hasHashes)) {
                $stats['attempted']++;
            }
        }

        $stats['complete'] = true;
        $stats['orphans'] = $this->cleanupOrphanedMetadata($userId);
        return $stats;
    }

    private function collectEbookFiles(Node $folder): \Generator {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                yield from $this->collectEbookFiles($node);
            } else {
                $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
                if (in_array($extension, ['epub', 'pdf', 'cbr', 'mobi'])) {
                    yield $node;
                }
            }
        }
    }

    private function getMetadataRow(string $userId, int $fileId): ?array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('koreader_metadata')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ?: null;
    }

    private function insertPlaceholder(Node $file, string $userId, int $attempts): ?int {
        $now = date('Y-m-d H:i:s');
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('koreader_metadata')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'file_id' => $qb->createNamedParameter($file->getId()),
                    'file_path' => $qb->createNamedParameter($file->getPath()),
                    'file_format' => $qb->createNamedParameter(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION))),
                    'status' => $qb->createNamedParameter('processing'),
                    'attempts' => $qb->createNamedParameter($attempts),
                    'created_at' => $qb->createNamedParameter($now),
                    'updated_at' => $qb->createNamedParameter($now)
                ])
                ->executeStatement();
            return (int)$qb->getLastInsertId();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $this->logger->error('Failed to insert metadata placeholder', ['exception' => $e]);
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to insert metadata placeholder', ['exception' => $e]);
            return null;
        }
    }

    private function setMetadataProcessing(int $metadataId, int $attempts): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('koreader_metadata')
            ->set('status', $qb->createNamedParameter('processing'))
            ->set('attempts', $qb->createNamedParameter($attempts))
            ->set('failed_at', $qb->createNamedParameter(null))
            ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($metadataId)))
            ->executeStatement();
    }

    private function markMetadataFailed(int $metadataId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('koreader_metadata')
            ->set('status', $qb->createNamedParameter('failed'))
            ->set('failed_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($metadataId)))
            ->executeStatement();
    }

    private function storeExtractedMetadata(Node $file, int $metadataId, array $metadata): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('koreader_metadata')
            ->set('file_path', $qb->createNamedParameter($file->getPath()))
            ->set('title', $qb->createNamedParameter($metadata['title'] ?: null))
            ->set('author', $qb->createNamedParameter($metadata['author'] ?: null))
            ->set('description', $qb->createNamedParameter($metadata['description'] ?: null))
            ->set('publisher', $qb->createNamedParameter($metadata['publisher'] ?: null))
            ->set('publication_date', $qb->createNamedParameter($metadata['publication_date'] ?: null))
            ->set('language', $qb->createNamedParameter($metadata['language'] ?: null))
            ->set('series', $qb->createNamedParameter($metadata['series'] ?: null))
            ->set('series_index', $qb->createNamedParameter($metadata['issue'] ? floatval($metadata['issue']) : null))
            ->set('subject', $qb->createNamedParameter($metadata['subject'] ?: null))
            ->set('tags', $qb->createNamedParameter($metadata['tags'] ?: null))
            ->set('file_format', $qb->createNamedParameter($metadata['format'] ?: null))
            ->set('issue', $qb->createNamedParameter($metadata['issue'] ?: null))
            ->set('volume', $qb->createNamedParameter($metadata['volume'] ?: null))
            ->set('status', $qb->createNamedParameter('ok'))
            ->set('failed_at', $qb->createNamedParameter(null))
            ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($metadataId)))
            ->executeStatement();
    }

    private function hasHashMappings(int $metadataId): bool {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*'))
            ->from('koreader_hash_mapping')
            ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count > 0;
    }

    private function loadExistingMetadata(string $userId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select(
                'id', 'file_id', 'updated_at', 'status', 'attempts', 'failed_at',
                'title', 'author', 'description', 'language', 'publisher', 'publication_date',
                'subject', 'series', 'issue', 'volume', 'tags'
            )
                ->from('koreader_metadata')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            $metadata = [];
            while ($row = $result->fetch()) {
                $metadata[$row['file_id']] = $row;
            }
            $result->closeCursor();

            return $metadata;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load existing metadata', [
                'exception' => $e
            ]);
            return [];
        }
    }

    private function loadMetadataIdsWithHashes(string $userId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->selectDistinct('metadata_id')
                ->from('koreader_hash_mapping')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->executeQuery();

            $ids = [];
            while ($row = $result->fetch()) {
                $ids[(int)$row['metadata_id']] = true;
            }
            $result->closeCursor();
            return $ids;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load metadata ids with hashes', ['exception' => $e]);
            return [];
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
                'tags' => $row['tags'] ?? '',
                'status' => $row['status'] ?? 'ok',
                'failed_at' => $row['failed_at'] ?? null
            ];
            
            // Add sync progress if available
            $this->addSyncProgressToMetadata($file, $book);

            return $book;

        } catch (\Exception $e) {
            $this->logger->error('Failed to convert database row to book array', [
                'exception' => $e
            ]);
            return null;
        }
    }

    private function extractMetadata(Node $file, ?array $storedMetadata = null): array {
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

        // Always parse the file. Retries of a placeholder row must re-parse it.
        if ($extension === 'epub') {
            $this->extractEpubMetadata($file, $metadata);
        } elseif ($extension === 'pdf') {
            $this->extractPdfMetadata($file, $metadata);
        } elseif ($extension === 'cbr') {
            // Only process CBR if Archive class is available
            if (class_exists('Kiwilan\Archive\Archive')) {
                $this->extractCbrMetadata($file, $metadata);
            } else {
                $metadata['format'] = 'cbr';
            }
        } elseif ($extension === 'mobi') {
            $this->extractMobiMetadata($file, $metadata);
        }

        // User-edited / stored values take precedence over parsed values.
        if ($storedMetadata) {
            $fieldsToOverride = ['title', 'author', 'description', 'language', 'publisher', 'publication_date', 'subject', 'series', 'issue', 'volume', 'tags'];
            foreach ($fieldsToOverride as $field) {
                if (!empty($storedMetadata[$field])) {
                    $metadata[$field] = $storedMetadata[$field];
                }
            }
        }

        return $metadata;
    }

    /**
     * Extract metadata for display and editing (used in upload workflow)
     * Returns raw extracted metadata without progress information
     */
    public function extractMetadataForUpload(Node $file): array {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

        $metadata = [
            'title' => pathinfo($file->getName(), PATHINFO_FILENAME),
            'author' => '',
            'description' => '',
            'language' => '',
            'publisher' => '',
            'publication_date' => '',
            'subject' => '',
            'series' => '',
            'issue' => '',
            'volume' => '',
            'tags' => '',
            'format' => $extension
        ];

        try {
            // Extract metadata from file based on format
            if ($extension === 'epub') {
                $this->extractEpubMetadata($file, $metadata);
            } elseif ($extension === 'pdf') {
                $this->extractPdfMetadata($file, $metadata);
            } elseif ($extension === 'cbr') {
                if (class_exists('Kiwilan\Archive\Archive')) {
                    $this->extractCbrMetadata($file, $metadata);
                } else {
                    // Fallback: basic filename metadata for CBR
                    $filename = pathinfo($file->getName(), PATHINFO_FILENAME);
                    $metadata['title'] = $filename;
                }
            } elseif ($extension === 'mobi') {
                $this->extractMobiMetadata($file, $metadata);
            }
        } catch (\Exception $e) {
            // If extraction fails, keep the filename-based defaults
            $this->logger->error('Metadata extraction failed', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
        }

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
            $this->logger->error('Failed to add sync progress for file', [
                'file_path' => $file->getPath(),
                'exception' => $e
            ]);
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
            $this->logger->error('Failed to get document hashes for file', [
                'file_id' => $fileId,
                'exception' => $e
            ]);
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
            $this->logger->error('Failed to get sync progress for hashes', ['exception' => $e]);
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

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

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
            $this->logger->error('CBR metadata extraction failed', ['exception' => $e]);
        }
    }

    private function extractComicInfoMetadata(Node $file, &$metadata) {
        try {
            $content = $file->getContent();
            $tempFile = tempnam(sys_get_temp_dir(), 'cbr_info_');
            file_put_contents($tempFile, $content);
            
            $archive = \Kiwilan\Archive\Archive::make($tempFile);
            if (!$archive) {
                if (file_exists($tempFile)) { unlink($tempFile); }
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
            
            if (file_exists($tempFile)) { unlink($tempFile); }
            
            if ($comicInfoXml) {
                $this->parseComicInfoXml($comicInfoXml, $metadata);
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('ComicInfo.xml extraction failed', ['exception' => $e]);
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
            $this->logger->error('ComicInfo.xml parsing failed', ['exception' => $e]);
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
            $this->logger->error('MOBI metadata extraction failed', ['exception' => $e]);
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
            $this->logger->error('MOBI header reading failed', ['exception' => $e]);
        }
    }

    public function searchBooks($query, $page = 1, $perPage = 20) {
        return $this->getPaginatedSearchResults($query, $page, $perPage);
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
            $this->logger->error('Failed to get paginated search results', ['exception' => $e]);
            return [];
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
            $this->logger->error('Failed to get search result count', ['exception' => $e]);
            return 0;
        }
    }



    public function getBookById($id) {
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
               ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter((int)$id)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row) {
                return $this->convertDatabaseRowToBookArray($row, $user->getUID());
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to get book by ID', [
                'id' => $id,
                'exception' => $e
            ]);
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
                if (file_exists($tempFile)) { unlink($tempFile); }
                return new DataResponse(['error' => 'Could not read EPUB file'], 500);
            }
            
            // Find cover image
            $coverImage = $this->findEpubCover($zip);
            
            if ($coverImage) {
                $imageContent = $zip->getFromName($coverImage);
                $zip->close();
                if (file_exists($tempFile)) { unlink($tempFile); }
                
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
                                if (file_exists($tempThumb)) { unlink($tempThumb); }
                            }
                        });
                        
                        return $response;
                    }
                }
            }
            
            $zip->close();
            if (file_exists($tempFile)) { unlink($tempFile); }
            
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
                if (file_exists($tempFile)) { unlink($tempFile); }
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
                                if (file_exists($tempFile)) { unlink($tempFile); }
                            }
                            if (file_exists($tempThumb)) {
                                if (file_exists($tempThumb)) { unlink($tempThumb); }
                            }
                        });
                        
                        return $response;
                    }
                }
            }
            
            if (file_exists($tempFile)) { unlink($tempFile); }
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

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->executeStatement();
            
            $this->logger->info('Successfully removed book metadata and related records', ['file_path' => $file->getPath()]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove metadata for file', ['file_path' => $file->getPath(), 'exception' => $e]);
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
            $this->logger->error('Failed to retrieve metadata ID', ['exception' => $e]);
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
                
                $this->logger->info('Removed sync progress for document hashes', ['hash_count' => count($documentHashes), 'metadata_id' => $metadataId]);
            }

            // Step 3: Remove all hash mappings for this book
            $removedMappings = $this->removeHashMappings($metadataId, $userId);
            
            if ($removedMappings > 0) {
                $this->logger->info('Removed hash mappings', ['mappings_removed' => $removedMappings, 'metadata_id' => $metadataId]);
            }

            $this->db->commit();
            
            $this->logger->info('Successfully cleaned up all references for book', ['metadata_id' => $metadataId, 'user' => $userId]);
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to cleanup book references', ['metadata_id' => $metadataId, 'exception' => $e]);
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
                    $this->logger->info('Cleaned up orphaned metadata entry', ['file_id' => $fileId, 'metadata_id' => $metadataId]);
                }
            }
            $result->closeCursor();

            if ($cleanedCount > 0) {
                $this->logger->info('Cleaned up orphaned metadata entries', ['count' => $cleanedCount, 'user' => $userId]);
            }

            return $cleanedCount;

        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup orphaned metadata', ['user' => $userId, 'exception' => $e]);
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
            $this->logger->error('Failed to get document hashes', ['metadata_id' => $metadataId, 'exception' => $e]);
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
            $this->logger->error('Failed to remove sync progress for hashes', ['exception' => $e]);
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
            $this->logger->error('Failed to remove hash mappings', ['metadata_id' => $metadataId, 'exception' => $e]);
            return 0;
        }
    }

    /**
     * Create hash mappings for a file
     * Generates both binary and filename hashes and stores them in koreader_hash_mapping table
     */
    private function createHashMappingsForFile(Node $file, string $userId, int $metadataId): void {
        try {
            // Generate document hashes
            $hashes = $this->hashGenerator->generateDocumentHashesFromNode($file);
            $binaryHash = $hashes['binary_hash'] ?? null;
            $filenameHash = $hashes['filename_hash'] ?? null;

            if (!$binaryHash && !$filenameHash) {
                $this->logger->warning('Failed to generate hashes for file', [
                    'file_path' => $file->getPath(),
                    'user_id' => $userId,
                    'metadata_id' => $metadataId
                ]);
                return;
            }

            // Remove existing hash mappings for this metadata record
            $this->removeHashMappings($metadataId, $userId);

            $now = date('Y-m-d H:i:s');

            // Insert binary hash mapping
            if ($binaryHash) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('koreader_hash_mapping')
                    ->values([
                        'user_id' => $qb->createNamedParameter($userId),
                        'document_hash' => $qb->createNamedParameter($binaryHash),
                        'hash_type' => $qb->createNamedParameter('binary'),
                        'metadata_id' => $qb->createNamedParameter($metadataId),
                        'created_at' => $qb->createNamedParameter($now)
                    ])
                    ->executeStatement();
            }

            // Insert filename hash mapping
            if ($filenameHash) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('koreader_hash_mapping')
                    ->values([
                        'user_id' => $qb->createNamedParameter($userId),
                        'document_hash' => $qb->createNamedParameter($filenameHash),
                        'hash_type' => $qb->createNamedParameter('filename'),
                        'metadata_id' => $qb->createNamedParameter($metadataId),
                        'created_at' => $qb->createNamedParameter($now)
                    ])
                    ->executeStatement();
            }

            $this->logger->info('Created hash mappings for file', [
                'file_path' => $file->getPath(),
                'user_id' => $userId,
                'metadata_id' => $metadataId,
                'binary_hash' => $binaryHash,
                'filename_hash' => $filenameHash
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create hash mappings', [
                'file_path' => $file->getPath(),
                'user_id' => $userId,
                'metadata_id' => $metadataId,
                'exception' => $e->getMessage()
            ]);
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
            $this->logger->error('Failed to get authors', ['exception' => $e]);
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


        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('author')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('author'))
               ->andWhere($qb->expr()->neq('author', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get authors count', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by author', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by author count', ['exception' => $e]);
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
            $this->logger->error('Failed to get series', ['exception' => $e]);
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


        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('series')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('series'))
               ->andWhere($qb->expr()->neq('series', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get series count', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by series', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by series count', ['exception' => $e]);
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
            $this->logger->error('Failed to get genres', ['exception' => $e]);
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


        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('subject')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('subject'))
               ->andWhere($qb->expr()->neq('subject', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get genres count', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by genre', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by genre count', ['exception' => $e]);
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
            $this->logger->error('Failed to get formats', ['exception' => $e]);
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


        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('file_format')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('file_format'))
               ->andWhere($qb->expr()->neq('file_format', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get formats count', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by format', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by format count', ['exception' => $e]);
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
            $this->logger->error('Failed to get languages', ['exception' => $e]);
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


        try {
            $qb = $this->db->getQueryBuilder();

            $qb->selectDistinct('language')
               ->from('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNotNull('language'))
               ->andWhere($qb->expr()->neq('language', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $count = 0;
            while ($result->fetch()) {
                $count++;
            }
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get languages count', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by language', ['exception' => $e]);
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
            $this->logger->error('Failed to get books by language count', ['exception' => $e]);
            return 0;
        }
    }
}