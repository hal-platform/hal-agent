<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Mockery;
use PHPUnit_Framework_TestCase;
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

        $this->assertInstanceOf('QL\Hal\Core\Entity\EventLog', $spy);

        $this->assertSame('info', $spy->getStatus());
        $this->assertSame('unknown', $spy->getEvent());
        $this->assertSame(1, $spy->getOrder());
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

        $this->assertSame(1, $spies[0]->getOrder());
        $this->assertSame(2, $spies[1]->getOrder());
        $this->assertSame(3, $spies[2]->getOrder());
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

        $build = Mockery::mock('Ql\Hal\Core\Entity\Build');

        $factory = new EventFactory($this->em);
        $factory->setBuild($build);
        $factory->setStage('build.end');
        $factory->success();

        $this->assertSame($build, $spy->getBuild());
        $this->assertSame('build.end', $spy->getEvent());
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

        $push = Mockery::mock('Ql\Hal\Core\Entity\Push');

        $factory = new EventFactory($this->em);
        $factory->setPush($push);
        $factory->setStage('push.end');
        $factory->success();

        $this->assertSame($push, $spy->getPush());
        $this->assertSame('push.end', $spy->getEvent());
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

        $this->assertSame('testing message', $spy->getMessage());
        $this->assertSame($expectedContext, $spy->getData());
    }
}
