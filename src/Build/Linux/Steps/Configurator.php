<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux\Steps;

use Hal\Core\Entity\JobType\Build;

class Configurator
{
    /**
     * @var string
     */
    private $linuxBuildDirectory;

    /**
     * @var string
     */
    private $linuxUser;

    /**
     * @var array
     */
    private $buildServers;

    /**
     * @param string $linuxBuildDirectory
     * @param string $linuxUser
     * @param array $buildServers
     */
    public function __construct(string $linuxBuildDirectory, string $linuxUser, array $buildServers)
    {
        $this->linuxBuildDirectory = $linuxBuildDirectory;
        $this->linuxUser = $linuxUser;
        $this->buildServers = $buildServers;
    }

    /**
     * @param Build $build
     *
     * @return array
     */
    public function __invoke(Build $build)
    {
        $buildServer = $this->buildServers[array_rand($this->buildServers)];
        $buildConnection = sprintf('%s@%s', $this->linuxUser, $buildServer);

        return [
            'builder_connection' => $buildConnection,
            'remote_file' => $this->generateLinuxBuildPath($build->id()),
            'environment_variables' => $this->buildEnvironmentVariables($build)
        ];
    }

    /**
     * @param Build $build
     *
     * @return array
     */
    private function buildEnvironmentVariables(Build $build): ?array
    {
        $environmentName = ($environment = $build->environment()) ? $environment->name() : 'None';
        $applicationName = ($application = $build->application()) ? $application->name() : 'None';

        $env = [
            'HAL_JOB_ID' => $build->id(),
            'HAL_VCS_COMMIT' => $build->commit(),
            'HAL_VCS_REF' => $build->reference(),

            'HAL_ENVIRONMENT' => $environmentName,
            'HAL_APPLICATION' => $applicationName,
        ];

        return $env;
    }

    /**
     * @param string $uniqueID
     *
     * @return string
     */
    private function generateLinuxBuildPath($uniqueID)
    {
        $remoteDir = rtrim($this->linuxBuildDirectory, '/');

        return sprintf('%s/hal-build-%s.tgz', $remoteDir, $uniqueID);
    }
}
