<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

trait LineCheckerTrait
{
    public function assertContainsLines(array $expected, $actual)
    {
        foreach ($expected as $line) {
            $this->assertContains($line, $actual);
        }
    }
}
