<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to convert app-wide settings to per-user settings
 *
 * This migration moves folder, restrict_uploads, and auto_rename settings
 * from app-wide configuration to per-user preferences, and removes
 * the auto_cleanup setting entirely.
 */
class Version0004Date20250916000000 extends SimpleMigrationStep {

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
        $output->info('Converting app-wide settings to per-user settings...');

        // Read existing app-wide settings
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('configkey', 'configvalue')
            ->from('appconfig')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter('koreader_companion')))
            ->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter(['folder', 'restrict_uploads', 'auto_rename', 'auto_cleanup'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeQuery();

        $appSettings = [];
        while ($row = $result->fetch()) {
            $appSettings[$row['configkey']] = $row['configvalue'];
        }
        $result->closeCursor();

        $output->info(sprintf('Found %d app-wide settings to migrate', count($appSettings)));

        // Skip auto_cleanup - it will be removed
        unset($appSettings['auto_cleanup']);

        // If we have settings to migrate, apply them to all users
        if (!empty($appSettings)) {
            // Get all user IDs
            $userQb = $this->db->getQueryBuilder();
            $userResult = $userQb->select('uid')
                ->from('users')
                ->executeQuery();

            $userCount = 0;
            while ($userRow = $userResult->fetch()) {
                $userId = $userRow['uid'];

                // For each setting, check if user already has a preference
                foreach ($appSettings as $settingKey => $settingValue) {
                    // Check if user already has this preference
                    $checkQb = $this->db->getQueryBuilder();
                    $existingResult = $checkQb->select('userid')
                        ->from('preferences')
                        ->where($checkQb->expr()->eq('userid', $checkQb->createNamedParameter($userId)))
                        ->andWhere($checkQb->expr()->eq('appid', $checkQb->createNamedParameter('koreader_companion')))
                        ->andWhere($checkQb->expr()->eq('configkey', $checkQb->createNamedParameter($settingKey)))
                        ->executeQuery();

                    $hasExisting = $existingResult->fetchOne();
                    $existingResult->closeCursor();

                    if (!$hasExisting) {
                        // Set user preference with the app-wide value as default
                        $insertQb = $this->db->getQueryBuilder();
                        $insertQb->insert('preferences')
                            ->values([
                                'userid' => $insertQb->createNamedParameter($userId),
                                'appid' => $insertQb->createNamedParameter('koreader_companion'),
                                'configkey' => $insertQb->createNamedParameter($settingKey),
                                'configvalue' => $insertQb->createNamedParameter($settingValue)
                            ])
                            ->executeStatement();
                    }
                }
                $userCount++;
            }
            $userResult->closeCursor();

            $output->info(sprintf('Migrated settings for %d users', $userCount));
        }

        // Remove the old app-wide settings
        $deleteQb = $this->db->getQueryBuilder();
        $deleteQb->delete('appconfig')
            ->where($deleteQb->expr()->eq('appid', $deleteQb->createNamedParameter('koreader_companion')))
            ->andWhere($deleteQb->expr()->in('configkey', $deleteQb->createNamedParameter(['folder', 'restrict_uploads', 'auto_rename', 'auto_cleanup'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeStatement();

        $output->info('Removed old app-wide settings from appconfig');

        // Log the defaults that will be used for new users
        $defaults = [
            'folder' => $appSettings['folder'] ?? 'eBooks',
            'restrict_uploads' => $appSettings['restrict_uploads'] ?? 'no',
            'auto_rename' => $appSettings['auto_rename'] ?? 'no'
        ];

        $output->info('Migration completed successfully.');
        $output->info('Settings are now per-user with defaults: ' . json_encode($defaults));
    }
}