<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Credential;
use Hal\Core\Entity\Credential\AWSRoleCredential;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Mockery;

class ConfiguratorTest extends MockeryTestCase
{
    public $authenticator;
    public $credential;

    public function setUp()
    {
        $this->authenticator = Mockery::mock(AWSAuthenticator::class);
    }

    public function testSuccess()
    {
        $expected = [
            'region' => 'us-test-1',
            'active_lb' => 'elb.active_lb',
            'passive_lb' => 'elb.passive_lb',
            'ec2_tag' => 'elb.ec2_tag'
        ];

        $this->authenticator->shouldReceive('getELB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);

        $this->authenticator->shouldReceive('getEC2')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);

        $release = $this->createMockRelease();
        $configurator = new Configurator($this->authenticator);

        $actual = $configurator($release);

        $this->assertTrue(isset($actual['sdk']['elb']));
        $this->assertTrue(isset($actual['sdk']['ec2']));
        unset($actual['sdk']);

        $this->assertSame($expected, $actual);
    }

    public function testConfiguratorError()
    {
        $release = $this->createMockRelease();

        $this->authenticator->shouldReceive('getELB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(null);
        $this->authenticator->shouldReceive('getELB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);
        $this->authenticator->shouldReceive('getELB')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(null);

        $this->authenticator->shouldReceive('getEC2')
            ->with('us-test-1', Mockery::any())
            ->once()
            ->andReturn(true);
        $this->authenticator->shouldReceive('getEC2')
            ->with('us-test-1', Mockery::any())
            ->times(2)
            ->andReturn(null);

        $configurator = new Configurator($this->authenticator);

        for ($i = 1; $i <= 3; $i++) {
            $actual = $configurator($release);

            $this->assertSame(null, $actual);
        }
    }

    public function testAuthenticatorFails()
    {
        $release = $this->createMockRelease();
        $release->target()->withCredential(null);

        $configurator = new Configurator($this->authenticator);

        $actual = $configurator($release);

        $this->assertSame(null, $actual);
    }

    private function createMockRelease()
    {
        return (new Release('1234'))
            ->withTarget(
                (new Target)
                    ->withParameter('region', 'us-test-1')
                    ->withParameter('elb.active_lb', 'elb.active_lb')
                    ->withParameter('elb.passive_lb', 'elb.passive_lb')
                    ->withParameter('elb.ec2_tag', 'elb.ec2_tag')
                    ->withCredential(
                        (new Credential)
                            ->withDetails(
                                new AWSRoleCredential
                            )
                    )
            );
    }
}
