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

    public function testSuccess()
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

        $expected = [
            'build' => $build,
            'buildCommand' => 'derp',
            'buildFile' => 'testdir/hal9000-build-1234.tar.gz',
            'buildPath' => 'testdir/hal9000-build-1234',
            'archiveFile' => 'ARCHIVE_PATH/hal9000-1234.tar.gz',
            'githubUser' => 'user1',
            'githubRepo' => 'repo1',
            'githubReference' => '5555',
            'artifacts' => [
                'testdir/hal9000-build-1234.tar.gz',
                'testdir/hal9000-build-1234'
            ]
        ];

        $expectedEnv = [
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
            'COMPOSER_HOME' => 'testdir/home/',
            'COMPOSER_NO_INTERACTION' => '1',
            'NPM_CONFIG_STRICT_SSL' => 'false',
            'GEM_HOME' => 'testdir/home/gempath/here',
            'GEM_PATH' => 'testdir/home/gempath/here:anotherpath'
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

        $this->assertSame($expectedEnv, $properties['environmentVariables']);

        unset($properties['environmentVariables']);
        $this->assertSame($expected, $properties);
    }
}
