<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Helper;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * A custom PSR-3 logger that lets us send a shit ton of messages to the logger
 * and collect them at the end of a command run.
 */
class MemoryLogger extends AbstractLogger
{
    const MESSAGE_WITH_NO_BREAKS = <<<'TEXT'
%s: %s

TEXT;
    const MESSAGE_WITH_BREAKS = <<<'TEXT'
%s:
%s

TEXT;

    /**
     *            level,  message, context
     * @var array(string $level, string $message,  array $context)[]
     */
    private $messages = [];

    /**
     * @var LoggerInterface
     */
    private $bufferedLogger;

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = [])
    {
        $this->messages[] = [$level, $message, $context];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return $this->messages;
    }

    /**
     * @param boolean $showContext
     * @return string
     */
    public function output($showContext = false)
    {
        $output = '';
        foreach ($this->messages as $message) {
            $output .= $this->format($message, $showContext);
        }

        return $output;
    }

    /**
     * Collate all buffered messages and send to a logger.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function send($level, $message, array $context = [])
    {
        // silently fail if no logger or messages
        if (!$this->bufferedLogger || !$this->messages) {
            return;
        }

        $context = array_merge($context, ['messages' => $this->output(true)]);
        $this->bufferedLogger->$level($message, $context);
    }

    /**
     * @param LoggerInterface $logger
     * @return null
     */
    public function setBufferedLogger(LoggerInterface $logger)
    {
        $this->bufferedLogger = $logger;
    }

    /**
     * @param array $message
     * @param boolean $showContext
     * @return string
     */
    private function format(array $message, $showContext)
    {
        list($level, $subject, $context) = $message;

        $formatted = str_pad(sprintf('[%s]', strtoupper($level)), 10) . ' ';
        $formatted .= $subject . "\n";

        if ($context && $showContext) {
            $formatted .= $this->formatContext($context);
        }

        return $formatted;
    }

    /**
     * @param array $context
     * @return string
     */
    private function formatContext(array $context)
    {
        $rendered = '';
        foreach ($context as $property => $value) {
            // array
            if (is_array($value)) {
                $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
                $rendered .= sprintf(self::MESSAGE_WITH_BREAKS, $property, json_encode($value, $flags));

            // text with line breaks
            } elseif (is_string($value) && strpos($value, "\n") !== false) {
                $rendered .= sprintf(self::MESSAGE_WITH_BREAKS, $property, $value);

            // everything else
            } else {
                $rendered .= sprintf(self::MESSAGE_WITH_NO_BREAKS, $property, var_export($value, true));
            }
        }

        return $rendered . "\n";
    }
}
