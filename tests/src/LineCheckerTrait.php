<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

trait LineCheckerTrait
{
    public function assertContainsLines($expected, $actual)
    {
        if (!is_array($expected)) {
            $expected = explode("\n", $expected);
        }
        foreach ($expected as $line) {
            if (strlen($line) === 0) {
                continue;
            }

            $this->assertContains(trim($line), $actual);
        }
    }
}
