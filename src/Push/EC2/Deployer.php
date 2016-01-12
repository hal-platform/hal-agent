<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\EC2;

use Aws\Ec2\Ec2Client;
use QL\Hal\Agent\Push\AWSAuthenticator;
use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\EnumType\ServerEnum;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - EC2';
    const STATUS = 'Deploying push by EC2';
    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'EC2 deployment system is not configured';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for EC2 deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for EC2 deployment';
    const ERR_NO_INSTANCES = 'No EC2 instances are running';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @var InstanceFinder
     */
    private $finder;

    /**
     * @var Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param AWSAuthenticator $authenticator
     * @param InstanceFinder $finder
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $authenticator,
        InstanceFinder $finder,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->authenticator = $authenticator;

        $this->finder = $finder;
        $this->pusher = $pusher;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[ServerEnum::TYPE_EC2]) || !$this->verifyConfiguration($properties[ServerEnum::TYPE_EC2])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 300;
        }

        // authenticate
        if (!$ec2 = $this->authenticate($properties)) {
            return 301;
        }

        if (!$instances = $this->finder($ec2, $properties)) {
            $this->logger->event('failure', self::ERR_NO_INSTANCES);
            return 302;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // push
        if (!$this->push($properties, $instances)) {
            return 303;
        }

        // SKIP post-push commands
        if ($properties['configuration']['post_push']) {
            $this->logger->event('info', self::SKIP_POST_PUSH);
        }

        // success
        return 0;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function verifyConfiguration($properties)
    {
        $this->status('Verifying EC2 configuration', self::SECTION);

        if (!is_array($properties)) {
            return false;
        }

        if (!array_key_exists('region', $properties)) {
            return false;
        }

        if (!array_key_exists('credential', $properties)) {
            return false;
        }

        if (!array_key_exists('pool', $properties)) {
            return false;
        }

        if (!array_key_exists('remotePath', $properties)) {
            return false;
        }

        if (!array_key_exists('remoteUser', $properties)) {
            return false;
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return Ec2Client|null
     */
    private function authenticate(array $properties)
    {
        $this->status('Authenticating with AWS', self::SECTION);

        return $this->authenticator->getEC2(
            $properties[ServerEnum::TYPE_EC2]['region'],
            $properties[ServerEnum::TYPE_EC2]['credential']
        );
    }

    /**
     * @param Ec2Client $ec2
     * @param array $properties
     *
     * @return array|null
     */
    private function finder(Ec2Client $ec2, array $properties)
    {
        $this->status('Finding EC2 instances in pool', self::SECTION);

        $finder = $this->finder;
        $instances = $finder(
            $ec2,
            $properties[ServerEnum::TYPE_EC2]['pool'],
            InstanceFinder::RUNNING
        );

        if (!$instances) {
            return null;
        }

        return $instances;
    }

    /**
     * @param array $properties
     * @param array $instances
     *
     * @return boolean
     */
    private function push(array $properties, array $instances)
    {
        $this->status('Pushing code to EC2 instances', self::SECTION);

        $pusher = $this->pusher;
        return $pusher(
            $properties['location']['path'],
            $properties[ServerEnum::TYPE_EC2]['remoteUser'],
            $properties[ServerEnum::TYPE_EC2]['remotePath'],
            $properties['configuration']['exclude'],
            $instances
        );
    }
}
