<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;
use Symfony\Component\Process\ProcessBuilder;

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
    const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt.';

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

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
     * @param BuildRepository $buildRepo
     * @param ProcessBuilder $processBuilder
     * @param string $envPath
     * @param string $archivePath
     */
    public function __construct(BuildRepository $buildRepo, ProcessBuilder $processBuilder, $envPath, $archivePath)
    {
        $this->buildRepo = $buildRepo;
        $this->processBuilder = $processBuilder;
        $this->envPath = $envPath;
        $this->archivePath = $archivePath;
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
            'buildCommand' => $build->getRepository()->getBuildCmd(),

            'buildFile' => $this->generateRepositoryDownload($build->getId()),
            'buildPath' => $this->generateBuildPath($build->getId()),
            'archiveFile' => $this->generateBuildArchive($build->getId()),

            'githubUser' => $build->getRepository()->getGithubUser(),
            'githubRepo' => $build->getRepository()->getGithubRepo(),
            'githubReference' => $build->getCommit(),

            'environmentVariables' => $this->generateBuildEnvironmentVariables($build)
        ];

        $properties['artifacts'] = $this->findBuildArtifacts($properties);

        return $properties;
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
     * Find the build artifacts that must be cleaned up after build.
     *
     * @param array $properties
     * @return array
     */
    private function findBuildArtifacts(array $properties)
    {
        $artifacts = [
            $properties['buildFile'],
            $properties['buildPath']
        ];

        $caches = [
            'BOWER_STORAGE__CACHE',
            'BOWER_STORAGE__PACKAGES',
            'BOWER_TMP',
            'COMPOSER_CACHE_DIR',
            'NPM_CONFIG_CACHE'
        ];

        foreach ($caches as $cache) {
            if (isset($properties['environmentVariables'][$cache])) {
                $artifacts[] = $properties['environmentVariables'][$cache];
            }
        }

        // Add $HOME if this is an isolated build
        // For the love of all that is holy $HOME better be set to a build specific directory!
        if (false) {
        // if ($properties['build']->getRepository()->isIsolated()) {
            $artifacts[] = $properties['HOME'];
        }

        return $artifacts;
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
     *  Generate a target for $HOME and/or $TEMP with an optional suffix for uniqueness
     *
     *  @param string $suffix
     *  @return string
     */
    private function generateHomePath($suffix = '')
    {
        if (!$this->homeDirectory) {
            $this->homeDirectory = $this->getBuildDirectory() . 'home';
        }

        $suffix = (strlen($suffix) > 0) ? sprintf('.%s', $suffix) : '';

        return rtrim($this->homeDirectory, DIRECTORY_SEPARATOR) . $suffix . DIRECTORY_SEPARATOR;
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
            'HOME' => $this->generateHomePath($build->getRepository()->getId()),
            'PATH' => $this->envPath,

            'HAL_BUILDID' => $build->getId(),
            'HAL_COMMIT' => $build->getCommit(),
            'HAL_GITREF' => $build->getBranch(),
            'HAL_ENVIRONMENT' => $build->getEnvironment()->getKey(),
            'HAL_REPO' => $build->getRepository()->getKey()
        ];

        // add package manager configuration
        $vars = array_merge($vars, $this->getPackageManagerEnv($vars));
        $vars = array_merge($vars, $this->getRubyEnv($vars));
        $vars = array_merge($vars, $this->getIsolatedEnv($vars, $build));

        return $vars;
    }

    /**
     * NOT ACTIVE.
     *
     * This was not updated to correctly isolated the ruby env
     *
     * @param array $env
     * @param Build $build
     * @return array
     */
    private function getIsolatedEnv(array $vars, Build $build)
    {
        if (true) {
        // if ($build->getRepository()->isIsolated()) {
            return [];
        }

        $buildPath = $this->generateBuildPath($build->getId());

        return [
            # DEFAULT = ???, version < 1.0.0
            'BOWER_STORAGE__CACHE' => $buildPath . '-bower-cache',

            # DEFAULT = ???, version >= 1.0.0
            'BOWER_STORAGE__PACKAGES' => $buildPath . '-bower-cache',

            # DEFAULT = $TEMP/bower
            'BOWER_TMP' => $buildPath . '-bower',

            # DEFAULT = $COMPOSER_HOME/cache
            'COMPOSER_CACHE_DIR' => $buildPath . '-composer-cache',

            # DEFAULT = $HOME/.npm
            'NPM_CONFIG_CACHE' =>  $buildPath . '-npm-cache',

            'HOME' =>  $buildPath . '-home'
        ];
    }

    /**
     * Ruby sucks. This is ridiculous.
     *
     * @param array $env
     * @return array
     */
    private function getRubyEnv(array $vars)
    {
        /**
         * Get the default gempath when we remove the GEM_HOME and GEM_PATH env variables
         */
        $process = $this->processBuilder
            ->setWorkingDirectory($vars['HOME'])
            ->setArguments(['gem', 'env', 'gempath'])
            ->addEnvironmentVariables(['HOME' => $vars['HOME']])
            ->getProcess();

        $process->run();

        if (!$gemPaths = $process->getOutput()) {
            return [];
        }

        $gemPaths = trim($gemPaths);
        $default = null;

        /**
         * Get the gem path within the HOME dir
         */
        $paths = explode(':', $gemPaths);
        foreach ($paths as $path) {
            if (stripos($path, $vars['HOME']) === 0) {
                $default = $path;
            }
        }

        if (!$default) {
            // if not found just bail out and don't set any ruby envs.
            return [];
        }

        $bindir = sprintf('%s/bin', $default);

        return [
            // wheres gems are installed
            'GEM_HOME' => $default,

            // where gems are searched for
            'GEM_PATH' => implode(':', $paths),

            // Add the new gembin dir to the PATH
            'PATH' => sprintf('%s:%s', $bindir, $vars['PATH'])
        ];
    }

    /**
     * @param array $env
     * @return array
     */
    private function getPackageManagerEnv(array $vars)
    {
        return [
            'BOWER_INTERACTIVE' => 'false',
            'BOWER_STRICT_SSL' => 'false',

            'COMPOSER_HOME' => $vars['HOME'],
            'COMPOSER_NO_INTERACTION' => '1',

            'NPM_CONFIG_STRICT_SSL' => 'false',
            'NPM_CONFIG_COLOR' => 'always'
        ];
    }

}
