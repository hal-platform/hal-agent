<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Doctrine\ORM\EntityManager;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Utility\BuildEnvironmentResolver;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Environment;
use Hal\Core\Repository\BuildRepository;

class ResolverTest extends MockeryTestCase
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

    public function tearDown()
    {
        $tempBuildDir = 'testdir';

        if (is_dir($tempBuildDir)) {
            rmdir($tempBuildDir);
        }
    }

    /**
     * @expectedException Hal\Agent\Build\BuildException
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
     * @expectedException Hal\Agent\Build\BuildException
     * @expectedExceptionMessage Build "1234" has a status of "removed"! It cannot be rebuilt.
     */
    public function testBuildNotCorrectStatus()
    {
        $build = new Build;
        $build->withStatus('removed');

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
                'exclude' => [],
                'build' => [],
                'build_transform' => [],
                'pre_push' => [],
                'deploy' => [],
                'post_push' =>[]
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
            ->withGitHub(new Application\GitHubApplication('user1', 'repo1'))
            ->withIdentifier('repokey');

        $build = (new Build)
            ->withId('1234')
            ->withStatus('Pending')
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
