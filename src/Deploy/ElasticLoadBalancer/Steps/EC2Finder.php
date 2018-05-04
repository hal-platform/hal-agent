<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Hal\Agent\Logger\EventLogger;
use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;
use Aws\Exception\CredentialsException;
use Aws\Exception\AwsException;
use Aws\ResultInterface;

class EC2Finder
{
    const EVENT_MESSAGE = 'EC2 Instances Finder';
    const ERR_TAGGED_INSTANCES = 'Could not find tagged instances';

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
     * @param Ec2Client $ec2
     * @param string $tagFilters
     *
     * @return ?array
     */
    public function __invoke(Ec2Client $ec2, $tagFilters): ?array
    {
        if (!$filters = $this->parseTags($tagFilters)) {
            return null;
        }

        try {
            $result = $ec2->describeInstances([
                'Filters' => $filters
            ]);

        } catch (AwsException | CredentialsException $e) {
            $context = ['error' => $e->getMessage()];
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);

            return null;
        }

        $instances = [];
        $data = $result->search('Reservations[].Instances[]') ?: [];
        $instanceIDs = $result->search('Reservations[].Instances[].InstanceId') ?: [];

        if (count($instanceIDs) === 0) {
            return null;
        }

        foreach ($data as $instance) {
            $instances[] = [
                'instanceId' => $instance['InstanceId'],
                'type' => $instance['InstanceType'],
                'state' => $instance['State'],
            ];
        }

        $instances_summary = $this->formatInstancesSummary($filters, $instances);

        $context = ['summary' => $instances_summary];
        $this->logger->event('success', self::EVENT_MESSAGE, $context);

        return $instanceIDs;
    }

    /**
     * Example:
     *
     * Input:
     * `deploymentgroup,Name=myapp`
     *
     * Output:
     *   [
     *     ['Name' => 'tag-key', 'Values' => ['deploymentgroup']],
     *     ['Name' => 'tag:Name', 'Values' => ['myapp']]
     *   ]
     *
     * @param string $tagFilters
     *
     * @return array
     */
    private function parseTags($tagFilters)
    {
        $filters = [];

        $tagFilters = explode(',', $tagFilters);

        foreach ($tagFilters as $tag) {
            if (!$tag) {
                continue;
            }
            $tagValue = explode('=', $tag);

            // Matching against the PRESENCE of a tag
            if (count($tagValue) === 1) {
                $filters[] = [
                    'Name' => 'tag-key',
                    'Values' => [$tagValue[0]]
                ];

            // Matching against KEY = VALUE for a tag.
            } elseif (count($tagValue) === 2) {
                $filters[] = [
                    'Name' => "tag:$tagValue[0]",
                    'Values' => [$tagValue[1]]
                ];
            }
        }

        return $filters;
    }

    /**
     * @param array $filters
     * @param array $instances
     *
     * @return string
     */
    private function formatInstancesSummary(array $filters, array $instances)
    {
        $formattedFilters = [];
        foreach ($filters as $f) {
            $formattedFilters[] = sprintf(' - %s = %s', $f['Name'], $f['Values'][0]);
        }

        $outputs = [
            'Filters:',
            implode("\n", $formattedFilters),
            ""
        ];

        $config = [30, 20, 70];
        $rows = [
            ['Instance ID', 'Instance Type', 'State'],
            array_map(function ($size) {
                return str_repeat('-', $size);
            }, $config)
        ];

        foreach ($instances as $instance) {
            $state = isset($instance['state']['Name']) ? $instance['state']['Name'] : 'Unknown';
            $rows[] = [
                $instance['instanceId'],
                $instance['type'],
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
