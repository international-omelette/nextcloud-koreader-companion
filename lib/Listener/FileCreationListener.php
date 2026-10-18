<?php
namespace OCA\KoreaderCompanion\Listener;

use OCA\KoreaderCompanion\BackgroundJob\IndexFileJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IConfig;

/**
 * Queues background indexing when an ebook file is created or written.
 *
 * Extraction never runs inside the request. Chunked uploads fire NodeWritten
 * many times for the same file, so the job list is checked for duplicates
 * before adding.
 */
class FileCreationListener implements IEventListener {

    private IJobList $jobList;
    private IConfig $config;

    public function __construct(
        IJobList $jobList,
        IConfig $config
    ) {
        $this->jobList = $jobList;
        $this->config = $config;
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent)) {
            return;
        }

        $node = $event->getNode();

        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            return;
        }

        if (!$this->isEbookInBooksFolder($node)) {
            return;
        }

        $userId = $this->extractUserIdFromPath($node->getPath());
        if (!$userId) {
            return;
        }

        $argument = [
            'fileId' => $node->getId(),
            'userId' => $userId,
        ];

        if (!$this->jobList->has(IndexFileJob::class, $argument)) {
            $this->jobList->add(IndexFileJob::class, $argument);
        }
    }

    private function isEbookInBooksFolder($node): bool {
        $path = $node->getPath();

        // Extract user ID from path to get their configured folder
        $userId = $this->extractUserIdFromPath($path);
        if (!$userId) {
            return false;
        }

        $folderName = $this->config->getUserValue($userId, 'koreader_companion', 'folder', 'eBooks');

        if (strpos($path, "/files/$folderName/") === false) {
            return false;
        }

        $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        return in_array($extension, ['epub', 'pdf', 'cbr', 'mobi']);
    }

    private function extractUserIdFromPath(string $path): ?string {
        if (preg_match('/^\/([^\/]+)\/files\//', $path, $matches)) {
            return $matches[1];
        }
        return null;
    }
}