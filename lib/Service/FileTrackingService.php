<?php
namespace OCA\KoreaderCompanion\Service;

use OCP\IDBConnection;
use OCP\Files\Node;

/**
 * Service for tracking file uploads and managing upload restrictions
 */
class FileTrackingService {

    private $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Mark a file as uploaded through the app
     */
    public function markFileAsAppUploaded(Node $file, string $userId): void {
        $this->trackFile($file, $userId, 'app');
    }

    /**
     * Mark a file as externally uploaded
     */
    public function markFileAsExternalUpload(Node $file, string $userId): void {
        $this->trackFile($file, $userId, 'external');
    }

    /**
     * Check if a file was uploaded through the app
     */
    public function isAppUploadedFile(Node $file, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        
        $result = $qb->select('upload_method')
            ->from('koreader_file_tracking')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row && $row['upload_method'] === 'app';
    }

    /**
     * Get upload method for a file (app, external, or null if not tracked)
     */
    public function getFileUploadMethod(Node $file, string $userId): ?string {
        $qb = $this->db->getQueryBuilder();
        
        $result = $qb->select('upload_method')
            ->from('koreader_file_tracking')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row ? $row['upload_method'] : null;
    }

    /**
     * Remove tracking for a file (when file is deleted)
     */
    public function removeFileTracking(int $fileId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        
        $qb->delete('koreader_file_tracking')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeStatement();
    }

    /**
     * Get all app-uploaded file IDs for a user
     */
    public function getAppUploadedFileIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        
        $result = $qb->select('file_id')
            ->from('koreader_file_tracking')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('upload_method', $qb->createNamedParameter('app')))
            ->executeQuery();

        $fileIds = [];
        while ($row = $result->fetch()) {
            $fileIds[] = (int)$row['file_id'];
        }
        $result->closeCursor();

        return $fileIds;
    }

    /**
     * Migrate existing tracking data from user preferences to database
     */
    public function migrateFromUserPreferences(string $userId, array $fileIds): void {
        // Note: This would need file objects to get paths, 
        // so it should be called from a service that has access to files
        foreach ($fileIds as $fileId) {
            try {
                $this->trackFileById($fileId, $userId, 'app');
            } catch (\Exception $e) {
                // Log error but continue with other files
                error_log("eBooks app: Failed to migrate tracking for file ID $fileId: " . $e->getMessage());
            }
        }
    }

    /**
     * Clean up tracking for deleted files
     */
    public function cleanupDeletedFiles(): int {
        // This would require checking file existence, which is complex
        // For now, we'll rely on file deletion events to clean up
        return 0;
    }

    private function trackFile(Node $file, string $userId, string $method): void {
        $currentTime = date('Y-m-d H:i:s');
        
        $qb = $this->db->getQueryBuilder();
        
        // Try to update existing record first
        $updated = $qb->update('koreader_file_tracking')
            ->set('upload_method', $qb->createNamedParameter($method))
            ->set('file_path', $qb->createNamedParameter($file->getPath()))
            ->set('updated_at', $qb->createNamedParameter($currentTime))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
            ->executeStatement();

        if ($updated === 0) {
            // Insert new record if update didn't affect any rows
            $qb = $this->db->getQueryBuilder();
            $qb->insert('koreader_file_tracking')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'file_id' => $qb->createNamedParameter($file->getId()),
                    'file_path' => $qb->createNamedParameter($file->getPath()),
                    'upload_method' => $qb->createNamedParameter($method),
                    'created_at' => $qb->createNamedParameter($currentTime),
                    'updated_at' => $qb->createNamedParameter($currentTime),
                ])
                ->executeStatement();
        }
    }

    private function trackFileById(int $fileId, string $userId, string $method): void {
        $currentTime = date('Y-m-d H:i:s');
        
        $qb = $this->db->getQueryBuilder();
        
        // Try to update existing record first
        $updated = $qb->update('koreader_file_tracking')
            ->set('upload_method', $qb->createNamedParameter($method))
            ->set('updated_at', $qb->createNamedParameter($currentTime))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
            ->executeStatement();

        if ($updated === 0) {
            // Insert new record if update didn't affect any rows
            $qb = $this->db->getQueryBuilder();
            $qb->insert('koreader_file_tracking')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'file_id' => $qb->createNamedParameter($fileId),
                    'file_path' => $qb->createNamedParameter(''), // Will be empty for migrated data
                    'upload_method' => $qb->createNamedParameter($method),
                    'created_at' => $qb->createNamedParameter($currentTime),
                    'updated_at' => $qb->createNamedParameter($currentTime),
                ])
                ->executeStatement();
        }
    }
}