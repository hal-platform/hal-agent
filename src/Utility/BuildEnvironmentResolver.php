<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Agent\Build\Unix\UnixBuildHandler;
use Hal\Agent\Build\Windows\WindowsBuildHandler;
use Hal\Agent\Build\WindowsAWS\WindowsAWSBuildHandler;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Release;
use Hal\Core\Entity\Target;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Resolve properties about the build environment
 */
class BuildEnvironmentResolver
{
    const UNIQUE_BUILD_PATH = 'hal9000-%s';

    const WINDOWS_AWS_INPUT_ARTIFACT = '%s-input.tar.gz';
    const WINDOWS_AWS_OUTPUT_ARTIFACT = '%s-output.tar.gz';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * UNIX properties
     *
     * @var string|null
     */
    private $unixBuildDirectory;
    private $unixUser;
    private $unixServer;

    /**
     * Windows AWS properties
     *
     * @var string|null
     */
    private $windowsRegion;
    private $windowsCredentialName;
    private $windowsBucket;
    private $windowsInstanceFilter;

    /**
     * WINDOWS properties
     *
     * @var string|null
     */
    private $windowsBuildDirectory;
    private $windowsUser;
    private $windowsServer;

    /**
     * @param EntityManagerInterface $em
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(EntityManagerInterface $em, ProcessBuilder $processBuilder)
    {
        $this->em = $em;
        $this->processBuilder = $processBuilder;
    }

    /**
     * Retrieve build-system specific properties for build
     *
     * @param Build $build
     *
     * @return array
     */
    public function getBuildProperties(Build $build)
    {
        $uniqueId = sprintf('build-%s', $build->id());

        return $this->getLinuxProperties($build, $uniqueId) + $this->getWindowsAWSProperties($build, $uniqueId);
    }

    /**
     * Retrieve build-system specific properties for release
     *
     * @param Release $release
     *
     * @return array
     */
    public function getReleaseProperties(Release $release)
    {
        $uniqueId = sprintf('release-%s', $release->id());

        $properties = $this->getLinuxProperties($release->build(), $uniqueId) + $this->getWindowsAWSProperties($release->build(), $uniqueId);
        $releaseEnv = $this->getStandardReleaseEnvironment($release);

        $platforms = [UnixBuildHandler::PLATFORM_TYPE, WindowsAWSBuildHandler::PLATFORM_TYPE];

        foreach ($platforms as $platform) {
            if (isset($properties[$platform]['environmentVariables'])) {
                $properties[$platform]['environmentVariables'] = $releaseEnv + $properties[$platform]['environmentVariables'];
            }
        }

        return $properties;
    }

    /**
     * Add windows build server info so non-unix builds can be built.
     *
     * @param string $user
     * @param string $server
     * @param string $baseDirectory
     *
     * @return null
     */
    public function setWindowsBuilder($user, $server, $baseDirectory)
    {
        $this->windowsUser = $user;
        $this->windowsServer = $server;
        $this->windowsBuildDirectory = $baseDirectory;
    }

    /**
     * Add unix build server info so unix builds can be built.
     *
     * @param string $user
     * @param string $server
     * @param string $baseDirectory
     *
     * @return null
     */
    public function setUnixBuilder($user, $server, $baseDirectory)
    {
        $this->unixUser = $user;
        $this->unixServer = $server;
        $this->unixBuildDirectory = $baseDirectory;
    }

    /**
     * Add windows AWS build server info so windows builds can be run.
     *
     * @param string $region
     * @param string $credentialName
     * @param string $bucket
     * @param string $tagFilter
     *
     * @return null
     */
    public function setWindowsAWSBuilder($region, $credentialName, $bucket, $tagFilter)
    {
        $this->windowsRegion = $region;
        $this->windowsCredentialName = $credentialName;
        $this->windowsBucket = $bucket;
        $this->windowsInstanceFilter = $tagFilter;
    }

    /**
     * @param Build $build
     * @param string $uniqueId
     *
     * @return array
     */
    private function getLinuxProperties(Build $build, $uniqueId)
    {
        // sanity check
        if (!$this->unixBuildDirectory || !$this->unixUser || !$this->unixServer) {
            return [];
        }

        $environmentName = ($environment = $build->environment()) ? $environmentName = $environment->name() : 'Any';

        $env = [
            'HAL_BUILDID' => $build->id(),
            'HAL_COMMIT' => $build->commit(),
            'HAL_GITREF' => $build->reference(),
            'HAL_ENVIRONMENT' => $environmentName,
            'HAL_APP' => $build->application()->identifier()
        ];

        $properties = [
            UnixBuildHandler::PLATFORM_TYPE => [
                'buildUser' => $this->unixUser,
                'buildServer' => $this->unixServer,
                'remoteFile' => $this->generateUnixBuildPath($uniqueId),
                'environmentVariables' => $env
            ]
        ];

        return $properties;
    }

    /**
     * @param Build $build
     * @param string $uniqueId
     *
     * @return array
     */
    private function getWindowsAWSProperties(Build $build, $uniqueId)
    {
        // sanity check
        if (!$this->windowsRegion || !$this->windowsCredentialName || !$this->windowsBucket || !$this->windowsInstanceFilter) {
            return [];
        }

        $credential = $this->em
            ->getRepository(Credential::class)
            ->findOneBy(['isInternal' => true, 'name' => $this->windowsCredentialName]);

        $env = $this->getStandardBuildEnvironment($build);

        $properties = [
            WindowsAWSBuildHandler::PLATFORM_TYPE => [
                'region' => $this->windowsRegion,
                'credential' => $credential ? $credential->details() : null,

                'instanceFilter' => $this->windowsInstanceFilter,

                'bucket' => $this->windowsBucket,
                'objectInput' => sprintf(self::WINDOWS_AWS_INPUT_ARTIFACT, $uniqueId),
                'objectOutput' => sprintf(self::WINDOWS_AWS_OUTPUT_ARTIFACT, $uniqueId),

                'environmentVariables' => $env
            ]
        ];

        return $properties;
    }

    /**
     * @param Build $build
     * @param string $uniqueId
     *
     * @return array
     */
    private function getWindowsProperties(Build $build, $uniqueId)
    {
        // sanity check
        if (!$this->windowsBuildDirectory || !$this->windowsUser || !$this->windowsServer) {
            return [];
        }

        $environmentName = ($environment = $build->environment()) ? $environmentName = $environment->name() : 'Any';

        $env = [
            'HAL_BUILDID' => $build->id(),
            'HAL_COMMIT' => $build->commit(),
            'HAL_GITREF' => $build->reference(),
            'HAL_ENVIRONMENT' => $environmentName,
            'HAL_APP' => $build->application()->identifier()
        ];

        $properties = [
            WindowsBuildHandler::PLATFORM_TYPE => [
                'buildUser' => $this->windowsUser,
                'buildServer' => $this->windowsServer,
                'remotePath' => $this->generateWindowsBuildPath($uniqueId),
                'environmentVariables' => $env
            ]
        ];

        return $properties;
    }

    /**
     * Generate a target for the windows build path.
     *
     * @param string $uniqueId
     *
     * @return string
     */
    private function generateWindowsBuildPath($uniqueId)
    {
        $buildPath = sprintf(
            '%s/%s/',
            rtrim($this->windowsBuildDirectory, DIRECTORY_SEPARATOR),
            sprintf(self::UNIQUE_BUILD_PATH, $uniqueId)
        );

        return $buildPath;
    }

    /**
     * @param Release $release
     *
     * @return array
     */
    private function getStandardReleaseEnvironment(Release $release)
    {
        $method = $release->target()->group()->type();

        $env = [
            'HAL_PUSHID' => $release->id(),
            'HAL_ENVIRONMENT' => $release->target()->group()->environment()->name(),
            'HAL_METHOD' => $method,
            'HAL_CONTEXT' => $release->target()->parameter(Target::PARAM_CONTEXT)
        ];

        return $env;
    }

    /**
     * @param Build $build
     *
     * @return array
     */
    private function getStandardBuildEnvironment(Build $build)
    {
        $environmentName = $build->environment() ? $build->environment()->name() : '';
        $env = [
            'HAL_BUILDID' => $build->id(),
            'HAL_COMMIT' => $build->commit(),
            'HAL_GITREF' => $build->reference(),
            'HAL_ENVIRONMENT' => $environmentName,
            'HAL_REPO' => $build->application()->identifier()
        ];

        return $env;
    }

    /**
     * Generate a target for the unix build path.
     *
     * @param string $uniqueId
     *
     * @return string
     */
    private function generateUnixBuildPath($uniqueId)
    {
        $buildPath = sprintf(
            '%s/%s.tar.gz',
            rtrim($this->unixBuildDirectory, DIRECTORY_SEPARATOR),
            sprintf(self::UNIQUE_BUILD_PATH, $uniqueId)
        );

        return $buildPath;
    }
}
