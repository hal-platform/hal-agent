<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Hal\Core\Repository\ReleaseRepository;
use Mockery;

class ResolverTest extends MockeryTestCase
{
    public $em;
    public $releaseRepo;
    public $encryptedResolver;

    public function setUp()
    {
        $this->releaseRepo = Mockery::mock(ReleaseRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->releaseRepo
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

    public function testReleaseNotFound()
    {
        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('Release "5678" could not be found!');

        $this->releaseRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $resolver = new Resolver($this->em, $this->encryptedResolver);

        $resolver('5678');
    }

    public function testReleaseNotCorrectStatus()
    {
        $this->expectException(DeployException::class);
        $this->expectExceptionMessage('Release "5678" has a status of "failure"! It cannot be redeployed.');

        $release = new Release;
        $release->withStatus('failure');

        $this->releaseRepo
            ->shouldReceive('find')
            ->andReturn($release);

        $resolver = new Resolver($this->em, $this->encryptedResolver);

        $resolver('5678');
    }

    public function testSuccess()
    {
        $release = $this->createMockRelease();

        $expected = [
            'job' => $release,
            'platform' => '',

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

            'workspace_path' => __DIR__ . '/.temp/hal-release-5678',
            'artifact_stored_file' => '/ARCHIVE_PATH/hal-1234.tar.gz',

            'encrypted' => [
                'encrypted_1' => '1234',
                'encrypted_2' => '5678'
            ]
        ];

        $this->releaseRepo
            ->shouldReceive('find')
            ->andReturn($release);

        $this->encryptedResolver
            ->shouldReceive('getEncryptedPropertiesWithSources')
            ->andReturn([
                'encrypted' => ['encrypted_1' => '1234', 'encrypted_2' => '5678']
            ]);

        $resolver = new Resolver($this->em, $this->encryptedResolver);
        $resolver->setLocalTempPath(__DIR__ . '/.temp');
        $resolver->setArchivePath('/ARCHIVE_PATH');

        $properties = $resolver('1234');

        $this->assertSame($expected['job'], $properties['job']);
        $this->assertSame($expected['default_configuration'], $properties['default_configuration']);
        $this->assertSame($expected['workspace_path'], $properties['workspace_path']);
        $this->assertSame($expected['artifact_stored_file'], $properties['artifact_stored_file']);

        $this->assertSame($expected['encrypted'], $properties['encrypted']);
    }

    private function createMockRelease()
    {
        $build = (new Build('1234'))
            ->withStatus('pending')
            ->withReference('master')
            ->withCommit('5555');

        return (new Release('5678'))
            ->withBuild($build)
            ->withApplication(new Application)
            ->withTarget(new Target)
            ->withEnvironment(
                (new Environment)
                    ->withName('staging')
            );
    }
}
