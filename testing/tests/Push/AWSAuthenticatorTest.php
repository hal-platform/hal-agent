<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Crypto\CryptoException;
use RuntimeException;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\Ec2\Ec2Client;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\S3\S3Client;
use Aws\Sdk;

class AWSAuthenticatorTest extends PHPUnit_Framework_TestCase
{
    private $logger;
    private $di;
    private $decrypter;
    private $credentials;
    private $aws;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->di = Mockery::mock('Symfony\Component\DependencyInjection\ContainerInterface');

        $this->credentials = Mockery::mock('QL\Hal\Core\Entity\Credential\AWSCredential');
        $this->decrypter = Mockery::mock('QL\Hal\Core\Crypto\Decrypter');

        // can't mock :(
        $this->aws = new Sdk(['version' => 'latest']);
    }

    public function testRegionInvalid()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', AWSAuthenticator::ERR_INVALID_REGION, [
                'specified_region' => 'badregion'
            ])
            ->once();

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getEB('badregion', null);

        $this->assertSame(null, $service);
    }

    public function testCredentialsInvalidType()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', AWSAuthenticator::ERR_INVALID_CREDENTIAL)
            ->once();

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getEC2('us-east-1', null);

        $this->assertSame(null, $service);
    }

    public function testCredentialsSecretIsEmpty()
    {
        $this->credentials
            ->shouldReceive('secret')
            ->andReturn('');

        $this->logger
            ->shouldReceive('event')
            ->with('failure', AWSAuthenticator::ERR_INVALID_SECRET)
            ->once();

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getS3('us-east-1', $this->credentials);

        $this->assertSame(null, $service);
    }

    /**
     * @expectedException RuntimeException
     */
    public function testCredentialsDecrypterIsMisconfigured()
    {
        $this->credentials
            ->shouldReceive('secret')
            ->andReturn('derp');
        $this->di
            ->shouldReceive('get')
            ->with('decrypter')
            ->andThrow(new RuntimeException);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', AWSAuthenticator::ERR_MISCONFIGURED_ENCRYPTION)
            ->once();

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getEC2('us-east-1', $this->credentials);

        $this->assertSame(null, $service);
    }

    public function testCredentialsCannotBeDecrypted()
    {
        $this->credentials
            ->shouldReceive('secret')
            ->andReturn('derp');
        $this->di
            ->shouldReceive('get')
            ->with('decrypter')
            ->andReturn($this->decrypter);
        $this->decrypter
            ->shouldReceive('decrypt')
            ->with('derp')
            ->andThrow(new CryptoException);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', AWSAuthenticator::ERR_INVALID_SECRET)
            ->once();

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getEC2('us-east-1', $this->credentials);

        $this->assertSame(null, $service);
    }

    public function testGetCD()
    {
        $this->credentials
            ->shouldReceive([
                'key' => 'key-id',
                'secret' => 'derp'
            ]);

        $this->di
            ->shouldReceive('get')
            ->with('decrypter')
            ->andReturn($this->decrypter);
        $this->decrypter
            ->shouldReceive('decrypt')
            ->with('derp')
            ->andReturn('underp');

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getCD('us-west-1', $this->credentials);

        $this->assertInstanceOf(CodeDeployClient::CLASS, $service);
    }

    public function testGetEB()
    {
        $this->credentials
            ->shouldReceive([
                'key' => 'key-id',
                'secret' => 'derp'
            ]);

        $this->di
            ->shouldReceive('get')
            ->with('decrypter')
            ->andReturn($this->decrypter);
        $this->decrypter
            ->shouldReceive('decrypt')
            ->with('derp')
            ->andReturn('underp');

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getEB('us-east-1', $this->credentials);

        $this->assertInstanceOf(ElasticBeanstalkClient::CLASS, $service);
    }

    public function testGetEC2()
    {
        $this->credentials
            ->shouldReceive([
                'key' => 'key-id',
                'secret' => 'derp'
            ]);

        $this->di
            ->shouldReceive('get')
            ->with('decrypter')
            ->andReturn($this->decrypter);
        $this->decrypter
            ->shouldReceive('decrypt')
            ->with('derp')
            ->andReturn('underp');

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getEC2('us-east-1', $this->credentials);

        $this->assertInstanceOf(Ec2Client::CLASS, $service);
    }

    public function testGetS3()
    {
        $this->credentials
            ->shouldReceive([
                'key' => 'key-id',
                'secret' => 'derp'
            ]);

        $this->di
            ->shouldReceive('get')
            ->with('decrypter')
            ->andReturn($this->decrypter);
        $this->decrypter
            ->shouldReceive('decrypt')
            ->with('derp')
            ->andReturn('underp');

        $authenticator = new AWSAuthenticator($this->logger, $this->di, $this->aws);
        $service = $authenticator->getS3('us-east-1', $this->credentials);

        $this->assertInstanceOf(S3Client::CLASS, $service);
    }
}
