<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Repository;

class BuildEnvironmentResolverTest extends PHPUnit_Framework_TestCase
{
    public function testNoPropertiesReturnedIfWindowsAndUnixDataNotSet()
    {
        $build = $this->createMockBuild();
        $processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $resolver = new BuildEnvironmentResolver($processBuilder);

        $expected = [];
        $actual = $resolver->getProperties($build);

        $this->assertSame($expected, $actual);
    }

    public function testUnixPropertiesDoesStuffRubyStuff()
    {
        $build = $this->createMockBuild();

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'testdir/home/gempath/here:anotherpath',
            'isSuccessful' => true
        ])->makePartial();

        $processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]', ['getProcess' => $process]);

        $resolver = new BuildEnvironmentResolver($processBuilder);
        $resolver->setUnixBuilder('/homedir/', 'global/path:usr/bin', '/unix/builds');

        $expected = [
            'unix' => [
                'environmentVariables' => [
                    'HOME' => '/homedir.1234/',
                    'PATH' => 'global/path:usr/bin',

                    'HAL_BUILDID' => '1234',
                    'HAL_COMMIT' => '5555',
                    'HAL_GITREF' => 'master',
                    'HAL_ENVIRONMENT' => 'envkey',
                    'HAL_REPO' => 'repokey',

                    'BOWER_INTERACTIVE' => 'false',
                    'BOWER_STRICT_SSL' => 'false',

                    'COMPOSER_HOME' => '/homedir.1234/.composer',
                    'COMPOSER_NO_INTERACTION' => '1',

                    'NPM_CONFIG_STRICT_SSL' => 'false',
                    'NPM_CONFIG_COLOR' => 'always'
                ]
            ]
        ];

        $actual = $resolver->getProperties($build);

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

        $actual = $resolver->getProperties($build);

        $this->assertSame($expected, $actual);
    }

    private function createMockBuild()
    {
        $environment = new Environment;
        $environment->setKey('envkey');

        $repository = new Repository;
        $repository->setId(1234);
        $repository->setGithubUser('user1');
        $repository->setGithubRepo('repo1');
        $repository->setBuildCmd('derp');
        $repository->setKey('repokey');

        $build = new Build;
        $build->setId('1234');
        $build->setStatus('Waiting');
        $build->setEnvironment($environment);
        $build->setRepository($repository);
        $build->setBranch('master');
        $build->setCommit('5555');

        return $build;
    }
}
