<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use MCP\DataType\Time\Clock;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Server;

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
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->repo = Mockery::mock('QL\Hal\Core\Repository\PushRepository', [
            'find' => null,
            'findBy' => []
        ]);

        $this->em = Mockery::mock('Doctrine\ORM\EntityManager', [
            'getRepository' => $this->repo
        ]);

        $this->clock = new Clock('now', 'UTC');
        $this->envResolver = Mockery::mock('QL\Hal\Agent\Utility\BuildEnvironmentResolver');
        $this->encryptedResolver = Mockery::mock('QL\Hal\Agent\Utility\EncryptedPropertyResolver', [
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

    /**
     * @expectedException QL\Hal\Agent\Push\PushException
     * @expectedExceptionMessage Cannot deploy to EB. AWS has not been configured.
     */
    public function testElasticBeanstalkSanityCheckFails()
    {
        $push = (new Push)
            ->withStatus('Waiting')
            ->withApplication(new Application)
            ->withBuild(new Build)
            ->withDeployment(
                (new Deployment)
                    ->withServer(
                        (new Server)
                            ->withType('elasticbeanstalk')
                    )
            );

        $clock = new Clock('2015-03-15 12:00:00', 'UTC');

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
                'post_push' => [
                    'bin/post'
                ]
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/2015-02/hal9000-b2.5tnbBn8.tar.gz',
                'legacy_archive' => 'ARCHIVE_PATH/hal9000/hal9000-b2.5tnbBn8.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempZipArchive' => 'testdir/hal9000-push-1234.zip'
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-push-1234.zip',
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
            ->withKey('repokey')
            ->withGithubOwner('user1')
            ->withGithubRepo('repo1')
            ->withEbName('eb_name');

        $app->setBuildTransformCmd('bin/build-transform');
        $app->setPrePushCmd('bin/pre');
        $app->setPostPushCmd('bin/post');

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
                    ->withEbEnvironment('e-ididid')
                    ->withServer(
                        (new Server)
                            ->withType('elasticbeanstalk')
                    )
            );

        $expected = [
            'method' => 'elasticbeanstalk',

            'elasticbeanstalk' => [
                'application' => 'eb_name',
                'environment' => 'e-ididid'
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
                'post_push' => [
                    'bin/post'
                ]
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-b9.1234.tar.gz',
                'legacy_archive' => 'ARCHIVE_PATH/hal9000/hal9000-b9.1234.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempZipArchive' => 'testdir/hal9000-push-1234.zip'
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-push-1234.zip',
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
        $action->setAwsCredentials('key', 'secret');

        $properties = $action('1234');

        $this->assertSame($expected['method'], $properties['method']);
        $this->assertSame($expected['configuration'], $properties['configuration']);
        $this->assertSame($expected['pushProperties'], $properties['pushProperties']);
        $this->assertSame($expected['location'], $properties['location']);
        $this->assertSame($expected['artifacts'], $properties['artifacts']);
        $this->assertSame($expected['elasticbeanstalk'], $properties['elasticbeanstalk']);
    }

    public function testEc2Success()
    {
        $app = (new Application)
            ->withKey('repokey')
            ->withGithubOwner('user1')
            ->withGithubRepo('repo1')
            ->withEbName('eb_name');

        $app->setBuildTransformCmd('bin/build-transform');
        $app->setPrePushCmd('bin/pre');
        $app->setPostPushCmd('bin/post');

        $push = (new Push)
            ->withId('1234')
            ->withStatus('Waiting')
            ->withApplication($app)
            ->withBuild(
                (new Build)
                    ->withId('8956')
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
                    ->withEc2Pool('pool_name')
                    ->withPath('/ec2/path/var/www')
                    ->withServer(
                        (new Server)
                            ->withType('ec2')
                    )
            );

        $expected = [
            'method' => 'ec2',

            'ec2' => [
                'pool' => 'pool_name',
                'remotePath' => '/ec2/path/var/www'
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
                'post_push' => [
                    'bin/post'
                ]
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-8956.tar.gz',
                'legacy_archive' => 'ARCHIVE_PATH/hal9000/hal9000-8956.tar.gz',
                'tempArchive' => 'testdir/hal9000-push-1234.tar.gz',
                'tempZipArchive' => 'testdir/hal9000-push-1234.zip'
            ],

            'artifacts' => [
                'testdir/hal9000-push-1234.tar.gz',
                'testdir/hal9000-push-1234.zip',
                'testdir/hal9000-push-1234'
            ],

            'pushProperties' => [
                'id' => '8956',
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
        $action->setAwsCredentials('key', 'secret');

        $properties = $action('1234');

        $this->assertSame($expected['method'], $properties['method']);
        $this->assertSame($expected['configuration'], $properties['configuration']);
        $this->assertSame($expected['pushProperties'], $properties['pushProperties']);
        $this->assertSame($expected['location'], $properties['location']);
        $this->assertSame($expected['artifacts'], $properties['artifacts']);
        $this->assertSame($expected['ec2'], $properties['ec2']);
    }
}
