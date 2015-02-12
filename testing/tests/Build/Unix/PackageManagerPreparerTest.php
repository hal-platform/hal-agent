<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Filesystem\Exception\IOException;

class PackageManagerPreparerTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testCorrectFileContentsAreWritten()
    {
        $expectedComposer = <<<'JSON'
{
    "config": {
        "github-oauth" : {
            "github.com": "tokentoken"
        }
    }
}

JSON;

        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem', ['exists' => false]);

        $composer = null;
        $filesystem
            ->shouldReceive('dumpFile')
            ->with('/composerhome/config.json', Mockery::on(function($v) use (&$composer) {
                $composer = $v;
                return true;
            }));

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'composerConfig' => '/composerhome/config.json'
            ])->once();

        $preparer = new PackageManagerPreparer($this->logger, $filesystem, 'tokentoken');

        $env = [
            'HOME' => '/home',
            'COMPOSER_HOME' => '/composerhome'
        ];

        $preparer($env);

        $this->assertSame($expectedComposer, $composer);
    }

    public function testLoggedMessagesWhenDumpFileExplodes()
    {
        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem', ['exists' => false]);

        $filesystem
            ->shouldReceive('dumpFile')
            ->andThrow(new IOException('msg'));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'composerConfig' => '/composerhome/config.json'
            ])->once();

        $preparer = new PackageManagerPreparer($this->logger, $filesystem, 'tokentoken');

        $env = [
            'HOME' => '/home',
            'COMPOSER_HOME' => '/composerhome'
        ];

        $preparer($env);
    }

    public function testLoggedMessagesWhenConfigurationsFound()
    {
        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem', ['exists' => true]);
        $preparer = new PackageManagerPreparer($this->logger, $filesystem, 'tokentoken');

        $env = [
            'HOME' => '/home',
            'COMPOSER_HOME' => '/composerhome'
        ];

        $preparer($env);
    }
}
