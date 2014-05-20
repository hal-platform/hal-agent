<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\MemoryLogger;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Repository;

class ResolverTest extends PHPUnit_Framework_TestCase
{
    public function testBuildNotFound()
    {
        $logger = new MemoryLogger;
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => null
        ]);

        $action = new Resolver($logger, $repo, 'ENV_PATH', 'ARCHIVE_PATH');

        $properties = $action('1234');
        $this->assertNull($properties);

        $message = $logger[0];
        $this->assertSame('error', $message[0]);
        $this->assertSame('Build "1234" could not be found!', $message[1]);
    }

    public function testBuildNotCorrectStatus()
    {
        $build = new Build;
        $build->setStatus('Poo');

        $logger = new MemoryLogger;
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => $build
        ]);

        $action = new Resolver($logger, $repo, 'ENV_PATH', 'ARCHIVE_PATH');

        $properties = $action('1234');
        $this->assertNull($properties);

        $message = $logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Found build: 1234', $message[1]);

        $message = $logger[1];
        $this->assertSame('error', $message[0]);
        $this->assertSame('Build "1234" has a status of "Poo"! It cannot be rebuilt.', $message[1]);
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
            'PATH' => 'ENV_PATH',
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
            'NPM_CONFIG_STRICT_SSL' => 'false'
        ];

        $logger = new MemoryLogger;
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository', [
            'find' => $build
        ]);

        $action = new Resolver($logger, $repo, 'ENV_PATH', 'ARCHIVE_PATH');
        $action->setBaseBuildDirectory('testdir');

        $properties = $action('1234');
        $this->assertSame($expectedEnv, $properties['environmentVariables']);

        unset($properties['environmentVariables']);
        $this->assertSame($expected, $properties);
    }
}
