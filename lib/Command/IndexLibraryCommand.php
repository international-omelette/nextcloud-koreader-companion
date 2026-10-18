<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Command;

use OCA\KoreaderCompanion\Service\BookService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Index (or reconcile) the KOReader library from the filesystem.
 *
 * This is the unbounded counterpart to the background reconciler: it is used
 * to seed a fresh install and to rebuild after "clear library".
 */
class IndexLibraryCommand extends Command {

    public function __construct(
        private BookService $bookService,
        private IUserManager $userManager
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('koreader:index')
            ->setDescription('Index the KOReader library from the filesystem')
            ->addArgument(
                'user',
                InputArgument::OPTIONAL,
                'Nextcloud user id to index (defaults to all users)'
            )
            ->addOption(
                'status',
                null,
                InputOption::VALUE_NONE,
                'Only print the indexing status summary'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $userId = $input->getArgument('user');

        $userIds = [];
        if ($userId !== null) {
            if ($this->userManager->get($userId) === null) {
                $output->writeln("<error>User '{$userId}' not found</error>");
                return Command::FAILURE;
            }
            $userIds[] = $userId;
        } else {
            $this->userManager->callForSeenUsers(function ($user) use (&$userIds) {
                $userIds[] = $user->getUID();
                return null;
            });
        }

        if (empty($userIds)) {
            $output->writeln('<comment>No users to index.</comment>');
            return Command::SUCCESS;
        }

        $progress = new ProgressBar($output, count($userIds));
        $progress->setFormat('verbose');
        $progress->start();

        $totalAttempted = 0;
        $totalOrphans = 0;

        foreach ($userIds as $uid) {
            $stats = $this->bookService->reconcileUser($uid);
            $totalAttempted += $stats['attempted'];
            $totalOrphans += $stats['orphans'];

            if ($output->isVerbose() || $input->getOption('status')) {
                $output->writeln('');
                $output->writeln(sprintf(
                    '  %s: visited %d, indexed %d, orphaned %d, complete %s',
                    $uid,
                    $stats['visited'],
                    $stats['attempted'],
                    $stats['orphans'],
                    $stats['complete'] ? 'yes' : 'no'
                ));
            }

            $progress->advance();
        }

        $progress->finish();
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Attempted %d file(s), removed %d orphan(s) for %d user(s).</info>',
            $totalAttempted,
            $totalOrphans,
            count($userIds)
        ));

        return Command::SUCCESS;
    }
}