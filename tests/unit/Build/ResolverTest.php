<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Repository\BuildRepository;
use Mockery;
use Symfony\Component\Filesystem\Filesystem;

class ResolverTest extends MockeryTestCase
{
    public $em;
    public $buildRepo;
    public $encryptedResolver;
    public $filesystem;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->buildRepo
        ]);

        $this->encryptedResolver = Mockery::mock(EncryptedPropertyResolver::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
    }

    public function tearDown()
    {
        $tempBuildDir = __DIR__ . '/.temp';

        if (is_dir($tempBuildDir)) {
            rmdir($tempBuildDir);
        }
    }

    public function testBuildNotFound()
    {
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('Build "1234" could not be found!');

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $action = new Resolver($this->em, $this->encryptedResolver, $this->filesystem, '/tmp/1234');

        $action('1234');
    }

    public function testBuildNotCorrectStatus()
    {
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('Build "1234" has a status of "removed"! It cannot be rebuilt.');

        $build = new Build;
        $build->withStatus('removed');

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $action = new Resolver($this->em, $this->encryptedResolver, $this->filesystem, '/tmp/1234');

        $action('1234');
    }

    public function testSuccess()
    {
        $build = $this->createMockBuild();

        $expected = [
            'job' => $build,

            'default_configuration' => [
                'platform' => 'linux',
                'image' => '',
                'dist' => '.',
                'transform_dist' => '.',

                'env' => [],

                'build' => [],

                'build_transform' => [],
                'before_deploy' => [],
                'deploy' => [],

                'after_deploy' => [],

                'rsync_exclude' => [],
                'rsync_before' => [],
                'rsync_after' =>[],
            ],

            'workspace_path' => '/tmp/1234/hal-build-1234',

            'encrypted' => [
                'encrypted_1' => '1234',
                'encrypted_2' => '5678'
            ]
        ];

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/tmp/1234')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('touch')
            ->with('/tmp/1234/.hal-agent')
            ->once();

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $this->encryptedResolver
            ->shouldReceive('getEncryptedPropertiesWithSources')
            ->andReturn([
                'encrypted' => ['encrypted_1' => '1234', 'encrypted_2' => '5678'],
                'sources' => ['encrypted_1' => 'from test']
            ]);

        $action = new Resolver($this->em, $this->encryptedResolver, $this->filesystem, '/tmp/1234');

        $properties = $action('1234');

        $this->assertSame($expected['job'], $properties['job']);
        $this->assertSame($expected['default_configuration'], $properties['default_configuration']);
        $this->assertSame($expected['workspace_path'], $properties['workspace_path']);

        $this->assertSame($expected['encrypted'], $properties['encrypted']);
    }

    private function createMockBuild()
    {
        return (new Build('1234'))
            ->withStatus('pending')
            ->withReference('master')
            ->withCommit('5555')

            ->withApplication(new Application)
            ->withEnvironment(
                (new Environment)
                    ->withName('staging')
            );
    }
}
