<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\ElasticBeanstalk\Exception\ElasticBeanstalkException;
use Aws\ResultInterface;
use DateTime;
use QL\MCP\Common\Time\Clock;
use QL\MCP\Common\Time\TimePoint;

/**
 * Get the health status for an elastic beanstalk environment
 *
 * Status:
 *   - Launching
 *   - Updating
 *   - Ready
 *   - Terminating
 *   - Terminated
 *
 *   - Missing
 *   - Invalid
 *
 * Health:
 *   - Red
 *   - Yellow
 *   - Green
 *   - Grey
 *
 *  HealthStatus:
 *   - NoData
 *   - Unknown
 *   - Pending
 *   - Ok
 *   - Info
 *   - Warning
 *   - Degraded
 *   - Severe
 */
class HealthChecker
{
    const NON_STANDARD_MISSING = 'Missing';
    const NON_STANDARD_INVALID = 'Invalid';
    const NUM_RECENT_EVENTS = 25;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var string
     */
    private $outputTimezone;

    /**
     * @param Clock $clock
     * @param string $outputTimezone
     */
    public function __construct(Clock $clock, $outputTimezone)
    {
        $this->clock = $clock;
        $this->outputTimezone = $outputTimezone;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $applicationName
     * @param string $environment
     *
     * @return array
     */
    public function __invoke(ElasticBeanstalkClient $eb, $applicationName, $environment)
    {
        try {
            $health = $this->getEnvironmentHealth($eb, $applicationName, $environment);
            $history = $this->getEventHistory($eb, $applicationName);

        } catch (ElasticBeanstalkException $ex) {
            return $this->buildResponse(self::NON_STANDARD_INVALID);
        }

        $context = $health + $history;
        return $context;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $applicationName
     * @param string $environment
     *
     * @return array
     */
    public function getEnvironmentHealth(ElasticBeanstalkClient $eb, $applicationName, $environment)
    {
        $prop = 'EnvironmentNames';
        if (substr($environment, 0, 2) === 'e-') {
            $prop = 'EnvironmentIds';
        }

        $result = $eb->describeEnvironments([
            'ApplicationName' => $applicationName,
            $prop => [$environment]
        ]);

        if (!$environment = $result->search('Environments[0]')) {
            return $this->buildResponse(self::NON_STANDARD_MISSING, 'Grey');
        }

        return $this->buildResponse(
            $result->search('Environments[0].Status'),
            $result->search('Environments[0].Health'),
            $result->search('Environments[0].HealthStatus')
        );
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $applicationName
     *
     * @return array
     */
    public function getEventHistory(ElasticBeanstalkClient $eb, $applicationName)
    {
        $result = $eb->describeEvents([
            'ApplicationName' => $applicationName,
            'MaxRecords' => self::NUM_RECENT_EVENTS,
        ]);

        $history = $this->parseEvents($result);
        return ['eventHistory' => $history];
    }

    /**
     * @param string $status
     * @param string $health
     * @param string $healthStatus
     *
     * @return array
     */
    private function buildResponse($status, $health = '', $healthStatus = '')
    {
        return [
            'status' => $status,
            'health' => $health,
            'healthStatus' => $healthStatus
        ];
    }

    /**
     * @param ResultInterface $result
     *
     * @return string
     */
    private function parseEvents(ResultInterface $result)
    {
        $config = [30, 10, 30, 30, 20];
        $header = [
            ['Time', 'Severity', 'Application', 'Environment', 'Message'],
            array_map(function ($size) {
                return str_repeat('-', $size);
            }, $config)
        ];

        $output = [
            $this->renderLine($header[0], $config),
            $this->renderLine($header[1], $config),
        ];

        $events = $result->search('Events') ?: [];

        foreach ($events as $event) {
            $event = [
                $this->formatTime($event['EventDate']),
                $event['Severity'],
                $event['ApplicationName'],
                isset($event['EnvironmentName']) ? $event['EnvironmentName'] : 'N/A',
                $event['Message']
            ];

            $output[] = $this->renderLine($event, $config);
        }

        return implode("\n", $output);
    }

    /**
     * @param array $event
     * @param array $config
     *
     * @return string
     */
    private function renderLine(array $event, array $config)
    {
        $line = [];
        foreach ($config as $index => $size) {
            $line[] = str_pad($event[$index], $size);
        }

        return implode(' | ', $line);
    }

    /**
     * @param TimePoint|string|null $time
     *
     * @return string
     */
    private function formatTime($time)
    {
        if (!$time) {
            return 'N/A';
        }

        if (!$time instanceof TimePoint) {
            $time = $this->clock->fromString($time, DateTime::ATOM);
        }

        if ($time) {
            return $time->format('M d, Y h:i:s T', $this->outputTimezone);
        }

        return 'N/A';
    }
}
