<?php
namespace OCA\KoreaderCompanion\Controller;

use OCA\KoreaderCompanion\BackgroundJob\ReconcileUserJob;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class KoreaderController extends Controller {

    private $db;
    private $userSession;
    private $userManager;
    private $config;
    private $jobList;
    private $logger;

    public function __construct(
        $AppName, 
        IRequest $request, 
        IDBConnection $db, 
        IUserSession $userSession, 
        IUserManager $userManager, 
        IConfig $config,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        parent::__construct($AppName, $request);
        $this->db = $db;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->config = $config;
        $this->jobList = $jobList;
        $this->logger = $logger;
    }

    
    /**
     * @NoCSRFRequired
     * @PublicPage
     * @NoAdminRequired
     */
    public function authUser() {
        if (!$this->authenticateKoreader()) {
            return $this->createKoreaderResponse(['message' => 'Unauthorized'], 401);
        }
        
        return $this->createKoreaderResponse(['message' => 'OK']);
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @NoAdminRequired
     */
    public function getProgress($document) {
        if (!$this->authenticateKoreader()) {
            return $this->createKoreaderResponse(['message' => 'Unauthorized'], 401);
        }

        $syncUser = $this->getCurrentSyncUser();
        if (!$syncUser) {
            return $this->createKoreaderResponse(['message' => 'Sync user not found'], 404);
        }
        
        // First try to get progress using the document hash directly
        $progress = $this->getDocumentProgress($syncUser['id'], $document);
        
        // If not found, ask the reconciler to index and return not-found.
        // KOReader retries, so eventual consistency is acceptable.
        if (!$progress) {
            $bookInfo = $this->findBookByHash($document, $syncUser['id']);
            if (!$bookInfo) {
                $this->queueReconcileIfNeeded($syncUser['id']);
                $this->logUnknownDocument($document, $syncUser['id']);
                return $this->createKoreaderResponse(['message' => 'Document not found'], 404);
            }
            
            // Try again with the mapped document hash
            $progress = $this->getDocumentProgress($syncUser['id'], $document);
        }
        
        if (!$progress) {
            return $this->createKoreaderResponse(['message' => 'Document not found'], 404);
        }

        return $this->createKoreaderResponse([
            'progress' => $progress['progress'] ?? '',
            'percentage' => (float)($progress['percentage'] ?? 0.0),
            'device' => $progress['device'] ?? '',
            'device_id' => $progress['device_id'] ?? ''
        ]);
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @NoAdminRequired
     */
    public function updateProgress() {
        if (!$this->authenticateKoreader()) {
            return $this->createKoreaderResponse(['message' => 'Unauthorized'], 401);
        }

        $syncUser = $this->getCurrentSyncUser();
        if (!$syncUser) {
            return $this->createKoreaderResponse(['message' => 'Sync user not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $document = $input['document'] ?? '';
        $progress = $input['progress'] ?? null;
        $percentage = $input['percentage'] ?? null;
        $device = $input['device'] ?? '';
        $deviceId = $input['device_id'] ?? '';
        
        if (empty($document)) {
            return $this->createKoreaderResponse(['error' => 'Document hash required'], 400);
        }
        
        // DB-only lookup; on a miss queue reconciliation (rate-limited).
        // Progress is still saved for unknown documents for KOReader compat.
        $bookInfo = $this->findBookByHash($document, $syncUser['id']);
        if (!$bookInfo) {
            $this->queueReconcileIfNeeded($syncUser['id']);
            $this->logUnknownDocument($document, $syncUser['id']);
        }
        
        $this->saveDocumentProgress($syncUser['id'], $document, $progress, $percentage, $device, $deviceId);
        
        return $this->createKoreaderResponse(['message' => 'Progress updated']);
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @NoAdminRequired
     */
    public function healthcheck() {
        return $this->createKoreaderResponse(['state' => 'OK']);
    }

    private function authenticateKoreader() {
        $authUser = $this->request->getHeader('x-auth-user');
        $authKey = $this->request->getHeader('x-auth-key');

        if (!$authUser || !$authKey) {
            return false;
        }

        // Check if user exists in Nextcloud
        $user = $this->userManager->get($authUser);
        if (!$user) {
            return false;
        }

        // Get user's stored KOReader sync password MD5 hash
        $storedMd5Hash = $this->config->getUserValue($authUser, 'koreader_companion', 'koreader_sync_password', '');

        if (empty($storedMd5Hash)) {
            return false; // No sync password set for this user
        }

        // KOReader sends MD5 hash of password in x-auth-key header
        // Compare directly with stored MD5 hash
        $authSuccess = false;

        if (strlen($authKey) === 32 && ctype_xdigit($authKey)) {
            // KOReader sent MD5 hash - compare directly with stored MD5 hash
            if ($authKey === $storedMd5Hash) {
                $authSuccess = true;
            }
        }

        if (!$authSuccess) {
            return false;
        }

        // Set authenticated user in session
        $this->userSession->setUser($user);

        return true;
    }
    
    private function getCurrentSyncUser() {
        $authUser = $this->request->getHeader('x-auth-user');
        
        if (!$authUser) {
            return null;
        }
        
        // Check if user exists in Nextcloud
        $user = $this->userManager->get($authUser);
        if (!$user) {
            return null;
        }
        
        // Return basic user info for progress tracking
        return [
            'id' => $authUser,  // Use username as ID for progress table
            'username' => $authUser,
            'nextcloud_user_id' => $authUser
        ];
    }
    
    
    private function getDocumentProgress($userId, $document) {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from('koreader_sync_progress')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('document_hash', $qb->createNamedParameter($document)))
            ->executeQuery();
        
        $progress = $result->fetch();
        $result->closeCursor();
        
        return $progress ?: null;
    }
    
    private function saveDocumentProgress($userId, $document, $progress, $percentage, $device, $deviceId) {
        $qb = $this->db->getQueryBuilder();
        
        // Check if progress exists
        $existing = $this->getDocumentProgress($userId, $document);
        
        if ($existing) {
            // Update existing record
            $qb->update('koreader_sync_progress')
                ->set('progress', $qb->createNamedParameter($progress))
                ->set('percentage', $qb->createNamedParameter($percentage))
                ->set('device', $qb->createNamedParameter($device))
                ->set('device_id', $qb->createNamedParameter($deviceId))
                ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('document_hash', $qb->createNamedParameter($document)))
                ->executeStatement();
        } else {
            // Insert new record
            $qb->insert('koreader_sync_progress')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'document_hash' => $qb->createNamedParameter($document),
                    'progress' => $qb->createNamedParameter($progress),
                    'percentage' => $qb->createNamedParameter($percentage),
                    'device' => $qb->createNamedParameter($device),
                    'device_id' => $qb->createNamedParameter($deviceId),
                    'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s'))
                ])
                ->executeStatement();
        }
    }

    /**
     * Find a book by its document hash using the hash mapping table
     *
     * @param string $documentHash MD5 hash from KOReader
     * @param string $userId Nextcloud username
     * @return array|null Book metadata if found, null if not found
     */
    private function findBookByHash(string $documentHash, string $userId): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('hm.metadata_id', 'hm.hash_type', 'em.file_id', 'em.title', 'em.author')
                ->from('koreader_hash_mapping', 'hm')
                ->innerJoin('hm', 'koreader_metadata', 'em', 'hm.metadata_id = em.id')
                ->where($qb->expr()->eq('hm.user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('hm.document_hash', $qb->createNamedParameter($documentHash)))
                ->executeQuery();

            $row = $result->fetch();
            $result->closeCursor();

            if ($row) {
                $this->logger->info('Found book by hash in mapping table', [
                    'hash' => $documentHash,
                    'user' => $userId,
                    'title' => $row['title'],
                    'hash_type' => $row['hash_type']
                ]);
                return $row;
            }

            // Not found in mapping table - let's check if we can find it by scanning books
            $this->logger->info('Book not found in hash mapping table', [
                'hash' => $documentHash,
                'user' => $userId
            ]);

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Error finding book by hash', [
                'hash' => $documentHash,
                'user' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Queue a bounded reconciliation for this user, at most once per 10 minutes.
     *
     * KOReader pushes progress frequently and unknown hashes (books outside the
     * library) never match, so an unbounded walk on every push is not acceptable.
     */
    private function queueReconcileIfNeeded(string $userId): void {
        try {
            $last = (int)$this->config->getUserValue($userId, 'koreader_companion', 'last_reconcile_queued', '0');
            if (time() - $last < 600) {
                return;
            }

            if (!$this->jobList->has(ReconcileUserJob::class, ['userId' => $userId])) {
                $this->jobList->add(ReconcileUserJob::class, ['userId' => $userId]);
            }
            $this->config->setUserValue($userId, 'koreader_companion', 'last_reconcile_queued', (string)time());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to queue reconcile job', [
                'user_id' => $userId,
                'exception' => $e
            ]);
        }
    }

    /**
     * Log unknown document hash
     *
     * @param string $documentHash MD5 hash from KOReader
     * @param string $userId Nextcloud username
     */
    private function logUnknownDocument(string $documentHash, string $userId): void {
        $this->logger->warning('Unknown document hash in KOReader sync', [
            'hash' => $documentHash,
            'user' => $userId,
            'message' => 'Document not found in hash mapping table and auto-indexing failed'
        ]);
        
        // Could also implement additional logging to a separate table for analytics
        // or administrative purposes if needed
    }

    /**
     * Parse various date formats into YYYY-MM-DD format for publication_date field
     */
    private function parsePublicationDate(?string $dateValue): ?string {
        if (empty($dateValue)) {
            return null;
        }

        $dateValue = trim($dateValue);

        // Handle 4-digit year format (most common case)
        if (preg_match('/^\d{4}$/', $dateValue)) {
            return $dateValue . '-01-01'; // Default to January 1st
        }

        // Handle YYYY-MM format
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            return "$year-$month-01"; // Default to 1st of month
        }

        // Handle YYYY-MM-DD format (already correct)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateValue, $matches)) {
            $year = $matches[1];
            $month = sprintf('%02d', intval($matches[2]));
            $day = sprintf('%02d', intval($matches[3]));
            
            // Validate the date
            if (checkdate(intval($month), intval($day), intval($year))) {
                return "$year-$month-$day";
            }
        }

        // Extract year from any string containing a 4-digit year
        if (preg_match('/(\d{4})/', $dateValue, $matches)) {
            $year = $matches[1];
            // Validate year range
            if (intval($year) >= 1000 && intval($year) <= 2099) {
                return "$year-01-01"; // Default to January 1st
            }
        }

        // Could not parse the date
        return null;
    }

    /**
     * Create KOReader-compliant JSON response
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return JSONResponse
     */
    private function createKoreaderResponse(array $data, int $statusCode = 200): JSONResponse {
        $response = new JSONResponse($data, $statusCode);
        $response->addHeader('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Validate client accepts KOReader content type
     * 
     * @return bool True if client accepts KOReader content type
     */
    private function validateAcceptHeader(): bool {
        $acceptHeader = $this->request->getHeader('Accept');
        
        // Accept if no Accept header is specified (backward compatibility)
        if (empty($acceptHeader)) {
            return true;
        }
        
        // Check for exact match or wildcard
        return strpos($acceptHeader, 'application/vnd.koreader.v1+json') !== false ||
               strpos($acceptHeader, 'application/*') !== false ||
               strpos($acceptHeader, '*/*') !== false;
    }
}
