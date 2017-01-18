<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push;

use Doctrine\ORM\EntityManager;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\BuildEnvironmentResolver;
use QL\Hal\Agent\Utility\EncryptedPropertyResolver;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Credential;
use QL\Hal\Core\Entity\Credential\AWSCredential;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Server;
use QL\Hal\Core\Repository\PushRepository;
use QL\MCP\Common\Time\Clock;

class ResolverTest extends PHPUnit_Framework_TestCase
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
        $this->repo = Mockery::mock(PushRepository::class, [
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

    /**
     * @expectedException QL\Hal\Agent\Push\PushException
     * @expectedExceptionMessage Push "1234" could not be found!
     */
    public function testPushNotFound()
    {
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

    /**
     * @expectedException QL\Hal\Agent\Push\PushException
     * @expectedExceptionMessage Push "1234" has a status of "Poo"! It cannot be redeployed.
     */
    public function testPushNotCorrectStatus()
    {
        $push = new Push;
        $push->withStatus('Poo');

        $this->repo
            ->shouldReceive('find')
            ->andReturn($push);

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

    /**
     * @expectedException QL\Hal\Agent\Push\PushException
     * @expectedExceptionMessage Push "1234" is trying to clobber a running push! It cannot be deployed at this time.
     */
    public function testPushFindsActiveDeployment()
    {
        $push = (new Push)
            ->withStatus('Waiting')
            ->withDeployment(new Deployment);

        $this->repo
            ->shouldReceive('find')
            ->andReturn($push);
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
            ->withKey('repokey')
            ->withGithubOwner('user1')
            ->withGithubRepo('repo1');

        $app->setBuildTransformCmd('bin/build-transform');
        $app->setPrePushCmd('bin/pre');
        $app->setPostPushCmd('bin/post');

        $push = (new Push)
            ->withId('1234')
            ->withStatus('Waiting')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b2.5tnbBn8')
                    ->withBranch('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withDeployment(
                (new Deployment)
                    ->withPath('/herp/derp')
                    ->withServer(
                        (new Server)
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
                    'HAL_PATH' => '/herp/derp',
                    'HAL_BUILDID' => 'b2.5tnbBn8',
                    'HAL_COMMIT' => '5555',
                    'HAL_GITREF' => 'master',
                    'HAL_ENVIRONMENT' => 'envname',
                    'HAL_REPO' => 'repokey'
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
                'build_transform' => [
                    'bin/build-transform'
                ],
                'pre_push' => [
                    'bin/pre'
                ],
                'deploy' => [],
                'post_push' => [
                    'bin/post'
                ]
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/2015-02/hal9000-b2.5tnbBn8.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempZipArchive' => 'testdir/hal9000-eb-1234.zip',
                'tempTarArchive' => 'testdir/hal9000-s3-1234.tar.gz'
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-eb-1234.zip',
                'testdir/hal9000-s3-1234.tar.gz',
                'testdir/hal9000-push-1234'
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
            ->shouldReceive('getPushProperties')
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
            ->withKey('repokey')
            ->withGithubOwner('user1')
            ->withGithubRepo('repo1');

        $app->setBuildTransformCmd('bin/build-transform');
        $app->setPrePushCmd('bin/pre');
        $app->setPostPushCmd('bin/post');

        $aws = new AWSCredential('key', 'encrypted');

        $push = (new Push)
            ->withId('1234')
            ->withStatus('Waiting')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b9.1234')
                    ->withBranch('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withDeployment(
                (new Deployment)
                    ->withEbName('eb_name')
                    ->withEbEnvironment('e-ididid')
                    ->withS3Bucket('eb_bucket')
                    ->withServer(
                        (new Server)
                            ->withName('aws-region')
                            ->withType('eb')
                    )
                    ->withCredential(
                        (new Credential)
                            ->withAWS($aws)
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
            ],
            'configuration' => [
                'system' => 'unix',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],
                'build' => [],
                'build_transform' => [
                    'bin/build-transform'
                ],
                'pre_push' => [
                    'bin/pre'
                ],
                'deploy' => [],
                'post_push' => [
                    'bin/post'
                ]
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-b9.1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempZipArchive' => 'testdir/hal9000-eb-1234.zip',
                'tempTarArchive' => 'testdir/hal9000-s3-1234.tar.gz',
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-eb-1234.zip',
                'testdir/hal9000-s3-1234.tar.gz',
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
            ->shouldReceive('getPushProperties')
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
        $this->assertSame($expected['eb'], $properties['eb']);
    }

    public function testCodeDeploySuccess()
    {
        $app = (new Application)
            ->withId('repo-id')
            ->withKey('repokey')
            ->withGithubOwner('user1')
            ->withGithubRepo('repo1');

        $app->setBuildTransformCmd('bin/build-transform');
        $app->setPrePushCmd('bin/pre');
        $app->setPostPushCmd('bin/post');

        $aws = new AWSCredential('key', 'encrypted');

        $push = (new Push)
            ->withId('1234')
            ->withStatus('Waiting')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b9.1234')
                    ->withBranch('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withDeployment(
                (new Deployment)
                    ->withCDName('cd_name')
                    ->withCDGroup('cd_group')
                    ->withCDConfiguration('cd_config')
                    ->withS3Bucket('cd_bucket')
                    ->withServer(
                        (new Server)
                            ->withName('aws-region')
                            ->withType('cd')
                    )
                    ->withCredential(
                        (new Credential)
                            ->withAWS($aws)
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
            ],
            'configuration' => [
                'system' => 'unix',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],
                'build' => [],
                'build_transform' => [
                    'bin/build-transform'
                ],
                'pre_push' => [
                    'bin/pre'
                ],
                'deploy' => [],
                'post_push' => [
                    'bin/post'
                ]
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-b9.1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempZipArchive' => 'testdir/hal9000-eb-1234.zip',
                'tempTarArchive' => 'testdir/hal9000-s3-1234.tar.gz',
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-eb-1234.zip',
                'testdir/hal9000-s3-1234.tar.gz',
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
            ->shouldReceive('getPushProperties')
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
            ->withKey('repokey')
            ->withGithubOwner('user1')
            ->withGithubRepo('repo1');

        $push = (new Push)
            ->withId('1234')
            ->withStatus('Waiting')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('b9.1234')
                    ->withBranch('master')
                    ->withCommit('5555')
                    ->withApplication($app)
                    ->withEnvironment(
                        (new Environment)
                            ->withName('envname')
                    )
            )
            ->withDeployment(
                (new Deployment)
                    ->withScriptContext('testdata')
                    ->withServer(
                        (new Server)
                            ->withType('script')
                    )
            );

        $expected = [
            'method' => 'script',

            'script' => [
                'environmentVariables' => [
                    'HAL_CONTEXT' => 'testdata',
                    'HAL_BUILDID' => 'b9.1234',
                    'HAL_COMMIT' => '5555',
                    'HAL_GITREF' => 'master',
                    'HAL_ENVIRONMENT' => 'envname',
                    'HAL_REPO' => 'repokey'
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
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-b9.1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempZipArchive' => 'testdir/hal9000-eb-1234.zip',
                'tempTarArchive' => 'testdir/hal9000-s3-1234.tar.gz',
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-eb-1234.zip',
                'testdir/hal9000-s3-1234.tar.gz',
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
            ->shouldReceive('getPushProperties')
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
