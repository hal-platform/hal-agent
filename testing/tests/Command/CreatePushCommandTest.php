<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command;

use PHPUnit_Framework_TestCase;
use MCP\DataType\Time\Clock;
use Mockery;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\User;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CreatePushCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;
    public $deployRepo;
    public $pushRepo;
    public $userRepo;
    public $clock;
    public $unique;

    public $input;
    public $output;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->deployRepo = Mockery::mock('QL\Hal\Core\Repository\DeploymentRepository');
        $this->pushRepo = Mockery::mock('QL\Hal\Core\Repository\PushRepository');
        $this->userRepo = Mockery::mock('QL\Hal\Core\Repository\UserRepository');

        $this->em
            ->shouldReceive('getRepository')
            ->with(Build::CLASS)
            ->andReturn($this->buildRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Deployment::CLASS)
            ->andReturn($this->deployRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Push::CLASS)
            ->andReturn($this->pushRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(User::CLASS)
            ->andReturn($this->userRepo);

        $this->clock = new Clock('now', 'UTC');
        $this->unique = Mockery::mock('QL\Hal\Core\JobIdGenerator');

        $this->output = new BufferedOutput;
    }

    public function testBuildNotFound()
    {
        $this->buildRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $this->input = new ArrayInput([
            'BUILD_ID' => '1',
            'DEPLOYMENT_ID' => '2'
        ]);

        $command = new CreatePushCommand(
            'derp:cmd',
            $this->em,
            $this->clock,
            $this->unique
        );

        $command->run($this->input, $this->output);

        $expected = [
            'Build not found.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testDeploymentNotFound()
    {
        $build = new Build;
        $build->withStatus('Success');

        $this->buildRepo
            ->shouldReceive('find')
            ->andReturn($build);

        $this->deployRepo
            ->shouldReceive('find')
            ->andReturnNull();

        $this->input = new ArrayInput([
            'BUILD_ID' => '1',
            'DEPLOYMENT_ID' => '2'
        ]);

        $command = new CreatePushCommand(
            'derp:cmd',
            $this->em,
            $this->clock,
            $this->unique
        );

        $command->run($this->input, $this->output);

        $expected = [
            'Deployment not found.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }
}
