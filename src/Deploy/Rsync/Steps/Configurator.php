<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync\Steps;

use Hal\Agent\Build\EnvironmentVariablesTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Parameters;

class Configurator
{
    use EnvironmentVariablesTrait;

    protected const NO_SERVERS = 'No Rsync servers defined';
    protected const TOO_MANY_SERVERS = 'Too many Rsync servers defined';
    protected const SYNC_PATH = '%s@%s:%s';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var string
     */
    private $sshUser;

    /**
     * @param EventLogger $logger
     * @param string $sshUser
     */
    public function __construct(EventLogger $logger, string $sshUser)
    {
        $this->logger = $logger;
        $this->sshUser = $sshUser;
    }

    /**
     * @param Release $release
     *
     * @return array|null
     */
    public function __invoke(Release $release): ?array
    {
        $target = $release->target();
        $servers = $target->parameter(Parameters::TARGET_RSYNC_SERVERS);
        $remotePath = $target->parameter(Parameters::TARGET_RSYNC_REMOTE_PATH);

        if (strlen($servers) === 0) {
            $this->logger->event('failure', static::NO_SERVERS);
            return null;
        }
        $serverList = explode(',', $servers);

        // Not allowed yet. Related task: issue #265
        if (count($serverList) > 1) {
            $context = ['servers' => $serverList];

            $this->logger->event('failure', static::TOO_MANY_SERVERS, $context);
            return null;
        }

        $hostname = $serverList[0];

        return [
            'remoteUser' => $this->sshUser,
            'remoteServer' => $hostname,
            'remotePath' => $remotePath,
            'syncPath' => sprintf(static::SYNC_PATH, $this->sshUser, $hostname, $remotePath),
            'environmentVariables' => $this->environmentVariables($release, $hostname, $remotePath)
        ];
    }

    /**
     * @param Release $release
     * @param string $hostname
     * @param string $remotePath
     *
     * @return array
     */
    private function environmentVariables(Release $release, $hostname, $remotePath): array
    {
        $rsyncEnvironment = [
            'HAL_HOSTNAME' => $hostname,
            'HAL_PATH' => $remotePath
        ];

        $buildEnvironment = $this->buildEnvironmentVariables($release);

        return array_merge($buildEnvironment, $rsyncEnvironment);
    }
}
