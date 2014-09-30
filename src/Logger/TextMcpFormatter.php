<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use MCP\DataType\Time\TimePoint;

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
class TextMcpFormatter implements FormatterInterface
{
    const HEADER_DUMB = <<<'FORMAT'
################################################################################
{humanTitle}
################################################################################

FORMAT;

    const HEADER_BUILD = <<<'FORMAT'

################################################################################
{humanTitle}
################################################################################
Who initiated this build?
{builder}

What environment is the build for?
{environment}

What repository was built?
{repository}

What code was built?
{github}

When was the build initiated?
{when}

What happened?
{humanStatus}

--------------------------------------------------------------------------------

FORMAT;

    const HEADER_PUSH = <<<'FORMAT'

################################################################################
{humanTitle}
################################################################################
Who initiated this push?
{pusher}

Where was the code pushed?
{server} in {environment}

What repository was pushed?
{repository}

What code was pushed?
{github}

When was the push initiated?
{when}

What happened?
{humanStatus}

--------------------------------------------------------------------------------

FORMAT;

    const RECORD_FORMAT = <<<'FORMAT'

--------------------------------------------------------------------------------
[%s] %s: %s
--------------------------------------------------------------------------------

FORMAT;

    /**
     * @type Normalizer
     */
    private $normalizer;

    /**
     * @param Normalizer $normalizer
     */
    public function __construct(Normalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * {@inheritdoc}
     */
    public function format(array $master, array $records)
    {
        unset($master['context']['email']);
        unset($master['context']['master']);

        $pretty = $this->formatHeader($master) . $this->formatChildren($records);
        $master['context']['exceptionData'] = $pretty;

        return $master;
    }

    /**
     * @param array $records
     * @return string
     */
    private function formatChildren(array $records)
    {
        if (!$records) {
            return '';
        }

        $output = '';
        foreach ($records as $record) {
            $output .= $this->formatRecord($record, static::RECORD_FORMAT, $record['context']);
        }

        return $output;
    }

    /**
     * @param array $records
     * @return string
     */
    private function formatHeader(array $master)
    {
        $tokens = ['humanTitle' => 'Something bad happened.'];
        $header = $this->replaceTokens(static::HEADER_DUMB, $tokens);

        if (isset($master['context']['push'])) {
            $header = $this->formatPushHeader($master);
        }

        if (isset($master['context']['build'])) {
            $header = $this->formatBuildHeader($master);
        }

        $context = $this->normalizer->normalize($master['context']);
        $renderedContext = $this->normalizer->flatten($context);

        return $header . $renderedContext;
    }

    /**
     * @param array $master
     * @return string
     */
    private function formatBuildHeader(array $master)
    {
        $build = $master['context']['build'];
        $isSuccess = ($build->getStatus() === 'Success');

        $username = $build->getUser() ? $build->getUser()->getHandle() : 'unknown';

        $tokens = [
            'builder' => $username,
            'environment' => $master['context']['environment'],
            'repository' => $master['context']['repository'],
            'github' => $master['context']['github'],
            'when' => $this->normalizeElapsedTime($build->getStart(), $build->getEnd()),
            'humanTitle' => ($isSuccess) ? 'The build succeeded' : 'The build failed'
        ];

        $tokens['humanStatus'] = $tokens['humanTitle'];
        if (!$isSuccess) {
            $buildExitCode = $master['context']['buildExitCode'];
            $tokens['humanStatus'] .= <<<MORE

The exit code of the agent was $buildExitCode.
Check below for more details on the build process.
MORE;
        }

        return $this->replaceTokens(static::HEADER_BUILD, $tokens);
    }

    /**
     * @param array $master
     * @return string
     */
    private function formatPushHeader(array $master)
    {
        $push = $master['context']['push'];
        $isSuccess = ($push->getStatus() === 'Success');

        $username = $push->getUser() ? $push->getUser()->getHandle() : 'unknown';

        $tokens = [
            'pusher' => $username,
            'server' => $master['context']['repository'],
            'environment' => $master['context']['environment'],
            'repository' => $master['context']['repository'],
            'github' => $master['context']['github'],
            'when' => $this->normalizeElapsedTime($push->getStart(), $push->getEnd()),
            'humanTitle' => ($isSuccess) ? 'The push succeeded' : 'The push failed'
        ];

        $tokens['humanStatus'] = $tokens['humanTitle'];
        if (!$isSuccess) {
            $pushExitCode = $master['context']['pushExitCode'];
            $tokens['humanStatus'] .= <<<MORE

The exit code of the agent was $pushExitCode.
Check below for more details on the push process.
MORE;
        }

        return $this->replaceTokens(static::HEADER_PUSH, $tokens);
    }

    /**
     * @param array $records
     * @param string $template
     * @param array $context
     * @return string
     */
    private function formatRecord(array $record, $template, array $context)
    {
        $record = sprintf(
            $template,
            $this->normalizer->normalize($record['datetime']),
            $record['level_name'],
            $record['message']
        );

        $context = $this->normalizer->normalize($context);
        $context = $this->normalizer->flatten($context);

        return $record . $context;
    }

    /**
     * @param TimePoint $start
     * @param TimePoint|null $end
     * @return string
     */
    private function normalizeElapsedTime(TimePoint $start, TimePoint $end = null)
    {
        $startTime = $start->format('Y-m-d H:i:s', 'America/Detroit');
        $endTime = ($end) ? $end->format('Y-m-d H:i:s', 'America/Detroit') : null;

        if (!$endTime) {
            return $startTime . "\nFinish time could not be determined.";
        }

        $diff = $start->diff($end);
        $elapsed = $diff->format('%s') . ' seconds';
        if ($minutes = $diff->format('%i')) {
            $elapsed = $minutes . ' minutes, ' . $elapsed;
        }

        return <<<FORMATTED
Start: $startTime EST
End: $endTime EST
Total elapsed time: $elapsed
FORMATTED;
    }

    /**
     * @param string $message
     * @param array $properties
     * @return string
     */
    private function replaceTokens($message, array $properties)
    {
        foreach ($properties as $name => $prop) {
            $token = sprintf('{%s}', $name);
            if (false !== strpos($message, $token)) {
                $message = str_replace($token, $prop, $message);
            }
        }

        return $message;
    }
}
