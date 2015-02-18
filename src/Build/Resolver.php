<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Build\Unix\UnixBuildHandler;
use QL\Hal\Agent\Build\Windows\WindowsBuildHandler;
use QL\Hal\Agent\Utility\BuildEnvironmentResolver;
use QL\Hal\Agent\Utility\DefaultConfigHelperTrait;
use QL\Hal\Agent\Utility\ResolverTrait;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;

/**
 * Resolve build properties from user and environment input
 */
class Resolver
{
    use DefaultConfigHelperTrait;
    use ResolverTrait;

    /**
     * @type string
     */
    const DOWNLOAD_FILE = 'hal9000-download-%s.tar.gz';

    /**
     * @type string
     */
    const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt.';

    /**
     * @type BuildRepository
     */
    private $buildRepo;

    /**
     * @type BuildEnvironmentResolver
     */
    private $environmentResolver;

    /**
     * @param BuildRepository $buildRepo
     * @param BuildEnvironmentResolver $environmentResolver
     */
    public function __construct(BuildRepository $buildRepo, BuildEnvironmentResolver $environmentResolver)
    {
        $this->buildRepo = $buildRepo;
        $this->environmentResolver = $environmentResolver;
    }

    /**
     * @param string $buildId
     *
     * @throws BuildException
     *
     * @return array
     */
    public function __invoke($buildId)
    {
        if (!$build = $this->buildRepo->find($buildId)) {
            throw new BuildException(sprintf(self::ERR_NOT_FOUND, $buildId));
        }

        if ($build->getStatus() !== 'Waiting') {
            throw new BuildException(sprintf(self::ERR_NOT_WAITING, $buildId, $build->getStatus()));
        }

        $properties = [
            'build' => $build,

            // default, overwritten by .hal9000.yml
            'configuration' => $this->buildDefaultConfiguration($build->getRepository()),

            'location' => [
                'download' => $this->generateRepositoryDownloadFile($build->getId()),
                'path' => $this->generateLocalTempPath($build->getId(), 'build'),
                'archive' => $this->generateBuildArchiveFile($build->getId()),
                'tempArchive' => $this->generateTempBuildArchiveFile($build->getId(), 'build')
            ],

            'github' => [
                'user' => $build->getRepository()->getGithubUser(),
                'repo' => $build->getRepository()->getGithubRepo(),
                'reference' => $build->getCommit()
            ],
        ];

        $properties['artifacts'] = $this->findBuildArtifacts($properties);


        // build system configuration
        $buildSystemProperties = $this->environmentResolver->getBuildProperties($build);
        $properties = array_merge($properties, $buildSystemProperties);

        return $properties;
    }

    /**
     * Find the build artifacts that must be cleaned up after build.
     *
     * @param array $properties
     * @return array
     */
    private function findBuildArtifacts(array $properties)
    {
        $artifacts = [
            $properties['location']['download'],
            $properties['location']['path'],
            $properties['location']['tempArchive']
        ];

        return $artifacts;
    }

    /**
     * Generate a target for the build archive.
     *
     * @param string $id
     * @return string
     */
    private function generateRepositoryDownloadFile($id)
    {
        return $this->getLocalTempPath() . sprintf(static::DOWNLOAD_FILE, $id);
    }
}
