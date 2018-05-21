<?php
/**
 * @copyright (c) 2018 Steve Kluck
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Job;

use Hal\Agent\Symfony\ProcessRunner;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class FileCompression
{
    private const EVENT_MESSAGE = 'Filesystem action';
    private const ERR_TIMEOUT = 'Filesystem action timed out';

    private const UNCOMPRESS_TGZ_FLAGS = '-vxz';
    private const COMPRESS_TGZ_FLAGS = '-vcz';
    private const COMPRESS_ZIP_FLAGS = '--recurse-paths';

    /**
     * @var ProcessRunner
     */
    private $runner;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var int
     */
    private $commandTimeout;

    /**
     * @param ProcessRunner $runner
     * @param Filesystem $filesystem
     * @param int $commandTimeout
     */
    public function __construct(ProcessRunner $runner, Filesystem $filesystem, int $commandTimeout)
    {
        $this->runner = $runner;
        $this->filesystem = $filesystem;

        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $workspacePath
     *
     * @return bool
     */
    public function createWorkspace(string $workspacePath): bool
    {
        try {
            $this->filesystem->mkdir($workspacePath);

        } catch(IOException $e) {
            return false;
        }

        return true;
    }

    /**
     * @param string $workspacePath
     * @param string $tarFile
     * @param int $stripDirectories
     *
     * @return bool
     */
    public function unpackTarArchive(string $workspacePath, string $tarFile, int $stripDirectories = 0): bool
    {
        $unpackCommand = [
            'tar',
            static::UNCOMPRESS_TGZ_FLAGS
        ];

        if ($stripDirectories > 0) {
            $unpackCommand[] = sprintf('--strip-components=%s', $stripDirectories);
        }

        $unpackCommand = array_merge($unpackCommand, [
            sprintf('--file=%s', $tarFile),
            sprintf('--directory=%s', $workspacePath)
        ]);

        $dispCommand = implode(' ', $unpackCommand);

        // @todo may need to NOT escape args?
        $process = $this->runner->prepare($unpackCommand, null, $this->commandTimeout);
        if (!$this->runner->run($process, $dispCommand, self::ERR_TIMEOUT)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->runner->onFailure($process, $dispCommand, self::EVENT_MESSAGE);
    }

    /**
     * @param string $workspacePath
     * @param string $tarFile
     *
     * @return bool
     */
    public function packTarArchive(string $workspacePath, string $tarFile): bool
    {
        $packCommand = [
            'tar',
            static::COMPRESS_TGZ_FLAGS,
            sprintf('--file=%s', $tarFile),
            '.'
        ];

        return $this->executePackCommand($packCommand, $workspacePath);
    }

    /**
     * @param string $workspacePath
     * @param string $zipFile
     *
     * @return bool
     */
    public function packZipArchive(string $workspacePath, string $zipFile): bool
    {
        $packCommand = [
            'zip',
            static::COMPRESS_ZIP_FLAGS,
            $zipFile,
            '.'
        ];

        return $this->executePackCommand($packCommand, $workspacePath);
    }

    /**
     * @param array $packCommand
     * @param string $workspacePath
     *
     * @return bool
     */
    private function executePackCommand(array $packCommand, string $workspacePath)
    {
        if (!is_dir($workspacePath)) {
            return false;
        }

        $dispCommand = implode(' ', $packCommand);

        // @todo may need to NOT escape args?
        $process = $this->runner->prepare($packCommand, $workspacePath, $this->commandTimeout);
        if (!$this->runner->run($process, $dispCommand, self::ERR_TIMEOUT)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->runner->onFailure($process, $dispCommand, self::EVENT_MESSAGE);
    }
}
