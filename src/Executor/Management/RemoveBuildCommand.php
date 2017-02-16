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
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Repository\BuildRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Filesystem\Filesystem;

class RemoveBuildCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use ResolverTrait;

    const COMMAND_TITLE = 'Build - Remove';

    const ERR_MISSING_BUILD_ARGS = 'At least one build must be specified for removal.';
    const ERR_BUILD_NOT_FOUND = 'Build "%s" not found.';
    const ERR_NOT_FINISHED = 'Build "%s" must be status "Success" or "Error" to be removed';
    const ERR_ALREADY_REMOVED = 'Archive for build "%s" was already removed.';

    const SUCCESS_MSG = 'Archive for build "%s" removed.';

    const REMOVED_SUMMARY = '%s builds processed. (%s successes, %s failures)';

    const FS_ARCHIVE_PREFIX = 'hal9000-%s.tar.gz';

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

    public function __construct(EntityManagerInterface $em, Filesystem $filesystem)
    {
        $this->em = $em;
        $this->buildRepo = $this->em->getRepository(Build::class);

        $this->filesystem = $filesystem;
    }

    /**
     * Configure the command.
     *
     * Set the command definition such as expected arguments, flags, or help text.
     *
     * @param Command $command
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Remove build archive')
            ->addArgument(
                'BUILD_ID',
                InputArgument::IS_ARRAY,
                'The ID(s) of the build(s)'
            );
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $io->title(self::COMMAND_TITLE);

        $builds = $io->getArgument('BUILD_ID');

        if (!$builds) {
            return $this->failure($io, self::ERR_MISSING_BUILD_ARGS);
        }

        $success = 0;
        $failure = 0;

        foreach ($builds as $buildId) {
            if ($this->removeBuild($io, $buildId) === 0) {
                $success++;
            } else {
                $failure++;
            }
        }

        // bubble up the build exit status if only one build
        if (count($builds) === 1) {
            if ($success > 0) {
                return 0;
            } else {
                return 1;
            }
        }

        // determine messaging for multi-removals
        $msg = sprintf(self::REMOVED_SUMMARY, count($builds), $success, $failure);
        if ($failure > 0) {
            return $this->failure($io, $msg);
        }

        return $this->success($io, $msg);
    }

    private function removeBuild(StyleInterface $io, $buildId)
    {
        /** @var Build $build */
        $build = $this->buildRepo->find($buildId);

        if (!$build instanceof Build) {
            return $this->failure($io, sprintf(self::ERR_BUILD_NOT_FOUND, $buildId));
        }

        if (!$archives = $this->generateArchiveLocations($build)) {
            return $this->failure($io, sprintf(self::ERR_NOT_FINISHED, $buildId));
        }

        $build->withStatus('Removed');
        $this->em->merge($build);
        $this->em->flush();

        if (!$source = $this->findSource($archives)) {
            return $this->failure($io, sprintf(self::ERR_ALREADY_REMOVED, $buildId));
        }

        $this->filesystem->remove($source);
        return $this->success($io, sprintf(self::SUCCESS_MSG, $buildId));
    }

    /**
     * @param Build $build
     *
     * @return string[]
     */
    private function generateArchiveLocations(Build $build)
    {
        //error and success builds? why not?
        if (!in_array($build->status(), ['Error', 'Success'], true)) {
            return [];
        }

        return [
            $this->generateBuildArchiveFile($build->id()),
            $this->generateLegacyBuildArchiveFile($build->id()) //still need this?
        ];
    }

    /**
     * @param string[] $sources
     *
     * @return string|null
     */
    private function findSource(array $sources)
    {
        foreach ($sources as $potentialSource) {
            if ($this->filesystem->exists($potentialSource)) {
                return $potentialSource;
            }
        }

        return null;
    }
}
