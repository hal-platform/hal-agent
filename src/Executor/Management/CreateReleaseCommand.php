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
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\Release;
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
    const ERR_NO_TARGET = 'Release target not found.';

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
    private $targetRepo;

    /**
     * @var Release
     */
    private $release;

    /**
     * @param EntityManagerInterface $em
     * @param Clock $clock
     */
    public function __construct(
        EntityManagerInterface $em,
        Clock $clock
    ) {
        $this->clock = $clock;

        $this->em = $em;
        $this->buildRepo = $em->getRepository(Build::class);
        $this->targetRepo = $em->getRepository(Target::class);
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

        if (!$target = $this->targetRepo->find($targetID)) {
            return $this->failure($io, self::ERR_NO_TARGET);
        }

        $release = (new Release())
            ->withStatus('Pending')

            ->withBuild($build)
            ->withTarget($target)
            ->withApplication($build->application());

        $this->em->persist($release);
        $this->em->flush();

        $repo = sprintf('%s/%s', $release->application()->gitHub()->owner(), $release->application()->gitHub()->repository());

        $io->section('Details');
        $io->listing([
            sprintf('Application: <info>%s</info>', $release->application()->identifier()),
            sprintf('Environment: <info>%s</info>', $target->group()->environment()->name()),
        ]);

        $io->section('Build Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $build->id()),
            sprintf('Source: <info>%s</info>', $repo),
            sprintf('Reference: <info>%s</info> (%s)', $build->reference(), $build->commit())
        ]);

        $io->section('Release Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $release->id()),
            sprintf('Target: <info>%s</info>', $target->format(true)),
        ]);

        $this->release = $release;
        $this->success($io, self::MSG_SUCCESS);
    }

    /**
     * Used to expose the created release to other commands.
     *
     * It's rather hacky.
     *
     * @return Release|null
     */
    public function release()
    {
        return $this->release;
    }
}
