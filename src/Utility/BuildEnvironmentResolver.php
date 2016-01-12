<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Utility;

use QL\Hal\Agent\Build\Unix\UnixBuildHandler;
use QL\Hal\Agent\Build\Windows\WindowsBuildHandler;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Resolve properties about the build environment
 */
class BuildEnvironmentResolver
{
    const UNIQUE_BUILD_PATH = 'hal9000-%s';

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
     * WINDOWS properties
     *
     * @var string|null
     */
    private $windowsBuildDirectory;
    private $windowsUser;
    private $windowsServer;

    /**
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(ProcessBuilder $processBuilder)
    {
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

        $properties = array_merge(
            $this->getUnixProperties($build, $uniqueId),
            $this->getWindowsProperties($build, $uniqueId)
        );

        return $properties;
    }

    /**
     * Retrieve build-system specific properties for push
     *
     * @param Push $push
     *
     * @return array
     */
    public function getPushProperties(Push $push)
    {
        $uniqueId = sprintf('push-%s', $push->id());

        $properties = array_merge(
            $this->getUnixProperties($push->build(), $uniqueId),
            $this->getWindowsProperties($push->build(), $uniqueId)
        );

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
     * @param Build $build
     * @param string $uniqueId
     *
     * @return array
     */
    private function getUnixProperties(Build $build, $uniqueId)
    {
        // sanity check
        if (!$this->unixBuildDirectory || !$this->unixUser || !$this->unixServer) {
            return [];
        }

        $env = [
            'HAL_BUILDID' => $build->id(),
            'HAL_COMMIT' => $build->commit(),
            'HAL_GITREF' => $build->branch(),
            'HAL_ENVIRONMENT' => $build->environment()->name(),
            'HAL_REPO' => $build->application()->key()
        ];

        $properties = [
            UnixBuildHandler::SERVER_TYPE => [
                'buildUser' => $this->unixUser,
                'buildServer' => $this->unixServer,
                'remotePath' => $this->generateUnixBuildPath($uniqueId),
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

        $env = [
            'HAL_BUILDID' => $build->id(),
            'HAL_COMMIT' => $build->commit(),
            'HAL_GITREF' => $build->branch(),
            'HAL_ENVIRONMENT' => $build->environment()->name(),
            'HAL_REPO' => $build->application()->key()
        ];

        $properties = [
            WindowsBuildHandler::SERVER_TYPE => [
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
     * Generate a target for the unix build path.
     *
     * @param string $uniqueId
     *
     * @return string
     */
    private function generateUnixBuildPath($uniqueId)
    {
        $buildPath = sprintf(
            '%s/%s/',
            rtrim($this->unixBuildDirectory, DIRECTORY_SEPARATOR),
            sprintf(self::UNIQUE_BUILD_PATH, $uniqueId)
        );

        return $buildPath;
    }
}
