<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Release;
use Hal\Core\Entity\JobEvent;
use Hal\Agent\Testing\JsonableStub;
use Hal\Agent\Testing\StringableStub;
use stdClass;

class EventFactoryTest extends MockeryTestCase
{
    public $em;
    public $random;

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

        $this->assertInstanceOf(JobEvent::CLASS, $spy);

        $this->assertSame('info', $spy->status());
        $this->assertSame('unknown', $spy->stage());
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
        $build->shouldReceive('id')->andReturn('blah');

        $factory = new EventFactory($this->em);
        $factory->setBuild($build);
        $factory->setStage('build.end');
        $factory->success();

        $this->assertSame('blah', $spy->parentID());
        $this->assertSame('build.end', $spy->stage());
    }

    public function testReleaseIsAttached()
    {
        $spy = null;

        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $release = Mockery::mock(Release::CLASS);
        $release->shouldReceive('id')->andReturn('blah');

        $factory = new EventFactory($this->em);
        $factory->setRelease($release);
        $factory->setStage('release.end');
        $factory->success();

        $this->assertSame('blah', $spy->parentID());
        $this->assertSame('release.end', $spy->stage());
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
        $this->assertSame($expectedContext, $spy->parameters());
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
            'stage' => 'unknown',
            'order' => 1,
            'message' => 'testing message',
            'status' => 'success',
            'parent_id' => 'b2.1234',
            'parameters' => '**DATA**'
        ];

        $this->assertArraySubset($expected, json_decode($spy, true));
    }
}
