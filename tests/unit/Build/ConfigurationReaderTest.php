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
        $this->parser = new Parser;
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
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any())
            ->once();

        $yamlContents = function() {
            return <<<YAML
testing:
    <<: *invalid_reference
YAML;
        };

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(null, $result);
    }

    public function testBadSystemIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "system" is invalid', Mockery::any())
            ->once();

        $yamlContents = function() {
            return <<<YAML
system: ['bad_array']
YAML;
        };

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(null, $result);
    }

    public function testBadDistIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "dist" is invalid', Mockery::any())
            ->once();

        $yamlContents = function() {
            return <<<YAML
dist: ['bad_array']
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(null, $result);
    }

    public function testBadListIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "post_push" is invalid', Mockery::any())
            ->once();


        $yamlContents = function() {
            return <<<YAML
exclude:
    - 'excluded_dir'
build: []
build_transform: null

pre_push: 'single_cmd'
deploy: null
post_push:
    - ['array_cmd_is_bad']
YAML;
        };

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(null, $result);
    }

    public function testTooManyCommandsIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Too many commands specified for "build". Must be less than 10.', Mockery::any())
            ->once();

        $yamlContents = function() {
            return <<<YAML
exclude:
    - 'excluded_dir'
build: 
    - cmd1
    - cmd2
    - cmd3
    - cmd4
    - cmd5
    - cmd6
    - cmd7
    - cmd8
    - cmd9
    - cmd10
    - cmd11
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);

        $default = [];
        $result = $reader('path', $default);

        $this->assertSame(null, $result);
    }

    public function testBadEnvIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "env" is invalid', Mockery::any())
            ->once();
        $yamlContents = function() {
            return <<<YAML
env: 'not a list'
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);
        $default = [];
        $result = $reader('path', $default);
        $this->assertSame(null, $result);
    }

    public function testBadEnvValuesIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml configuration key "env" is invalid', Mockery::any())
            ->once();
        $yamlContents = function() {
            return <<<YAML
env:
    test: 'not a list'
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);
        $default = [];
        $result = $reader('path', $default);
        $this->assertSame(null, $result);
    }

    public function testBadEnvVarListIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml env var for "test" is invalid', Mockery::any())
            ->once();
        $yamlContents = function () {
            return <<<YAML
env:
    test:
        - 'not associative array'
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);
        $default = [];
        $result = $reader('path', $default);
        $this->assertSame(null, $result);
    }

    public function testBadEnvVarNameIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml env var for "test" is invalid', Mockery::any())
            ->once();
        $yamlContents = function() {
            return <<<YAML
env:
    test:
        DERP: '1234'
        'invalid-env': 5678
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);
        $default = [];
        $result = $reader('path', $default);
        $this->assertSame(null, $result);
    }
    public function testBadEnvVarValueIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal9000.yml env var for "test" is invalid', Mockery::any())
            ->once();
        $yamlContents = function() {
            return <<<YAML
env:
    test:
        DERP1:
            - '1234'
        DERP2: '1234'
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);
        $default = [];
        $result = $reader('path', $default);
        $this->assertSame(null, $result);
    }
    public function testFileIsParsedSuccessfully()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();
        $yamlContents = function() {
            return <<<YAML
system: node8.1.4
dist: 'subdir'
exclude:
    - 'excluded_dir'
env:
    __novalidate:
        C3: '56             '
        MULTLINE_TEST: |
            derp herp
            oklol
    test:
        A1: 12
        B_2: '34'
    prod:
        derp: '1'
        HERP_DERP1: '2'
post_push:
    - 'cmd1'
    - 'cmd2'
YAML;
        };
        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser, $yamlContents);
        $default = [];
        $config = [
            'system' => 'node8.1.4',
            'dist' => 'subdir',
            'env' => [
                '__novalidate' => [
                    'C3' => '56',
                    'MULTLINE_TEST' =>
                        'derp herp
oklol'
                ],
                'test' => [
                    'A1' => '12',
                    'B_2' => '34'
                ],
                'prod' => [
                    'derp' => '1',
                    'HERP_DERP1' => '2'
                ]
            ],
            'exclude' => ['excluded_dir'],
            'build' => [],
            'build_transform' => [],
            'before_deploy' => [],
            'pre_push' => [],
            'deploy' => [],
            'post_push' => ['cmd1', 'cmd2'],
            'after_deploy' => []
        ];
        $result = $reader('path', $default);
        $this->assertSame($config, $result);
    }
}
