<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Management;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Utility\ResolverTrait;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Repository\JobType\BuildRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Filesystem\Filesystem;

class RemoveBuildCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use ResolverTrait;

    const STEPS = [];

    const COMMAND_TITLE = 'Remove build';
    const MSG_SUCCESS = 'Build removed successfully.';

    const HELP_BUILD = 'ID of the build to remove.';

    const ERR_INVALID_BUILD = 'Invalid build specified.';
    const ERR_INVALID_STATUS = 'Invalid build status. Cannot remove in-progress or failed builds.';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param EntityManagerInterface $em
     * @param Filesystem $filesystem
     */
    public function __construct(EntityManagerInterface $em, Filesystem $filesystem)
    {
        $this->em = $em;
        $this->buildRepo = $this->em->getRepository(Build::class);

        $this->filesystem = $filesystem;
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Remove build from the artifact repository.')
            ->addArgument('BUILD_ID', InputArgument::REQUIRED, self::HELP_BUILD);
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $buildID = $io->getArgument('BUILD_ID');

        $io->title(self::COMMAND_TITLE);

        $build = $this->buildRepo->find($buildID);
        if (!$build instanceof Build) {
            return $this->failure($io, self::ERR_INVALID_BUILD);
        }

        $archive = $this->generateArchiveLocation($build);

        $io->section('Build Information');
        $io->listing([
            sprintf('ID: <info>%s</info>', $build->id()),
            sprintf('Status: <info>%s</info>', $build->status()),
            sprintf('Archive: <info>%s</info>', $archive ?: 'Unknown'),
        ]);

        if (!$archive) {
            return $this->failure($io, self::ERR_INVALID_STATUS);
        }

        $build->withStatus('removed');
        $this->em->merge($build);
        $this->em->flush();

        if ($this->filesystem->exists($archive)) {
            $this->filesystem->remove($archive);
        }

        return $this->success($io, self::MSG_SUCCESS);
    }

    /**
     * @param Build $build
     *
     * @return string
     */
    private function generateArchiveLocation(Build $build)
    {
        if (!$build->isSuccess()) {
            return '';
        }

        return $this->generateBuildArchiveFile($build->id());
    }
}
