<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use MCP\DataType\Time\Clock;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Build\Logger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $em;
    public $clock;
    public $resolver;
    public $downloader;
    public $unpacker;
    public $builder;
    public $packer;
    public $downloadProgress;
    public $procesBuilder;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = new Logger;
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->clock = new Clock('now', 'UTC');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Build\Resolver');
        $this->downloader = Mockery::mock('QL\Hal\Agent\Build\Downloader');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Build\Unpacker');
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Builder');
        $this->packer = Mockery::mock('QL\Hal\Agent\Build\Packer');
        $this->downloadProgress = Mockery::mock('QL\Hal\Agent\Helper\DownloadProgressHelper');
        $this->procesBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');

        $this->output = new BufferedOutput;
    }

    public function testBuildResolvingFails()
    {
        $this->input = new ArrayInput([
            'BUILD_ID' => '1'
        ]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturnNull();

        $command = new BuildCommand(
            'cmd',
            false,
            $this->logger,
            $this->em,
            $this->clock,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->procesBuilder
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
Resolving...
Build details could not be resolved.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }
}
