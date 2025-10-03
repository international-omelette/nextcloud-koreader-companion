<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to properly drop the koreader_file_tracking table
 *
 * This fixes the bug in Version0004 where the table was not dropped due to
 * missing the oc_ prefix in the DROP TABLE statement.
 */
class Version0005Date20251003000000 extends SimpleMigrationStep {

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
        $output->info('Dropping orphaned file tracking table...');

        try {
            $this->db->executeStatement('DROP TABLE IF EXISTS `oc_koreader_file_tracking`');
            $output->info('File tracking table dropped successfully');
        } catch (\Exception $e) {
            $output->warning('Could not drop file tracking table: ' . $e->getMessage());
        }
    }
}
