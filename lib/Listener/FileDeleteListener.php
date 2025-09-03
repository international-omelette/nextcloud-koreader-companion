<?php
namespace OCA\KoreaderCompanion\Listener;

use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Service\FileTrackingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IDBConnection;

/**
 * Listens for file deletion events to clean up related database records
 * when ebook files are deleted from the filesystem
 */
class FileDeleteListener implements IEventListener {

    private $config;
    private $userSession;
    private $fileTrackingService;
    private $db;

    public function __construct(
        IConfig $config,
        IUserSession $userSession,
        FileTrackingService $fileTrackingService,
        IDBConnection $db
    ) {
        $this->config = $config;
        $this->userSession = $userSession;
        $this->fileTrackingService = $fileTrackingService;
        $this->db = $db;
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        $node = $event->getNode();
        
        // Only process files (not directories)
        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            return;
        }

        // Check if this is an ebook file in the configured Books folder
        if (!$this->isEbookInBooksFolder($node)) {
            return;
        }

        // Extract user ID from file path
        $userId = $this->extractUserIdFromPath($node->getPath());
        if (!$userId) {
            return;
        }

        $fileId = $node->getId();
        
        // Clean up all related database records
        $this->cleanupFileReferences($fileId, $userId, $node->getPath());
    }

    private function isEbookInBooksFolder($node): bool {
        $path = $node->getPath();
        $folderName = $this->config->getAppValue('koreader_companion', 'folder', 'eBooks');
        
        // Check if file is in the configured eBooks folder
        if (strpos($path, "/files/$folderName/") === false) {
            return false;
        }

        // Check if it's an ebook file
        $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        return in_array($extension, ['epub', 'pdf', 'cbr', 'mobi']);
    }

    private function extractUserIdFromPath(string $path): ?string {
        if (preg_match('/^\/([^\/]+)\/files\//', $path, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function cleanupFileReferences(int $fileId, string $userId, string $filePath): void {
        try {
            // Begin transaction to ensure atomic cleanup
            $this->db->beginTransaction();

            // Get metadata ID for this file
            $metadataId = $this->getMetadataId($userId, $fileId);
            
            if ($metadataId) {
                // Get all document hashes for this book from hash mappings
                $documentHashes = $this->getDocumentHashesForBook($metadataId, $userId);
                
                if (!empty($documentHashes)) {
                    // Remove sync progress for all hashes of this book
                    $progressRemoved = $this->removeSyncProgressForHashes($documentHashes, $userId);
                    if ($progressRemoved > 0) {
                        error_log("eBooks app: File deletion cleanup - removed $progressRemoved sync progress records for file: $filePath");
                    }
                }

                // Remove all hash mappings for this book
                $mappingsRemoved = $this->removeHashMappings($metadataId, $userId);
                if ($mappingsRemoved > 0) {
                    error_log("eBooks app: File deletion cleanup - removed $mappingsRemoved hash mappings for file: $filePath");
                }

                // Remove metadata record
                $metadataRemoved = $this->removeMetadata($userId, $fileId);
                if ($metadataRemoved > 0) {
                    error_log("eBooks app: File deletion cleanup - removed metadata for file: $filePath");
                }
            }

            // Remove file tracking record
            $this->fileTrackingService->removeFileTracking($fileId, $userId);

            $this->db->commit();
            
            error_log("eBooks app: Successfully cleaned up all database references for deleted file: $filePath (user: $userId)");
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('eBooks app: Failed to cleanup references for deleted file ' . $filePath . ': ' . $e->getMessage());
        }
    }

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
            error_log('eBooks app: Failed to retrieve metadata ID in file deletion cleanup: ' . $e->getMessage());
            return null;
        }
    }

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
            error_log('eBooks app: Failed to get document hashes in file deletion cleanup: ' . $e->getMessage());
            return [];
        }
    }

    private function removeSyncProgressForHashes(array $documentHashes, string $userId): int {
        if (empty($documentHashes)) {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_sync_progress')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->in('document_hash', $qb->createNamedParameter($documentHashes, \OCP\DB\IQueryBuilder::PARAM_STR_ARRAY)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to remove sync progress in file deletion cleanup: ' . $e->getMessage());
            return 0;
        }
    }

    private function removeHashMappings(int $metadataId, string $userId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_hash_mapping')
               ->where($qb->expr()->eq('metadata_id', $qb->createNamedParameter($metadataId)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to remove hash mappings in file deletion cleanup: ' . $e->getMessage());
            return 0;
        }
    }

    private function removeMetadata(string $userId, int $fileId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $affectedRows = $qb->delete('koreader_metadata')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->executeStatement();

            return $affectedRows;
        } catch (\Exception $e) {
            error_log('eBooks app: Failed to remove metadata in file deletion cleanup: ' . $e->getMessage());
            return 0;
        }
    }
}