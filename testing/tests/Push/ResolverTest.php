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
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository;
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
        $push->setStatus('Poo');

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
        $deployment = new Deployment;
        $push = new Push;
        $push->setStatus('Waiting');
        $push->setDeployment($deployment);

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
        $environment = new Environment;
        $server = new Server;
        $deployment = new Deployment;
        $repo = new Repository;

        $build = new Build;
        $push = new Push;

        $push->setStatus('Waiting');
        $push->setRepository($repo);
        $push->setDeployment($deployment);
        $push->setBuild($build);

        $server->setType('elasticbeanstalk');
        $deployment->setServer($server);

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
        $repository = new Repository;
        $repository->setGithubUser('user1');
        $repository->setGithubRepo('repo1');
        $repository->setBuildTransformCmd('bin/build-transform');
        $repository->setPrePushCmd('bin/pre');
        $repository->setPostPushCmd('bin/post');
        $repository->setKey('repokey');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('b2.5tnbBn8');
        $build->setBranch('master');
        $build->setCommit('5555');
        $build->setRepository($repository);
        $build->setEnvironment($environment);

        $server = new Server;
        $server->setName('127.0.0.1');
        $server->setType('rsync');

        $deployment = new Deployment;
        $deployment->setPath('/herp/derp');
        $deployment->setServer($server);

        $push = new Push;
        $push->setId('1234');
        $push->setStatus('Waiting');
        $push->setBuild($build);
        $push->setDeployment($deployment);
        $push->setRepository($repository);

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
        $repository = new Repository;
        $repository->setGithubUser('user1');
        $repository->setGithubRepo('repo1');
        $repository->setBuildTransformCmd('bin/build-transform');
        $repository->setPrePushCmd('bin/pre');
        $repository->setPostPushCmd('bin/post');
        $repository->setKey('repokey');
        $repository->setEbName('eb_name');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('b9.1234');
        $build->setBranch('master');
        $build->setCommit('5555');
        $build->setRepository($repository);
        $build->setEnvironment($environment);

        $server = new Server;
        $server->setType('elasticbeanstalk');

        $deployment = new Deployment;
        $deployment->setServer($server);
        $deployment->setEbEnvironment('e-ididid');

        $push = new Push;
        $push->setId('1234');
        $push->setStatus('Waiting');
        $push->setBuild($build);
        $push->setDeployment($deployment);
        $push->setRepository($repository);

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
        $repository = new Repository;
        $repository->setGithubUser('user1');
        $repository->setGithubRepo('repo1');
        $repository->setBuildTransformCmd('bin/build-transform');
        $repository->setPrePushCmd('bin/pre');
        $repository->setPostPushCmd('bin/post');
        $repository->setKey('repokey');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('8956');
        $build->setBranch('master');
        $build->setCommit('5555');
        $build->setRepository($repository);
        $build->setEnvironment($environment);

        $server = new Server;
        $server->setType('ec2');

        $deployment = new Deployment;
        $deployment->setServer($server);
        $deployment->setEc2Pool('pool_name');
        $deployment->setPath('/ec2/path/var/www');

        $push = new Push;
        $push->setId('1234');
        $push->setStatus('Waiting');
        $push->setBuild($build);
        $push->setDeployment($deployment);
        $push->setRepository($repository);

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
