<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

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
    const HEADER_FORMAT = <<<'FORMAT'

################################################################################
[%s] %s: %s
################################################################################

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
        $pretty = $this->formatRecord($master, static::HEADER_FORMAT, $master['context']) . $this->formatChildren($records);
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
}
