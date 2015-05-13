<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Docker;

use Doctrine\ORM\EntityManagerInterface;
use MCP\DataType\Time\Clock;
use QL\Hal\Core\Entity\AuditLog;
use QL\Hal\Core\Entity\User;
use QL\Hal\Core\Repository\AuditLogRepository;
use QL\Hal\Agent\Command\CommandTrait;
use QL\Hal\Agent\Command\FormatterTrait;
use QL\Hal\Agent\Remoting\FileSyncManager;
use QL\Hal\Agent\Github\ArchiveApi;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Update dockerfiles on build server
 *
 * BUILT FOR COMMAND LINE ONLY
 */
class UpdateSourcesCommand extends Command implements OutputAwareInterface
{
    use CommandTrait;
    use FormatterTrait;
    use OutputAwareTrait;

    const STATIC_HELP = <<<'HELP'
<fg=cyan>Exit codes:</fg=cyan>
HELP;

    /**
     * A list of all possible exit codes of this command
     *
     * @var array
     */
    private static $codes = [
        0 => 'Success',
        1 => 'Invalid temp directory.',
        2 => 'Invalid GitHub repository or reference.',
        3 => 'Archive download and unpack failed.',
        4 => 'Failed to locate unpacked archive.',
        5 => 'Failed to sanitize unpacked archive.',
        6 => 'An error occured while transferring dockerfile sources to build server.'
    ];

    /**
     * @type FileSyncManager
     */
    private $fileSyncManager;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * @type ArchiveApi
     */
    private $archiveApi;

    /**
     * @type EntityManagerInterface
     */
    private $em;

    /**
     * @type Clock
     */
    private $clock;

    /**
     * @type string
     */
    private $localTemp;
    private $unixBuildUser;
    private $unixBuildServer;
    private $unixDockerSourcePath;

    /**
     * @type string
     */
    private $defaultRepository;
    private $defaultReference;

    /**
     * @param string $name
     * @param FileSyncManager $fileSyncManager
     * @param ProcessBuilder $processBuilder
     * @param ArchiveApi $archiveApi
     * @param EntityManagerInterface $em
     * @param Clock $clock
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
        $name,
        FileSyncManager $fileSyncManager,
        ProcessBuilder $processBuilder,
        ArchiveApi $archiveApi,
        EntityManagerInterface $em,
        Clock $clock,

        $localTemp,
        $unixBuildUser,
        $unixBuildServer,
        $unixDockerSourcePath,

        $defaultRepository,
        $defaultReference
    ) {
        parent::__construct($name);

        $this->fileSyncManager = $fileSyncManager;
        $this->processBuilder = $processBuilder;
        $this->archiveApi = $archiveApi;
        $this->em = $em;
        $this->clock = $clock;

        $this->localTemp = $localTemp;
        $this->unixBuildUser = $unixBuildUser;
        $this->unixBuildServer = $unixBuildServer;
        $this->unixDockerSourcePath = $unixDockerSourcePath;

        $this->defaultRepository = $defaultRepository;
        $this->defaultReference = $defaultReference;
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
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

        $help = [self::STATIC_HELP];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }

        $this->setHelp(implode("\n", $help));
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setOutput($output);

        $repository = $input->getArgument('GIT_REPOSITORY') ?: $this->defaultRepository;
        $reference = $input->getArgument('GIT_REFERENCE') ?: $this->defaultReference;

        $this->status(sprintf('Repository: <info>%s</info>', $repository), 'GitHub');
        $this->status(sprintf('Reference: <info>%s</info>', $reference), 'GitHub');

        $archive = sprintf('%s/docker-images.tar.gz', rtrim($this->localTemp, '/'));
        $tempDir = sprintf('%s/docker-images', rtrim($this->localTemp, '/'));

        $this->status(sprintf('Download: <info>%s</info>', $archive), 'Temp');
        $this->status(sprintf('Directory: <info>%s</info>', $tempDir), 'Temp');

        if (!$this->sanityCheck($output, $this->localTemp)) {
            return $this->failure($output, 1);
        }

        if (!$this->download($repository, $reference, $archive)) {
            return $this->failure($output, 2);
        }

        if (!$this->unpackArchive($tempDir, $archive)) {
            $this->cleanupArtifacts($tempDir, $archive);
            return $this->failure($output, 3);
        }

        if (!$unpackedPath = $this->locateUnpackedArchive($tempDir)) {
            $this->cleanupArtifacts($tempDir, $archive);
            return $this->failure($output, 4);
        }

        if (!$this->sanitizeUnpackedArchive($unpackedPath)) {
            $this->cleanupArtifacts($tempDir, $archive);
            return $this->failure($output, 5);
        }

        $transfer = $this->transferFiles(
            $output,
            $tempDir,
            $this->unixBuildUser,
            $this->unixBuildServer,
            $this->unixDockerSourcePath
        );

        if (!$transfer) {
            $this->cleanupArtifacts($tempDir, $archive);
            return $this->failure($output, 6);
        }

        // Audit
        $this->audit($repository, $reference);

        $this->cleanupArtifacts($tempDir, $archive);
        return $this->success($output, 'Dockerfiles refreshed!');
    }

    /**
     * @param OutputInterface $output
     * @param string $tempDir
     *
     * @return bool
     */
    private function sanityCheck(OutputInterface $output, $tempDir)
    {
        if (is_writeable($tempDir)) {
            return true;
        }

        $output->writeln(sprintf('Temp directory "%s" is not writeable!', $tempDir));
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
        $unpackCommand = ['tar', '-vxzf', $archive, sprintf('--directory=%s', $tempDir)];

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
     * @param string $tempDir
     *
     * @return string|null
     */
    private function locateUnpackedArchive($tempDir)
    {
        $cmd = ['find', $tempDir, '-type', 'd'];
        $process = $this->processBuilder
            ->setWorkingDirectory($tempDir)
            ->setArguments($cmd)
            ->getProcess();

        $process->setCommandLine($process->getCommandLine() . ' -name * -prune');

        $process->run();

        if ($process->isSuccessful()) {
            return strtok($process->getOutput(), "\n");
        }

        return null;
    }

    /**
     * @param string $unpackedPath
     *
     * @return boolean
     */
    private function sanitizeUnpackedArchive($unpackedPath)
    {
        $mvCommand = 'mv {,.[!.],..?}* ..';
        $rmCommand = ['rmdir', $unpackedPath];

        $process = $this->processBuilder
            ->setWorkingDirectory($unpackedPath)
            ->setArguments([''])
            ->getProcess()
            // processbuilder escapes input, but we need these wildcards to resolve correctly unescaped
            ->setCommandLine($mvCommand);

        $process->run();

        // remove unpacked directory
        $removalProcess = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($rmCommand)
            ->getProcess();

        $removalProcess->run();
        return $removalProcess->isSuccessful();
    }

    /**
     * @param OutputInterface $output
     * @param string $localPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function transferFiles(OutputInterface $output, $localPath, $remoteUser, $remoteServer, $remotePath)
    {
        $command = $this->fileSyncManager->buildOutgoingRsync($localPath, $remoteUser, $remoteServer, $remotePath);
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

        if (!$isSuccessful = $process->isSuccessful()) {
            // most likely to fail, so dump output to shell
            $message = sprintf('<info>Exit Code:</info> %s', $process->getExitCode());
            $output->writeln($message);

            $output->writeln('<info>Std Err:</info>');
            $output->write($process->getErrorOutput());

            $output->writeln('<info>Protip:</info>');
            $output->write(sprintf('Ensure "%s" exists on the build server and is owned by "%s"', $remotePath, $remoteUser), true);
        }

        return $isSuccessful;
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

    /**
     * @param string $repository
     * @param string $reference
     *
     * @return void
     */
    private function audit($repository, $reference)
    {
        if (!$user = $this->em->find(User::CLASS, 9000)) {
            return;
        }

        $suffix = (1 === preg_match('/^([a-f0-9]{40})$/', $reference)) ? 'commit' : 'tree';
        $data = [
            'repository' => $repository,
            'reference' => $reference,
            'github' => sprintf('http://git/%s/%s/%s', $repository, $suffix, $reference)
        ];

        $log = new AuditLog;
        $log->setUser($user);
        $log->setRecorded($this->clock->read());
        $log->setEntity('DockerImages');
        $log->setAction('UPDATE');
        $log->setData(json_encode($data));

        $this->em->persist($log);
        $this->em->flush();
    }
}
