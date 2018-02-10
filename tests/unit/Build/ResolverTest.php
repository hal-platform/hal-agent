<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Build\BuildException;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Utility\BuildEnvironmentResolver;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Repository\BuildRepository;
use Mockery;

class ResolverTest extends MockeryTestCase
{
    public $em;
    public $buildRepo;
    public $encryptedResolver;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->buildRepo
        ]);

        $this->encryptedResolver = Mockery::mock(EncryptedPropertyResolver::class);
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

        $action = new Resolver($this->em, $this->encryptedResolver);

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

        $action = new Resolver($this->em, $this->encryptedResolver);

        $action('1234');
    }

    public function testSuccess()
    {
        $build = $this->createMockBuild();

        $expected = [
            'build' => $build,

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

                'exclude' => [],
                'pre_push' => [],
                'post_push' =>[],
            ],

            'workspace_path' => __DIR__ . '/.temp/hal-build-1234',
            'artifact_stored_file' => '/ARCHIVE_PATH/hal-1234.tar.gz',

            'encrypted' => [
                'encrypted_1' => '1234',
                'encrypted_2' => '5678'
            ]
        ];

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $this->encryptedResolver
            ->shouldReceive('getEncryptedPropertiesWithSources')
            ->andReturn([
                'encrypted' => ['encrypted_1' => '1234', 'encrypted_2' => '5678']
            ]);

        $action = new Resolver($this->em, $this->encryptedResolver);
        $action->setLocalTempPath(__DIR__ . '/.temp');
        $action->setArchivePath('/ARCHIVE_PATH');

        $properties = $action('1234');

        $this->assertSame($expected['build'], $properties['build']);
        $this->assertSame($expected['default_configuration'], $properties['default_configuration']);
        $this->assertSame($expected['workspace_path'], $properties['workspace_path']);
        $this->assertSame($expected['artifact_stored_file'], $properties['artifact_stored_file']);

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
