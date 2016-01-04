<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
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
        $this->remoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');
        $this->command = Mockery::mock('QL\Hal\Agent\Remoting\CommandContext');

        $this->command
            ->shouldReceive('withIsInteractive')
            ->andReturn($this->command);

        $this->commitApi = Mockery::mock('Github\Api\Repository\Commits');
        $this->parser = Mockery::mock('Symfony\Component\Yaml\Parser');
    }

    public function testCommandNotSuccessfulReturnsFalse()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'hostname', ['cd "path"', '&&', 'cat .hal9000.push.yml'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
            ->andReturn(false);

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi);
        $success = $action('sshuser', 'hostname', 'path', []);

        $this->assertFalse($success);
    }

    public function testOutputNotParseableReturnsFalse()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'hostname', ['cd "path"', '&&', 'cat .hal9000.push.yml'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
            ->andReturn(true);

        $this->remoter
            ->shouldReceive('getLastOutput')
            ->andReturn('bad-yaml');
        $this->parser
            ->shouldReceive('parse')
            ->with('bad-yaml')
            ->andReturn('bad-yaml');

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi);
        $success = $action('sshuser', 'hostname', 'path', []);

        $this->assertFalse($success);
    }

    public function testSourceNotParseableReturnsDefaultContext()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'hostname', ['cd "path"', '&&', 'cat .hal9000.push.yml'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
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

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi);
        $success = $action('sshuser', 'hostname', 'path', $new);

        $this->assertTrue($success);
    }

    public function testCodeRedeployedMessage()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'hostname', ['cd "path"', '&&', 'cat .hal9000.push.yml'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
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

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi);
        $success = $action('sshuser', 'hostname', 'path', $new);

        $this->assertTrue($success);
    }

    public function testSourceParseableReturnsFullContext()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'hostname', ['cd "path"', '&&', 'cat .hal9000.push.yml'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
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

        $action = new CodeDelta($this->logger, $this->remoter, $this->parser, $this->commitApi);
        $success = $action('sshuser', 'hostname', 'path', $new);

        $this->assertTrue($success);
    }
}
