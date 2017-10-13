<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Release;
use Hal\Core\Entity\Group;
use Symfony\Component\Process\ProcessBuilder;

class BuildEnvironmentResolverTest extends MockeryTestCase
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
                    'HAL_APP' => 'appkey'
                ]
            ]
        ];

        $actual = $resolver->getBuildProperties($build);

        $this->assertSame($expected, $actual);
    }

    public function testUnixPropertiesForPush()
    {
        $build = $this->createMockBuild();

        $push = (new Release('4321'))
            ->withBuild($build)
            ->withTarget(
                (new Target())
                    ->withParameter('context', 'context')
                    ->withGroup(
                        (new Group())
                            ->withType('script')
                    )
            );

        $processBuilder = Mockery::mock(ProcessBuilder::class);

        $resolver = new BuildEnvironmentResolver($processBuilder);
        $resolver->setUnixBuilder('builduser', 'nixserver', '/var/buildserver');

        $expected = [
            'unix' => [
                'buildUser' => 'builduser',
                'buildServer' => 'nixserver',
                'remoteFile' => '/var/buildserver/hal9000-release-4321.tar.gz',

                'environmentVariables' => [
                    'HAL_BUILDID' => '1234',
                    'HAL_COMMIT' => '5555',
                    'HAL_GITREF' => 'master',
                    'HAL_ENVIRONMENT' => 'envkey',
                    'HAL_APP' => 'appkey',
                    'HAL_PUSHID' => '4321',
                    'HAL_METHOD' => 'script',
                    'HAL_CONTEXT' => 'context'
                ]
            ]
        ];

        $actual = $resolver->getReleaseProperties($push);

        $this->assertSame($expected, $actual);
    }

    private function createMockBuild()
    {
        $app = (new Application)
            ->withId(1234)
            ->withIdentifier('appkey')
            ->withGitHub(new Application\GitHubApplication('user1', 'repo1'));

        $build = (new Build)
            ->withId('1234')
            ->withStatus('pending')
            ->withEnvironment(
                (new Environment)
                    ->withName('envkey')
            )
            ->withApplication($app)
            ->withReference('master')
            ->withCommit('5555');

        return $build;
    }
}
