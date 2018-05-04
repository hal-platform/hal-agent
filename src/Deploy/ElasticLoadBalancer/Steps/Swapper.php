<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\Waiter;
use Hal\Agent\Waiter\TimeoutException;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\ElasticLoadBalancing\Exception\ElasticLoadBalancingException;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use RuntimeException;

class Swapper
{
    private const EVENT_MESSAGE = 'Swaping instances in Elastic Load Balancers';

    private const INFO_WAITING_REGISTER  = 'Waiting for instances to come into service';
    private const INFO_WAITING_REMOVE = 'Waiting for instances to switch out of service';

    private const ERR_WAIT_REGISTER = 'An error occurred while waiting for instances to become live';
    private const ERR_WAIT_REMOVE = 'An error occurred while waiting for instances to be deregistered';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Waiter
     */
    private $waiter;

    /**
     * @param EventLogger $logger
     */
    public function __construct(EventLogger $logger, Waiter $waiter)
    {
        $this->logger = $logger;
        $this->waiter = $waiter;
    }

    /**
     * @param ElasticLoadBalancingClient $elb
     * @param string $elbName
     * @param array $addToELB
     * @param array $removeFromELB
     *
     * @return bool
     */
    public function __invoke(ElasticLoadBalancingClient $elb, $elbName, array $addToELB, array $removeFromELB): bool
    {
        $context = [
            'loadBalancer' => $elbName,
            'instancesToBeRegistered' => $addToELB,
            'instancesToBeRemoved' => $removeFromELB,
        ];

        try {
            $elb->registerInstancesWithLoadBalancer([
                'LoadBalancerName' => $elbName,
                'Instances' => $addToELB
            ]);

            $elb->deregisterInstancesFromLoadBalancer([
                'LoadBalancerName' => $elbName,
                'Instances' => $removeFromELB
            ]);

        } catch (ElasticLoadBalancingException | CredentialsException $ex) {
            $context = ['error' => $ex->getMessage()];
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        }

        $this->logger->event('success', self::EVENT_MESSAGE, $context);

        if (!$this->waitUntilInstancesInService($elb, $elbName, $addToELB)) {
            return false;
        }

        if (!$this->waitUntilInstancesOutOfService($elb, $elbName, $removeFromELB)) {
            return false;
        }

        return true;
    }

    /**
     * @param ElasticLoadBalancingClient $elb
     * @param string $elbName
     * @param array $instances
     *
     * @return bool
     */
    private function waitUntilInstancesInService(ElasticLoadBalancingClient $elb, $elbName, $instances) : bool
    {
        return $this->wait($elb, $elbName, $instances, 'InService', self::INFO_WAITING_REGISTER, self::ERR_WAIT_REGISTER);
    }

    /**
     * @param ElasticLoadBalancingClient $elb
     * @param string $elbName
     * @param array $instances
     *
     * @return bool
     */
    private function waitUntilInstancesOutOfService(ElasticLoadBalancingClient $elb, $elbName, $instances) : bool
    {
        return $this->wait($elb, $elbName, $instances, 'OutOfService', self::INFO_WAITING_REMOVE, self::ERR_WAIT_REMOVE);
    }

    /**
     * @param ElasticLoadBalancingClient $elb
     * @param string $elbName
     * @param array $instances
     * @param string $state
     * @param string $waitingMessage
     * @param string $errorMessage
     *
     * @return bool
     */
    private function wait(ElasticLoadBalancingClient $elb, string $elbName, array $instances, string $state, string $waitingMessage, string $errorMessage) : bool
    {
        $waiter = $this->buildWaiter($elb, $elbName, $instances, $state, $waitingMessage);
        try {
            $this->waiter->wait($waiter);
            return true;
        } catch (TimeoutException | AwsException | CredentialsException $ex) {
            $context = ['error' => $ex->getMessage()];
            $this->logger->event('failure', $errorMessage, $context);
            return false;
        }
    }

    /**
     * @param ElasticLoadBalancingClient $elb
     * @param string $elbName
     * @param array $instances
     * @param string $state
     *
     * @return callable
     */
    private function buildWaiter(ElasticLoadBalancingClient $elb, $elbName, $instances, $state, $waitingMessage)
    {
        $iteration = 0;
        return function () use ($elb, $elbName, $instances, $state, $waitingMessage, &$iteration) {

            $health = $elb->describeInstanceHealth([
                'LoadBalancerName' => $elbName
            ]);

            $jmesPath = sprintf('InstancesStates[?State == \'%s\'].InstanceId', $state);

            $inServiceInstances = $health->search($jmesPath);

            if (array_diff($instances, $inServiceInstances) != []) {
                return true;
            }

            // Pop a status every 9 iterations (3 minutes, using 20s interval)
            if (++$iteration % 9 === 0) {
                $this->logger->event('info', $waitingMessage, $health->search(''));
            }
        };
    }
}
