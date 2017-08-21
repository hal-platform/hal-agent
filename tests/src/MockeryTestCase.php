<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * See https://github.com/mockery/mockery/pull/691
 */
abstract class MockeryTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;
}
