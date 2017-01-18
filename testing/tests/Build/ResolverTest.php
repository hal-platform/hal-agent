<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build;

use Doctrine\ORM\EntityManager;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Utility\BuildEnvironmentResolver;
use QL\Hal\Agent\Utility\EncryptedPropertyResolver;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Repository\BuildRepository;

class ResolverTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;
    public $envResolver;
    public $encryptedResolver;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->buildRepo
        ]);

        $this->envResolver = Mockery::mock(BuildEnvironmentResolver::class);
        $this->encryptedResolver = Mockery::mock(EncryptedPropertyResolver::class);
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
        $build->withStatus('Poo');

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
                'deploy' => [],
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
        $app = (new Application)
            ->withGithubOwner('user1')
            ->withGithubRepo('repo1')
            ->withKey('repokey');
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
