<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

use Doctrine\ORM\EntityManager;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\BuildEnvironmentResolver;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Release;
use Hal\Core\Entity\Group;
use Hal\Core\Repository\ReleaseRepository;
use QL\MCP\Common\Time\Clock;

class ResolverTest extends MockeryTestCase
{
    public $logger;
    public $em;
    public $repo;
    public $clock;
    public $envResolver;
    public $encryptedResolver;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->repo = Mockery::mock(ReleaseRepository::class, [
            'find' => null,
            'findBy' => []
        ]);

        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->repo
        ]);

        $this->clock = new Clock('now', 'UTC');
        $this->envResolver = Mockery::mock(BuildEnvironmentResolver::class);
        $this->encryptedResolver = Mockery::mock(EncryptedPropertyResolver::class, [
            'getEncryptedPropertiesWithSources' => []
        ]);
    }

    public function testPushNotFound()
    {
        $this->expectException('Hal\Agent\Push\PushException');
        $this->expectExceptionMessage('Release "1234" could not be found!');

        $action = new Resolver(
            $this->logger,
            $this->em,
            $this->clock,
            $this->envResolver,
            $this->encryptedResolver,
            'sshuser',
            'http://git'
        );

        $properties = $action('1234');
    }

    public function testReleaseNotCorrectStatus()
    {
        $this->expectException('Hal\Agent\Push\PushException');
        $this->expectExceptionMessage('Release "1234" has a status of "failure"! It cannot be redeployed.');

        $release = new Release;
        $release->withStatus('failure');

        $this->repo
            ->shouldReceive('find')
            ->andReturn($release);

        $action = new Resolver(
            $this->logger,
            $this->em,
            $this->clock,
            $this->envResolver,
            $this->encryptedResolver,
            'sshuser',
            'http://git'
        );

        $properties = $action('1234');
    }

    public function testReleaseFindsActiveDeployment()
    {
        $this->expectException('Hal\Agent\Push\PushException');
        $this->expectExceptionMessage('Release "1234" is trying to clobber a running release! It cannot be deployed at this time.');

        $release = (new Release)
            ->withStatus('pending')
            ->withTarget(new Target());

        $this->repo
            ->shouldReceive('find')
            ->andReturn($release);
        $this->repo
            ->shouldReceive('findBy')
            ->andReturn(['derp']);

        $action = new Resolver(
            $this->logger,
            $this->em,
            $this->clock,
            $this->envResolver,
            $this->encryptedResolver,
            'sshuser',
            'http://git'
        );

        $properties = $action('1234');
    }

    public function testRsyncSuccess()
    {
        $app = (new Application)
            ->withIdentifier('repokey')
            ->withGithub(new Application\GitHubApplication('user1', 'repo1'));

        $push = (new Release())
            ->withId('1234')
            ->withStatus('pending')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b2.5tnbBn8')
                    ->withReference('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withTarget(
                (new Target())
                    ->withParameter(Target::PARAM_PATH, '/herp/derp')
                    ->withGroup(
                        (new Group())
                            ->withType('rsync')
                            ->withName('127.0.0.1')
                    )
            );

        $expected = [
            'method' => 'rsync',

            'rsync' => [
                'remoteUser' => 'sshuser',
                'remoteServer' => '127.0.0.1',
                'remotePath' => '/herp/derp',
                'syncPath' => 'sshuser@127.0.0.1:/herp/derp',

                'environmentVariables' => [
                    'HAL_HOSTNAME' => '127.0.0.1',
                    'HAL_PATH' => '/herp/derp'
                ]
            ],
            'configuration' => [
                'system' => 'unix',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],
                'build' => [],
                'build_transform' => [],
                'pre_push' => [],
                'deploy' => [],
                'post_push' => []
            ],

            'location' => [
                'path' => 'testdir/hal9000-release-1234',
                'archive' => 'ARCHIVE_PATH/2015-02/hal9000-b2.5tnbBn8.tar.gz',
                'tempArchive' => 'testdir/hal9000-release-1234.tar.gz',
                'tempUploadArchive' => 'testdir/hal9000-aws-1234'
            ],

            'artifacts' => [
                'testdir/hal9000-release-1234.tar.gz',
                'testdir/hal9000-aws-1234',
                'testdir/hal9000-release-1234'
            ],

            'pushProperties' => [
                'id' => 'b2.5tnbBn8',
                'source' => 'http://git/user1/repo1',
                'env' => 'envname',
                'user' => null,
                'reference' => 'master',
                'commit' => '5555',
                'date' => '2015-03-15T08:00:00-04:00'
            ]
        ];

        $clock = new Clock('2015-03-15 12:00:00', 'UTC');
        $this->envResolver
            ->shouldReceive('getReleaseProperties')
            ->andReturn([]);
        $this->repo
            ->shouldReceive('find')
            ->andReturn($push);
        $this->repo
            ->shouldReceive('findBy')
            ->andReturn([]);

        $action = new Resolver(
            $this->logger,
            $this->em,
            $clock,
            $this->envResolver,
            $this->encryptedResolver,
            'sshuser',
            'http://git'
        );
        $action->setLocalTempPath('testdir');
        $action->setArchivePath('ARCHIVE_PATH');

        $properties = $action('1234');

        $this->assertSame($expected['method'], $properties['method']);
        $this->assertSame($expected['configuration'], $properties['configuration']);
        $this->assertSame($expected['pushProperties'], $properties['pushProperties']);
        $this->assertSame($expected['location'], $properties['location']);
        $this->assertSame($expected['artifacts'], $properties['artifacts']);
        $this->assertSame($expected['rsync'], $properties['rsync']);
    }

    public function testElasticBeanstalkSuccess()
    {
        $app = (new Application)
            ->withId('repo-id')
            ->withIdentifier('repokey')
            ->withGitHub(new Application\GitHubApplication('user1', 'repo1'));

        $aws = new Credential\AWSStaticCredential('key', 'encrypted');

        $release = (new Release())
            ->withId('1234')
            ->withStatus('pending')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b9.1234')
                    ->withReference('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withTarget(
                (new Target())
                    ->withParameter(Target::PARAM_APP, 'eb_name')
                    ->withParameter(Target::PARAM_ENV, 'e-ididid')
                    ->withParameter(Target::PARAM_BUCKET, 'eb_bucket')
                    ->withGroup(
                        (new Group())
                            ->withName('aws-region')
                            ->withType('eb')
                    )
                    ->withCredential(
                        (new Credential)
                            ->withDetails($aws)
                    )
            );

        $expected = [
            'method' => 'eb',

            'eb' => [
                'region' => 'aws-region',
                'credential' => $aws,
                'application' => 'eb_name',
                'environment' => 'e-ididid',

                'bucket' => 'eb_bucket',
                'file' => 'repo-id/1234.zip',
                'src' => '.'
            ],
            'configuration' => [
                'system' => 'unix',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],
                'build' => [],
                'build_transform' => [],
                'pre_push' => [],
                'deploy' => [],
                'post_push' => []
            ],

            'location' => [
                'path' => 'testdir/hal9000-release-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-b9.1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-release-1234.tar.gz',
                'tempUploadArchive' => 'testdir/hal9000-aws-1234',
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-aws-1234',
                'testdir/hal9000-push-1234'
            ],

            'pushProperties' => [
                'id' => 'b9.1234',
                'source' => 'http://git/user1/repo1',
                'env' => 'envname',
                'user' => null,
                'reference' => 'master',
                'commit' => '5555',
                'date' => '2015-03-15T08:00:00-04:00'
            ]
        ];

        $clock = new Clock('2015-03-15 12:00:00', 'UTC');
        $this->envResolver
            ->shouldReceive('getReleaseProperties')
            ->andReturn([]);
        $this->repo
            ->shouldReceive('find')
            ->andReturn($release);
        $this->repo
            ->shouldReceive('findBy')
            ->andReturn([]);

        $action = new Resolver(
            $this->logger,
            $this->em,
            $clock,
            $this->envResolver,
            $this->encryptedResolver,
            'sshuser',
            'http://git'
        );
        $action->setLocalTempPath('testdir');
        $action->setArchivePath('ARCHIVE_PATH');

        $properties = $action('1234');

        $this->assertSame($expected['method'], $properties['method']);
        $this->assertSame($expected['configuration'], $properties['configuration']);
        $this->assertSame($expected['pushProperties'], $properties['pushProperties']);
        $this->assertSame($expected['location'], $properties['location']);
        $this->assertSame($expected['artifacts'], $properties['artifacts']);
        $this->assertSame($expected['eb'], $properties['eb']);
    }

    public function testCodeDeploySuccess()
    {
        $app = (new Application)
            ->withId('repo-id')
            ->withIdentifier('repokey')
            ->withGitHub(new Application\GitHubApplication('user1', 'repo1'));


        $aws = new Credential\AWSStaticCredential('key', 'encrypted');

        $push = (new Release())
            ->withId('1234')
            ->withStatus('Waiting')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b9.1234')
                    ->withReference('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withTarget(
                (new Target())
                    ->withParameter(Target::PARAM_APP, 'cd_name')
                    ->withParameter(Target::PARAM_GROUP, 'cd_group')
                    ->withParameter(Target::PARAM_CONFIG, 'cd_config')
                    ->withParameter(Target::PARAM_BUCKET, 'cd_bucket')
                    ->withGroup(
                        (new Group())
                            ->withName('aws-region')
                            ->withType('cd')
                    )
                    ->withCredential(
                        (new Credential)
                            ->withDetails($aws)
                    )
            );

        $expected = [
            'method' => 'cd',

            'cd' => [
                'region' => 'aws-region',
                'credential' => $aws,
                'application' => 'cd_name',
                'group' => 'cd_group',
                'configuration' => 'cd_config',

                'bucket' => 'cd_bucket',
                'file' => 'repo-id/1234.tar.gz',
                'src' => '.'
            ],
            'configuration' => [
                'system' => 'unix',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],
                'build' => [],
                'build_transform' => [],
                'pre_push' => [],
                'deploy' => [],
                'post_push' => []
            ],

            'location' => [
                'path' => 'testdir/hal9000-release-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-b9.1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-release-1234.tar.gz',
                'tempUploadArchive' => 'testdir/hal9000-aws-1234',
            ],

            'artifacts' => [
                'testdir/hal9000-release-1234.tar.gz',
                'testdir/hal9000-aws-1234',
                'testdir/hal9000-push-1234'
            ],

            'pushProperties' => [
                'id' => 'b9.1234',
                'source' => 'http://git/user1/repo1',
                'env' => 'envname',
                'user' => null,
                'reference' => 'master',
                'commit' => '5555',
                'date' => '2015-03-15T08:00:00-04:00'
            ]
        ];

        $clock = new Clock('2015-03-15 12:00:00', 'UTC');
        $this->envResolver
            ->shouldReceive('getReleaseProperties')
            ->andReturn([]);
        $this->repo
            ->shouldReceive('find')
            ->andReturn($push);
        $this->repo
            ->shouldReceive('findBy')
            ->andReturn([]);

        $action = new Resolver(
            $this->logger,
            $this->em,
            $clock,
            $this->envResolver,
            $this->encryptedResolver,
            'sshuser',
            'http://git'
        );
        $action->setLocalTempPath('testdir');
        $action->setArchivePath('ARCHIVE_PATH');

        $properties = $action('1234');

        $this->assertSame($expected['method'], $properties['method']);
        $this->assertSame($expected['configuration'], $properties['configuration']);
        $this->assertSame($expected['pushProperties'], $properties['pushProperties']);
        $this->assertSame($expected['location'], $properties['location']);
        $this->assertSame($expected['artifacts'], $properties['artifacts']);
        $this->assertSame($expected['cd'], $properties['cd']);
    }


    public function testScriptSuccess()
    {
        $app = (new Application)
            ->withId('repo-id')
            ->withIdentifier('repokey')
            ->withGitHub(new Application\GitHubApplication('user1', 'repo1'));

        $push = (new Release())
            ->withId('1234')
            ->withStatus('Waiting')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b9.1234')
                    ->withReference('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withTarget(
                (new Target())
                    ->withParameter(Target::PARAM_CONTEXT, 'testdata')
                    ->withGroup(
                        (new Group())
                            ->withType('script')
                    )
            );

        $expected = [
            'method' => 'script',

            'script' => [],
            'configuration' => [
                'system' => 'unix',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],
                'build' => [],
                'build_transform' => [],
                'pre_push' => [],
                'deploy' => [],
                'post_push' => []
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-b9.1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempUploadArchive' => 'testdir/hal9000-aws-1234'
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-aws-1234',
                'testdir/hal9000-push-1234'
            ],

            'pushProperties' => [
                'id' => 'b9.1234',
                'source' => 'http://git/user1/repo1',
                'env' => 'envname',
                'user' => null,
                'reference' => 'master',
                'commit' => '5555',
                'date' => '2015-03-15T08:00:00-04:00'
            ]
        ];

        $clock = new Clock('2015-03-15 12:00:00', 'UTC');
        $this->envResolver
            ->shouldReceive('getReleaseProperties')
            ->andReturn([]);
        $this->repo
            ->shouldReceive('find')
            ->andReturn($push);
        $this->repo
            ->shouldReceive('findBy')
            ->andReturn([]);

        $action = new Resolver(
            $this->logger,
            $this->em,
            $clock,
            $this->envResolver,
            $this->encryptedResolver,
            'sshuser',
            'http://git'
        );
        $action->setLocalTempPath('testdir');
        $action->setArchivePath('ARCHIVE_PATH');

        $properties = $action('1234');

        $this->assertSame($expected['method'], $properties['method']);
        $this->assertSame($expected['configuration'], $properties['configuration']);
        $this->assertSame($expected['pushProperties'], $properties['pushProperties']);
        $this->assertSame($expected['location'], $properties['location']);
        $this->assertSame($expected['artifacts'], $properties['artifacts']);
        $this->assertSame($expected['script'], $properties['script']);
    }
}
