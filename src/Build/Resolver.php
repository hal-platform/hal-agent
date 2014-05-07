<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;

/**
 * Resolve build properties from user and environment input
 */
class Resolver
{
    /**
     * @var string
     */
    const FS_DIRECTORY_PREFIX = 'hal9000-build-%s';
    const FS_BUILD_PREFIX = 'hal9000-build-%s.tar.gz';
    const FS_ARCHIVE_PREFIX = 'hal9000-%s.tar.gz';

    /**
     * @var string
     */
    const FOUND = 'Found build: %s';
    const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt.';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var string
     */
    private $envPath;

    /**
     * @var string
     */
    private $archivePath;

    /**
     * @var string
     */
    private $buildDirectory;

    /**
     * @var string
     */
    private $homeDirectory;

    /**
     * @param LoggerInterface $logger
     * @param BuildRepository $buildRepo
     * @param string $envPath
     * @param string $archivePath
     */
    public function __construct(LoggerInterface $logger, BuildRepository $buildRepo, $envPath, $archivePath)
    {
        $this->logger = $logger;
        $this->buildRepo = $buildRepo;
        $this->envPath = $envPath;
        $this->archivePath = $archivePath;
    }

    /**
     * @param string $buildId
     * @return array|null
     */
    public function __invoke($buildId)
    {
        if (!$build = $this->buildRepo->find($buildId)) {
            $this->logger->error(sprintf(self::ERR_NOT_FOUND, $buildId));
            return null;
        }

        $this->logger->info(sprintf(self::FOUND, $buildId));

        if ($build->getStatus() !== 'Waiting') {
            $this->logger->error(sprintf(self::ERR_NOT_WAITING, $buildId, $build->getStatus()));
            return null;
        }

        return [
            'build' => $build,
            'buildCommand' => $build->getRepository()->getBuildCmd(),

            'buildFile' => $this->generateRepositoryDownload($build->getId()),
            'buildPath' => $this->generateBuildPath($build->getId()),
            'archiveFile' => $this->generateBuildArchive($build->getId()),

            'githubUser' => $build->getRepository()->getGithubUser(),
            'githubRepo' => $build->getRepository()->getGithubRepo(),
            'githubReference' => $build->getCommit(),

            'environmentVariables' => $this->generateBuildEnvironmentVariables($build)
        ];
    }

    /**
     * Set the base directory in which temporary build artifacts are stored.
     *
     * If none is provided the system temporary directory is used.
     *
     * @param string $directory
     *  @return null
     */
    public function setBaseBuildDirectory($directory)
    {
        $this->buildDirectory = $directory;
    }

    /**
     * Set the home directory for all build scripts. This can easily be changed
     * later to be unique for each build.
     *
     * If none is provided a common location within the shared build directory is used.
     *
     *  @param string $directory
     *  @return string
     */
    public function setHomeDirectory($directory)
    {
        $this->homeDirectory = $directory;
    }

    /**
     *  Generate a target for the build archive.
     *
     *  @param string $id
     *  @return string
     */
    private function generateRepositoryDownload($id)
    {
        return $this->getBuildDirectory() . sprintf(self::FS_BUILD_PREFIX, $id);
    }

    /**
     *  Generate a target for the build path.
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildPath($id)
    {
        return $this->getBuildDirectory() . sprintf(self::FS_DIRECTORY_PREFIX, $id);
    }

    /**
     *  Generate a target for the github repository archive.
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildArchive($id)
    {
        return sprintf(
            '%s%s%s',
            rtrim($this->archivePath, '/'),
            DIRECTORY_SEPARATOR,
            sprintf(self::FS_ARCHIVE_PREFIX, $id)
        );
    }

    /**
     *  Generate a target for $HOME and/or $TEMP
     *
     *  @param string $id
     *  @return string
     */
    private function generateHomePath()
    {
        if (!$this->homeDirectory) {
            $this->homeDirectory = $this->getBuildDirectory() . 'build-home';
        }

        return rtrim($this->homeDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     *  @param string $id
     *  @return string
     */
    private function getBuildDirectory()
    {
        if (!$this->buildDirectory) {
            $this->buildDirectory = sys_get_temp_dir();
        }

        return rtrim($this->buildDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param Build $build
     * @return array
     */
    private function generateBuildEnvironmentVariables(Build $build)
    {
        $vars = [
            'HOME' => $this->generateHomePath(),
            'PATH' => $this->envPath,

            'HAL_BUILDID' => $build->getId(),
            'HAL_COMMIT' => $build->getCommit(),
            'HAL_GITREF' => $build->getBranch(),
            'HAL_ENVIRONMENT' => $build->getEnvironment()->getKey(),
            'HAL_REPO' => $build->getRepository()->getKey()
        ];

        return $vars;
    }
}
