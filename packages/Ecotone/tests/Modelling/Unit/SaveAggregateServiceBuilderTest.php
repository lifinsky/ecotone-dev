<?php

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Messaging\Store\Document\InMemoryDocumentStore;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateService;
use Ecotone\Modelling\BaseEventSourcingConfiguration;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\NoCorrectIdentifierDefinedException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\CreateAggregate;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\CreateSomething;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\EventSourcingAggregateWithInternalRecorder;
use Test\Ecotone\Modelling\Fixture\AggregateServiceBuilder\Something;
use Test\Ecotone\Modelling\Fixture\Blog\Article;
use Test\Ecotone\Modelling\Fixture\Blog\PublishArticleCommand;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\CreateOrderCommand;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\Order;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\OrderWithManualVersioning;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\FinishJob;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\Job;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\JobWasFinished;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\JobWasStarted;
use Test\Ecotone\Modelling\Fixture\EventSourcedAggregateWithInternalEventRecorder\StartJob;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\TwitContentWasChanged;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\Twitter;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\TwitterRepository;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\TwitterWithRecorder;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\TwitterWithRecorderRepository;
use Test\Ecotone\Modelling\Fixture\EventSourcingRepositoryShortcut\TwitWasCreated;
use Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\NoIdDefinedAfterCallingFactory\NoIdDefinedAfterRecordingEvents;
use Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\PublicIdentifierGetMethodWithParameters;
use Test\Ecotone\Modelling\Fixture\Ticket\StartTicketCommand;
use Test\Ecotone\Modelling\Fixture\Ticket\Ticket;

/**
 * Class ServiceCallToAggregateAdapterTest
 * @package Test\Ecotone\Modelling
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */

/**
 * licence Apache-2.0
 * @internal
 */
class SaveAggregateServiceBuilderTest extends TestCase
{
    public function test_saving_aggregate_method_with_only_command_as_parameter()
    {
        $this->assertEquals(
            1,
            EcotoneLite::bootstrapFlowTesting(
                [Order::class]
            )
                ->sendCommand(CreateOrderCommand::createWith(1, 10, 'Poland'))
                ->getAggregate(Order::class, ['orderId' => 1])
                ->getId()
        );
    }

    public function test_snapshoting_aggregate_after_single_event()
    {
        $inMemoryDocumentStore = InMemoryDocumentStore::createEmpty();

        $ticket = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            [DocumentStore::class => $inMemoryDocumentStore],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    (new BaseEventSourcingConfiguration())->withSnapshotsFor(Ticket::class, 1),
                ])
        )
            ->sendCommand(new StartTicketCommand($ticketId = 1))
            ->getAggregate(Ticket::class, ['ticketId' => $ticketId]);

        $this->assertEquals(
            $ticket,
            $inMemoryDocumentStore->getDocument(SaveAggregateService::getSnapshotCollectionName(Ticket::class), 1)
        );
    }

    public function test_skipping_snapshot_if_aggregate_not_registered_for_snapshoting()
    {
        $inMemoryDocumentStore = InMemoryDocumentStore::createEmpty();

        EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            [DocumentStore::class => $inMemoryDocumentStore],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    (new BaseEventSourcingConfiguration()),
                ])
        )
            ->sendCommand(new StartTicketCommand($ticketId = 1));

        $this->assertEquals(0, $inMemoryDocumentStore->countDocuments(SaveAggregateService::getSnapshotCollectionName(Ticket::class)));
    }

    public function test_skipping_snapshot_if_not_desired_version_yet()
    {
        $inMemoryDocumentStore = InMemoryDocumentStore::createEmpty();

        EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            [DocumentStore::class => $inMemoryDocumentStore],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    (new BaseEventSourcingConfiguration())->withSnapshotsFor(Ticket::class, 2),
                ])
        )
            ->sendCommand(new StartTicketCommand(1));

        $this->assertEquals(0, $inMemoryDocumentStore->countDocuments(SaveAggregateService::getSnapshotCollectionName(Ticket::class)));
    }

    public function test_returning_all_identifiers_assigned_during_aggregate_creation()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Article::class]
        );

        $this->assertEquals(
            ['author' => 'johny', 'title' => 'Cat book'],
            $ecotoneLite
                ->getGateway(CommandBus::class)
                ->send(PublishArticleCommand::createWith('johny', 'Cat book', 'Good content'))
        );
    }

    public function test_calling_save_method_with_automatic_increasing_version()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Order::class]
        );

        $aggregate = $ecotoneLite
            ->sendCommand(CreateOrderCommand::createWith(1, 1, 'Poland'))
            ->getAggregate(Order::class, ['orderId' => 1]);

        $this->assertEquals(1, $aggregate->getVersion());
    }

    public function test_calling_save_method_with_manual_increasing_version()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OrderWithManualVersioning::class]
        );

        $aggregate = $ecotoneLite
            ->sendCommandWithRoutingKey('order.create', CreateOrderCommand::createWith(1, 1, 'Poland'))
            ->getAggregate(OrderWithManualVersioning::class, ['orderId' => 1]);

        $this->assertEquals(0, $aggregate->getVersion());
    }

    public function test_throwing_exception_if_aggregate_before_saving_has_no_nullable_identifier()
    {
        $this->expectException(InvalidArgumentException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [NoIdDefinedAfterRecordingEvents::class]
        );
    }

    public function test_throwing_exception_if_aggregate_identifier_getter_has_parameters()
    {
        $this->expectException(NoCorrectIdentifierDefinedException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [PublicIdentifierGetMethodWithParameters::class]
        );
    }

    public function test_result_aggregate_are_published_in_order(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Job::class]
        );

        $jobId = Uuid::uuid4()->toString();
        $newJobId = Uuid::uuid4()->toString();

        self::assertEquals(
            [
                JobWasFinished::recordWith($jobId),
                JobWasStarted::recordWith($newJobId),
            ],
            $ecotoneLite
                ->sendCommand(new StartJob($jobId))
                ->discardRecordedMessages()
                ->sendCommandWithRoutingKey('job.finish_and_start', new FinishJob($jobId), metadata: [
                    'newJobId' => $newJobId,
                ])
                ->getRecordedEvents(),
        );
    }

    public function test_calling_action_method_of_existing_event_sourcing_aggregate_with_internal_recorder_which_creates_another_aggregate(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [EventSourcingAggregateWithInternalRecorder::class, Something::class],
        )
            ->sendCommand(new CreateAggregate($id = 1))
            ->sendCommand(new CreateSomething($id, $somethingId = 200));

        $this->assertSame(
            2,
            $ecotoneLite
                ->getAggregate(EventSourcingAggregateWithInternalRecorder::class, ['id' => $id])
                ->getVersion()
        );

        $this->assertEquals(
            1,
            $ecotoneLite
                ->getAggregate(Something::class, ['id' => $somethingId])
                ->getVersion()
        );
    }

    public function test_storing_pure_event_sourced_aggregate_via_business_repository_for_first_time(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Twitter::class, TwitterRepository::class],
        );

        /** @var TwitterRepository $twitterRepository */
        $twitterRepository = $ecotoneLite->getGateway(TwitterRepository::class);

        $twitterRepository->save(
            '1',
            0,
            [
                new TwitWasCreated('1', 'Hello world'),
            ]
        );

        $this->assertSame(
            'Hello world',
            $twitterRepository->getTwitter('1')
                ->getContent()
        );
    }

    public function test_storing_pure_event_sourced_aggregate_via_business_repository_for_update(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Twitter::class, TwitterRepository::class],
        );

        /** @var TwitterRepository $twitterRepository */
        $twitterRepository = $ecotoneLite->getGateway(TwitterRepository::class);

        $twitterRepository->save('1', 0, [new TwitWasCreated('1', 'Hello world')]);
        $twitterRepository->save('1', 1, [new TwitContentWasChanged('1', 'Hello world 2')]);

        $this->assertSame(
            'Hello world 2',
            $twitterRepository->getTwitter('1')
                ->getContent()
        );
    }

    public function test_storing_non_pure_event_sourced_aggregate_via_business_repository_for_first_time(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TwitterWithRecorder::class, TwitterWithRecorderRepository::class],
        );

        /** @var TwitterWithRecorderRepository $twitterRepository */
        $twitterRepository = $ecotoneLite->getGateway(TwitterWithRecorderRepository::class);

        $twitterRepository->save(
            TwitterWithRecorder::create('1', 'Hello world')
        );

        $this->assertSame(
            'Hello world',
            $twitterRepository->getTwitter('1')
                ->getContent()
        );
    }

    public function test_storing_non_pure_event_sourced_aggregate_via_business_repository_for_update(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TwitterWithRecorder::class, TwitterWithRecorderRepository::class],
        );

        /** @var TwitterWithRecorderRepository $twitterRepository */
        $twitterRepository = $ecotoneLite->getGateway(TwitterWithRecorderRepository::class);

        $twitterRepository->save(TwitterWithRecorder::create('1', 'Hello world'));

        $twitter = $twitterRepository->getTwitter('1');
        $twitter->changeContent('Hello world 2');
        $twitterRepository->save($twitter);

        $this->assertSame(
            'Hello world 2',
            $twitterRepository->getTwitter('1')
                ->getContent()
        );
    }
}
