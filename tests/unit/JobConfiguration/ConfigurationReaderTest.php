<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\JobConfiguration;

use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Mockery;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Parser;

class ConfigurationReaderTest extends MockeryTestCase
{
    public $logger;
    public $filesystem;
    public $parser;
    public $fixturesPath;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->filesystem = Mockery::mock(Filesystem::class, [
            'exists' => true
        ]);
        $this->parser = new Parser;

        $this->fixturesPath = __DIR__ . '/.fixtures';
    }

    public function testFileLocationsDoNotExist()
    {
        $path = __DIR__;

        $this->filesystem
            ->shouldReceive('exists')
            ->with("${path}/.hal.yml")
            ->andReturn(false)
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->with("${path}/.hal.yaml")
            ->andReturn(false)
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->with("${path}/.hal/config.yml")
            ->andReturn(false)
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->with("${path}/.hal/config.yaml")
            ->andReturn(false)
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->with("${path}/.hal9000.yml")
            ->andReturn(false)
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);

        $default = [];
        $result = $reader($path, $default);

        $this->assertSame($default, $result);
    }

    public function testParseFailureReturnsNull()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml was invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['invalid.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadPlatformIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml configuration key "platform" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['bad_platform.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadDistIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml configuration key "dist" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['bad_dist.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadListIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml configuration key "after_deploy" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['bad_list.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testTooManyCommandsIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Too many commands specified for "build". Must be less than 10.', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['too_many_steps.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadEnvIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml configuration key "env" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['env_invalid.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadEnvValuesIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml configuration key "env" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['env_type_invalid.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadEnvVarListIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml env var for "test" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['env_type_invalid_list.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadEnvVarNameIsFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml env var for "test" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['env_vars_invalid.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testBadEnvVarValueIsFailure()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);
        $this->logger
            ->shouldReceive('event')
            ->with('failure', '.hal.yaml env var for "test" is invalid', Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['env_vars_invalid_value.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame(null, $result);
    }

    public function testFileIsParsedSuccessfully()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $reader = new ConfigurationReader($this->logger, $this->filesystem, $this->parser);
        $reader->setValidConfigurationLocations(['valid.yaml']);

        $default = [];
        $result = $reader($this->fixturesPath, $default);

        $this->assertSame('linux', $result['platform']);
        $this->assertSame('node8.1.4', $result['image']);

        $this->assertSame('/path/to/build', $result['dist']);
        $this->assertSame('./path/to/release', $result['transform_dist']);

        $this->assertSame(['step build 1', 'step build 2', 'step build 3', 'step build 4'], $result['build']);
        $this->assertSame(['step build_transform 1'], $result['build_transform']);
        $this->assertSame(['step before_deploy 1'], $result['before_deploy']);
        $this->assertSame(['step deploy 1', 'step deploy 2'], $result['deploy']);
        $this->assertSame(['step after_deploy 1', 'step after_deploy 2', 'step after_deploy 3'], $result['after_deploy']);


        $this->assertSame(['excluded_dir'], $result['exclude']);
        $this->assertSame(['cp file_a file_b'], $result['pre_push']);
        $this->assertSame(['cp file_1 file_2'], $result['post_push']);

        $this->assertSame([
            '__novalidate' => [
                'C3' => '56',
                'MULTLINE_TEST' =>
                    "derp herp\noklol"
            ],
            'test' => [
                'A1' => '12',
                'B_2' => '34'
            ],
            'prod' => [
                'derp' => '1',
                'HERP_DERP1' => '2'
            ]
        ], $result['env']);
    }
}
