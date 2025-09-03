<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Complete initial database schema for KOReader Companion app
 * Creates all required tables with comprehensive indexes for OPDS and KOReader functionality
 */
class Version0001Date20250829000000 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create main metadata table for book metadata and cached information
        if (!$schema->hasTable('koreader_metadata')) {
            $table = $schema->createTable('koreader_metadata');
            
            // Primary key
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            
            // User and file identification
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'comment' => 'Nextcloud username'
            ]);
            
            $table->addColumn('file_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'comment' => 'Nextcloud file ID (FK to oc_filecache)'
            ]);
            
            $table->addColumn('file_path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
                'comment' => 'Full path to the file'
            ]);
            
            // Core metadata fields
            $table->addColumn('title', Types::STRING, [
                'notnull' => false,
                'length' => 500,
                'comment' => 'Book title (extracted or custom)'
            ]);
            
            $table->addColumn('author', Types::STRING, [
                'notnull' => false,
                'length' => 500,
                'comment' => 'Book author(s) (extracted or custom)'
            ]);
            
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
                'comment' => 'Book description/summary (extracted or custom)'
            ]);
            
            $table->addColumn('publisher', Types::STRING, [
                'notnull' => false,
                'length' => 200,
                'comment' => 'Publisher name'
            ]);
            
            $table->addColumn('publication_date', Types::DATE, [
                'notnull' => false,
                'comment' => 'Publication date (YYYY-MM-DD format)'
            ]);
            
            $table->addColumn('isbn', Types::STRING, [
                'notnull' => false,
                'length' => 20,
                'comment' => 'ISBN-10 or ISBN-13 identifier'
            ]);
            
            $table->addColumn('language', Types::STRING, [
                'notnull' => false,
                'length' => 10,
                'comment' => 'Language code (ISO 639-1/639-2)'
            ]);
            
            // Series information
            $table->addColumn('series', Types::STRING, [
                'notnull' => false,
                'length' => 300,
                'comment' => 'Series name'
            ]);
            
            $table->addColumn('series_index', Types::FLOAT, [
                'notnull' => false,
                'comment' => 'Position in series (supports decimals like 1.5)'
            ]);
            
            // Classification and tags
            $table->addColumn('subject', Types::STRING, [
                'notnull' => false,
                'length' => 500,
                'comment' => 'Subject/genre/category'
            ]);
            
            $table->addColumn('tags', Types::STRING, [
                'notnull' => false,
                'length' => 1000,
                'comment' => 'Custom tags (comma separated)'
            ]);
            
            // File format and technical details
            $table->addColumn('file_format', Types::STRING, [
                'notnull' => false,
                'length' => 10,
                'comment' => 'File format (epub, pdf, etc.)'
            ]);
            
            $table->addColumn('file_hash', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'comment' => 'SHA-256 hash of file content for change detection'
            ]);
            
            // KOReader hash columns for document matching
            $table->addColumn('binary_hash', Types::STRING, [
                'notnull' => false,
                'length' => 32,
                'comment' => 'MD5 hash using KOReader fastDigest algorithm for binary matching'
            ]);
            
            $table->addColumn('filename_hash', Types::STRING, [
                'notnull' => false,
                'length' => 32,
                'comment' => 'MD5 hash of filename for filename-based matching'
            ]);
            
            // Cover image handling
            $table->addColumn('cover_image', Types::TEXT, [
                'notnull' => false,
                'comment' => 'Base64 encoded thumbnail or path to cached image'
            ]);
            
            // Comic book specific fields
            $table->addColumn('issue', Types::STRING, [
                'notnull' => false,
                'length' => 50,
                'comment' => 'Comic book issue number (e.g., "12")'
            ]);
            
            $table->addColumn('volume', Types::STRING, [
                'notnull' => false,
                'length' => 50,
                'comment' => 'Comic book volume number (e.g., "3")'
            ]);
            
            // Timestamps
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'comment' => 'When metadata was first created'
            ]);
            
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
                'comment' => 'Last update timestamp'
            ]);

            // Primary key and basic indexes
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'file_id'], 'meta_unique');
            $table->addIndex(['user_id'], 'meta_user_idx');
            $table->addIndex(['file_id'], 'meta_file_idx');
            $table->addIndex(['file_path'], 'meta_path_idx');
            $table->addIndex(['binary_hash'], 'meta_bin_hash_idx');
            $table->addIndex(['filename_hash'], 'meta_file_hash_idx');
            
            // Comprehensive performance indexes for OPDS pagination and search
            $table->addIndex(['user_id', 'title'], 'idx_ebooks_pagination');
            $table->addIndex(['user_id', 'title'], 'idx_ebooks_search_title');
            $table->addIndex(['user_id', 'author'], 'idx_ebooks_search_author');
            $table->addIndex(['user_id', 'publication_date'], 'idx_ebooks_date');
            $table->addIndex(['user_id', 'series', 'series_index'], 'idx_ebooks_series');
            
            // Faceted browsing performance indexes
            $table->addIndex(['user_id', 'author'], 'meta_user_author_idx');
            $table->addIndex(['user_id', 'series'], 'meta_user_series_idx');
            $table->addIndex(['user_id', 'subject'], 'meta_user_subject_idx');
            $table->addIndex(['user_id', 'file_format'], 'meta_user_format_idx');
            $table->addIndex(['user_id', 'language'], 'meta_user_language_idx');
            $table->addIndex(['user_id', 'publication_date'], 'meta_user_pubdate_idx');
            $table->addIndex(['user_id', 'series', 'series_index'], 'meta_series_order_idx');
        }

        // Create KOReader sync progress table
        if (!$schema->hasTable('koreader_sync_progress')) {
            $table = $schema->createTable('koreader_sync_progress');
            
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'comment' => 'Nextcloud username'
            ]);
            
            $table->addColumn('document_hash', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'comment' => 'MD5 hash of document path (KOReader compatibility)'
            ]);
            
            $table->addColumn('progress', Types::TEXT, [
                'notnull' => false,
                'comment' => 'KOReader progress data (JSON)'
            ]);
            
            $table->addColumn('percentage', Types::STRING, [
                'notnull' => false,
                'length' => 10,
                'comment' => 'Reading percentage (0.0-1.0)'
            ]);
            
            $table->addColumn('device', Types::STRING, [
                'notnull' => false,
                'length' => 100,
                'comment' => 'Device name'
            ]);
            
            $table->addColumn('device_id', Types::STRING, [
                'notnull' => false,
                'length' => 100,
                'comment' => 'Device identifier'
            ]);
            
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
                'comment' => 'Last sync timestamp'
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'document_hash'], 'progress_unique');
            $table->addIndex(['user_id'], 'progress_user_idx');
            $table->addIndex(['document_hash'], 'progress_doc_idx');
        }

        // Create file tracking table for upload restrictions and monitoring
        if (!$schema->hasTable('koreader_file_tracking')) {
            $table = $schema->createTable('koreader_file_tracking');
            
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'comment' => 'Nextcloud username'
            ]);
            
            $table->addColumn('file_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'comment' => 'Nextcloud file ID'
            ]);
            
            $table->addColumn('file_path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
                'comment' => 'Full path to the file'
            ]);
            
            $table->addColumn('upload_method', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'comment' => 'How file was added: app, external, sync'
            ]);
            
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'comment' => 'When file was first tracked'
            ]);
            
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
                'comment' => 'Last update timestamp'
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'file_id'], 'tracking_unique');
            $table->addIndex(['user_id'], 'tracking_user_idx');
            $table->addIndex(['file_id'], 'tracking_file_idx');
            $table->addIndex(['upload_method'], 'tracking_method_idx');
            $table->addIndex(['file_path'], 'tracking_path_idx');
        }

        // Create hash mapping table for flexible document lookups
        if (!$schema->hasTable('koreader_hash_mapping')) {
            $table = $schema->createTable('koreader_hash_mapping');
            
            // Primary key
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            
            // User identification
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'comment' => 'Nextcloud username for user-scoped hashes'
            ]);
            
            // Document hash (either binary or filename-based)
            $table->addColumn('document_hash', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'comment' => 'MD5 hash of document (32-character hex string)'
            ]);
            
            // Hash type to distinguish between binary and filename methods
            $table->addColumn('hash_type', Types::STRING, [
                'notnull' => true,
                'length' => 10,
                'comment' => 'Type of hash: binary or filename (validated at application level)'
            ]);
            
            // Reference to metadata record
            $table->addColumn('metadata_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'comment' => 'Foreign key to koreader_metadata table'
            ]);
            
            // Timestamp for tracking when hash was created
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'comment' => 'When hash mapping was created'
            ]);

            // Primary key
            $table->setPrimaryKey(['id']);
            
            // Unique constraint to prevent duplicate hash mappings per user
            $table->addUniqueIndex(['user_id', 'document_hash'], 'hash_user_unique');
            
            // Index for efficient metadata lookups
            $table->addIndex(['metadata_id'], 'hash_meta_idx');
            
            // Index for efficient user-based queries
            $table->addIndex(['user_id'], 'hash_user_idx');
            
            // Index for hash type filtering
            $table->addIndex(['hash_type'], 'hash_type_idx');
            
            // Composite index for user + hash type queries
            $table->addIndex(['user_id', 'hash_type'], 'hash_user_type_idx');
        }

        return $schema;
    }
}