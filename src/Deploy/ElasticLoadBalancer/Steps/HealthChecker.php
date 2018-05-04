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

class HealthChecker
{
    private const STATUS_EMPTY = 'Empty';
    private const STATUS_OK = 'Ok';
    private const EVENT_MESSAGE = 'Checking Health of Elastic Load Balancers';

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
     *
     * @return bool
     */
    public function __invoke(ElasticLoadBalancingClient $elb, $elbName): bool
    {
        $context = [
            'loadBalancer' => $elbName
        ];

        try {
            $lb = $elb->describeLoadBalancers([
                'LoadBalancerNames' => [$elbName]
            ]);

            if (!$lb = $lb->search('LoadBalancerDescriptions[0]')) {
                return false;
            }

            $instances = $elb->describeInstanceHealth([
                'LoadBalancerName' => $elbName
            ]);

            if (!$instancesStates = $instances->search('InstanceStates')) {
                return false;
            }

        } catch (ElasticLoadBalancingException | CredentialsException $ex) {
            $context = array_merge($context, ['error' => $ex->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        }

        $elbSummary = $this->formatELBSummary($lb, $instancesStates);

        $this->logger->event('success', self::EVENT_MESSAGE, [
            'loadBalancer' => $elbSummary
        ]);

        return true;
    }

    /**
     * @param array $lb
     * @param array $instancesStates
     *
     * @return string
     */
    private function formatELBSummary(array $lb, array $instancesStates)
    {
        $status = $instancesStates ? self::STATUS_OK : self::STATUS_EMPTY;
        $outputs = [
            sprintf("Load Balancer: %s", $lb['LoadBalancerName']),
            sprintf("DNS: %s", $lb['DNSName']),
            sprintf("Type: %s (%s)", $lb['Scheme'], $status),
            ""
        ];

        $config = [30, 40, 30];
        $rows = [
            ['Instance ID', 'Load Balancer', 'Status'],
            array_map(function ($size) {
                return str_repeat('-', $size);
            }, $config)
        ];

        foreach ($instancesStates as $instance) {
            $state = $instance['State'];
            if ($instance['ReasonCode'] && $instance['ReasonCode'] !== 'N/A') {
                $state .= sprintf(' - [%s] %s', $instance['ReasonCode'], $instance['Description']);
            }

            $rows[] = [
                $instance['InstanceId'],
                $lb['LoadBalancerName'],
                $state
            ];
        }

        if (count($rows) === 2) {
            $rows[] = ['N/A', 'N/A', 'N/A'];
        }

        $outputs[] = $this->renderSummaryLines($rows, $config);

        return implode("\n", $outputs);
    }

    /**
     * @param array $lines
     * @param array $config
     *
     * @return string
     */
    private function renderSummaryLines(array $lines, array $config)
    {
        $formatted = [];
        foreach ($lines as $event) {
            $line = [];
            foreach ($config as $index => $size) {
                $line[] = str_pad($event[$index], $size);
            }

            $formatted[] = implode(' | ', $line);
        }

        return implode("\n", $formatted);
    }
}
