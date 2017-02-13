<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use Symfony\Component\Process\ProcessBuilder;

class BuildEnvironmentResolverTest extends PHPUnit_Framework_TestCase
{
    public function testNoPropertiesReturnedIfWindowsAndUnixDataNotSet()
    {
        $build = $this->createMockBuild();
        $processBuilder = Mockery::mock(ProcessBuilder::class);

        $resolver = new BuildEnvironmentResolver($processBuilder);

        $expected = [];
        $actual = $resolver->getBuildProperties($build);

        $this->assertSame($expected, $actual);
    }

    public function testUnixProperties()
    {
        $build = $this->createMockBuild();

        $processBuilder = Mockery::mock(ProcessBuilder::class);

        $resolver = new BuildEnvironmentResolver($processBuilder);
        $resolver->setUnixBuilder('builduser', 'nixserver', '/var/buildserver');

        $expected = [
            'unix' => [
                'buildUser' => 'builduser',
                'buildServer' => 'nixserver',
                'remoteFile' => '/var/buildserver/hal9000-build-1234.tar.gz',

                'environmentVariables' => [
                    'HAL_BUILDID' => '1234',
                    'HAL_COMMIT' => '5555',
                    'HAL_GITREF' => 'master',
                    'HAL_ENVIRONMENT' => 'envkey',
                    'HAL_REPO' => 'repokey'
                ]
            ]
        ];

        $actual = $resolver->getBuildProperties($build);

        $this->assertSame($expected, $actual);
    }

    public function testUnixPropertiesForPush()
    {
        $build = $this->createMockBuild();

        $push = (new Push)
            ->withId('4321')
            ->withBuild($build);

        $processBuilder = Mockery::mock(ProcessBuilder::class);

        $resolver = new BuildEnvironmentResolver($processBuilder);
        $resolver->setUnixBuilder('builduser', 'nixserver', '/var/buildserver');

        $expected = [
            'unix' => [
                'buildUser' => 'builduser',
                'buildServer' => 'nixserver',
                'remoteFile' => '/var/buildserver/hal9000-push-4321.tar.gz',

                'environmentVariables' => [
                    'HAL_BUILDID' => '1234',
                    'HAL_COMMIT' => '5555',
                    'HAL_GITREF' => 'master',
                    'HAL_ENVIRONMENT' => 'envkey',
                    'HAL_REPO' => 'repokey',
                    'HAL_PUSHID' => '4321'
                ]
            ]
        ];

        $actual = $resolver->getPushProperties($push);

        $this->assertSame($expected, $actual);
    }

    private function createMockBuild()
    {
        $app = (new Application)
            ->withId(1234)
            ->withKey('repokey')
            ->withGithubOwner('user1')
            ->withGithubOwner('repo1');
        $app->setBuildCmd('derp');

        $build = (new Build)
            ->withId('1234')
            ->withStatus('Waiting')
            ->withEnvironment(
                (new Environment)
                    ->withName('envkey')
            )
            ->withApplication($app)
            ->withBranch('master')
            ->withCommit('5555');

        return $build;
    }
}
