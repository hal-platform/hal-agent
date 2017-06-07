<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class ConfigurationReaderTest extends MockeryTestCase
{
    public $logger;
    public $filesystem;
    public $parser;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->parser = Mockery::mock(Parser::class);
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

        $this->assertSame([], $result);
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
            ->andThrow(ParseException::class);
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

        $this->assertSame(null, $result);
    }

    public function testBadSystemIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->andReturn([
                'system' => ['bad_array']
            ]);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "system" is invalid', Mockery::any())
            ->once();

        $closure = function() {return 'file';};
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $closure);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(null, $result);
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

        $this->assertSame(null, $result);
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
                'deploy' => null,
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

        $this->assertSame(null, $result);
    }

    public function testTooManyCommandsIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->andReturn([
                'exclude' => ['excluded_dir'],
                'build' => [
                    'cmd1',
                    'cmd2',
                    'cmd3',
                    'cmd4',
                    'cmd5',
                    'cmd6',
                    'cmd7',
                    'cmd8',
                    'cmd9',
                    'cmd10',
                    'cmd11',
                ],
            ]);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Too many commands specified for "build". Must be less than 10.', Mockery::any())
            ->once();

        $closure = function() {return 'file';};
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $closure);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(null, $result);
    }

    public function testFileIsParsed()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->parser
            ->shouldReceive('parse')
            ->andReturn([
                'system' => 'node0.11.5',
                'dist' => 'subdir',
                'exclude' => ['excluded_dir'],
                'build' => [],
                'build_transform' => null,
                'deploy' => null,
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
            'system' => 'node0.11.5',
            'dist' => 'subdir',
            'exclude' => ['excluded_dir'],
            'build' => [],
            'build_transform' => [],
            'pre_push' => [],
            'deploy' => [],
            'post_push' => ['cmd1', 'cmd2'],
        ];

        $result = $reader('path', $default);

        $this->assertSame($config, $result);
    }
}
