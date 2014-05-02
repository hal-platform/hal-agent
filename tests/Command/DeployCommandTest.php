<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use PHPUnit_Framework_TestCase;

class DeployCommandTest extends PHPUnit_Framework_TestCase
{
    public function test()
    {
        $this->markTestSkipped('This test is broken');
        $command = new DeployCommand('bonk');
    }
}
