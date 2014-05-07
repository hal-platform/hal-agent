<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use PHPUnit_Framework_TestCase;
use MCP\DataType\Time\Clock;
use Mockery;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CreatePushCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;
    public $deployRepo;
    public $clock;

    public $input;
    public $output;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository');
        $this->deployRepo = Mockery::mock('QL\Hal\Core\Entity\Repository\DeploymentRepository');
        $this->clock = new Clock('now', 'UTC');

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
            $this->buildRepo,
            $this->deployRepo
        );

        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Build not found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testDeploymentNotFound()
    {
        $build = new Build;
        $build->setStatus('Success');

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
            $this->buildRepo,
            $this->deployRepo
        );

        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Deployment not found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }
}
