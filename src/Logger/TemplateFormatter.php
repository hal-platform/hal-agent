<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use MCP\DataType\Time\TimePoint;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use Twig_Template;

/**
 * Standard Monolog Record:
 *
 * message:     (string)    $message
 * context:     (array)     $context
 * level:       (int)       $level
 * level_name:  (string)    $levelName
 * channel:     (string)    $name
 * datetime:    (DateTime)  $datetime
 * extra:       (array)     $extra
 */
class TemplateFormatter implements FormatterInterface
{
    const ICON_SUCCESS =  "\xE2\x9C\x94";
    const ICON_FAILURE =  "\xE2\x9C\x96";

    const ALERT_DEFAULT = 'Something terrible happened';

    /**
     * @type Normalizer
     */
    private $normalizer;

    /**
     * @type Twig_Template
     */
    private $twig;

    /**
     * @param Normalizer $normalizer
     * @param Twig_Template $twig
     */
    public function __construct(Normalizer $normalizer, Twig_Template $twig)
    {
        $this->normalizer = $normalizer;
        $this->twig = $twig;
    }

    /**
     * {@inheritdoc}
     */
    public function format(array $master, array $records)
    {
        $context = array_merge($this->formatMasterRecord($master), [
            'title' => 'derp derp',
            'logs' => $this->formatRecords($records)
        ]);

        $master['message'] = $this->twig->render($context);
        return $master;
    }

    /**
     * @param array $master
     * @return string
     */
    private function formatMasterRecord(array $master)
    {
        if (isset($master['context']['push'])) {
            return $this->formatPushProperties($master);
        }

        if (isset($master['context']['build'])) {
            return $this->formatBuildProperties($master);
        }

        return [
            'alert' => sprintf('[%s] %s', self::ICON_FAILURE, self::ALERT_DEFAULT)
        ];
    }

    /**
     * @param array $master
     * @param Build|Push $job
     * @return array
     */
    private function getJobProperties(array $master, $job)
    {
        $isSuccess = ($job->getStatus() === 'Success');
        $cleanedContext = $master['context'];
        unset($cleanedContext['email']);
        unset($cleanedContext['master']);
        unset($cleanedContext['link']);

        return [
            'alert' => sprintf('[%s] %s', self::ICON_FAILURE, self::ALERT_DEFAULT),
            'type' => 'job',
            'master_context' => $this->normalize($cleanedContext),

            'is_success' => $isSuccess,
            'username' => $job->getUser() ? $job->getUser()->getHandle() : 'unknown',
            'link' => $master['context']['link'],

            'repository_group' => $master['context']['repository_group'],
            'repository_description' => $master['context']['repository_description'],

            'environment' => $master['context']['environment'],
            'repository' => $master['context']['repository'],
            'github' => $master['context']['github'],
            'time' => $this->formatTime($job->getStart(), $job->getEnd())
        ];
    }

    /**
     * @param array $master
     * @return string
     */
    private function formatBuildProperties(array $master)
    {
        $data = $this->getJobProperties($master, $master['context']['build']);
        $data['is_build'] = true;
        $data['type'] = 'build';

        // default
        $data['alert'] = sprintf('[%s] %s', self::ICON_SUCCESS, 'The build succeeded');

        if (!$data['is_success']) {
            $data['alert'] = sprintf('[%s] %s', self::ICON_FAILURE, 'The build failed');
        }

        return $data;
    }

    /**
     * @param array $master
     * @return string
     */
    private function formatPushProperties(array $master)
    {
        $data = $this->getJobProperties($master, $master['context']['push']);

        $data['server'] = $master['context']['repository'];
        $data['is_push'] = true;
        $data['type'] = 'push';

        // default
        $data['alert'] = sprintf('[%s] %s', self::ICON_SUCCESS, 'The push succeeded');

        if (!$data['is_success']) {
            $data['alert'] = sprintf('[%s] %s', self::ICON_FAILURE, 'The push failed');
        }

        return $data;
    }

    /**
     * @param array $records
     * @return array
     */
    private function formatRecords(array $records)
    {
        $formatted = [];
        foreach ($records as $record) {
            $formatted[] = [
                'message' => $this->normalize($record['message']),
                'time' => $this->normalize($record['datetime']),
                'level' => strtolower($record['level_name']),
                'context' => $this->normalize($record['context'])
            ];
        }

        return $formatted;
    }

    /**
     * @param mixed $data
     * @return string
     */
    private function normalize($data)
    {
        $data = $this->normalizer->normalize($data);

        if (is_array($data)) {
            $data = $this->normalizer->flatten($data);
        }

        return $data;
        // return htmlspecialchars($data, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * @param TimePoint $start
     * @param TimePoint|null $end
     * @return string
     */
    private function formatTime(TimePoint $start, TimePoint $end = null)
    {
        $startTime = $start->format('Y-m-d H:i:s', 'America/Detroit');
        $endTime = ($end) ? $end->format('Y-m-d H:i:s', 'America/Detroit') : null;
        $elapsed = null;

        if ($endTime) {
            $diff = $start->diff($end);
            $elapsed = $diff->format('%s') . ' seconds';
            if ($minutes = $diff->format('%i')) {
                $elapsed = $minutes . ' minutes, ' . $elapsed;
            }
        }

        return [
            'start' => $startTime,
            'end' => $endTime,
            'elapsed' => $elapsed
        ];
    }
}
