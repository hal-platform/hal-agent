<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManager;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;
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
     * @var array
     */
    private static $codes = [
        0 => 'Archive removed.',
        1 => 'Build not found.',
        2 => 'Incorrect build status. Only Successful builds can be removed.',
        4 => 'Build archive already removed.'
    ];

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $archivePath;

    /**
     * @param string $name
     * @param EntityManager $entityManager
     * @param BuildRepository $buildRepo
     * @param Filesystem $filesystem
     * @param string $archivePath
     */
    public function __construct($name,
        EntityManager $entityManager,
        BuildRepository $buildRepo,
        Filesystem $filesystem,
        $archivePath
    ) {
        parent::__construct($name);

        $this->entityManager = $entityManager;
        $this->buildRepo = $buildRepo;
        $this->filesystem = $filesystem;
        $this->archivePath = $archivePath;
    }

    /**
     *  Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Remove build archive.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::REQUIRED,
                'The ID of the build.'
            )
            ->addOption(
                'silent',
                null,
                InputOption::VALUE_NONE,
                'Silently fail if archive does not exist.'
            );

        $help = ['<fg=cyan>Exit codes:</fg=cyan>'];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $help));
    }

    /**
     *  Run the command
     *
     *  @param InputInterface $input
     *  @param OutputInterface $output
     *  @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $buildId = $input->getArgument('BUILD_ID');
        $isSilent = $input->getOption('silent');

        if (!$build = $this->buildRepo->find($buildId)) {
            return $this->silentFailure($output, $isSilent, 1);
        }

        if (!$archive = $this->generateArchiveLocation($build)) {
            return $this->silentFailure($output, $isSilent, 2);
        }

        // Update entity
        $build->setStatus('Removed');
        $this->entityManager->merge($build);
        $this->entityManager->flush();

        if (!$this->filesystem->exists($archive)) {
            return $this->silentFailure($output, $isSilent, 4);
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
        if ($build->getStatus() !== 'Success') {
            return '';
        }

        return sprintf(
            '%s%s%s',
            rtrim($this->archivePath, '/'),
            DIRECTORY_SEPARATOR,
            sprintf(self::FS_ARCHIVE_PREFIX, $build->getId())
        );
    }

    /**
     * @param OutputInterface $output
     * @param boolean $isSilent
     * @param int $exitCode
     * @return int
     */
    private function silentFailure(OutputInterface $output, $isSilent, $exitCode)
    {
        if ($isSilent) {
            return 0;
        }

        return $this->failure($output, $exitCode);
    }
}
