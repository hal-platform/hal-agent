<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Generic;

use Hal\Agent\Symfony\ProcessRunner;

class FileCompression
{
    private const EVENT_MESSAGE = 'Filesystem action';
    private const ERR_TIMEOUT = 'Filesystem action timed out';

    private const UNCOMPRESS_TGZ_FLAGS = '-vxz';
    private const COMPRESS_TGZ_FLAGS = '-vcz';

    /**
     * @var ProcessRunner
     */
    private $runner;

    /**
     * @var int
     */
    private $commandTimeout;

    /**
     * @param ProcessRunner $runner
     * @param int $commandTimeout
     */
    public function __construct(ProcessRunner $runner, int $commandTimeout)
    {
        $this->runner = $runner;

        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $workspacePath
     *
     * @return bool
     */
    public function createWorkspace(string $workspacePath): bool
    {
        $makeCommand = ['mkdir', $workspacePath];

        $process = $this->runner->prepare($makeCommand, null, $this->commandTimeout);
        if (!$this->runner->run($process, self::ERR_TIMEOUT)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->runner->onFailure($process, implode(' ', $makeCommand), self::EVENT_MESSAGE);
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

        // @todo may need to NOT escape args?
        $process = $this->runner->prepare($unpackCommand, null, $this->commandTimeout);
        if (!$this->runner->run($process, self::ERR_TIMEOUT)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->runner->onFailure($process, implode(' ', $unpackCommand), self::EVENT_MESSAGE);
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

        // @todo may need to NOT escape args?
        $process = $this->runner->prepare($packCommand, $workspacePath, $this->commandTimeout);
        if (!$this->runner->run($process, self::ERR_TIMEOUT)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        return $this->runner->onFailure($process, implode(' ', $packCommand), self::EVENT_MESSAGE);
    }
}
