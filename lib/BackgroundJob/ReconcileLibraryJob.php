<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Hourly sweep that schedules a bounded reconcile job per seen user.
 *
 * This is the safety net for files that arrive without Node events (files:scan,
 * external storage, direct writes) and for every pre-existing install on
 * upgrade. The heavy lifting happens in ReconcileUserJob so each user is
 * isolated and can be retried independently after a fatal error.
 */
class ReconcileLibraryJob extends TimedJob {

    private IUserManager $userManager;
    private IJobList $jobList;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        IUserManager $userManager,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->userManager = $userManager;
        $this->jobList = $jobList;
        $this->logger = $logger;

        $this->setInterval(3600);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $this->userManager->callForSeenUsers(function (IUser $user) {
            $userId = $user->getUID();

            if (!$this->jobList->has(ReconcileUserJob::class, ['userId' => $userId])) {
                $this->jobList->add(ReconcileUserJob::class, ['userId' => $userId]);
            }

            return null;
        });
    }
}