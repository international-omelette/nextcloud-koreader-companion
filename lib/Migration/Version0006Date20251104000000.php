<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to simplify hash lookup by removing the hash mapping table
 *
 * This migration:
 * - Drops the koreader_hash_mapping table (redundant denormalization)
 * - Adds composite indexes to koreader_metadata for efficient hash lookups
 * - Simplifies the data model to use direct column queries with OR clauses
 */
class Version0006Date20251104000000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Drop the hash mapping table as it's redundant
        if ($schema->hasTable('koreader_hash_mapping')) {
            $output->info('Dropping koreader_hash_mapping table...');
            $schema->dropTable('koreader_hash_mapping');
            $output->info('Hash mapping table dropped successfully');
        }

        // Add composite indexes to koreader_metadata for efficient hash lookups
        if ($schema->hasTable('koreader_metadata')) {
            $table = $schema->getTable('koreader_metadata');

            // Add composite index for binary hash lookups
            if (!$table->hasIndex('meta_user_binary_hash_idx')) {
                $table->addIndex(['user_id', 'binary_hash'], 'meta_user_binary_hash_idx');
                $output->info('Added composite index for binary hash lookups');
            }

            // Add composite index for filename hash lookups
            if (!$table->hasIndex('meta_user_filename_hash_idx')) {
                $table->addIndex(['user_id', 'filename_hash'], 'meta_user_filename_hash_idx');
                $output->info('Added composite index for filename hash lookups');
            }
        }

        return $schema;
    }
}
