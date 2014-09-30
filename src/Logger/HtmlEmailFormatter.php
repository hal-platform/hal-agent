<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Logger;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;

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
class HtmlEmailFormatter implements FormatterInterface
{
    /**
     * Table markup
     * replacements: rows
     *
     * @type string
     */
    const TABLE = <<<'HTML'
<table cellspacing="1" cellpadding="5" border="0" style="padding: 10px auto;width: 100%%">
%s
</table>

HTML;

    /**
     * Title row. The minimum markup for a single message.
     * replacements: color, level, message
     *
     * @type string
     */
    const TITLE_ROW = <<<'FORMAT'
    <tr style="background: %1$s;font-weight: bold; font-size: 1.25em;">
        <td style="color: #fff;" width="150">%2$s</td>
        <td style="color: #fff;">%3$s</td>
    </tr>

FORMAT;

    /**
     * Table markup
     * replacements: property, value
     *
     * @type string
     */
    const CONTEXT_ROW = <<<'FORMAT'
    <tr style="background: #eee;">
        <td valign="top">%1$s</td>
        <td colspan="2">
            <pre>%2$s</pre>
        </td>
    </tr>

FORMAT;

    /**
     * Log level colors
     *
     * @type array
     */
    protected static $colors = [
        Logger::DEBUG     => '#cccccc',
        Logger::INFO      => '#468847',
        Logger::NOTICE    => '#3a87ad',
        Logger::WARNING   => '#c09853',
        Logger::ERROR     => '#f0ad4e',
        Logger::CRITICAL  => '#FF7708',
        Logger::ALERT     => '#C12A19',
        Logger::EMERGENCY => '#000000'
    ];

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
        $master['message'] = $this->formatMaster($master) . $this->formatRecords($records);

        return $master;
    }

    /**
     * @param string $name
     * @param string $data
     * @return string
     */
    private function addRow($name, $data)
    {
        return sprintf(
            static::CONTEXT_ROW,
            $this->normalize($name),
            $this->normalize($data)
        );
    }

    /**
     * @param array $record
     * @return string
     */
    private function addTitle(array $record)
    {
        return sprintf(
            static::TITLE_ROW,
            static::$colors[$record['level']],
            $this->normalize($record['level_name']),
            $this->normalize($record['message'])
        );
    }

    /**
     * @param array $master
     * @return string
     */
    private function formatMaster(array $master)
    {
        $html = $this->formatRecord($master);
        return sprintf(static::TABLE, $html);
    }

    /**
     * @param array $record
     * @return string
     */
    private function formatRecord(array $record)
    {
        $rows = [
            $this->addTitle($record),
            $this->addRow('time', $this->normalize($record['datetime']))
        ];

        if ($record['context']) {
            $rows[] = $this->addRow('data', $this->normalize($record['context']));
        }

        $html = implode("\n", $rows);

        return $html;
    }

    /**
     * @param array $records
     * @return string
     */
    private function formatRecords(array $records)
    {
        if (count($records) === 0) {
            return '';
        }

        $html = '';
        foreach ($records as $record) {
            $html .= $this->formatRecord($record);
        }

        return sprintf(static::TABLE, $html);
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

        return htmlspecialchars($data, ENT_NOQUOTES, 'UTF-8');
    }
}
