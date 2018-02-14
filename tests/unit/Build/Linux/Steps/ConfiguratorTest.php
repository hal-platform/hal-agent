<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux\Steps;

use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use PHPUnit\Framework\TestCase;

class ConfiguratorTest extends TestCase
{
    public function testSuccess()
    {
        $build = $this->createMockBuild();

        $expected = [
            'builder_connection' => 'build_user@builder.example.com',
            'remote_file' => '/tmp/builds/hal-job-1234.tgz',
            'environment_variables' => [
                'HAL_JOB_ID' => '1234',
                'HAL_JOB_TYPE' => 'build',

                'HAL_VCS_COMMIT' => '7de49f3',
                'HAL_VCS_REF' => 'master',

                'HAL_ENVIRONMENT' => 'staging',
                'HAL_APPLICATION' => 'Demo App',

                'HAL_CONTEXT' => ''
            ]
        ];

        $configurator = new Configurator(
            '/tmp/builds',
            'build_user',
            [
                'builder.example.com'
            ]
        );

        $actual = $configurator($build);

        $this->assertSame($expected, $actual);
    }

    private function createMockBuild()
    {
        return (new Build('1234'))
            ->withStatus('pending')
            ->withReference('master')
            ->withCommit('7de49f3')

            ->withApplication(
                (new Application)
                    ->withName('Demo App')
            )
            ->withEnvironment(
                (new Environment)
                    ->withName('staging')
            );
    }
}
