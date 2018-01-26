<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;
use Aws\Exception\CredentialsException;

/**
 * @todo Move this out of this namespace, since its generic enough to be used elsewhere (and is - see windows builder)
 */
class EC2Finder
{
    const STATUS_INVALID = 'Invalid';
    const STATUS_BAD_CREDS = 'Bad Credentials';
    const STATUS_EMPTY = 'Empty';
    const STATUS_OK = 'Ok';

    /**
     * @param Ec2Client $ec2
     * @param string $tagFilters
     *
     * @return array
     */
    public function __invoke(Ec2Client $ec2, $tagFilters)
    {
        if (!$filters = $this->parseTags($tagFilters)) {
            return [
                'status' => self::STATUS_INVALID,
                'instances' => []
            ];
        }

        try {
            $result = $ec2->describeInstances([
                'Filters' => $filters
            ]);

        } catch (Ec2Exception $ex) {
            return [
                'status' => self::STATUS_INVALID,
                'instances' => []
            ];
        } catch (CredentialsException $e) {
            return [
                'status' => self::STATUS_BAD_CREDS,
                'instances' => []
            ];
        }

        $instances = [];
        $data = $result->search('Reservations[].Instances[]') ?: [];
        $instanceIDs = $result->search('Reservations[].Instances[].InstanceId') ?: [];

        foreach ($data as $instance) {
            $instances[] = [
                'instance_id' => $instance['InstanceId'],
                'type' => $instance['InstanceType'],
                'state' => $instance['State'],
                'platform' => isset($instance['Platform']) ? $instance['Platform'] : '',
            ];
        }

        return [
            'status' => (count($instanceIDs) === 0) ? self::STATUS_EMPTY : self::STATUS_OK,

            'instance_ids' => $instanceIDs,
            'instances' => $instances,
            'summary' => $this->formatInstancesSummary($filters, $instances)
        ];
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
                    'Name' => sprintf('tag:%s', $tagValue[0]),
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
                $instance['instance_id'],
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
