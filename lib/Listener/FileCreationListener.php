<?php
namespace OCA\KoreaderCompanion\Listener;

use OCA\KoreaderCompanion\Service\BookService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IConfig;

class FileCreationListener implements IEventListener {

    private $bookService;
    private $config;

    public function __construct(
        BookService $bookService,
        IConfig $config
    ) {
        $this->bookService = $bookService;
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

        $this->bookService->syncFileMetadata($node, $userId);
    }

    private function isEbookInBooksFolder($node): bool {
        $path = $node->getPath();
        $folderName = $this->config->getAppValue('koreader_companion', 'folder', 'eBooks');

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
