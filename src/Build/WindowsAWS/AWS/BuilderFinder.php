<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\AWS;

use Aws\Ec2\Ec2Client;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\AWS\EC2Finder;

class BuilderFinder
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Found eligible builder "%s"';

    const ERR_NO_INSTANCES = 'No eligible builders found for this platform';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var EC2Finder
     */
    private $instanceFinder;

    /**
     * @param EventLogger $logger
     * @param EC2Finder $instanceFinder
     */
    public function __construct(EventLogger $logger, EC2Finder $instanceFinder)
    {
        $this->logger = $logger;
        $this->instanceFinder = $instanceFinder;
    }

    /**
     * Select the instance from round robin with a specific tag
     *
     * @param Ec2Client $ec2
     * @param string $tagFilter
     *
     * @return string|null
     */
    public function __invoke(Ec2Client $ec2, $tagFilter)
    {
        $summary = ($this->instanceFinder)($ec2, $tagFilter);

        if ($summary['status'] !== EC2Finder::STATUS_OK) {
            $this->logger->event('failure', self::ERR_NO_INSTANCES, ['filter' => $tagFilter]);
            return null;
        }

        $potentialInstances = array_filter($summary['instances'], function ($v) {
            return ($v['platform'] === 'windows' && $v['state']['Name'] === 'running');
        });

        if (!$potentialInstances) {
            $this->logger->event('failure', self::ERR_NO_INSTANCES, ['filter' => $tagFilter, 'instances' => $summary['summary']]);
            return null;
        }

        shuffle($potentialInstances);
        $instance = array_pop($potentialInstances);
        $instanceID = $instance['instance_id'];

        $this->logger->event('success', sprintf(self::EVENT_MESSAGE, $instanceID));
        return $instanceID;
    }
}
