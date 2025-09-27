<?php
namespace OCA\KoreaderCompanion\Controller;

use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Service\FilenameService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;

class SettingsController extends Controller {

    private $config;
    private $userSession;
    private $db;
    private $bookService;
    private $rootFolder;
    private $filenameService;

    public function __construct(IRequest $request, IConfig $config, IUserSession $userSession, IDBConnection $db, BookService $bookService, IRootFolder $rootFolder, FilenameService $filenameService, $appName) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->db = $db;
        $this->bookService = $bookService;
        $this->rootFolder = $rootFolder;
        $this->filenameService = $filenameService;
    }

    /**
     * Helper method to get authenticated user or return error response
     */
    private function getAuthenticatedUser() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not logged in'], 401);
        }
        return $user;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setFolder($folder) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        $userId = $user->getUID();
        $currentFolder = $this->config->getUserValue($userId, $this->appName, 'folder', 'eBooks');

        // Check if folder is actually changing
        $isFolderChanging = ($currentFolder !== $folder);

        // Set the new folder
        $this->config->setUserValue($userId, $this->appName, 'folder', $folder);

        // Automatically clear library metadata when folder changes
        if ($isFolderChanging) {
            $cleared = $this->clearLibraryMetadata($userId);
            return new JSONResponse([
                'status' => 'success',
                'folder_changed' => true,
                'cleared' => $cleared,
                'message' => $cleared > 0 ? "Folder updated and library cleared. {$cleared} books will need to be re-indexed." : 'Folder updated.'
            ]);
        }

        return new JSONResponse([
            'status' => 'success',
            'folder_changed' => false
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setAutoRename($auto_rename) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        // Ensure we have a valid string value ('yes' or 'no')
        $value = ($auto_rename === 'yes') ? 'yes' : 'no';

        $this->config->setUserValue($user->getUID(), $this->appName, 'auto_rename', $value);
        return new JSONResponse(['status' => 'success']);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function batchRename($auto_rename) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        $userId = $user->getUID();

        try {
            // First, enable auto-rename setting
            $this->config->setUserValue($userId, $this->appName, 'auto_rename', $auto_rename);

            // Get user's eBooks folder
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folderName = $this->config->getUserValue($userId, $this->appName, 'folder', 'eBooks');

            try {
                $booksFolder = $userFolder->get($folderName);
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['error' => 'eBooks folder not found'], 404);
            }

            $totalBooks = $this->bookService->getTotalBookCount();

            if ($totalBooks === 0) {
                return new JSONResponse([
                    'status' => 'success',
                    'renamed_count' => 0,
                    'total_books' => 0
                ]);
            }

            // Process books immediately with chunked approach
            return $this->processBatchRenameImmediate($userId, $userFolder, $totalBooks);

        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Batch rename failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Process batch rename immediately using optimized chunked processing
     */
    private function processBatchRenameImmediate(string $userId, $userFolder, int $totalBooks): JSONResponse {
        $renamedCount = 0;
        $processedCount = 0;
        $chunkSize = 100;

        // OPTIMIZATION: Sync filesystem metadata ONCE at start of batch operation
        // This eliminates 50+ redundant filesystem scans
        $this->bookService->ensureMetadataUpToDate($userId);

        // Process books in chunks to handle medium libraries efficiently
        $totalPages = ceil($totalBooks / $chunkSize);

        for ($page = 1; $page <= $totalPages; $page++) {
            // Get chunk of books with metadata from database (skip metadata update)
            $books = $this->bookService->getBooks($page, $chunkSize, 'title', true);

            if (empty($books)) {
                continue; // Skip if database approach fails for this chunk
            }

            // Process each book in current chunk
            foreach ($books as $book) {
                try {
                    $fileId = $book['id'];
                    $files = $userFolder->getById($fileId);

                    if (empty($files)) {
                        continue; // File not found, skip
                    }

                    $file = $files[0];
                    $currentName = $file->getName();
                    $processedCount++;

                    // Generate standardized filename using the metadata from database
                    $newName = $this->filenameService->generateStandardFilename($book, $currentName);

                    if ($newName !== $currentName) {
                        // Check for conflicts and resolve
                        $parentFolder = $file->getParent();
                        $finalName = $this->filenameService->resolveFilenameConflict($parentFolder, $newName);

                        // Perform the rename
                        $file->move($parentFolder->getPath() . '/' . $finalName);
                        $renamedCount++;
                    }
                } catch (\Exception $e) {
                    // Log error but continue with other files
                    continue;
                }
            }

            // Reduced delay between chunks since we eliminated the filesystem scanning overhead
            if ($page < $totalPages) {
                usleep(500000); // 0.5s delay between chunks
            }
        }

        return new JSONResponse([
            'status' => 'success',
            'renamed_count' => $renamedCount,
            'total_books' => $totalBooks,
            'processed_count' => $processedCount,
            'processed_immediately' => true
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getSettings() {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }

        $userId = $user->getUID();
        return new JSONResponse([
            'folder' => $this->config->getUserValue($userId, $this->appName, 'folder', 'eBooks'),
            'auto_rename' => $this->config->getUserValue($userId, $this->appName, 'auto_rename', 'no')
        ]);
    }

    /**
     * Clear library metadata while preserving sync progress
     *
     * @param string $userId User ID
     * @return int Number of metadata records cleared
     */
    private function clearLibraryMetadata($userId): int {
        try {
            $this->db->beginTransaction();

            // Count metadata records to be cleared
            $countQb = $this->db->getQueryBuilder();
            $result = $countQb->select($countQb->func()->count('*'))
                ->from('koreader_metadata')
                ->where($countQb->expr()->eq('user_id', $countQb->createNamedParameter($userId)))
                ->executeQuery();
            $count = (int) $result->fetchOne();

            if ($count > 0) {
                // Delete hash mappings first (they reference metadata)
                $hashQb = $this->db->getQueryBuilder();
                $hashQb->delete('koreader_hash_mapping')
                    ->where($hashQb->expr()->eq('user_id', $hashQb->createNamedParameter($userId)))
                    ->executeStatement();

                // Delete metadata records
                $metaQb = $this->db->getQueryBuilder();
                $metaQb->delete('koreader_metadata')
                    ->where($metaQb->expr()->eq('user_id', $metaQb->createNamedParameter($userId)))
                    ->executeStatement();
            }

            // Note: We deliberately do NOT clear koreader_sync_progress to preserve reading progress

            $this->db->commit();
            return $count;
        } catch (\Exception $e) {
            $this->db->rollBack();
            \OC::$server->getLogger()->error('Failed to clear library metadata', [
                'user' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

}
