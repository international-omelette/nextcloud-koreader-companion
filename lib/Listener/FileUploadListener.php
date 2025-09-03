<?php
namespace OCA\KoreaderCompanion\Listener;

use OCA\KoreaderCompanion\Service\FileTrackingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Files\IRootFolder;

/**
 * Listens for file system events to detect external uploads to Books folder
 * and handle them according to upload restriction settings
 */
class FileUploadListener implements IEventListener {

    private $config;
    private $userSession;
    private $rootFolder;
    private $fileTrackingService;

    public function __construct(
        IConfig $config,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        FileTrackingService $fileTrackingService
    ) {
        $this->config = $config;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->fileTrackingService = $fileTrackingService;
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent)) {
            return;
        }

        $node = $event->getNode();
        
        // Only process files (not directories)
        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            return;
        }

        // Check if upload restrictions are enabled
        $restrictUploads = $this->config->getAppValue('koreader_companion', 'restrict_uploads', 'no');
        if ($restrictUploads !== 'yes') {
            return; // No restrictions enabled
        }

        // Check if this is an ebook file in the configured Books folder
        if (!$this->isEbookInBooksFolder($node)) {
            return;
        }

        // Extract user ID from file path
        $userId = $this->extractUserIdFromPath($node->getPath());
        if (!$userId) {
            return;
        }

        // Check if this file was uploaded through the app
        if (!$this->fileTrackingService->isAppUploadedFile($node, $userId)) {
            $this->handleExternalUpload($node, $userId);
        }
    }

    private function isEbookInBooksFolder($node): bool {
        $path = $node->getPath();
        $folderName = $this->config->getAppValue('koreader_companion', 'folder', 'eBooks');
        
        // Check if file is in the configured eBooks folder
        if (strpos($path, "/files/$folderName/") === false) {
            return false;
        }

        // Check if it's an ebook file
        $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        return in_array($extension, ['epub', 'pdf', 'cbr', 'mobi']);
    }

    private function extractUserIdFromPath(string $path): ?string {
        if (preg_match('/^\/([^\/]+)\/files\//', $path, $matches)) {
            return $matches[1];
        }
        return null;
    }


    private function handleExternalUpload($node, string $userId): void {
        // Mark file as external upload in tracking
        $this->fileTrackingService->markFileAsExternalUpload($node, $userId);
        
        // Check if auto-cleanup is enabled
        $autoCleanup = $this->config->getAppValue('koreader_companion', 'auto_cleanup', 'no');
        
        if ($autoCleanup === 'yes') {
            $this->quarantineFile($node, $userId);
        }

        // Log the external upload
        error_log("eBooks app: External upload detected - {$node->getName()} for user $userId" .
                 ($autoCleanup === 'yes' ? ' (quarantined)' : ' (ignored in library)'));
    }

    private function quarantineFile($file, string $userId): void {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            
            // Create quarantine folder if it doesn't exist
            try {
                $quarantineFolder = $userFolder->get('Books_Quarantine');
            } catch (\OCP\Files\NotFoundException $e) {
                $quarantineFolder = $userFolder->newFolder('Books_Quarantine');
            }

            // Move file to quarantine with auto-renaming
            $newName = $file->getName();
            $counter = 1;
            while ($quarantineFolder->nodeExists($newName)) {
                $pathInfo = pathinfo($file->getName());
                $newName = $pathInfo['filename'] . "_$counter." . $pathInfo['extension'];
                $counter++;
            }

            $file->move($quarantineFolder->getPath() . '/' . $newName);
            
            error_log("eBooks app: Quarantined external upload {$file->getName()} to Books_Quarantine/$newName for user $userId");
            
        } catch (\Exception $e) {
            error_log("eBooks app: Failed to quarantine external upload {$file->getName()} for user $userId: " . $e->getMessage());
        }
    }
}