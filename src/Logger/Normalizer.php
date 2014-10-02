<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use DateTime;
use Exception;
use Traversable;

/**
 * All this code was stolen from Monolog\Formatter\NormalizerFormatter
 */
class Normalizer
{
    /**
     * @type string
     */
    private $dateFormat;

    /**
     * @param string $dataFormat
     */
    public function __construct($dateFormat)
    {
        $this->dateFormat = $dateFormat;
    }

    /**
     * @param mixed $data
     * @return string|array
     */
    public function normalize($data)
    {
        if (is_string($data)) {
            return $data;
        }

        if (is_scalar($data) || is_null($data)) {
            return var_export($data, true);
        }

        if (is_array($data) || $data instanceof Traversable) {
            $normalized = [];

            $count = 1;
            foreach ($data as $key => $value) {
                if ($count++ >= 10000) {
                    $normalized['...'] = 'Over 10000 items, aborting normalization';
                    break;
                }
                $normalized[$key] = $this->normalize($value);
            }

            return $normalized;
        }

        if ($data instanceof DateTime) {
            return $data->format($this->dateFormat);
        }

        if (is_object($data)) {
            if ($data instanceof Exception) {
                return $this->normalizeException($data);
            }

            return sprintf('[object] (%s: %s)', get_class($data), $this->toJson($data));
        }

        if (is_resource($data)) {
            return '[resource]';
        }

        return sprintf('[unknown(%s)]', gettype($data));
    }

    /**
     * @param mixed $data
     * @return string|array
     */
    public function flatten($normalized, $level = 0)
    {
        $output = '';
        $pad = str_pad('', $level * 4);

        if (!is_array($normalized)) {
            return $pad . $normalized . "\n";
        }

        foreach ($normalized as $key => $data) {
            $output .= $pad . sprintf('%s:', $key) . "\n";

            if (is_array($data)) {
                $output .= $this->flatten($data, $level + 1);
            } else {
                $output .= $pad . $data . "\n";
            }

            if ($level === 0) {
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * @param Exception $exception
     * @return array
     */
    public function normalizeException(Exception $exception)
    {
        $data = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => sprintf('%s:%s', $exception->getFile(), $exception->getLine())
        ];

        $trace = $exception->getTrace();
        foreach ($trace as $frame) {
            if (isset($frame['file'])) {
                $data['trace'][] = sprintf('%s:%s', $frame['file'], $frame['line']);
            } else {
                $data['trace'][] = $this->toJson($frame);
            }
        }

        if ($previous = $exception->getPrevious()) {
            $data['previous'] = $this->normalizeException($previous);
        }

        return $data;
    }

    /**
     * @param mixed $data
     * @return string
     */
    public function toJson($data)
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
