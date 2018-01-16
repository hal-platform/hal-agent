<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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
    private const ERR_NO_TARGET = 'No target specified';
    private const ERR_INVALID_TARGET = 'Invalid target specified';
    private const ERR_IN_PROGRESS = 'Release %s to target already in progress';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var EntityRepository
     */
    private $scheduledRepo;
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
     * @return void
     */
    public function abort(Job $job)
    {
        $scheduled = $this->scheduledRepo->findBy(['triggerJob' => $job]);
        if (!$scheduled) {
            return;
        }

        foreach ($scheduled as $action) {
            $action->withStatus(ScheduledActionStatusEnum::TYPE_ABORTED);
            $this->em->merge($action);
        }
    }

    /**
     * @param Job $job
     *
     * @return void
     */
    public function launch(Job $job)
    {
        $scheduled = $this->scheduledRepo->findBy(['triggerJob' => $job]);
        if (!$scheduled) {
            return;
        }

        foreach ($scheduled as $action) {
            $status = ScheduledActionStatusEnum::TYPE_ABORTED;

            if ($action->triggerJob() instanceof Build && $action->parameter('entity') === 'Release') {
                if ($release = $this->launchRelease($action, $action->triggerJob())) {
                    $status = ScheduledActionStatusEnum::TYPE_LAUNCHED;
                    $action->withScheduledJob($release);
                }
            }

            $process->withStatus($status);
            $this->em->merge($process);
        }
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
        if ($targetID = $action->parameter('target_id')) {
            $process->withMessage(self::ERR_NO_TARGET);
            return;
        }

        // Err: no valid deployment
        $target = $this->targetRepo->find(['id' => $targetID, 'application' => $application]);
        if (!$target) {
            $process->withMessage(self::ERR_INVALID_TARGET);
            return;
        }

        // Err: active push already underway
        $lastJob = $target->lastJob();
        if ($lastJob && $lastJob->inProgress()) {
            $process->withMessage(sprintf(self::ERR_IN_PROGRESS, $lastJob->id()));
            return;
        }

        $release = (new Release)
            ->withStatus(JobStatusEnum::TYPE_PENDING)
            ->withBuild($build)
            ->withTarget($target)

            ->withUser($user)
            ->withApplication($application)
            ->withEnvironment($target->environment());

        // Record active release on target
        $target->withJob($release);

        $this->em->merge($target);
        $this->em->persist($release);

        return $release;
    }
}
