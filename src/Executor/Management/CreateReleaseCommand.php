<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\JobIdGenerator;
use QL\MCP\Common\Time\Clock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

class CreateReleaseCommand implements ExecutorInterface
{
    use ExecutorTrait;

    const COMMAND_TITLE = 'Create release';
    const MSG_SUCCESS = 'Release created.';

    const HELP_BUILD = 'The ID of the build to deploy.';
    const HELP_TARGET = 'The ID of the target to deploy to.';

    const ERR_NO_BUILD = 'Build not found.';
    const ERR_NOT_RUNNABLE = 'Build cannot be deployed. It is invalid or removed.';
    const ERR_NO_TARGET = 'Deployment target not found.';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var EntityRepository
     */
    private $buildRepo;
    private $deploymentRepo;

    /**
     * @var JobIdGenerator
     */
    private $unique;

    /**
     * @var Push
     */
    private $release;

    /**
     * @param EntityManagerInterface $em
     * @param Clock $clock
     * @param JobIdGenerator $unique
     */
    public function __construct(
        EntityManagerInterface $em,
        Clock $clock,
        JobIdGenerator $unique
    ) {
        $this->clock = $clock;

        $this->em = $em;
        $this->buildRepo = $em->getRepository(Build::class);
        $this->deploymentRepo = $em->getRepository(Deployment::class);

        $this->unique = $unique;
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Create a release to be deployed to a target by a runner.')

            ->addArgument('BUILD_ID', InputArgument::REQUIRED, self::HELP_BUILD)
            ->addArgument('TARGET_ID', InputArgument::REQUIRED, self::HELP_TARGET);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $buildID = $io->getArgument('BUILD_ID');
        $targetID = $io->getArgument('TARGET_ID');

        if (!$build = $this->buildRepo->find($buildID)) {
            return $this->failure($io, self::ERR_NO_BUILD);
        }

        if (!$build->isSuccess()) {
            return $this->failure($io, self::ERR_NOT_RUNNABLE);
        }

        if (!$deployment = $this->deploymentRepo->find($targetID)) {
            return $this->failure($io, self::ERR_NO_TARGET);
        }

        $push = (new Push($this->unique->generatePushId()))
            ->withCreated($this->clock->read())
            ->withStatus('Waiting')

            ->withBuild($build)
            ->withDeployment($deployment)
            ->withApplication($build->application());

        $this->em->persist($push);
        $this->em->flush();

        $repo = sprintf('%s/%s', $push->application()->githubOwner(), $push->application()->githubRepo());

        $io->section('Details');
        $io->listing([
            sprintf('Application: <info>%s</info>', $push->application()->key()),
            sprintf('Environment: <info>%s</info>', $build->environment()->name()),
        ]);

        $io->section('Build Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $build->id()),
            sprintf('Source: <info>%s</info>', $repo),
            sprintf('Reference: <info>%s</info> (%s)', $build->branch(), $build->commit())
        ]);

        $io->section('Release Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $push->id()),
            sprintf('Target: <info>%s</info>', $deployment->formatPretty(true)),
        ]);

        $this->release = $push;
        $this->success($io, self::MSG_SUCCESS);
    }

    /**
     * Used to expose the created release to other commands.
     *
     * It's rather hacky.
     *
     * @return Push|null
     */
    public function release()
    {
        return $this->release;
    }
}
