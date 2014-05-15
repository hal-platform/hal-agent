<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Formatter\NormalizerFormatter;

class TextFormatter extends NormalizerFormatter
{
    const MESSAGE_FORMAT = <<<'FORMAT'

--------------------------------------------------------------------------------
[%s] %s: %s
--------------------------------------------------------------------------------

FORMAT;

    /**
     * {@inheritdoc}
     */
    public function __construct($dateFormat = null)
    {
        parent::__construct($dateFormat);
    }

    /**
     * {@inheritdoc}
     */
    public function format(array $record)
    {
        foreach ($record as $key => $data) {
            $record[$key] = $this->normalize($data);
        }

        $output = sprintf(
            static::MESSAGE_FORMAT,
            $record['datetime'],
            $record['level_name'],
            $record['message']
        );

        if (!$record['context']) {
            return $output;
        }

        foreach ($record['context'] as $key => $data) {
            $data = $this->stringify($data);
            if (strpos($data, "\n") === false) {
                $output .= sprintf("%s: %s\n", $key, $data);
            } else {
                $output .= sprintf("%s:\n%s\n", $key, $data);
            }
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     * @return string
     */
    public function formatBatch(array $records)
    {
        $message = '';
        foreach ($records as $record) {
            $message .= $this->format($record);
        }

        return $message;
    }

    /**
     * @param string|array $data
     * @return string
     */
    private function stringify($data)
    {
        if (is_array($data)) {
            return $this->toJson($data);
        }

        return $data;
    }
}
