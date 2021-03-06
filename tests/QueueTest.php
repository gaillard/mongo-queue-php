<?php

namespace DominionEnterprises\Mongo;

/**
 * @coversDefaultClass \DominionEnterprises\Mongo\Queue
 * @covers ::<private>
 * @uses \DominionEnterprises\Mongo\Queue::__construct
 */
final class QueueTest extends \PHPUnit_Framework_TestCase
{
    private $_collection;
    private $_mongoUrl;
    private $_queue;

    public function setUp()
    {
        $this->_mongoUrl = getenv('TESTING_MONGO_URL') ?: 'mongodb://localhost:27017';
        $mongo = new \MongoClient($this->_mongoUrl);
        $this->_collection = $mongo->selectDB('testing')->selectCollection('messages');
        $this->_collection->drop();

        $this->_queue = new Queue($this->_mongoUrl, 'testing', 'messages');
    }

    /**
     * @test
     * @covers ::__construct
     * @expectedException \InvalidArgumentException
     */
    public function constructWithNonStringUrl()
    {
        new Queue(1, 'testing', 'messages');
    }

    /**
     * @test
     * @covers ::__construct
     * @expectedException \InvalidArgumentException
     */
    public function constructWithNonStringDb()
    {
        new Queue($this->_mongoUrl, true, 'messages');
    }

    /**
     * @test
     * @covers ::__construct
     * @expectedException \InvalidArgumentException
     */
    public function constructWithNonStringCollection()
    {
        new Queue($this->_mongoUrl, 'testing', new \stdClass());
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     */
    public function ensureGetIndex()
    {
        $this->_queue->ensureGetIndex(['type' => 1], ['boo' => -1]);
        $this->_queue->ensureGetIndex(['another.sub' => 1]);

        $this->assertSame(4, count($this->_collection->getIndexInfo()));

        $expectedOne = ['running' => 1, 'payload.type' => 1, 'priority' => 1, 'created' => 1, 'payload.boo' => -1, 'earliestGet' => 1];
        $resultOne = $this->_collection->getIndexInfo();
        $this->assertSame($expectedOne, $resultOne[1]['key']);

        $expectedTwo = ['running' => 1, 'resetTimestamp' => 1];
        $resultTwo = $this->_collection->getIndexInfo();
        $this->assertSame($expectedTwo, $resultTwo[2]['key']);

        $expectedThree = ['running' => 1, 'payload.another.sub' => 1, 'priority' => 1, 'created' => 1, 'earliestGet' => 1];
        $resultThree = $this->_collection->getIndexInfo();
        $this->assertSame($expectedThree, $resultThree[3]['key']);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \Exception
     */
    public function ensureGetIndexWithTooLongCollectionName()
    {
        $collectionName = 'messages012345678901234567890123456789012345678901234567890123456789';
        $collectionName .= '012345678901234567890123456789012345678901234567890123456789';//128 chars

        $queue = new Queue($this->_mongoUrl, 'testing', $collectionName);
        $queue->ensureGetIndex([]);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithNonStringBeforeSortKey()
    {
        $this->_queue->ensureGetIndex([0 => 1]);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithNonStringAfterSortKey()
    {
        $this->_queue->ensureGetIndex(['field' => 1], [0 => 1]);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithBadBeforeSortValue()
    {
        $this->_queue->ensureGetIndex(['field' => 'NotAnInt']);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithBadAfterSortValue()
    {
        $this->_queue->ensureGetIndex([], ['field' => 'NotAnInt']);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     */
    public function ensureCountIndex()
    {
        $this->_queue->ensureCountIndex(['type' => 1, 'boo' => -1], false);
        $this->_queue->ensureCountIndex(['another.sub' => 1], true);

        $this->assertSame(3, count($this->_collection->getIndexInfo()));

        $expectedOne = ['payload.type' => 1, 'payload.boo' => -1];
        $resultOne = $this->_collection->getIndexInfo();
        $this->assertSame($expectedOne, $resultOne[1]['key']);

        $expectedTwo = ['running' => 1, 'payload.another.sub' => 1];
        $resultTwo = $this->_collection->getIndexInfo();
        $this->assertSame($expectedTwo, $resultTwo[2]['key']);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     */
    public function ensureCountIndexWithPrefixOfPrevious()
    {
        $this->_queue->ensureCountIndex(['type' => 1, 'boo' => -1], false);
        $this->_queue->ensureCountIndex(['type' => 1], false);

        $this->assertSame(2, count($this->_collection->getIndexInfo()));

        $expected = ['payload.type' => 1, 'payload.boo' => -1];
        $result = $this->_collection->getIndexInfo();
        $this->assertSame($expected, $result[1]['key']);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureCountIndexWithNonStringKey()
    {
        $this->_queue->ensureCountIndex([0 => 1], false);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureCountIndexWithBadValue()
    {
        $this->_queue->ensureCountIndex(['field' => 'NotAnInt'], false);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureCountIndexWithNonBoolIncludeRunning()
    {
        $this->_queue->ensureCountIndex(['field' => 1], 1);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getByBadQuery()
    {
        $this->_queue->send(['key1' => 0, 'key2' => true]);

        $result = $this->_queue->get(['key3' => 0], PHP_INT_MAX, 0);
        $this->assertNull($result);

        $this->assertSame(1, $this->_collection->count());
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonIntWaitDuration()
    {
        $this->_queue->get([], 0, 'NotAnInt');
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonIntPollDuration()
    {
        $this->_queue->get([], 0, 0, new \stdClass());
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getWithNegativePollDuration()
    {
        $this->_queue->send(['key1' => 0]);
        $this->assertNotNull($this->_queue->get([], 0, 0, -1));
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonStringKey()
    {
        $this->_queue->get([0 => 'a value'], 0);
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonIntRunningResetDuration()
    {
        $this->_queue->get([], true);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getByFullQuery()
    {
        $messageOne = ['id' => 'SHOULD BE REMOVED', 'key1' => 0, 'key2' => true];

        $this->_queue->send($messageOne);
        $this->_queue->send(['key' => 'value']);

        $result = $this->_queue->get($messageOne, PHP_INT_MAX, 0);

        $this->assertNotSame($messageOne['id'], $result['id']);

        $messageOne['id'] = $result['id'];
        $this->assertSame($messageOne, $result);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getBySubDocQuery()
    {
        $messageTwo = ['one' => ['two' => ['three' => 5, 'notused' => 'notused'], 'notused' => 'notused'], 'notused' => 'notused'];

        $this->_queue->send(['key1' => 0, 'key2' => true]);
        $this->_queue->send($messageTwo);

        $result = $this->_queue->get(['one.two.three' => ['$gt' => 4]], PHP_INT_MAX, 0);
        $this->assertSame(['id' => $result['id']] + $messageTwo, $result);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getBeforeAck()
    {
        $messageOne = ['key1' => 0, 'key2' => true];

        $this->_queue->send($messageOne);
        $this->_queue->send(['key' => 'value']);

        $this->_queue->get($messageOne, PHP_INT_MAX, 0);

        //try get message we already have before ack
        $result = $this->_queue->get($messageOne, PHP_INT_MAX, 0);
        $this->assertNull($result);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getWithCustomPriority()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];
        $messageThree = ['key' => 2];

        $this->_queue->send($messageOne, 0, 0.5);
        $this->_queue->send($messageTwo, 0, 0.4);
        $this->_queue->send($messageThree, 0, 0.3);

        $resultOne = $this->_queue->get([], PHP_INT_MAX, 0);
        $resultTwo = $this->_queue->get([], PHP_INT_MAX, 0);
        $resultThree = $this->_queue->get([], PHP_INT_MAX, 0);

        $this->assertSame(['id' => $resultOne['id']] + $messageThree, $resultOne);
        $this->assertSame(['id' => $resultTwo['id']] + $messageTwo, $resultTwo);
        $this->assertSame(['id' => $resultThree['id']] + $messageOne, $resultThree);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getWithTimeBasedPriority()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];
        $messageThree = ['key' => 2];

        $this->_queue->send($messageOne);
        $this->_queue->send($messageTwo);
        $this->_queue->send($messageThree);

        $resultOne = $this->_queue->get([], PHP_INT_MAX, 0);
        $resultTwo = $this->_queue->get([], PHP_INT_MAX, 0);
        $resultThree = $this->_queue->get([], PHP_INT_MAX, 0);

        $this->assertSame(['id' => $resultOne['id']] + $messageOne, $resultOne);
        $this->assertSame(['id' => $resultTwo['id']] + $messageTwo, $resultTwo);
        $this->assertSame(['id' => $resultThree['id']] + $messageThree, $resultThree);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     * @uses \DominionEnterprises\Mongo\Queue::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::requeue
     */
    public function getWithTimeBasedPriorityWithOldTimestamp()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];
        $messageThree = ['key' => 2];

        $this->_queue->send($messageOne);
        $this->_queue->send($messageTwo);
        $this->_queue->send($messageThree);

        $resultTwo = $this->_queue->get([], PHP_INT_MAX, 0);
        //ensuring using old timestamp shouldn't affect normal time order of send()s
        $this->_queue->requeue($resultTwo, 0, 0.0, false);

        $resultOne = $this->_queue->get([], PHP_INT_MAX, 0);
        $resultTwo = $this->_queue->get([], PHP_INT_MAX, 0);
        $resultThree = $this->_queue->get([], PHP_INT_MAX, 0);

        $this->assertSame(['id' => $resultOne['id']] + $messageOne, $resultOne);
        $this->assertSame(['id' => $resultTwo['id']] + $messageTwo, $resultTwo);
        $this->assertSame(['id' => $resultThree['id']] + $messageThree, $resultThree);
    }

    /**
     * @test
     * @covers ::get
     */
    public function getWait()
    {
        $start = microtime(true);

        $this->_queue->get([], PHP_INT_MAX, 200);

        $end = microtime(true);

        $this->assertTrue($end - $start >= 0.200);
        $this->assertTrue($end - $start < 0.300);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function earliestGet()
    {
         $messageOne = ['key1' => 0, 'key2' => true];

         $this->_queue->send($messageOne, time() + 1);

         $this->assertNull($this->_queue->get($messageOne, PHP_INT_MAX, 0));

         sleep(1);

         $this->assertNotNull($this->_queue->get($messageOne, PHP_INT_MAX, 0));
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function resetStuck()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];

        $this->_queue->send($messageOne);
        $this->_queue->send($messageTwo);

        //sets to running
        $this->_collection->update(['payload.key' => 0], ['$set' => ['running' => true, 'resetTimestamp' => new \MongoDate()]]);
        $this->_collection->update(['payload.key' => 1], ['$set' => ['running' => true, 'resetTimestamp' => new \MongoDate()]]);

        $this->assertSame(2, $this->_collection->count(['running' => true]));

        //sets resetTimestamp on messageOne
        $this->_queue->get($messageOne, 0, 0);

        //resets and gets messageOne
        $this->assertNotNull($this->_queue->get($messageOne, PHP_INT_MAX, 0));

        $this->assertSame(1, $this->_collection->count(['running' => false]));
    }

    /**
     * @test
     * @covers ::count
     * @expectedException \InvalidArgumentException
     */
    public function countWithNonNullOrBoolRunning()
    {
        $this->_queue->count([], 1);
    }

    /**
     * @test
     * @covers ::count
     * @expectedException \InvalidArgumentException
     */
    public function countWithNonStringKey()
    {
        $this->_queue->count([0 => 'a value']);
    }

    /**
     * @test
     * @covers ::count
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function testCount()
    {
        $message = ['boo' => 'scary'];

        $this->assertSame(0, $this->_queue->count($message, true));
        $this->assertSame(0, $this->_queue->count($message, false));
        $this->assertSame(0, $this->_queue->count($message));

        $this->_queue->send($message);
        $this->assertSame(1, $this->_queue->count($message, false));
        $this->assertSame(0, $this->_queue->count($message, true));
        $this->assertSame(1, $this->_queue->count($message));

        $this->_queue->get($message, PHP_INT_MAX, 0);
        $this->assertSame(0, $this->_queue->count($message, false));
        $this->assertSame(1, $this->_queue->count($message, true));
        $this->assertSame(1, $this->_queue->count($message));
    }

    /**
     * @test
     * @covers ::ack
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ack()
    {
        $messageOne = ['key1' => 0, 'key2' => true];

        $this->_queue->send($messageOne);
        $this->_queue->send(['key' => 'value']);

        $result = $this->_queue->get($messageOne, PHP_INT_MAX, 0);
        $this->assertSame(2, $this->_collection->count());

        $this->_queue->ack($result);
        $this->assertSame(1, $this->_collection->count());
    }

    /**
     * @test
     * @covers ::ack
     * @expectedException \InvalidArgumentException
     */
    public function ackBadArg()
    {
        $this->_queue->ack(['id' => new \stdClass()]);
    }

    /**
     * @test
     * @covers ::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ackSend()
    {
        $messageOne = ['key1' => 0, 'key2' => true];
        $messageThree = ['hi' => 'there', 'rawr' => 2];

        $this->_queue->send($messageOne);
        $this->_queue->send(['key' => 'value']);

        $resultOne = $this->_queue->get($messageOne, PHP_INT_MAX, 0);
        $this->assertSame(2, $this->_collection->count());

        $this->_queue->ackSend($resultOne, $messageThree);
        $this->assertSame(2, $this->_collection->count());

        $actual = $this->_queue->get(['hi' => 'there'], PHP_INT_MAX, 0);
        $expected = ['id' => $resultOne['id']] + $messageThree;

        $actual['id'] = $actual['id']->__toString();
        $expected['id'] = $expected['id']->__toString();
        $this->assertSame($expected, $actual);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithWrongIdType()
    {
        $this->_queue->ackSend(['id' => 5], []);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNanPriority()
    {
        $this->_queue->ackSend(['id' => new \MongoId()], [], 0, NAN);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNonFloatPriority()
    {
        $this->_queue->ackSend(['id' => new \MongoId()], [], 0, 'NotAFloat');
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNonIntEarliestGet()
    {
        $this->_queue->ackSend(['id' => new \MongoId()], [], true);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNonBoolNewTimestamp()
    {
        $this->_queue->ackSend(['id' => new \MongoId()], [], 0, 0.0, 1);
    }

    /**
     * @test
     * @covers ::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ackSendWithHighEarliestGet()
    {
        $this->_queue->send([]);
        $messageToAck = $this->_queue->get([], PHP_INT_MAX, 0);

        $this->_queue->ackSend($messageToAck, [], PHP_INT_MAX);

        $expected = [
            'payload' => [],
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => Queue::MONGO_INT32_MAX,
            'priority' => 0.0,
        ];

        $message = $this->_collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ackSendWithLowEarliestGet()
    {
        $this->_queue->send([]);
        $messageToAck = $this->_queue->get([], PHP_INT_MAX, 0);

        $this->_queue->ackSend($messageToAck, [], -1);

        $expected = [
            'payload' => [],
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => 0,
            'priority' => 0.0,
        ];

        $message = $this->_collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::requeue
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function requeue()
    {
        $messageOne = ['key1' => 0, 'key2' => true];

        $this->_queue->send($messageOne);
        $this->_queue->send(['key' => 'value']);

        $resultBeforeRequeue = $this->_queue->get($messageOne, PHP_INT_MAX, 0);

        $this->_queue->requeue($resultBeforeRequeue);
        $this->assertSame(2, $this->_collection->count());

        $resultAfterRequeue = $this->_queue->get($messageOne, 0);
        $this->assertSame(['id' => $resultAfterRequeue['id']] + $messageOne, $resultAfterRequeue);
    }

    /**
     * @test
     * @covers ::requeue
     * @uses \DominionEnterprises\Mongo\Queue::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function requeueBadArg()
    {
        $this->_queue->requeue(['id' => new \stdClass()]);
    }

    /**
     * @test
     * @covers ::send
     */
    public function send()
    {
        $payload = ['key1' => 0, 'key2' => true];
        $this->_queue->send($payload, 34, 0.8);

        $expected = [
            'payload' => $payload,
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => 34,
            'priority' => 0.8,
        ];

        $message = $this->_collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::send
     * @expectedException \InvalidArgumentException
     */
    public function sendWithNanPriority()
    {
        $this->_queue->send([], 0, NAN);
    }

    /**
     * @test
     * @covers ::send
     * @expectedException \InvalidArgumentException
     */
    public function sendWithNonIntegerEarliestGet()
    {
        $this->_queue->send([], true);
    }

    /**
     * @test
     * @covers ::send
     * @expectedException \InvalidArgumentException
     */
    public function sendWithNonFloatPriority()
    {
        $this->_queue->send([], 0, new \stdClass());
    }

    /**
     * @test
     * @covers ::send
     */
    public function sendWithHighEarliestGet()
    {
        $this->_queue->send([], PHP_INT_MAX);

        $expected = [
            'payload' => [],
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => Queue::MONGO_INT32_MAX,
            'priority' => 0.0,
        ];

        $message = $this->_collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::send
     */
    public function sendWithLowEarliestGet()
    {
        $this->_queue->send([], -1);

        $expected = ['payload' => [], 'running' => false, 'resetTimestamp' => Queue::MONGO_INT32_MAX, 'earliestGet' => 0, 'priority' => 0.0];

        $message = $this->_collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }
}
