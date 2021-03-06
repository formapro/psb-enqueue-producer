<?php
namespace ProophTest\ServiceBus;

use Enqueue\Client\Config;
use Enqueue\Consumption\ChainExtension;
use Enqueue\Consumption\Extension\LimitConsumedMessagesExtension;
use Enqueue\Consumption\Extension\LimitConsumptionTimeExtension;
use Enqueue\SimpleClient\SimpleClient;
use Formapro\Prooph\ServiceBus\Message\Enqueue\Commands;
use Formapro\Prooph\ServiceBus\Message\Enqueue\EnqueueMessageProcessor;
use Formapro\Prooph\ServiceBus\Message\Enqueue\EnqueueMessageProducer;
use Formapro\Prooph\ServiceBus\Message\Enqueue\EnqueueSerializer;
use PHPUnit\Framework\TestCase;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Plugin\Router\CommandRouter;
use Prooph\ServiceBus\Plugin\Router\EventRouter;
use Prooph\ServiceBus\QueryBus;
use ProophTest\ServiceBus\Mock\DoSomething;
use ProophTest\ServiceBus\Mock\MessageHandler;
use ProophTest\ServiceBus\Mock\SomethingDone;
use Symfony\Component\Filesystem\Filesystem;

class EnqueueMessageProducerTest extends TestCase
{
    /**
     * @var SimpleClient
     */
    private $client;

    private $serializer;

    protected function setUp()
    {
        (new Filesystem())->remove(__DIR__.'/queues/');

        $this->client = new SimpleClient('file://'.__DIR__.'/queues');

        $this->serializer = new EnqueueSerializer(new FQCNMessageFactory(), new NoOpMessageConverter());
    }

    /**
     * @test
     */
    public function it_sends_a_command_to_queue_pulls_it_with_consumer_and_forwards_it_to_command_bus()
    {
        $command = new DoSomething(['data' => 'test command']);

        //The message dispatcher works with a ready-to-use enqueue producer and one queue
        $messageProducer = new EnqueueMessageProducer($this->client->getProducer(), $this->serializer);

        //Normally you would send the command on a command bus. We skip this step here cause we are only
        //interested in the function of the message dispatcher
        $messageProducer($command);

        //Set up command bus which will receive the command message from the enqueue consumer
        $consumerCommandBus = new CommandBus();

        $doSomethingHandler = new MessageHandler();

        $router = new CommandRouter();
        $router->route($command->messageName())->to($doSomethingHandler);
        $router->attachToMessageBus($consumerCommandBus);

        $enqueueProcessor = new EnqueueMessageProcessor($consumerCommandBus, new EventBus(), new QueryBus(), $this->serializer);
        $this->client->bind(Config::COMMAND_TOPIC, Commands::PROOPH_BUS, $enqueueProcessor);

        $this->client->consume(new ChainExtension([
            new LimitConsumedMessagesExtension(1),
            new LimitConsumptionTimeExtension(new \DateTime('now + 5 seconds'))
        ]));

        $this->assertNotNull($doSomethingHandler->getLastMessage());

        $this->assertEquals($command->payload(), $doSomethingHandler->getLastMessage()->payload());
    }

    /**
     * @test
     */
    public function it_sends_an_event_to_queue_pulls_it_with_consumer_and_forwards_it_to_event_bus()
    {
        $event = new SomethingDone(['data' => 'test event']);

        //The message dispatcher works with a ready-to-use enqueue producer and one queue
        $messageProducer = new EnqueueMessageProducer($this->client->getProducer(), $this->serializer);

        //Normally you would send the event on a event bus. We skip this step here cause we are only
        //interested in the function of the message dispatcher
        $messageProducer($event);

        //Set up event bus which will receive the event message from the enqueue consumer
        $consumerEventBus = new EventBus();

        $somethingDoneListener = new MessageHandler();

        $router = new EventRouter();
        $router->route($event->messageName())->to($somethingDoneListener);
        $router->attachToMessageBus($consumerEventBus);

        $enqueueProcessor = new EnqueueMessageProcessor(new CommandBus(), $consumerEventBus, new QueryBus(), $this->serializer);
        $this->client->bind(Config::COMMAND_TOPIC, Commands::PROOPH_BUS, $enqueueProcessor);

        $this->client->consume(new ChainExtension([
            new LimitConsumedMessagesExtension(1),
            new LimitConsumptionTimeExtension(new \DateTime('now + 5 seconds'))
        ]));

        $this->assertNotNull($somethingDoneListener->getLastMessage());

        $this->assertEquals($event->payload(), $somethingDoneListener->getLastMessage()->payload());
    }
}
