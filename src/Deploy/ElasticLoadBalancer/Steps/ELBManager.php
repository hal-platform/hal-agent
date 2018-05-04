<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\ElasticLoadBalancing\Exception\ElasticLoadBalancingException;
use Aws\Exception\CredentialsException;
use Hal\Agent\Logger\EventLogger;

class ELBManager
{
    private const EVENT_MESSAGE = 'Validate ELBs contain only eligible instances';
    private const STATUS_FOUND_UNKNOWNS = 'Found unknown instances in ELBs - These will not be swapped';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @param EventLogger $logger
     */
    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param ElasticLoadBalancingClient $elb
     * @param string $elbName
     * @param array $taggedInstances
     *
     * @return ?array
     */
    public function getValidELBInstances(ElasticLoadBalancingClient $elb, string $elbName, array $taggedInstances): ?array
    {
        if (!$elbInstancesStates = $this->describeLoadBalancer($elb, $elbName)) {
            return null;
        }

        [$destinationInstances, $unknownInstances] = $this->filterInstances($elbInstancesStates, $taggedInstances);

        if (!$this->validateUnknownInstances($elbName, $unknownInstances, $taggedInstances)) {
            return null;
        }

        if ($destinationInstances) {
            return $destinationInstances;
        }

        return null;
    }

    /**
     * @param ElasticLoadBalancingClient $elb
     * @param string $elbName
     *
     * @return ?array
     */
    public function describeLoadBalancer(ElasticLoadBalancingClient $elb, string $elbName): ?array
    {
        try {
            $result = $elb->describeInstanceHealth([
                'LoadBalancerName' => $elbName
            ]);

        } catch (ElasticLoadBalancingException | CredentialsException $ex) {
            $context = ['error' => $ex->getMessage()];
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return null;
        }

        return $result->search('InstancesStates');
    }

    /**
     * @param array $elbInstancesStates
     * @param array $taggedInstances
     *
     * @return array
     */
    private function filterInstances(array $elbInstancesStates, array $taggedInstances)
    {
        $destination = [];
        $unknowns = [];

        foreach ($elbInstancesStates as $a) {
            if (in_array($a['InstanceId'], $taggedInstances)) {
                $destination[] = $a['InstanceId'];
            } else {
                $unknowns[] = $a;
            }
        }

        return [$destination, $unknowns];
    }

    /**
     * @param string $elbName
     * @param array $unknownElbInstances
     * @param array $taggedInstances
     *
     * @return bool
     */
    private function validateUnknownInstances(string $elbName, array $unknownElbInstances, array $taggedInstances)
    {
        if (!$unknownElbInstances) {
            return true;
        }

        $context = [
            'loadBalancer' => $elbName,
            'unknownInstances' => $unknownElbInstances,
            'taggedInstances' => $taggedInstances,
        ];

        $this->logger->event('info', self::STATUS_FOUND_UNKNOWNS, $context);

        if ($activeUnknowns = $this->activeUnknownInstancesSummary($unknownElbInstances)) {
            $context = $context + [
                'invalidActiveInstances' => implode("\n\n", $activeUnknowns)
            ];

            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        }

        return true;
    }

    /**
     * @param array $unknownElbInstances
     *
     * @return ?array
     */
    private function activeUnknownInstancesSummary(array $unknownElbInstances)
    {
        $activeUnknowns = [];

        foreach ($unknownElbInstances as $instance) {
            if ($instance['State'] != 'OutOfService') {
                $activeUnknowns[] = sprintf(
                    "Instance: %s\nState: %s\nReason: %s - %s",
                    $instance['InstanceId'],
                    $instance['State'],
                    $instance['ReasonCode'],
                    $instance['Description']
                );
            }
        }

        if ($activeUnknowns) {
            return $activeUnknowns;
        }

        return null;
    }
}
