<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Remove build archive
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class RemoveBuildCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;

    /**
     * @var string
     */
    const FS_ARCHIVE_PREFIX = 'hal9000-%s.tar.gz';

    /**
     * A list of all possible exit codes of this command
     *
     * @type array
     */
    private static $codes = [
        0 => 'Archive removed.',
        1 => 'At least once build must be specified for removal.'
    ];

    /**
     * @type EntityManagerInterface
     */
    private $em;

    /**
     * @type EntityRepository
     */
    private $buildRepo;

    /**
     * @type Filesystem
     */
    private $filesystem;

    /**
     * @type string
     */
    private $archivePath;

    /**
     * @param string $name
     * @param EntityManagerInterface $em
     * @param Filesystem $filesystem
     * @param string $archivePath
     */
    public function __construct($name,
        EntityManagerInterface $em,
        Filesystem $filesystem,
        $archivePath
    ) {
        parent::__construct($name);

        $this->em = $em;
        $this->buildRepo = $em->getRepository(Build::CLASS);

        $this->filesystem = $filesystem;
        $this->archivePath = $archivePath;
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Remove build archive.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::IS_ARRAY,
                'The ID of the build.'
            );

        $help = [
            '<fg=cyan>Exit codes:</fg=cyan>'
        ];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $help));
    }

    /**
     * Run the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $builds = $input->getArgument('BUILD_ID');

        if (!$builds) {
            return $this->failure($output, 1);
        }

        $success = 0;
        $failure = 0;

        foreach ($builds as $buildId) {
            if ($this->removeBuild($buildId, $output) === 0) {
                $success++;
            } else {
                $failure++;
            }
        }

        // bubble up the build exit status if only one build
        if (count($builds) === 1) {
            if ($success > 0) {
                return $this->success($output);
            } else {
                return 1;
            }
        }

        // determine messaging for multiremovals
        $msg = sprintf('%s builds processed. (%s successes, %s failures)', count($builds), $success, $failure);
        if ($failure > 0) {
            $output->writeln(sprintf('<error>%s</error>', $msg));
            return 1;
        }

        $output->writeln(sprintf('<bg=green>%s</bg=green>', $msg));
        return 0;
    }

    /**
     * @param string $buildId
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int $exitCode
     */
    private function removeBuild($buildId, OutputInterface $output)
    {
        if (!$build = $this->buildRepo->find($buildId)) {
            $output->writeln(sprintf('<error>%s</error>', sprintf('Build "%s" not found.', $buildId)));
            return 1;
        }

        if (!$archive = $this->generateArchiveLocation($build)) {
            $output->writeln(sprintf('<error>%s</error>', sprintf('Build "%s" must be status "Success" to be removed.', $buildId)));
            return 1;
        }

        // Update entity
        $build->withStatus('Removed');
        $this->em->merge($build);
        $this->em->flush();

        // remove file
        if (!$this->filesystem->exists($archive)) {
            $output->writeln(sprintf('<error>%s</error>', sprintf('Archive for build "%s" was already removed.', $buildId)));
            return 1;
        }

        $this->filesystem->remove($archive);
        return $this->success($output, sprintf('Archive for build "%s" removed.', $buildId));
    }

    /**
     *  @param Build $build
     *  @return string
     */
    private function generateArchiveLocation(Build $build)
    {
        if ($build->status() !== 'Success') {
            return '';
        }

        return sprintf(
            '%s%s%s',
            rtrim($this->archivePath, '/'),
            DIRECTORY_SEPARATOR,
            sprintf(self::FS_ARCHIVE_PREFIX, $build->id())
        );
    }
}
