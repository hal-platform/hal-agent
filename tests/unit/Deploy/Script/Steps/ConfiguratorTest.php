<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Script\Steps;

use Hal\Agent\JobExecution;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;

class ConfiguratorTest extends MockeryTestCase
{
    public function testEmptyConfigReturnsNull()
    {
        $execution = new JobExecution(
            'script',
            'deploy',
            []
        );
        $actual = (new Configurator)($execution);

        $this->assertSame(null, $actual);
    }

    public function testSuccess()
    {
        $platform = 'linux';
        $stage = 'deploy';
        $config = [ 'platform' => $platform ];

        $execution = new JobExecution(
            'script',
            $stage,
            $config
        );

        $actual = (new Configurator)($execution);
        $execution = $actual['scriptExecution'];

        $this->assertSame($platform, $actual['platform']);
        $this->assertSame($platform, $execution->platform());
        $this->assertSame($stage, $execution->stage());
        $this->assertSame($config, $execution->config());
    }
}
