<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * See https://github.com/mockery/mockery/pull/691
 */
abstract class MockeryTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;


    /**
     * Returns with expectation and spy in an array.
     * When the spy is called, it returns the spied input from the with expectation.
     *
     * @return array
     */
    public function spy()
    {
        $spied = null;

        $with = Mockery::on(function($v) use (&$spied) {
            $spied = $v;
            return true;
        });

        $spy = function() use (&$spied) {
            return $spied;
        };

        return [$with, $spy];
    }
}
