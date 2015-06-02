<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\EventLog;
use QL\Hal\Agent\Testing\JsonableStub;
use QL\Hal\Agent\Testing\StringableStub;
use stdClass;

class EventFactoryTest extends PHPUnit_Framework_TestCase
{
    public $em;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
    }

    public function testDefaultStageIsUnknown()
    {
        $spy = null;
        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $factory = new EventFactory($this->em);
        $factory->info();

        $this->assertInstanceOf(EventLog::CLASS, $spy);

        $this->assertSame('info', $spy->status());
        $this->assertSame('unknown', $spy->event());
        $this->assertSame(1, $spy->order());
    }

    public function testOrderIsRecorded()
    {
        $spies = [];

        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$spies) {
                $spies[] = $v;
                return true;
            }));

        $factory = new EventFactory($this->em);
        $factory->info();
        $factory->failure();
        $factory->success();

        $this->assertSame(1, $spies[0]->order());
        $this->assertSame(2, $spies[1]->order());
        $this->assertSame(3, $spies[2]->order());
    }

    public function testBuildIsAttached()
    {
        $spy = null;

        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $build = Mockery::mock(Build::CLASS);

        $factory = new EventFactory($this->em);
        $factory->setBuild($build);
        $factory->setStage('build.end');
        $factory->success();

        $this->assertSame($build, $spy->build());
        $this->assertSame('build.end', $spy->event());
    }

    public function testPushIsAttached()
    {
        $spy = null;

        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $push = Mockery::mock(Push::CLASS);

        $factory = new EventFactory($this->em);
        $factory->setPush($push);
        $factory->setStage('push.end');
        $factory->success();

        $this->assertSame($push, $spy->push());
        $this->assertSame('push.end', $spy->event());
    }

    public function testFullLogCreated()
    {
        $spy = null;

        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $factory = new EventFactory($this->em);

        $jsonable = new JsonableStub;
        $stringable = new StringableStub;

        $jsonable->data = ['json' => 'data'];
        $stringable->output = 'test1234';

        $factory->success('testing message', [
            'data' => 'testing',
            'bad_object' => new stdClass,
            'json' => $jsonable,
            'stringable' => $stringable

        ]);

        $expectedContext = [
            'Data' => 'testing',
            'Json' => ['json'=> 'data'],
            'Stringable' => 'test1234'
        ];

        $this->assertSame('testing message', $spy->message());
        $this->assertSame($expectedContext, $spy->data());
    }

    public function testSerializedLogSentToRedisWithoutData()
    {
        $predis = Mockery::mock('Predis\Client');
        $build = Mockery::mock(Build::CLASS, [
            'id' => 'b2.1234'
        ]);

        $this->em
            ->shouldReceive('persist')
            ->once();

        $spy = null;
        $predis
            ->shouldReceive('expire')
            ->once();
        $predis
            ->shouldReceive('lpush')
            ->with('event-logs:b2.1234', Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $factory = new EventFactory($this->em);
        $factory->setRedisHandler($predis);
        $factory->setBuild($build);

        $jsonable = new JsonableStub;
        $stringable = new StringableStub;

        $jsonable->data = ['json' => 'data'];
        $stringable->output = 'test1234';

        $factory->success('testing message', [
            'data' => 'testing',
            'bad_object' => new stdClass,
            'json' => $jsonable,
            'stringable' => $stringable
        ]);

        $expected = [
            'id' => null,
            'created' => null,
            'event' => 'unknown',
            'order' => 1,
            'message' => 'testing message',
            'status' => 'success',
            'build' => 'b2.1234',
            'push' => null,
            'data' => '**DATA**'
        ];

        $this->assertSame($expected, json_decode($spy, true));
    }
}
