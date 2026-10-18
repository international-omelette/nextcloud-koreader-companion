<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds fatal-safe indexing state to the metadata cache.
 *
 * Extraction runs in a background job. A PHP fatal error (e.g. an out-of-memory
 * PDF parse) is not catchable, so the row is marked `processing` and an attempt
 * counted before extraction starts. The reconciler treats a `processing` row
 * that has exhausted MAX_INDEX_ATTEMPTS as `failed` and stops retrying it.
 */
class Version0007Date20251020000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('koreader_metadata')) {
            return null;
        }

        $table = $schema->getTable('koreader_metadata');
        $changed = false;

        if (!$table->hasColumn('status')) {
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'ok',
                'comment' => 'Indexing state: pending|processing|ok|failed',
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('attempts')) {
            $table->addColumn('attempts', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
                'comment' => 'Consecutive indexing attempts for the current file version',
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('failed_at')) {
            $table->addColumn('failed_at', Types::DATETIME, [
                'notnull' => false,
                'comment' => 'When indexing last gave up; a newer mtime allows recovery',
            ]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}