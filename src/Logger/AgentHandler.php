<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Formatter\FormatterInterface as MonologFormatterInterface;
use Psr\Log\LoggerInterface;

class AgentHandler implements HandlerInterface
{
    /**
     * @type LoggerInterface[]
     */
    private $loggers;

    /**
     * @type FormatterInterface[]
     */
    private $formatters;

    /**
     * Bullshit we don't need from HandlerInterface
     */
    public function isHandling(array $record) {return true;}
    public function handle(array $record) {}
    public function pushProcessor($callback) {}
    public function popProcessor() {}
    public function setFormatter(MonologFormatterInterface $formatter) {}
    public function getFormatter() {}

    /**
     * Handles a set of records at once.
     *
     * @param array $records The records to handle (an array of record arrays)
     */
    public function handleBatch(array $records)
    {
        if (count($records) < 2) {
            return $this->sendOnlyToMCP($records);
        }

        if (!$this->isMaster(end($records))) {
            return $this->sendOnlyToMCP($records);
        }

        $master = array_pop($records);

        foreach ($this->loggers as $name => $logger) {
            $record = $this->formatters[$name]->format($master, $records);

            $level = strtolower($record['level_name']);
            $logger->$level($record['message'], $record['context']);
        }
    }

    /**
     * @param string $name
     * @param LoggerInterface $logger
     * @param FormatterInterface $formatter
     * @return null
     */
    public function setLogger($name, LoggerInterface $logger, FormatterInterface $formatter)
    {
        $this->loggers[$name] = $logger;
        $this->formatters[$name] = $formatter;
    }

    /**
     * @param Message[] $records
     * @return null
     */
    private function sendOnlyToMCP(array $records)
    {
        if (!array_key_exists('mcp', $this->loggers)) {
            return;
        }

        $master = array_pop($records);
        $record = $this->formatters['mcp']->format($master, $records);

        $level = strtolower($record['level_name']);
        $this->loggers['mcp']->$level($record['message'], $record['context']);
    }

    /**
     * @param array $record
     * @return boolean
     */
    private function isMaster(array $record)
    {
        $context = $record['context'];

        if (array_key_exists('master', $context) && $context['master']) {
            return true;
        }

        return false;
    }
}
