<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;

class BuildEnvironmentResolverTest extends PHPUnit_Framework_TestCase
{
    public function testNoPropertiesReturnedIfWindowsAndUnixDataNotSet()
    {
        $build = $this->createMockBuild();
        $processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $resolver = new BuildEnvironmentResolver($processBuilder);

        $expected = [];
        $actual = $resolver->getBuildProperties($build);

        $this->assertSame($expected, $actual);
    }

    public function testWindowsProperties()
    {
        $build = $this->createMockBuild();

        $processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $resolver = new BuildEnvironmentResolver($processBuilder);
        $resolver->setWindowsBuilder('winuser', 'windowsbox1', '/win/builds');

        $expected = [
            'windows' => [
                'buildUser' => 'winuser',
                'buildServer' => 'windowsbox1',
                'remotePath' => '/win/builds/hal9000-build-1234/',

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

    public function testWindowsPropertiesForPush()
    {
        $build = $this->createMockBuild();

        $push = (new Push)
            ->withId(4321)
            ->withBuild($build);

        $processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $resolver = new BuildEnvironmentResolver($processBuilder);
        $resolver->setWindowsBuilder('winuser', 'windowsbox1', '/win/builds');

        $expected = [
            'windows' => [
                'buildUser' => 'winuser',
                'buildServer' => 'windowsbox1',
                'remotePath' => '/win/builds/hal9000-push-4321/',

                'environmentVariables' => [
                    'HAL_BUILDID' => '1234',
                    'HAL_COMMIT' => '5555',
                    'HAL_GITREF' => 'master',
                    'HAL_ENVIRONMENT' => 'envkey',
                    'HAL_REPO' => 'repokey'
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
