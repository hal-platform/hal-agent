<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;

class CodeDeltaTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $remoter;
    public $commitApi;
    public $parser;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->remoter = Mockery::mock('QL\Hal\Agent\RemoteProcess', ['__invoke' => false]);
        $this->commitApi = Mockery::mock('Github\Api\Repository\Commits');
        $this->parser = Mockery::mock('Symfony\Component\Yaml\Parser');
    }

    public function testCommandNotSuccessfulReturnsFalse()
    {
        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi, 'sshuser');
        $success = $action('hostname', 'path', []);

        $this->assertFalse($success);
    }

    public function testOutputNotParseableReturnsFalse()
    {
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->andReturn('bad-yaml');
        $this->parser
            ->shouldReceive('parse')
            ->with('bad-yaml')
            ->andReturn('bad-yaml');

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi, 'sshuser');
        $success = $action('hostname', 'path', []);

        $this->assertFalse($success);
    }

    public function testSourceNotParseableReturnsDefaultContext()
    {
        $this->remoter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->andReturn('test-output');

        $this->logger
            ->shouldReceive('event')
            ->with('success', CodeDelta::EVENT_MESSAGE, [
                'user' => 'testuser',
                'time' => '2014-10-15',
                'status' => 'Code change found.',
                'gitCommit' => 'test1'
            ])->once();

        $old = [
            'user' => 'testuser',
            'date' => '2014-10-15',

            'commit' => 'test1',
            'reference' => 'test1',
            'source' => 'bad-data'
        ];

        $new = [
            'commit' => ''
        ];

        $this->parser
            ->shouldReceive('parse')
            ->with('test-output')
            ->andReturn($old);

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi, 'sshuser');
        $success = $action('hostname', 'path', $new);

        $this->assertTrue($success);
    }

    public function testCodeRedeployedMessage()
    {
        $this->remoter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->andReturn('test-output');

        $this->logger
            ->shouldReceive('event')
            ->with('success', CodeDelta::EVENT_MESSAGE, [
                'user' => 'testuser',
                'time' => '2014-10-15',
                'status' => 'No change. Code was redeployed.'
            ])->once();

        $old = [
            'user' => 'testuser',
            'date' => '2014-10-15',

            'commit' => 'test_hash1',
            'reference' => 'test2',
            'source' => 'http://github.com/orgname/reponame'
        ];

        $new = [
            'commit' => 'test_hash1'
        ];

        $this->parser
            ->shouldReceive('parse')
            ->with('test-output')
            ->andReturn($old);

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi, 'sshuser');
        $success = $action('hostname', 'path', $new);

        $this->assertTrue($success);
    }

    public function testSourceParseableReturnsFullContext()
    {
        $this->remoter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->andReturn('test-output');

        $this->commitApi
            ->shouldReceive('compare')
            ->with('orgname', 'reponame', 'test_hash1', 'test_hash2')
            ->andReturn([
                'status' => 'behind',
                'permalink_url' => 'http://some/url',
                'behind_by' => '15',
                'ahead_by' => ''
            ]);

        $this->logger
            ->shouldReceive('event')
            ->with('success', CodeDelta::EVENT_MESSAGE, [
                'user' => 'testuser',
                'time' => '2014-10-15',
                'status' => 'Code change found.',
                'gitCommit' => 'test_hash1',
                'gitReference' => 'test2',
                'githubComparisonURL' => 'http://some/url',
                'commitStatus' => [
                    'status' => 'behind',
                    'behind_by' => '15'
                ]
            ])->once();

        $old = [
            'user' => 'testuser',
            'date' => '2014-10-15',

            'commit' => 'test_hash1',
            'reference' => 'test2',
            'source' => 'http://github.com/orgname/reponame'
        ];

        $new = [
            'commit' => 'test_hash2'
        ];

        $this->parser
            ->shouldReceive('parse')
            ->with('test-output')
            ->andReturn($old);

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi, 'sshuser');
        $success = $action('hostname', 'path', $new);

        $this->assertTrue($success);
    }
}
