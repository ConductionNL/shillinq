<?php

/**
 * Unit tests for the BookingCreatedTimelinePublishListener.
 *
 * Slice 07 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Verifies the listener's contract:
 *
 *   - Skips events on non-Appointment schemas.
 *   - Skips bookings without a `pipelinqContactId` (slice 06 owns the
 *     "not linked" rendering).
 *   - Builds the fixed `booking.created` payload from the appointment
 *     row.
 *   - Hands off to {@see TimelineRetryQueue} on synchronous publish
 *     failure.
 *   - NEVER raises — the booking commit must not be blocked by a
 *     downstream publish problem (D3).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\BookingCreatedTimelinePublishListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Verifies the listener orchestrates the publish path correctly.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */
final class BookingCreatedTimelinePublishListenerTest extends TestCase {

	/**
	 * Build a recording logger.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

	}//end recordingLogger()

	/**
	 * Build a recording TimelineRetryQueue + a stubbable publish adapter.
	 *
	 * @param bool $publishOk Result the stub returns.
	 * @param array<int, TimelineEventDto> &$publishCalls Capture of every publish call.
	 * @param array<int, TimelineEventDto> &$enqueueCalls Capture of every enqueue call.
	 *
	 * @return array{0:PipelinqContactAdapter, 1:TimelineRetryQueue}
	 */
	private function deps(bool $publishOk, array &$publishCalls, array &$enqueueCalls): array {
		$adapter = new class($publishOk, $publishCalls) extends PipelinqContactAdapter {

			/**
			 * @var bool
			 */
			private bool $publishOk;

			/**
			 * @var array<int, TimelineEventDto>
			 */
			private array $publishCalls;

			/**
			 * @param bool $publishOk Result.
			 * @param array<int, TimelineEventDto> &$publishCalls Capture.
			 */
			public function __construct(bool $publishOk, array &$publishCalls) {
				$this->publishOk = $publishOk;
				$this->publishCalls = & $publishCalls;
			}//end __construct()

			/**
			 * @param TimelineEventDto $event Event.
			 *
			 * @return bool
			 */
			public function publishTimelineEvent(TimelineEventDto $event): bool {
				$this->publishCalls[] = $event;
				return $this->publishOk;
			}//end publishTimelineEvent()
		};

		$queue = new class($enqueueCalls) implements TimelineRetryQueue {

			/**
			 * @var array<int, TimelineEventDto>
			 */
			private array $enqueueCalls;

			/**
			 * @param array<int, TimelineEventDto> &$enqueueCalls Capture sink.
			 */
			public function __construct(array &$enqueueCalls) {
				$this->enqueueCalls = & $enqueueCalls;
			}//end __construct()

			/**
			 * @param TimelineEventDto $event Event.
			 *
			 * @return void
			 */
			public function enqueue(TimelineEventDto $event): void {
				$this->enqueueCalls[] = $event;
			}//end enqueue()
		};

		return [$adapter, $queue];
	}//end deps()

	/**
	 * Build an Appointment ObjectEntity carrying the numeric schema **id**,
	 * exactly as OpenRegister stamps it
	 * (`setSchema((string) $schema->getId())`).
	 *
	 * A hand-built entity carrying the slug is a shape production never
	 * produces; the slug arrives through {@see ListenerSchemaResolver}.
	 *
	 * @param array<string, mixed> $appointment Object payload.
	 * @param string $schemaId Numeric schema id as OR stamps it.
	 *
	 * @return ObjectEntity
	 */
	private function appointmentEntity(array $appointment, string $schemaId = '7001'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schemaId);
		$entity->setObject($appointment);
		return $entity;
	}//end appointmentEntity()

	/**
	 * Build a ListenerSchemaResolver stub that reports a given schema slug.
	 *
	 * @param string $slug Slug the resolver resolves the entity's id to.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(string $slug): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		return $resolver;
	}//end resolver()

	/**
	 * Happy path — pipelinqContactId set, publish succeeds, no retry
	 * queueing.
	 *
	 * @return void
	 */
	public function testHappyPathPublishesFixedPayload(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		[$adapter, $queue] = $this->deps(publishOk: true, publishCalls: $publishCalls, enqueueCalls: $enqueueCalls);

		$listener = new BookingCreatedTimelinePublishListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			schemaResolver: $this->resolver('appointment'),
			logger: $this->recordingLogger()
		);

		$listener->handle(
			new ObjectCreatedEvent(
				$this->appointmentEntity(
					[
						'appointmentId' => 'booking-abc-123',
						'pipelinqContactId' => 'pl-contact-42',
						'serviceId' => 'haircut',
						'startTime' => '2026-06-07T10:00:00Z',
						'endTime' => '2026-06-07T11:00:00Z',
						'resourceId' => 'chair-1',
						'status' => 'pending_confirmation',
						'createdAt' => '2026-06-06T12:34:56Z',
					]
				)
			)
		);

		self::assertCount(1, $publishCalls);
		self::assertCount(0, $enqueueCalls);

		$dto = $publishCalls[0];
		self::assertSame('booking.created', $dto->type());
		self::assertSame('booking-abc-123', $dto->externalId());
		self::assertSame('pl-contact-42', $dto->contactId());

		$payload = $dto->toPayload();
		self::assertSame('2026-06-06T12:34:56Z', $payload['timestamp']);
		self::assertSame(
			[
				'bookingNumber' => 'booking-abc-123',
				'service' => 'haircut',
				'startTime' => '2026-06-07T10:00:00Z',
				'endTime' => '2026-06-07T11:00:00Z',
				'resourceId' => 'chair-1',
				'status' => 'pending_confirmation',
			],
			$payload['metadata']
		);

	}//end testHappyPathPublishesFixedPayload()

	/**
	 * A publish that returns FALSE is handed off to the retry queue.
	 *
	 * @return void
	 */
	public function testFailureHandsOffToRetryQueue(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		[$adapter, $queue] = $this->deps(publishOk: false, publishCalls: $publishCalls, enqueueCalls: $enqueueCalls);

		$listener = new BookingCreatedTimelinePublishListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			schemaResolver: $this->resolver('appointment'),
			logger: $this->recordingLogger()
		);

		$listener->handle(
			new ObjectCreatedEvent(
				$this->appointmentEntity(
					[
						'appointmentId' => 'booking-abc-123',
						'pipelinqContactId' => 'pl-contact-42',
					]
				)
			)
		);

		self::assertCount(1, $publishCalls);
		self::assertCount(1, $enqueueCalls);
		self::assertSame('booking-abc-123', $enqueueCalls[0]->externalId());

	}//end testFailureHandsOffToRetryQueue()

	/**
	 * Bookings without a pipelinqContactId are silently skipped — slice 06
	 * already labels them "not linked" in the UI.
	 *
	 * @return void
	 */
	public function testMissingPipelinqContactIdSkipsPublish(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		[$adapter, $queue] = $this->deps(publishOk: true, publishCalls: $publishCalls, enqueueCalls: $enqueueCalls);

		$listener = new BookingCreatedTimelinePublishListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			schemaResolver: $this->resolver('appointment'),
			logger: $this->recordingLogger()
		);

		$listener->handle(
			new ObjectCreatedEvent(
				$this->appointmentEntity(
					[
						'appointmentId' => 'booking-abc-123',
						// pipelinqContactId omitted.
					]
				)
			)
		);

		self::assertCount(0, $publishCalls);
		self::assertCount(0, $enqueueCalls);

	}//end testMissingPipelinqContactIdSkipsPublish()

	/**
	 * Non-Appointment schemas are ignored — the ObjectCreatedEvent fires
	 * for every schema in OR, and this listener is interested only in
	 * appointments.
	 *
	 * @return void
	 */
	public function testNonAppointmentSchemaIsIgnored(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		[$adapter, $queue] = $this->deps(publishOk: true, publishCalls: $publishCalls, enqueueCalls: $enqueueCalls);

		$listener = new BookingCreatedTimelinePublishListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			schemaResolver: $this->resolver('invoice'),
			logger: $this->recordingLogger()
		);

		$listener->handle(
			new ObjectCreatedEvent(
				$this->appointmentEntity(
					[
						'appointmentId' => 'whatever',
						'pipelinqContactId' => 'pl-contact-42',
					],
					schemaId: '7050'
				)
			)
		);

		self::assertCount(0, $publishCalls);
		self::assertCount(0, $enqueueCalls);

	}//end testNonAppointmentSchemaIsIgnored()

}//end class
