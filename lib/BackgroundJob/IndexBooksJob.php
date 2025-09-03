<?php
namespace OCA\KoreaderCompanion\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCA\KoreaderCompanion\Service\BookService;
use OCA\KoreaderCompanion\Service\IndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

class IndexBooksJob extends TimedJob {

    private $bookService;
    private $indexService;
    private $logger;

    public function __construct(ITimeFactory $timeFactory, BookService $bookService, IndexService $indexService, LoggerInterface $logger) {
        parent::__construct($timeFactory);
        $this->bookService = $bookService;
        $this->indexService = $indexService;
        $this->logger = $logger;
        
        // Run every hour
        $this->setInterval(3600);
    }

    protected function run($argument) {
        $this->logger->info('Starting background book indexing job');
        
        try {
            // Get all users and scan their configured book folders
            $users = \OC::$server->getUserManager()->search('');
            $totalBooks = 0;
            $newBooks = 0;
            $updatedBooks = 0;
            
            foreach ($users as $user) {
                $this->logger->info('Scanning books for user: ' . $user->getUID());
                
                $userStats = $this->indexService->indexUserBooks($user->getUID());
                $totalBooks += $userStats['total'];
                $newBooks += $userStats['new'];
                $updatedBooks += $userStats['updated'];
            }
            
            $this->logger->info("Background indexing completed - Total: $totalBooks, New: $newBooks, Updated: $updatedBooks");
            
        } catch (\Exception $e) {
            $this->logger->error('Background indexing job failed: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }
}