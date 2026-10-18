<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\BackgroundJob;

use OCA\KoreaderCompanion\Service\BookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Bounded reconcile of one user's library with the filesystem.
 *
 * Files that never produced a Node event (occ files:scan, external storage,
 * direct disk writes) are picked up here. The budget counts extractions plus a
 * wall-clock cap; when exhausted, the job re-queues itself so a large library
 * finishes over several runs instead of one unbounded pass.
 */
class ReconcileUserJob extends QueuedJob {

    private BookService $bookService;
    private IJobList $jobList;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        BookService $bookService,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->bookService = $bookService;
        $this->jobList = $jobList;
        $this->logger = $logger;
    }

    protected function run($argument): void {
        $userId = (string)($argument['userId'] ?? '');
        if ($userId === '') {
            return;
        }

        try {
            $stats = $this->bookService->reconcileUser(
                $userId,
                BookService::RECONCILE_MAX_EXTRACTIONS,
                BookService::RECONCILE_MAX_SECONDS
            );

            if (!$stats['complete']) {
                $this->jobList->add(self::class, ['userId' => $userId]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('ReconcileUserJob failed', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }
}