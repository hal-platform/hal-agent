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
    const FS_ARCHIVE_PREFIX = 'hal9000-archive-%s.tar.qz';

    /**
     * @var string
     */
    const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt. Or can it?';

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
    private $buildDirectory;

    /**
     * @var LoggerInterface $logger
     * @var BuildRepository $buildRepo
     */
    public function __construct(LoggerInterface $logger, BuildRepository $buildRepo)
    {
        $this->logger = $logger;
        $this->buildRepo = $buildRepo;
    }

    /**
     * @param string $buildId
     * @return array
     */
    public function __invoke($buildId)
    {
        $build = $this->buildRepo->find($buildId);
        if (!$build = $this->buildRepo->find($buildId)) {
            $this->logger->error(sprintf(self::ERR_NOT_FOUND, $buildId));
            return null;
        }

        $this->logger->info(sprintf('Found build: %s', $buildId));

        if ($build->getStatus() !== 'Waiting') {
            $this->logger->error(sprintf(self::ERR_NOT_WAITING, $buildId, $build->getStatus()));
            return null;
        }

        return [
            'build' => $build,
            'buildArchive' => $this->generateArchiveTarget($build->getId()),
            'buildPath' => $this->generateBuildDirectory($build->getId()),
            'githubUser' => $build->getRepository()->getGithubUser(),
            'githubRepo' => $build->getRepository()->getGithubRepo(),
            'githubReference' => $build->getCommit()
        ];
    }

    /**
     * @param string $directory
     *  @return null
     */
    public function setBaseBuildDirectory($directory)
    {
        $this->buildDirectory = $directory;
    }

    /**
     *  Generate, but don't create, an archive target
     *
     *  @param string $id
     *  @return string
     */
    private function generateArchiveTarget($id)
    {
        return $this->getBuildDirectory() . 'debug/' . sprintf(self::FS_ARCHIVE_PREFIX, substr($id, 0, 7));
    }

    /**
     *  Generate, but don't create, a build directory for later use
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildDirectory($id)
    {
        return $this->getBuildDirectory() . 'debug/' . sprintf(self::FS_DIRECTORY_PREFIX, substr($id, 0, 7));
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
}
