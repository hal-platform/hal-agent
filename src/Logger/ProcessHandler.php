<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Logger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Process;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\JobIdGenerator;

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
    const ERR_NO_DEPLOYMENT = 'No target specified.';
    const ERR_INVALID_DEPLOYMENT = 'Invalid target specified.';
    const ERR_IN_PROGRESS = 'Push %s to target already in progress.';

    /**
     * @type EntityManagerInterface
     */
    private $em;

    /**
     * @type EntityRepository
     */
    private $processRepo;
    private $deploymentRepo;

    /**
     * @type JobIdGenerator
     */
    private $unique;

    /**
     * @param EntityManagerInterface $em
     * @param JobIdGenerator $unique
     */
    public function __construct(EntityManagerInterface $em, JobIdGenerator $unique)
    {
        $this->em = $em;
        $this->unique = $unique;

        $this->processRepo = $em->getRepository(Process::class);
        $this->deploymentRepo = $em->getRepository(Deployment::class);
    }

    /**
     * @param Build|Push $job
     *
     * @return void
     */
    public function abort($job)
    {
        // Silently fail for non-jobs
        if (!$job instanceof Build && !$job instanceof Push) {
            return;
        }

        $children = $this->processRepo->findBy(['parent' => $job->id()]);

        if (!$children) {
            return;
        }

        foreach ($children as $process) {
            $process->withStatus('Aborted');
            $this->em->merge($process);
        }
    }

    /**
     * @param Build|Push $job
     *
     * @return void
     */
    public function launch($job)
    {
        // Silently fail for non-jobs
        if (!$job instanceof Build && !$job instanceof Push) {
            return;
        }

        $children = $this->processRepo->findBy(['parent' => $job->id()]);

        if (!$children) {
            return;
        }

        foreach ($children as $process) {
            $status = 'Aborted';

            if ($process->childType() === 'Push') {
                if ($push = $this->launchPush($job, $process)) {
                    $status = 'Launched';
                    $process->withChild($push);
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
     * @param Build|Push $parent
     * @param Process $process
     *
     * @return Push|null
     */
    private function launchPush($parent, Process $process)
    {
        $build = ($parent instanceof Build) ? $parent : $parent->build();
        $application = $parent->application();
        $context = $process->context();

        // Invalid context
        if (!isset($context['deployment'])) {
            $process->withMessage(self::ERR_NO_DEPLOYMENT);
            return;
        }

        // Err: no valid deployment
        $deployment = $this->deploymentRepo->findOneBy(['id' => $context['deployment'], 'application' => $application]);
        if (!$deployment) {
            $process->withMessage(self::ERR_INVALID_DEPLOYMENT);
            return;
        }

        // Err: active push already underway
        $activePush = $deployment->push();
        if ($activePush && $activePush->isPending()) {
            $process->withMessage(sprintf(self::ERR_IN_PROGRESS, $activePush->id()));
            return;
        }

        $id = $this->unique->generatePushId();

        $push = (new Push($id))
            ->withBuild($build)
            ->withUser($parent->user())
            ->withApplication($application)
            ->withDeployment($deployment);

        // Record active push on deployment
        $deployment->withPush($push);

        $this->em->merge($deployment);
        $this->em->persist($push);

        return $push;
    }
}
