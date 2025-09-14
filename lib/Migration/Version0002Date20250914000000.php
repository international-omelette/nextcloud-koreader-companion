<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to handle password hash format change from bcrypt to MD5
 *
 * This migration clears existing bcrypt password hashes to force users
 * to reset their KOReader sync passwords using the new MD5-based system
 * that's compatible with KOReader's authentication protocol.
 */
class Version0002Date20250914000000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Clear existing password hashes that are bcrypt format
        // Users will need to set their passwords again through the web UI

        $output->info('Checking for existing KOReader sync passwords...');

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('userid', 'configvalue')
            ->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter('koreader_companion')))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('koreader_sync_password')))
            ->executeQuery();

        $clearedCount = 0;
        while ($row = $result->fetch()) {
            $userId = $row['userid'];
            $hash = $row['configvalue'];

            // Check if this looks like a bcrypt hash (starts with $2y$, $2a$, $2b$)
            // or if it's not a 32-character hex string (which would be MD5)
            $isBcrypt = preg_match('/^\$2[ayb]\$/', $hash);
            $isMd5 = preg_match('/^[a-f0-9]{32}$/', $hash);

            if ($isBcrypt || !$isMd5) {
                // Clear the invalid hash
                $deleteQb = $this->db->getQueryBuilder();
                $deleteQb->delete('preferences')
                    ->where($deleteQb->expr()->eq('userid', $deleteQb->createNamedParameter($userId)))
                    ->andWhere($deleteQb->expr()->eq('appid', $deleteQb->createNamedParameter('koreader_companion')))
                    ->andWhere($deleteQb->expr()->eq('configkey', $deleteQb->createNamedParameter('koreader_sync_password')))
                    ->executeStatement();

                $clearedCount++;
                $output->info("Cleared invalid password hash for user: {$userId}");
            }
        }
        $result->closeCursor();

        if ($clearedCount > 0) {
            $output->warning("Cleared {$clearedCount} invalid password hash(es). Users will need to set their KOReader sync passwords again through the web interface.");
        } else {
            $output->info('No invalid password hashes found.');
        }

        // Also clean up any leftover plain password entries
        $cleanupQb = $this->db->getQueryBuilder();
        $cleanupQb->delete('preferences')
            ->where($cleanupQb->expr()->eq('appid', $cleanupQb->createNamedParameter('koreader_companion')))
            ->andWhere($cleanupQb->expr()->eq('configkey', $cleanupQb->createNamedParameter('koreader_sync_password_plain')))
            ->executeStatement();

        $output->info('Migration completed successfully.');
    }
}