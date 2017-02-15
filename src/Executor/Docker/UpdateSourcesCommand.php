<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Docker;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Github\ArchiveApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Update dockerfiles on build server
 */
class UpdateSourcesCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;

    const COMMAND_TITLE = 'Docker - Update Dockerfile Sources';

    const MSG_SUCCESS = 'Dockerfiles refreshed!';

    const ERRT_TEMP = 'Temp directory "%s" is not writeable!';
    const ERR_DOWNLOAD = 'Invalid GitHub repository or reference.';
    const ERR_UNPACK = 'Archive download and unpack failed.';
    const ERR_TRANSFER = 'An error occurred while transferring dockerfiles to build server.';
    const ERRT_TRANSFER_TIP = 'Ensure "%s" exists on the build server and is owned by "%s"';

    /**
     * @var FileSyncManager
     */
    private $fileSyncManager;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var ArchiveApi
     */
    private $archiveApi;

    /**
     * @var string
     */
    private $localTemp;
    private $unixBuildUser;
    private $unixBuildServer;
    private $unixDockerSourcePath;

    /**
     * @var string
     */
    private $defaultRepository;
    private $defaultReference;

    /**
     * @param string $name
     * @param FileSyncManager $fileSyncManager
     * @param ProcessBuilder $processBuilder
     * @param ArchiveApi $archiveApi
     *
     * @param string $localTemp
     * @param string $unixBuildUser
     * @param string $unixBuildServer
     * @param string $unixDockerSourcePath
     *
     * @param string $defaultRepository
     * @param string $defaultReference
     */
    public function __construct(
        FileSyncManager $fileSyncManager,
        ProcessBuilder $processBuilder,
        ArchiveApi $archiveApi,

        $localTemp,
        $unixBuildUser,
        $unixBuildServer,
        $unixDockerSourcePath,

        $defaultRepository,
        $defaultReference
    ) {
        $this->fileSyncManager = $fileSyncManager;
        $this->processBuilder = $processBuilder;
        $this->archiveApi = $archiveApi;

        $this->localTemp = $localTemp;
        $this->unixBuildUser = $unixBuildUser;
        $this->unixBuildServer = $unixBuildServer;
        $this->unixDockerSourcePath = $unixDockerSourcePath;

        $this->defaultRepository = $defaultRepository;
        $this->defaultReference = $defaultReference;
    }

    /**
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Update dockerfile sources on build server.')
            ->addArgument(
                'GIT_REPOSITORY',
                InputArgument::OPTIONAL,
                'Customize the source respository of the dockerfiles.'
            )
            ->addArgument(
                'GIT_REFERENCE',
                InputArgument::OPTIONAL,
                'Customize the source version of the dockerfiles.'
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

        $repository = $io->getArgument('GIT_REPOSITORY') ?: $this->defaultRepository;
        $reference = $io->getArgument('GIT_REFERENCE') ?: $this->defaultReference;

        $io->section('GitHub');
        $io->text([
            sprintf('Repository: <info>%s</info>', $repository),
            sprintf('Reference: <info>%s</info>', $reference)
        ]);

        $archive = sprintf('%s/docker-images.tar.gz', rtrim($this->localTemp, '/'));
        $tempDir = sprintf('%s/docker-images', rtrim($this->localTemp, '/'));

        $io->section('Temp');
        $io->text([
            sprintf('Download: <info>%s</info>', $archive),
            sprintf('Directory: <info>%s</info>', $tempDir)
        ]);

        if (!$this->sanityCheck($this->localTemp)) {
            return $this->failure($io, sprintf(self::ERRT_TEMP, $this->localTemp));
        }

        if (!$this->download($repository, $reference, $archive)) {
            $this->cleanupArtifacts($tempDir, $archive);
            return $this->failure($io, self::ERR_DOWNLOAD);
        }

        if (!$this->unpackArchive($tempDir, $archive)) {
            $this->cleanupArtifacts($tempDir, $archive);
            return $this->failure($io, self::ERR_UNPACK);
        }

        if (!$this->transferFiles($io, $tempDir, $this->unixDockerSourcePath)) {
            $this->cleanupArtifacts($tempDir, $archive);
            return $this->failure($io, self::ERR_TRANSFER);
        }

        $this->cleanupArtifacts($tempDir, $archive);
        return $this->success($io, self::MSG_SUCCESS);
    }

    /**
     * @param string $tempDir
     *
     * @return bool
     */
    private function sanityCheck($tempDir)
    {
        if (is_writeable($tempDir)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $repository
     * @param string $reference
     * @param string $target
     *
     * @return bool
     */
    private function download($repository, $reference, $target)
    {
        $repository = explode('/', $repository);
        if (count($repository) !== 2) {
            return false;
        }

        list($user, $repo) = $repository;

        return $this->archiveApi->download($user, $repo, $reference, $target);
    }

    /**
     * @param string $buildPath
     * @param string $archive
     *
     * @return boolean
     */
    private function unpackArchive($tempDir, $archive)
    {
        $makeCommand = ['mkdir', $tempDir];
        $unpackCommand = [
            'tar',
            '-vxz',
            '--strip-components=1',
            sprintf('--file=%s', $archive),
            sprintf('--directory=%s', $tempDir)
        ];

        $makeProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($makeCommand)
            ->getProcess();

        $unpackProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($unpackCommand)
            ->getProcess();

        $makeProcess->run();
        if (!$makeProcess->isSuccessful()) {
            return false;
        }

        $unpackProcess->run();

        return $unpackProcess->isSuccessful();
    }

    /**
     * @param IOInterface $io
     * @param string $localPath
     * @param string $remotePath
     *
     * @return bool
     */
    private function transferFiles(IOInterface $io, $localPath, $remotePath)
    {
        $command = $this->fileSyncManager->buildOutgoingRsync(
            $localPath,
            $this->unixBuildUser,
            $this->unixBuildServer,
            $remotePath
        );

        if ($command === null) {
            return false;
        }

        $rsyncCommand = implode(' ', $command);

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments([''])
            ->getProcess()
            // processbuilder escapes input, but it breaks the rsync params
            ->setCommandLine($rsyncCommand);

        $process->run();
        $success = $process->isSuccessful();

        if (!$success) {
            $io->note(self::ERR_TRANSFER);
            $io->text($process->getErrorOutput());
            $io->note(sprintf(self::ERRT_TRANSFER_TIP, $remotePath, $this->unixBuildUser));
        }

        return $success;
    }

    /**
     * @param string $tempDir
     * @param string $archive
     *
     * @return void
     */
    private function cleanupArtifacts($tempDir, $archive)
    {
        $dirCommand = ['rm', '-r', $tempDir];
        $archiveCommand = ['rm', $archive];

        $rmDir = $this->processBuilder
            ->setWorkingDirectory($this->localTemp)
            ->setArguments($dirCommand)
            ->getProcess();
        $rmArchive = $this->processBuilder
            ->setWorkingDirectory($this->localTemp)
            ->setArguments($archiveCommand)
            ->getProcess();

        $rmDir->run();
        $rmArchive->run();
    }
}
