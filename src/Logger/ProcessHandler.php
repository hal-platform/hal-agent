<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\JobProcess;
use Hal\Core\Entity\Release;
use Hal\Core\Type\JobProcessStatusEnum;
use Hal\Core\Type\JobStatusEnum;

/**
 * This can launch or abort Processes for the following scenarios:
 *
 * Build
 *     -> Child Push
 *
 * Push
 *     -> Child Push
 *
 */
class ProcessHandler
{
    const ERR_NO_DEPLOYMENT = 'No target specified';
    const ERR_INVALID_DEPLOYMENT = 'Invalid target specified';
    const ERR_IN_PROGRESS = 'Release %s to target already in progress';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var EntityRepository
     */
    private $processRepo;
    private $targetRepo;

    /**
     * @param EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;

        $this->processRepo = $em->getRepository(JobProcess::class);
        $this->targetRepo = $em->getRepository(Target::class);
    }

    /**
     * @param Build|Release $job
     *
     * @return void
     */
    public function abort($job)
    {
        // Silently fail for non-jobs
        if (!$job instanceof Build && !$job instanceof Release) {
            return;
        }

        $children = $this->processRepo->findBy(['parentID' => $job->id()]);

        if (!$children) {
            return;
        }

        foreach ($children as $process) {
            $process->withStatus(JobProcessStatusEnum::TYPE_ABORTED);
            $this->em->merge($process);
        }
    }

    /**
     * @param Build|Release $job
     *
     * @return void
     */
    public function launch($job)
    {
        // Silently fail for non-jobs
        if (!$job instanceof Build && !$job instanceof Release) {
            return;
        }

        $children = $this->processRepo->findBy(['parentID' => $job->id()]);

        if (!$children) {
            return;
        }

        foreach ($children as $process) {
            $status = JobProcessStatusEnum::TYPE_ABORTED;

            if ($process->childType() === 'Release') {
                if ($release = $this->launchRelease($job, $process)) {
                    $status = JobProcessStatusEnum::TYPE_LAUNCHED;
                    $process->withChild($release);
                }
            }

            $process->withStatus($status);
            $this->em->merge($process);
        }
    }

    /**
     * From parent:
     *   - User
     *   - Application
     *   - Build
     *
     * From context:
     *   - Deployment (must match parent application)
     *
     * @param Build|Release $parent
     * @param JobProcess $process
     *
     * @return Release|null
     */
    private function launchRelease($parent, JobProcess $process)
    {
        $build = ($parent instanceof Build) ? $parent : $parent->build();
        $application = $parent->application();
        $context = $process->parameters();

        // Invalid context
        if (!isset($context['deployment'])) {
            $process->withMessage(self::ERR_NO_DEPLOYMENT);
            return;
        }

        // Err: no valid deployment
        /** @var Target $target */
        $target = $this->targetRepo->findOneBy(['id' => $context['deployment'], 'application' => $application]);
        if (!$target) {
            $process->withMessage(self::ERR_INVALID_DEPLOYMENT);
            return;
        }

        // Err: active push already underway
        $activeRelease = $target->release();
        if ($activeRelease && in_array($activeRelease->status(), [JobStatusEnum::TYPE_RUNNING, JobStatusEnum::TYPE_PENDING, JobStatusEnum::TYPE_DEPLOYING])) {
            $process->withMessage(sprintf(self::ERR_IN_PROGRESS, $activeRelease->id()));
            return;
        }

        $release = (new Release())
            ->withBuild($build)
            ->withUser($parent->user())
            ->withApplication($application)
            ->withTarget($target);

        // Record active push on deployment
        $target->withRelease($release);

        $this->em->merge($target);
        $this->em->persist($release);

        return $release;
    }
}
