<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Repository;

class ResolverTest extends PHPUnit_Framework_TestCase
{
    /**
     * @expectedException QL\Hal\Agent\Build\BuildException
     * @expectedExceptionMessage Build "1234" could not be found!
     */
    public function testBuildNotFound()
    {
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => null
        ]);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $action = new Resolver($repo, $builder, 'ENV_PATH', 'ARCHIVE_PATH');

        $properties = $action('1234');
    }

    /**
     * @expectedException QL\Hal\Agent\Build\BuildException
     * @expectedExceptionMessage Build "1234" has a status of "Poo"! It cannot be rebuilt.
     */
    public function testBuildNotCorrectStatus()
    {
        $build = new Build;
        $build->setStatus('Poo');

        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => $build
        ]);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $action = new Resolver($repo, $builder, 'ENV_PATH', 'ARCHIVE_PATH');

        $properties = $action('1234');
    }

    public function testWindowsSettingsResolvedWhenSet()
    {
        $build = $this->createMockBuild();

        $expected = [
            'buildUser' => 'buildinguser',
            'buildServer' => 'windowsserver',
            'remotePath' => '$HOME/builds/hal9000-build-1234',
            'environmentVariables' => [
                'HAL_BUILDID' => '1234',
                'HAL_COMMIT' => '5555',
                'HAL_GITREF' => 'master',
                'HAL_ENVIRONMENT' => 'envkey',
                'HAL_REPO' => 'repokey'
            ]
        ];

        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => $build
        ]);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'testdir/home/gempath/here:anotherpath',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]', ['getProcess' => $process]);

        $action = new Resolver($repo, $builder, 'ENV_PATH', 'ARCHIVE_PATH');
        $action->setBaseBuildDirectory('testdir');
        $action->setWindowsBuilder('buildinguser', 'windowsserver');

        $properties = $action('1234');

        $this->assertSame($expected, $properties['windows']);
    }

    public function testUnixSettingsResolved()
    {
        $build = $this->createMockBuild();

        $expected = [
            'environmentVariables' => [
                'HOME' => 'testdir/home/',
                'PATH' => 'testdir/home/gempath/here/bin:ENV_PATH',
                'HAL_BUILDID' => '1234',
                'HAL_COMMIT' => '5555',
                'HAL_GITREF' => 'master',
                'HAL_ENVIRONMENT' => 'envkey',
                'HAL_REPO' => 'repokey',

                // package manager configuration
                'BOWER_INTERACTIVE' => 'false',
                'BOWER_STRICT_SSL' => 'false',
                'COMPOSER_HOME' => 'testdir/home/.composer',
                'COMPOSER_NO_INTERACTION' => '1',
                'NPM_CONFIG_STRICT_SSL' => 'false',
                'NPM_CONFIG_COLOR' => 'always',
                'GEM_HOME' => 'testdir/home/gempath/here',
                'GEM_PATH' => 'testdir/home/gempath/here:anotherpath'
            ]
        ];

        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => $build
        ]);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'testdir/home/gempath/here:anotherpath',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]', ['getProcess' => $process]);

        $action = new Resolver($repo, $builder, 'ENV_PATH', 'ARCHIVE_PATH');
        $action->setBaseBuildDirectory('testdir');

        $properties = $action('1234');

        $this->assertSame($expected, $properties['unix']);
    }

    public function testSuccess()
    {
        $build = $this->createMockBuild();

        $expected = [
            'build' => $build,

            'configuration' => [
                'system' => 'unix',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],

                'build' => [
                    'derp'
                ],
                'build_transform' => [],
                'pre_push' => [],
                'post_push' => []
            ],

            'location' => [
                'download' => 'testdir/hal9000-download-1234.tar.gz',
                'path' => 'testdir/hal9000-build-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-1234.tar.gz'
            ],
            'github' => [
                'user' => 'user1',
                'repo' => 'repo1',
                'reference' => '5555',
            ],
            'artifacts' => [
                'testdir/hal9000-download-1234.tar.gz',
                'testdir/hal9000-build-1234',
                'testdir/hal9000-1234.tar.gz'
            ]
        ];

        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => $build
        ]);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'testdir/home/gempath/here:anotherpath',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]', ['getProcess' => $process]);

        $action = new Resolver($repo, $builder, 'ENV_PATH', 'ARCHIVE_PATH');
        $action->setBaseBuildDirectory('testdir');

        $properties = $action('1234');

        $this->assertSame($expected['configuration'], $properties['configuration']);
        $this->assertSame($expected['location'], $properties['location']);
        $this->assertSame($expected['artifacts'], $properties['artifacts']);
        $this->assertSame($expected['github'], $properties['github']);
    }

    private function createMockBuild()
    {
        $environment = new Environment;
        $environment->setKey('envkey');

        $repository = new Repository;
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
