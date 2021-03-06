<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\ScheduledAction;
use Hal\Core\Entity\Target;
use Hal\Core\Type\JobStatusEnum;
use Hal\Core\Type\ScheduledActionStatusEnum;

/**
 * This can launch or abort scheduled actions
 */
class ProcessHandler
{
    private const SUCCESS_LAUNCHED_JOB = 'Scheduled job launched successfully';

    private const ERR_NO_TARGET = 'No target specified';
    private const ERR_INVALID_TARGET = 'Invalid target specified';
    private const ERR_IN_PROGRESS = 'Release %s to target already in progress';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var ObjectRepository
     */
    private $scheduledRepo;

    /**
     * @var ObjectRepository
     */
    private $targetRepo;

    /**
     * @param EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;

        $this->scheduledRepo = $em->getRepository(ScheduledAction::class);
        $this->targetRepo = $em->getRepository(Target::class);
    }

    /**
     * @param Job $job
     *
     * @return bool
     */
    public function abort(Job $job): bool
    {
        $scheduled = $this->scheduledRepo->findBy(['triggerJob' => $job, 'status' => ScheduledActionStatusEnum::TYPE_PENDING]);
        if (!$scheduled) {
            return false;
        }

        foreach ($scheduled as $action) {
            $action->withStatus(ScheduledActionStatusEnum::TYPE_ABORTED);
            $this->em->merge($action);
        }

        return true;
    }

    /**
     * @param Job $job
     *
     * @return bool
     */
    public function launch(Job $job): bool
    {
        $scheduled = $this->scheduledRepo->findBy(['triggerJob' => $job, 'status' => ScheduledActionStatusEnum::TYPE_PENDING]);
        if (!$scheduled) {
            return false;
        }

        foreach ($scheduled as $action) {
            $status = ScheduledActionStatusEnum::TYPE_ABORTED;

            if ($action->triggerJob() instanceof Build && $action->parameter('entity') === 'Release') {
                if ($release = $this->launchRelease($action, $action->triggerJob())) {
                    $status = ScheduledActionStatusEnum::TYPE_LAUNCHED;
                    $action->withScheduledJob($release);
                    $action->withMessage(self::SUCCESS_LAUNCHED_JOB);
                }
            }

            $action->withStatus($status);
            $this->em->merge($action);
        }

        return true;
    }

    /**
     * @param ScheduledAction $action
     * @param Build $build
     *
     * @return Release|null
     */
    private function launchRelease(ScheduledAction $action, Build $build)
    {
        $application = $build->application();
        $user = $build->user();

        // Invalid parameters
        if (!$targetID = $action->parameter('target_id')) {
            $action->withMessage(self::ERR_NO_TARGET);
            return null;
        }

        // Err: no valid deployment
        if (!$target = $this->targetRepo->findOneBy(['id' => $targetID, 'application' => $application])) {
            $action->withMessage(self::ERR_INVALID_TARGET);
            return null;
        }


        if (!$target instanceof Target) {
            return null;
        }

        // Err: active push already underway
        $lastJob = $target->lastJob();
        if ($lastJob && $lastJob->inProgress()) {
            $action->withMessage(sprintf(self::ERR_IN_PROGRESS, $lastJob->id()));
            return null;
        }

        $release = (new Release)
            ->withStatus(JobStatusEnum::TYPE_PENDING)
            ->withBuild($build)
            ->withTarget($target)

            ->withUser($user)
            ->withApplication($application)
            ->withEnvironment($target->environment());

        // Record active release on target
        $target->withLastJob($release);

        $this->em->merge($target);
        $this->em->persist($release);

        return $release;
    }
}
