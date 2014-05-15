<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Logger;

class HtmlFormatter extends NormalizerFormatter
{
    /**
     * Table markup
     * replacements: rows
     *
     * @var string
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
     * @var string
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
     * @var string
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
     * @var array
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
     * Formats a log record.
     *
     * @param array $record A record to format
     * @param boolean $rowsOnly if true, only rows will be returned, without the table.
     * @return string
     */
    public function format(array $record, $rowsOnly = false)
    {
        $rows = [
            $this->addTitle($record),
            $this->addRow('time', $record['datetime'])
        ];

        foreach ($record['context'] as $key => $data) {
            $rows[] = $this->addRow($key, $data);
        }

        $html = implode("\n", $rows);
        if ($rowsOnly) {
            return $html;
        }

        return sprintf(static::TABLE, $html);
    }

    /**
     * {@inheritdoc}
     * @return string
     */
    public function formatBatch(array $records)
    {
        $html = '';
        foreach ($records as $record) {
            $html .= $this->format($record, true);
        }

        return sprintf(static::TABLE, $html);
    }

    /**
     * @param string $name
     * @param string $data
     * @return string
     */
    protected function addRow($name, $data)
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
    protected function addTitle(array $record)
    {
        return sprintf(
            static::TITLE_ROW,
            static::$colors[$record['level']],
            $this->normalize($record['level_name']),
            $this->normalize($record['message'])
        );
    }

    /**
     * Normalize a value for html.
     *
     * @param mixed $data
     * @return string
     */
    protected function normalize($data)
    {
        $data = parent::normalize($data);
        if (is_array($data)) {
            $data = $this->toJson($data);
        }

        return htmlspecialchars($data, ENT_NOQUOTES, 'UTF-8');
    }
}
