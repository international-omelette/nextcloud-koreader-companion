<?php
namespace OCA\KoreaderCompanion\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;

class SettingsController extends Controller {

    private $config;
    private $userSession;
    private $db;

    public function __construct(IRequest $request, IConfig $config, IUserSession $userSession, IDBConnection $db, $appName) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->db = $db;
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
    public function setRestrictUploads($value) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }
        $this->config->setUserValue($user->getUID(), $this->appName, 'restrict_uploads', $value);
        return new JSONResponse(['status' => 'success']);
    }


    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setAutoRename($value) {
        $user = $this->getAuthenticatedUser();
        if ($user instanceof JSONResponse) {
            return $user; // Return error response
        }
        $this->config->setUserValue($user->getUID(), $this->appName, 'auto_rename', $value);
        return new JSONResponse(['status' => 'success']);
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
            'restrict_uploads' => $this->config->getUserValue($userId, $this->appName, 'restrict_uploads', 'no'),
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
