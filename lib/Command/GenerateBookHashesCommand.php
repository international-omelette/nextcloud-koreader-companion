<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Command;

use OCA\KoreaderCompanion\Service\DocumentHashGenerator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to generate document hashes for existing books in the database
 * 
 * This command processes existing books in the koreader_metadata table that don't have
 * hashes generated yet, using the DocumentHashGenerator service to create both
 * binary and filename hashes for KOReader sync compatibility.
 */
class GenerateBookHashesCommand extends Command {

    protected static $defaultName = 'ebooks:generate-hashes';

    private DocumentHashGenerator $hashGenerator;
    private IDBConnection $db;
    private IRootFolder $rootFolder;
    private IConfig $config;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        DocumentHashGenerator $hashGenerator,
        IDBConnection $db,
        IRootFolder $rootFolder,
        IConfig $config,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->hashGenerator = $hashGenerator;
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    protected function configure(): void {
        $this
            ->setName('ebooks:generate-hashes')
            ->setDescription('Generate hashes for existing books in the database')
            ->setHelp('This command generates binary and filename hashes for all existing books in the koreader_metadata table that do not have hashes yet. This is needed for KOReader sync compatibility.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be processed without making any changes'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Process only books for specific user (by user ID)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Regenerate hashes even if they already exist'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of books to process at a time',
                '50'
            )
            ->addOption(
                'show-details',
                'd',
                InputOption::VALUE_NONE,
                'Show detailed progress information'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $isDryRun = $input->getOption('dry-run');
        $specificUser = $input->getOption('user');
        $force = $input->getOption('force');
        $batchSize = (int) $input->getOption('batch-size');
        $showDetails = $input->getOption('show-details');

        if ($batchSize <= 0) {
            $output->writeln('<error>Batch size must be greater than 0</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>eBooks Hash Generation Tool</info>');
        $output->writeln('');

        if ($isDryRun) {
            $output->writeln('<comment>DRY RUN MODE - No changes will be made</comment>');
        }

        if ($specificUser) {
            $user = $this->userManager->get($specificUser);
            if (!$user) {
                $output->writeln("<error>User '{$specificUser}' not found</error>");
                return Command::FAILURE;
            }
            $output->writeln("<info>Processing books for user: {$specificUser}</info>");
        } else {
            $output->writeln('<info>Processing books for all users</info>');
        }

        if ($force) {
            $output->writeln('<comment>Force mode enabled - will regenerate existing hashes</comment>');
        }

        $output->writeln("Batch size: {$batchSize}");
        $output->writeln('');

        try {
            // Get books that need hash generation
            $books = $this->getBooksNeedingHashes($specificUser, $force);
            $totalBooks = count($books);

            if ($totalBooks === 0) {
                $output->writeln('<info>No books found that need hash generation.</info>');
                return Command::SUCCESS;
            }

            $output->writeln("<info>Found {$totalBooks} books that need hash generation</info>");

            if ($isDryRun) {
                $this->showDryRunResults($books, $output, $showDetails);
                return Command::SUCCESS;
            }

            // Process books in batches
            $progress = new ProgressBar($output, $totalBooks);
            $progress->setFormat('verbose');
            $progress->start();

            $processed = 0;
            $succeeded = 0;
            $failed = 0;
            $errors = [];

            for ($i = 0; $i < $totalBooks; $i += $batchSize) {
                $batch = array_slice($books, $i, $batchSize);
                
                $this->db->beginTransaction();
                try {
                    foreach ($batch as $book) {
                        $result = $this->processBook($book, $showDetails, $output);
                        
                        if ($result['success']) {
                            $succeeded++;
                        } else {
                            $failed++;
                            $errors[] = $result['error'];
                        }
                        
                        $processed++;
                        $progress->advance();
                    }
                    
                    $this->db->commit();
                } catch (\Exception $e) {
                    $this->db->rollBack();
                    $output->writeln('');
                    $output->writeln("<error>Batch processing failed: {$e->getMessage()}</error>");
                    $failed += count($batch) - ($processed % $batchSize);
                    break;
                }
            }

            $progress->finish();
            $output->writeln('');
            $output->writeln('');

            // Show final results
            $output->writeln('<info>Hash generation completed!</info>');
            $output->writeln("Total processed: {$processed}");
            $output->writeln("Successful: {$succeeded}");
            $output->writeln("Failed: {$failed}");

            if ($failed > 0 && $showDetails) {
                $output->writeln('');
                $output->writeln('<comment>Errors encountered:</comment>');
                foreach (array_slice($errors, 0, 10) as $error) { // Show first 10 errors
                    $output->writeln("  - {$error}");
                }
                if (count($errors) > 10) {
                    $remaining = count($errors) - 10;
                    $output->writeln("  ... and {$remaining} more errors");
                }
            }

            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Command failed: {$e->getMessage()}</error>");
            $this->logger->error('Hash generation command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Get books from the database that need hash generation
     */
    private function getBooksNeedingHashes(?string $specificUser, bool $force): array {
        $qb = $this->db->getQueryBuilder();
        
        $qb->select('id', 'user_id', 'file_path', 'title', 'binary_hash', 'filename_hash')
           ->from('koreader_metadata');

        if ($specificUser) {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($specificUser)));
        }

        if (!$force) {
            // Only get books without hashes
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('binary_hash'),
                $qb->expr()->isNull('filename_hash')
            ));
        }

        $qb->orderBy('user_id', 'ASC')
           ->addOrderBy('title', 'ASC');

        $result = $qb->executeQuery();
        return $result->fetchAll();
    }

    /**
     * Show what would be processed in dry-run mode
     */
    private function showDryRunResults(array $books, OutputInterface $output, bool $showDetails): void {
        $output->writeln('<comment>Books that would be processed:</comment>');
        $output->writeln('');

        $userGroups = [];
        foreach ($books as $book) {
            $userGroups[$book['user_id']][] = $book;
        }

        foreach ($userGroups as $userId => $userBooks) {
            $count = count($userBooks);
            $output->writeln("<info>User: {$userId} ({$count} books)</info>");
            
            if ($showDetails) {
                foreach ($userBooks as $book) {
                    $hasHashes = '';
                    if ($book['binary_hash']) {
                        $hasHashes .= 'B';
                    }
                    if ($book['filename_hash']) {
                        $hasHashes .= 'F';
                    }
                    if (!$hasHashes) {
                        $hasHashes = 'none';
                    }
                    
                    $output->writeln("  - {$book['title']} (ID: {$book['id']}, Current hashes: {$hasHashes})");
                }
            }
            $output->writeln('');
        }
    }

    /**
     * Process a single book to generate hashes
     */
    private function processBook(array $book, bool $showDetails, OutputInterface $output): array {
        try {
            $userId = $book['user_id'];
            $filePath = $book['file_path'];
            $bookId = $book['id'];

            if ($showDetails) {
                $output->writeln('');
                $output->writeln("Processing: {$book['title']} (User: {$userId})");
            }

            // Get the actual file
            $userFolder = $this->rootFolder->getUserFolder($userId);
            
            // Convert absolute path to relative path
            $userPathPrefix = "/{$userId}/files/";
            if (strpos($filePath, $userPathPrefix) === 0) {
                $relativePath = substr($filePath, strlen($userPathPrefix));
            } else {
                $relativePath = $filePath; // Already relative or different format
            }
            
            try {
                $file = $userFolder->get($relativePath);
            } catch (\Exception $e) {
                $error = "File not found: {$filePath} for user {$userId}";
                if ($showDetails) {
                    $output->writeln("  <error>{$error}</error>");
                }
                return ['success' => false, 'error' => $error];
            }

            if (!$file->isReadable()) {
                $error = "File not readable: {$filePath} for user {$userId}";
                if ($showDetails) {
                    $output->writeln("  <error>{$error}</error>");
                }
                return ['success' => false, 'error' => $error];
            }

            // Generate hashes using the service
            $hashes = $this->hashGenerator->generateDocumentHashesFromNode($file);

            $binaryHash = $hashes['binary_hash'];
            $filenameHash = $hashes['filename_hash'];

            if (!$binaryHash && !$filenameHash) {
                $error = "Failed to generate any hashes for: {$filePath}";
                if ($showDetails) {
                    $output->writeln("  <error>{$error}</error>");
                }
                return ['success' => false, 'error' => $error];
            }

            // Update the metadata table
            $updateQb = $this->db->getQueryBuilder();
            $updateQb->update('koreader_metadata')
                    ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($bookId, IQueryBuilder::PARAM_INT)));

            if ($binaryHash) {
                $updateQb->set('binary_hash', $updateQb->createNamedParameter($binaryHash));
            }
            if ($filenameHash) {
                $updateQb->set('filename_hash', $updateQb->createNamedParameter($filenameHash));
            }

            $updateQb->executeStatement();

            // Create entries in the hash mapping table
            $this->createHashMappings($bookId, $userId, $binaryHash, $filenameHash);

            if ($showDetails) {
                $hashInfo = [];
                if ($binaryHash) {
                    $hashInfo[] = "Binary: {$binaryHash}";
                }
                if ($filenameHash) {
                    $hashInfo[] = "Filename: {$filenameHash}";
                }
                $output->writeln("  <info>Generated hashes: " . implode(', ', $hashInfo) . "</info>");
            }

            return ['success' => true];

        } catch (\Exception $e) {
            $error = "Exception processing book {$book['id']}: {$e->getMessage()}";
            if ($showDetails) {
                $output->writeln("  <error>{$error}</error>");
            }
            
            $this->logger->error('Error processing book for hash generation', [
                'book_id' => $book['id'],
                'user_id' => $book['user_id'],
                'file_path' => $book['file_path'],
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Create hash mapping entries in the database
     */
    private function createHashMappings(int $metadataId, string $userId, ?string $binaryHash, ?string $filenameHash): void {
        $now = new \DateTime();

        if ($binaryHash) {
            // Delete existing binary hash mapping for this user/hash combination
            $deleteQb = $this->db->getQueryBuilder();
            $deleteQb->delete('koreader_hash_mapping')
                    ->where($deleteQb->expr()->eq('user_id', $deleteQb->createNamedParameter($userId)))
                    ->andWhere($deleteQb->expr()->eq('document_hash', $deleteQb->createNamedParameter($binaryHash)))
                    ->andWhere($deleteQb->expr()->eq('hash_type', $deleteQb->createNamedParameter('binary')));
            $deleteQb->executeStatement();

            // Insert new binary hash mapping
            $insertQb = $this->db->getQueryBuilder();
            $insertQb->insert('koreader_hash_mapping')
                    ->values([
                        'user_id' => $insertQb->createNamedParameter($userId),
                        'document_hash' => $insertQb->createNamedParameter($binaryHash),
                        'hash_type' => $insertQb->createNamedParameter('binary'),
                        'metadata_id' => $insertQb->createNamedParameter($metadataId, IQueryBuilder::PARAM_INT),
                        'created_at' => $insertQb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)
                    ]);
            $insertQb->executeStatement();
        }

        if ($filenameHash) {
            // Delete existing filename hash mapping for this user/hash combination
            $deleteQb = $this->db->getQueryBuilder();
            $deleteQb->delete('koreader_hash_mapping')
                    ->where($deleteQb->expr()->eq('user_id', $deleteQb->createNamedParameter($userId)))
                    ->andWhere($deleteQb->expr()->eq('document_hash', $deleteQb->createNamedParameter($filenameHash)))
                    ->andWhere($deleteQb->expr()->eq('hash_type', $deleteQb->createNamedParameter('filename')));
            $deleteQb->executeStatement();

            // Insert new filename hash mapping
            $insertQb = $this->db->getQueryBuilder();
            $insertQb->insert('koreader_hash_mapping')
                    ->values([
                        'user_id' => $insertQb->createNamedParameter($userId),
                        'document_hash' => $insertQb->createNamedParameter($filenameHash),
                        'hash_type' => $insertQb->createNamedParameter('filename'),
                        'metadata_id' => $insertQb->createNamedParameter($metadataId, IQueryBuilder::PARAM_INT),
                        'created_at' => $insertQb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)
                    ]);
            $insertQb->executeStatement();
        }
    }
}