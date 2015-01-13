<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class ConfigurationReaderTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $filesystem;
    public $parser;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $this->parser = Mockery::mock('Symfony\Component\Yaml\Parser');
    }

    public function testFileDoesNotExist()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('path/.hal9000.yml')
            ->andReturn(false);

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(true, $result);
    }

    public function testParseFailureReturnsFalse()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('path/.hal9000.yml')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->with('bad_file')
            ->andThrow('Symfony\Component\Yaml\Exception\ParseException');
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $badClosure = function() {
            return 'bad_file';
        };

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $badClosure);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(false, $result);
    }

    public function testBadEnvironmentIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->andReturn([
                'environment' => ['bad_array']
            ]);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "environment" is invalid', Mockery::any())
            ->once();

        $closure = function() {return 'file';};
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $closure);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(false, $result);
    }

    public function testBadDistIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->andReturn([
                'dist' => ['bad_array']
            ]);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "dist" is invalid', Mockery::any())
            ->once();

        $closure = function() {return 'file';};
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $closure);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(false, $result);
    }

    public function testBadListIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->andReturn([
                'exclude' => ['excluded_dir'],
                'build' => [],
                'build_transform' => null,
                'pre_push' => 'single_cmd',
                'post_push' => [['array_cmd_is_bad']],
            ]);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "post_push" is invalid', Mockery::any())
            ->once();

        $closure = function() {return 'file';};
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $closure);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(false, $result);
    }

    public function testFileIsParsed()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->andReturn([
                'environment' => 'node0.11.5',
                'dist' => 'subdir',
                'exclude' => ['excluded_dir'],
                'build' => [],
                'build_transform' => null,
                'post_push' => ['cmd1', 'cmd2'],
            ]);
        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $closure = function() {return 'file';};
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $closure);

        $default = [];
        $config = [
            'environment' => 'node0.11.5',
            'dist' => 'subdir',
            'exclude' => ['excluded_dir'],
            'build' => [],
            'build_transform' => [],
            'pre_push' => [],
            'post_push' => ['cmd1', 'cmd2'],
        ];

        $result = $reader('path', $default);

        $this->assertSame(true, $result);
        $this->assertSame($config, $default);
    }
}
