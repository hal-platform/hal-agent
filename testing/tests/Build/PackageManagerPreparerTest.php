<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\MemoryLogger;
use Symfony\Component\Filesystem\Exception\IOException;

class PackageManagerPreparerTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = new MemoryLogger;
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

        $preparer = new PackageManagerPreparer($this->logger, $filesystem, 'tokentoken');

        $env = [
            'HOME' => '/home',
            'COMPOSER_HOME' => '/composerhome'
        ];

        $preparer($env);

        $message = $this->logger[0];
        $this->assertSame('warning', $message[0]);
        $this->assertSame('Composer configuration could not be written.', $message[1]);
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

        $message = $this->logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Composer configuration found.', $message[1]);
    }
}
