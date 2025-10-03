<?php
namespace OCA\KoreaderCompanion\Listener;

use OCA\KoreaderCompanion\Service\BookService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Listens for file deletion events to clean up related database records
 * when ebook files are deleted from the filesystem
 */
class FileDeleteListener implements IEventListener {

    private $config;
    private $userSession;
    private $db;
    private LoggerInterface $logger;

    public function __construct(
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->userSession = $userSession;
        $this->db = $db;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        $node = $event->getNode();

        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            return;
        }

        if (!$this->isEbookInBooksFolder($node)) {
            return;
        }

        $userId = $this->extractUserIdFromPath($node->getPath());
        if (!$userId) {
            return;
        }

        $fileId = $node->getId();
        $this->cleanupFileReferences($fileId, $userId, $node->getPath());
    }

    private function isEbookInBooksFolder($node): bool {
        $path = $node->getPath();

        // Extract user ID from path to get their configured folder
        $userId = $this->extractUserIdFromPath($path);
        if (!$userId) {
            return false;
        }

        $folderName = $this->config->getUserValue($userId, 'koreader_companion', 'folder', 'eBooks');

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
                        $this->logger->info('File deletion cleanup - removed sync progress records', [
                            'records_removed' => $progressRemoved,
                            'file_path' => $filePath
                        ]);
                    }
                }

                // Remove all hash mappings for this book
                $mappingsRemoved = $this->removeHashMappings($metadataId, $userId);
                if ($mappingsRemoved > 0) {
                    $this->logger->info('File deletion cleanup - removed hash mappings', [
                        'mappings_removed' => $mappingsRemoved,
                        'file_path' => $filePath
                    ]);
                }

                // Remove metadata record
                $metadataRemoved = $this->removeMetadata($userId, $fileId);
                if ($metadataRemoved > 0) {
                    $this->logger->info('File deletion cleanup - removed metadata', [
                        'file_path' => $filePath
                    ]);
                }
            }

            $this->db->commit();

            $this->logger->info('Successfully cleaned up all database references for deleted file', [
                'file_path' => $filePath,
                'user' => $userId
            ]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to cleanup references for deleted file', [
                'file_path' => $filePath,
                'exception' => $e
            ]);
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
            $this->logger->error('Failed to retrieve metadata ID in file deletion cleanup', [
                'exception' => $e
            ]);
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
            $this->logger->error('Failed to get document hashes in file deletion cleanup', [
                'exception' => $e
            ]);
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
            $this->logger->error('Failed to remove sync progress in file deletion cleanup', [
                'exception' => $e
            ]);
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
            $this->logger->error('Failed to remove hash mappings in file deletion cleanup', [
                'exception' => $e
            ]);
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
            $this->logger->error('Failed to remove metadata in file deletion cleanup', [
                'exception' => $e
            ]);
            return 0;
        }
    }
}