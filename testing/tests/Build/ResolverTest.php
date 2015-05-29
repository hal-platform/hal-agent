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
    public $em;
    public $buildRepo;
    public $envResolver;
    public $encryptedResolver;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager', [
            'getRepository' => $this->buildRepo
        ]);

        $this->envResolver = Mockery::mock('QL\Hal\Agent\Utility\BuildEnvironmentResolver');
        $this->encryptedResolver = Mockery::mock('QL\Hal\Agent\Utility\EncryptedPropertyResolver');
    }
    /**
     * @expectedException QL\Hal\Agent\Build\BuildException
     * @expectedExceptionMessage Build "1234" could not be found!
     */
    public function testBuildNotFound()
    {
        $this->buildRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $action = new Resolver($this->em, $this->envResolver, $this->encryptedResolver);

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

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $action = new Resolver($this->em, $this->envResolver, $this->encryptedResolver);

        $properties = $action('1234');
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
                'tempArchive' => 'testdir/hal9000-build-1234.tar.gz'
            ],
            'github' => [
                'user' => 'user1',
                'repo' => 'repo1',
                'reference' => '5555',
            ],
            'artifacts' => [
                'testdir/hal9000-download-1234.tar.gz',
                'testdir/hal9000-build-1234',
                'testdir/hal9000-build-1234.tar.gz'
            ],
            'encrypted' => []
        ];

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $this->envResolver
            ->shouldReceive('getBuildProperties')
            ->andReturn([]);
        $this->encryptedResolver
            ->shouldReceive('getEncryptedPropertiesWithSources')
            ->andReturn([]);

        $action = new Resolver($this->em, $this->envResolver, $this->encryptedResolver);
        $action->setLocalTempPath('testdir');
        $action->setArchivePath('ARCHIVE_PATH');

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
