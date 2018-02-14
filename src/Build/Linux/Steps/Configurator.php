<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux\Steps;

use Hal\Agent\Build\EnvironmentVariablesTrait;
use Hal\Core\Entity\Job;

class Configurator
{
    use EnvironmentVariablesTrait;

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
     * @param Job $job
     *
     * @return array
     */
    public function __invoke(Job $job)
    {
        $buildServer = $this->buildServers[array_rand($this->buildServers)];
        $buildConnection = sprintf('%s@%s', $this->linuxUser, $buildServer);

        return [
            'builder_connection' => $buildConnection,
            'remote_file' => $this->generateLinuxBuildPath($job->id()),
            'environment_variables' => $this->buildEnvironmentVariables($job)
        ];
    }

    /**
     * @param string $uniqueID
     *
     * @return string
     */
    private function generateLinuxBuildPath($uniqueID)
    {
        $remoteDir = rtrim($this->linuxBuildDirectory, '/');

        return sprintf('%s/hal-job-%s.tgz', $remoteDir, $uniqueID);
    }
}
