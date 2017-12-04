<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Because the default console helpers should be traits.
 *
 * @see https://github.com/symfony/Console/blob/master/Helper/FormatterHelper.php
 */
trait FormatterTrait
{
    /**
     * Formats a message within a section.
     *
     * @param string $section The section name
     * @param string $message The message
     * @param string $style The style to apply to the section
     *
     * @return string The format section
     */
    public function formatSection($section, $message, $style = 'info')
    {
        return sprintf('<%s>[%s]</%s> %s', $style, $section, $style, $message);
    }

    /**
     * Formats a message as a block of text.
     *
     * @param string|array $messages The message to write in the block
     * @param string $style The style to apply to the whole block
     * @param Boolean $large Whether to return a large block
     *
     * @return string The formatter message
     */
    public function formatBlock($messages, $style, $large = false)
    {
        if (!is_array($messages)) {
            $messages = array($messages);
        }

        $len = 0;
        $lines = array();
        foreach ($messages as $message) {
            $message = OutputFormatter::escape($message);
            $lines[] = sprintf($large ? '  %s  ' : ' %s ', $message);
            $len = max(strlen($message) + ($large ? 4 : 2), $len);
        }

        $messages = $large ? array(str_repeat(' ', $len)) : array();
        for ($i = 0; isset($lines[$i]); ++$i) {
            $messages[] = $lines[$i].str_repeat(' ', $len - strlen($lines[$i]));
        }
        if ($large) {
            $messages[] = str_repeat(' ', $len);
        }

        for ($i = 0; isset($messages[$i]); ++$i) {
            $messages[$i] = sprintf('<%s>%s</%s>', $style, $messages[$i], $style);
        }

        return implode("\n", $messages);
    }

    /**
     * Truncates a message to the given length.
     *
     * @param string $message
     * @param int $length
     * @param string $suffix
     *
     * @return string
     */
    public function truncate($message, $length, $suffix = '...')
    {
        $computedLength = $length - strlen($suffix);

        if ($computedLength > strlen($message)) {
            return $message;
        }

        if (false === $encoding = mb_detect_encoding($message, null, true)) {
            return substr($message, 0, $length).$suffix;
        }

        return mb_substr($message, 0, $length, $encoding).$suffix;
    }
}
