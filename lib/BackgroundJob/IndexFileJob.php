<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\BackgroundJob;

use OCA\KoreaderCompanion\Service\BookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Indexes a single file after an upload/change event.
 *
 * The argument is {fileId, userId}; the path is never stored because file IDs
 * are stable across renames. Runs without a user session, so it only calls
 * BookService methods that take an explicit userId.
 */
class IndexFileJob extends QueuedJob {

    private BookService $bookService;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        BookService $bookService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->bookService = $bookService;
        $this->logger = $logger;
    }

    protected function run($argument): void {
        $fileId = (int)($argument['fileId'] ?? 0);
        $userId = (string)($argument['userId'] ?? '');

        if ($fileId <= 0 || $userId === '') {
            return;
        }

        try {
            $this->bookService->indexFile($fileId, $userId);
        } catch (\Throwable $e) {
            $this->logger->error('IndexFileJob failed', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }
}