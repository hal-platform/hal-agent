<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * This handler is designed to collate a group of messages into a single log message and send it off.
 *
 * When handling batches, the final message is popped off and used as the parent message.
 */
class ParentLogHandler extends AbstractProcessingHandler
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     * @param integer $level  The minimum logging level at which this handler will be triggered
     * @param Boolean $bubble Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(LoggerInterface $logger, $level = Logger::DEBUG, $bubble = true)
    {
        $this->logger = $logger;

        parent::__construct($level, $bubble);
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records)
    {
        $messages = [];

        foreach ($records as $record) {
            if ($this->isHandling($record)) {
                $messages[] = $this->processRecord($record);
            }
        }

        if (!empty($messages)) {
            $record = array_pop($messages);
            $record['formatted'] = $this->getFormatter()->formatBatch($messages);

            $this->send($record);
        }
    }

    /**
     * Send the formatted log message with child message. Child messages are assumed to be in formatted data.
     *
     * We use the "exceptionData" because it displays nicely in core logger.
     *
     * @param array $record The parent record
     */
    private function send(array $record)
    {
        $level = strtolower($record['level_name']);
        $record['context']['exceptionData'] = $record['formatted'];

        $this->logger->$level($record['message'], $record['context']);
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record)
    {
        $this->send($record);
    }
}
