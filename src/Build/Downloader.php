<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Build\Generic\FileCompression;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\VersionControl\VCS;
use Hal\Core\VersionControl\VCSException;
use Hal\Core\VersionControl\VCSDownloaderInterface;

class Downloader
{
    const EVENT_MESSAGE = 'Download source code from version control provider.';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var FileCompression
     */
    private $fileCompression;

    /**
     * @var VCS
     */
    private $vcs;

    /**
     * @param EventLogger $logger
     * @param FileCompression $fileCompression
     * @param VCS $vcs
     */
    public function __construct(EventLogger $logger, FileCompression $fileCompression, VCS $vcs)
    {
        $this->logger = $logger;
        $this->fileCompression = $fileCompression;
        $this->vcs = $vcs;
    }

    /**
     * @param Build $build
     * @param string $workspace
     *
     * @return bool
     */
    public function __invoke(Build $build, string $workspace): bool
    {
        $buildPath = $workspace . '/build';
        $sourceCodeFile = $workspace . '/source_code.tgz';

        if (!$downloader = $this->getVCSDownloader($build)) {
            return false;
        }

        if (!$this->fileCompression->createWorkspace($workspace)) {
            return false;
        }

        if (!$this->fileCompression->createWorkspace($buildPath)) {
            return false;
        }

        if ($downloader instanceof VCSDownloaderInterface) {
            if (!$this->downloadSourceCode($build, $downloader, $sourceCodeFile)) {
                return false;
            }

            if (!$this->fileCompression->unpackTarArchive($buildPath, $sourceCodeFile)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Build $build
     *
     * @return mixed|true|null
     */
    private function getVCSDownloader(Build $build)
    {
        $provider = $build->application()->provider();

        // Having no VCS configured is "ok" - (think of built-in processes or system-level jobs)
        // But it may be a mistake, and the app may not be configured correctly. If there are no
        // build artifacts, we should probably error.
        if (!$provider) {
            return true;
        }

        $downloader = $this->vcs->downloader($provider);
        if ($downloader) {
            return $downloader;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, [
            'errors' => $this->vcs->errors()
        ]);

        return null;
    }

    /**
     * @param Build $build
     * @param mixed $downloader
     * @param string $targetFile
     *
     * @return bool
     */
    private function downloadSourceCode(Build $build, $downloader, $targetFile)
    {
        // No vcs is ok. Let the job continue.
        if (!$downloader) {
            return true;
        }

        $application = $build->application();
        $commit = $build->commit();

        try {
            $isSuccessful = true;
            $result = $downloader->download($application, $commit, $targetFile);
        } catch (VCSException $ex) {
            $isSuccessful = false;
        }

        if ($isSuccessful) {
            $filesize = filesize($targetFile);
            $this->logger->event('success', self::EVENT_MESSAGE, [
                'size' => sprintf('%s MB', round($filesize / 1048576, 2))
            ]);

            return true;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE);

        return false;
    }
}
