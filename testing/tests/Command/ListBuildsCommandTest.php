<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\MCP\Common\Time\TimePoint;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ListBuildsCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;
    public $applicationRepo;
    public $envRepo;

    public $filesystem;
    public $archive;

    public $input;
    public $output;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->applicationRepo = Mockery::mock('Doctrine\ORM\EntityRepository');
        $this->envRepo = Mockery::mock('QL\Hal\Core\Repository\EnvironmentRepository');

        $this->em
            ->shouldReceive('getRepository')
            ->with(Build::CLASS)
            ->andReturn($this->buildRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Application::CLASS)
            ->andReturn($this->applicationRepo);
        $this->em
            ->shouldReceive('getRepository')
            ->with(Environment::CLASS)
            ->andReturn($this->envRepo);


        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $this->archive = 'path';

        $this->output = new BufferedOutput;
    }

    public function testBuildsNotFound()
    {
        $this->buildRepo
            ->shouldReceive('matching')
            ->andReturn([]);

        $this->input = new ArrayInput([]);

        $command = new ListBuildsCommand(
            'derp:cmd',
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $command->run($this->input, $this->output);

        $expected = [
            'No builds found.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testBuildTableOutput()
    {
        $environment = Mockery::mock('QL\Hal\Core\Entity\Environment', ['name' => 'env-name']);
        $application = Mockery::mock('QL\Hal\Core\Entity\Application', ['key' => 'repo-name']);

        $build1 = (new Build)
            ->withApplication($application)
            ->withEnvironment($environment)
            ->withId('1234')
            ->withStatus('Success')
            ->withCreated(new TimePoint(2015, 3, 15, 4, 5, 6, 'UTC'));

        $build2 = (new Build)
            ->withApplication($application)
            ->withEnvironment($environment)
            ->withId('5678')
            ->withStatus('Waiting');

        $this->buildRepo
            ->shouldReceive('matching')
            ->andReturn([$build1, $build2]);

        $this->input = new ArrayInput([]);

        $command = new ListBuildsCommand(
            'derp:cmd',
            $this->em,
            $this->filesystem,
            $this->archive
        );

        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Displaying 1 - 2 out of 2: 
+---------+---------------------+------+------------+-------------+--------------------------+
| Status  | Created Time        | Id   | Repository | Environment | Archive                  |
+---------+---------------------+------+------------+-------------+--------------------------+
| Success | 2015-03-15 00:05:06 | 1234 | repo-name  | env-name    | path/hal9000-1234.tar.gz |
| Waiting |                     | 5678 | repo-name  | env-name    |                          |
+---------+---------------------+------+------------+-------------+--------------------------+

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }
}
