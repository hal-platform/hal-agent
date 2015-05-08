<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
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
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * UNIX properties
     *
     * @type string|null
     */
    private $unixBuildDirectory;
    private $unixUser;
    private $unixServer;

    /**
     * WINDOWS properties
     *
     * @type string|null
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
        $uniqueId = sprintf('build-%s', $build->getId());

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
        $uniqueId = sprintf('push-%s', $push->getId());

        $properties = array_merge(
            $this->getUnixProperties($push->getBuild(), $uniqueId),
            $this->getWindowsProperties($push->getBuild(), $uniqueId)
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
            'HAL_BUILDID' => $build->getId(),
            'HAL_COMMIT' => $build->getCommit(),
            'HAL_GITREF' => $build->getBranch(),
            'HAL_ENVIRONMENT' => $build->getEnvironment()->getKey(),
            'HAL_REPO' => $build->getRepository()->getKey()
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
            'HAL_BUILDID' => $build->getId(),
            'HAL_COMMIT' => $build->getCommit(),
            'HAL_GITREF' => $build->getBranch(),
            'HAL_ENVIRONMENT' => $build->getEnvironment()->getKey(),
            'HAL_REPO' => $build->getRepository()->getKey()
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

    // /**
    //  * Ruby sucks. This is ridiculous.
    //  *
    //  * @param array $env
    //  *
    //  * @return array
    //  */
    // private function getRubyEnv(array $vars)
    // {
    //     /**
    //      * Get the default gempath when we remove the GEM_HOME and GEM_PATH env variables
    //      */
    //     $process = $this->processBuilder
    //         ->setWorkingDirectory($vars['HOME'])
    //         ->setArguments(['gem', 'env', 'gempath'])
    //         ->addEnvironmentVariables(['HOME' => $vars['HOME']])
    //         ->getProcess();

    //     $process->run();

    //     if (!$gemPaths = $process->getOutput()) {
    //         return [];
    //     }

    //     $gemPaths = trim($gemPaths);
    //     $default = null;

    //     /**
    //      * Get the gem path within the HOME dir
    //      */
    //     $paths = explode(':', $gemPaths);
    //     foreach ($paths as $path) {
    //         if (stripos($path, $vars['HOME']) === 0) {
    //             $default = $path;
    //         }
    //     }

    //     if (!$default) {
    //         // if not found just bail out and don't set any ruby envs.
    //         return [];
    //     }

    //     $bindir = sprintf('%s/bin', $default);

    //     return [
    //         // wheres gems are installed
    //         'GEM_HOME' => $default,

    //         // where gems are searched for
    //         'GEM_PATH' => implode(':', $paths),

    //         // Add the new gembin dir to the PATH
    //         'PATH' => sprintf('%s:%s', $bindir, $vars['PATH'])
    //     ];
    // }

    // /**
    //  * @param array $env
    //  *
    //  * @return array
    //  */
    // private function getPackageManagerEnv(array $vars)
    // {
    //     return [
    //         'BOWER_INTERACTIVE' => 'false',
    //         'BOWER_STRICT_SSL' => 'false',

    //         'COMPOSER_HOME' => sprintf('%s/%s', rtrim($vars['HOME'], DIRECTORY_SEPARATOR), '.composer'),
    //         'COMPOSER_NO_INTERACTION' => '1',

    //         'NPM_CONFIG_STRICT_SSL' => 'false',
    //         'NPM_CONFIG_COLOR' => 'always'
    //     ];
    // }
}
