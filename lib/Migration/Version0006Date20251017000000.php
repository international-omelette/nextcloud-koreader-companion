<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Cleans up schema left behind by earlier releases:
 *  - drops obsolete indexes on koreader_metadata (an oversized file_path index
 *    that exceeded the MySQL/MariaDB InnoDB key length limit and made
 *    installation fail with SQLSTATE 42000 / error 1071, plus duplicate indexes)
 *  - drops the dead koreader_file_tracking table, which was only used by
 *    removed upload-restriction features and which migration Version0005 only
 *    removed on some database backends (it used a hard-coded `oc_` prefix)
 */
class Version0006Date20251017000000 extends SimpleMigrationStep {

    private const OBSOLETE_INDEXES = [
        'koreader_metadata' => [
            'meta_path_idx',
            'idx_ebooks_search_title',
            'idx_ebooks_search_author',
            'meta_user_pubdate_idx',
            'meta_series_order_idx',
        ],
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        foreach (self::OBSOLETE_INDEXES as $tableName => $indexNames) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);
            foreach ($indexNames as $indexName) {
                if ($table->hasIndex($indexName)) {
                    $table->dropIndex($indexName);
                    $changed = true;
                    $output->info(sprintf('Dropped obsolete index %s from %s', $indexName, $tableName));
                }
            }
        }

        if ($schema->hasTable('koreader_file_tracking')) {
            $schema->dropTable('koreader_file_tracking');
            $changed = true;
            $output->info('Dropped obsolete table koreader_file_tracking');
        }

        return $changed ? $schema : null;
    }
}