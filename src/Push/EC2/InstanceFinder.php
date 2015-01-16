<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\EC2;

use Aws\Ec2\Ec2Client;

/**
 * Get EC2 instances in a pool
 *
 * Instance state:
 *     - 0 : pending
 *     - 16 : running
 *     - 32 : shutting-down
 *     - 48 : terminated
 *     - 64 : stopping
 *     - 80 : stopped
 *
 */

class InstanceFinder
{
    const RUNNING = 16;

    const TAG_NAME_FOR_POOL = 'hal_pool';

    /**
     * @type Ec2Client
     */
    private $ec2;

    /**
     * @type int
     */
    private static $instanceStates = [
        0,
        16,
        32,
        48,
        64,
        80
    ];

    /**
     * @param Ec2Client $ec2
     */
    public function __construct(Ec2Client $ec2)
    {
        $this->ec2 = $ec2;
    }

    /**
     * Find instances in a pool, optionally filter by their state.
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.Ec2.Ec2Client.html#_describeInstances
     *
     * @param string $pool
     * @param string $state
     *
     * @return array
     */
    public function __invoke($pool, $state = null)
    {
        $tagQuery = sprintf('tag:%s', self::TAG_NAME_FOR_POOL);
        $filters = [
            ['Name' => $tagQuery, 'Values' => [$pool]]
        ];

        // only add state filter if it is valid
        if ($state && in_array($state, self::$instanceStates, true)) {
            $filters[] = ['Name' => 'instance-state-code', 'Values' => [$state]];
        }

        $reservations = $this->ec2->describeInstances([
            'Filters' => $filters
        ]);

        $reservations = $reservations['Reservations'];

        // well thats weird
        if (count($reservations) !== 1) {
            return [];
        }

        $instances = $reservations[0]['Instances'];

        return $instances;
    }
}
