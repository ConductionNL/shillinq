<?php

/**
 * Unit tests for the BookingLifecycleTransitionListener.
 *
 * Slice 08 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Verifies the listener's contract:
 *
 *   - Builds the fixed `booking.confirmed` / `booking.cancelled` /
 *     `booking.completed` payload from the appointment row.
 *   - Includes `cancellationReason` in metadata for cancellations when
 *     present.
 *   - Skips non-Appointment schemas, non-publishing transitions, and
 *     bookings without a `pipelinqContactId`.
 *   - On {@see TimelinePublishOutcome::Success}        — does NOT enqueue,
 *     does NOT call the admin notifier; emits a DEBUG log.
 *   - On {@see TimelinePublishOutcome::AuthRejected}   — calls the admin
 *     notifier, does NOT enqueue (permanent failure).
 *   - On {@see TimelinePublishOutcome::Transient}      — enqueues for
 *     async retry (slice 09 binding), does NOT call the admin notifier.
 *   - NEVER raises — the booking transition commit must not be blocked
 *     by a downstream publish problem (D3).
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\BookingLifecycleTransitionListener;
use OCA\Shillinq\Service\Pipelinq\PipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\Pipelinq\TimelinePublishOutcome;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Verifies the lifecycle listener orchestrates the publish + retry +
 * admin-alert paths correctly.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */
final class BookingLifecycleTransitionListenerTest extends TestCase {

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
	 * Build a stub adapter, retry queue, and admin notifier wired to
	 * capture sinks.
	 *
	 * @param TimelinePublishOutcome $outcome Outcome the stub returns.
	 * @param array<int, TimelineEventDto> &$publishCalls Capture of publish calls.
	 * @param array<int, TimelineEventDto> &$enqueueCalls Capture of enqueue calls.
	 * @param array<int, TimelineEventDto> &$adminCalls Capture of admin-notifier calls.
	 *
	 * @return array{0:PipelinqContactAdapter, 1:TimelineRetryQueue, 2:PipelinqAdminNotifier}
	 */
	private function deps(
		TimelinePublishOutcome $outcome,
		array &$publishCalls,
		array &$enqueueCalls,
		array &$adminCalls,
	): array {
		$adapter = new class($outcome, $publishCalls) extends PipelinqContactAdapter {

			/**
			 * @var TimelinePublishOutcome
			 */
			private TimelinePublishOutcome $outcome;

			/**
			 * @var array<int, TimelineEventDto>
			 */
			private array $publishCalls;

			/**
			 * @param TimelinePublishOutcome $outcome Outcome.
			 * @param array<int, TimelineEventDto> &$publishCalls Capture.
			 */
			public function __construct(TimelinePublishOutcome $outcome, array &$publishCalls) {
				$this->outcome = $outcome;
				$this->publishCalls = & $publishCalls;
			}//end __construct()

			/**
			 * @param TimelineEventDto $event Event.
			 *
			 * @return TimelinePublishOutcome
			 */
			public function publishWithOutcome(TimelineEventDto $event): TimelinePublishOutcome {
				$this->publishCalls[] = $event;
				return $this->outcome;
			}//end publishWithOutcome()
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

		$notifier = new class($adminCalls) implements PipelinqAdminNotifier {

			/**
			 * @var array<int, TimelineEventDto>
			 */
			private array $adminCalls;

			/**
			 * @param array<int, TimelineEventDto> &$adminCalls Capture sink.
			 */
			public function __construct(array &$adminCalls) {
				$this->adminCalls = & $adminCalls;
			}//end __construct()

			/**
			 * @param TimelineEventDto $event Event whose publish was rejected.
			 *
			 * @return void
			 */
			public function notifyAuthFailure(TimelineEventDto $event): void {
				$this->adminCalls[] = $event;
			}//end notifyAuthFailure()
		};

		return [$adapter, $queue, $notifier];
	}//end deps()

	/**
	 * Build a fully-formed ObjectTransitionedEvent for an Appointment.
	 *
	 * @param string $to Post-transition lifecycle value.
	 * @param array<string, mixed> $appointment Object payload.
	 * @param string $schema Schema name (default 'appointment').
	 * @param string $from Pre-transition lifecycle value (default 'pending_confirmation').
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transitionEvent(
		string $to,
		array $appointment,
		string $schema = 'appointment',
		string $from = 'pending_confirmation',
	): ObjectTransitionedEvent {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($appointment);
		return new ObjectTransitionedEvent(
			$entity,
			'transition',
			$from,
			$to,
			null,
			'shillinq',
			$schema
		);

	}//end transitionEvent()

	/**
	 * Booking confirmed publishes a `booking.confirmed` event with the
	 * appointment metadata.
	 *
	 * @return void
	 */
	public function testConfirmedTransitionPublishesBookingConfirmedEvent(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Success,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'confirmed',
				appointment: [
					'appointmentId' => 'booking-c-1',
					'pipelinqContactId' => 'pl-contact-42',
					'serviceId' => 'haircut',
					'startTime' => '2026-06-08T10:00:00Z',
					'endTime' => '2026-06-08T11:00:00Z',
					'resourceId' => 'chair-1',
					'status' => 'confirmed',
					'updatedAt' => '2026-06-07T09:00:00Z',
				]
			)
		);

		self::assertCount(1, $publishCalls);
		self::assertCount(0, $enqueueCalls);
		self::assertCount(0, $adminCalls);

		$dto = $publishCalls[0];
		self::assertSame(TimelineEventDto::TYPE_BOOKING_CONFIRMED, $dto->type());
		self::assertSame('booking-c-1', $dto->externalId());
		self::assertSame('pl-contact-42', $dto->contactId());

		$payload = $dto->toPayload();
		self::assertSame('2026-06-07T09:00:00Z', $payload['timestamp']);
		self::assertSame(
			[
				'bookingNumber' => 'booking-c-1',
				'service' => 'haircut',
				'startTime' => '2026-06-08T10:00:00Z',
				'endTime' => '2026-06-08T11:00:00Z',
				'resourceId' => 'chair-1',
				'status' => 'confirmed',
			],
			$payload['metadata']
		);

	}//end testConfirmedTransitionPublishesBookingConfirmedEvent()

	/**
	 * Cancellations forward the `cancellationReason` into the metadata.
	 *
	 * @return void
	 */
	public function testCancelledTransitionForwardsCancellationReason(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Success,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'cancelled',
				appointment: [
					'appointmentId' => 'booking-x-2',
					'pipelinqContactId' => 'pl-contact-99',
					'status' => 'cancelled',
					'cancellationReason' => 'customer no-show',
				],
				from: 'confirmed'
			)
		);

		self::assertCount(1, $publishCalls);
		$dto = $publishCalls[0];
		self::assertSame(TimelineEventDto::TYPE_BOOKING_CANCELLED, $dto->type());

		$metadata = $dto->toPayload()['metadata'];
		self::assertArrayHasKey('cancellationReason', $metadata);
		self::assertSame('customer no-show', $metadata['cancellationReason']);

	}//end testCancelledTransitionForwardsCancellationReason()

	/**
	 * Completed transition publishes a `booking.completed` event.
	 *
	 * @return void
	 */
	public function testCompletedTransitionPublishesBookingCompletedEvent(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Success,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'completed',
				appointment: [
					'appointmentId' => 'booking-d-3',
					'pipelinqContactId' => 'pl-contact-7',
					'status' => 'completed',
				],
				from: 'confirmed'
			)
		);

		self::assertCount(1, $publishCalls);
		self::assertSame(TimelineEventDto::TYPE_BOOKING_COMPLETED, $publishCalls[0]->type());

	}//end testCompletedTransitionPublishesBookingCompletedEvent()

	/**
	 * A 401 outcome triggers the admin notifier and does NOT enqueue
	 * the event for retry — the next attempt would hit the same token.
	 *
	 * @return void
	 */
	public function testAuthRejectedOutcomeFiresAdminNotifierAndSkipsRetry(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::AuthRejected,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'confirmed',
				appointment: [
					'appointmentId' => 'booking-a-1',
					'pipelinqContactId' => 'pl-contact-1',
				]
			)
		);

		self::assertCount(1, $publishCalls);
		self::assertCount(1, $adminCalls);
		self::assertCount(0, $enqueueCalls, '401 outcomes MUST NOT be requeued');
		self::assertSame('booking-a-1', $adminCalls[0]->externalId());

	}//end testAuthRejectedOutcomeFiresAdminNotifierAndSkipsRetry()

	/**
	 * A transient outcome enqueues the event for async retry; the admin
	 * notifier is NOT called.
	 *
	 * @return void
	 */
	public function testTransientOutcomeEnqueuesForRetry(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Transient,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'completed',
				appointment: [
					'appointmentId' => 'booking-t-1',
					'pipelinqContactId' => 'pl-contact-5',
				]
			)
		);

		self::assertCount(1, $publishCalls);
		self::assertCount(1, $enqueueCalls);
		self::assertCount(0, $adminCalls);
		self::assertSame('booking-t-1', $enqueueCalls[0]->externalId());

	}//end testTransientOutcomeEnqueuesForRetry()

	/**
	 * Transitions to states outside the {confirmed, cancelled, completed}
	 * set (e.g. drafted, rescheduled) are silently ignored.
	 *
	 * @return void
	 */
	public function testUnknownTransitionTargetIsIgnored(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Success,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'rescheduled',
				appointment: [
					'appointmentId' => 'booking-u-1',
					'pipelinqContactId' => 'pl-contact-1',
				]
			)
		);

		self::assertCount(0, $publishCalls);
		self::assertCount(0, $enqueueCalls);
		self::assertCount(0, $adminCalls);

	}//end testUnknownTransitionTargetIsIgnored()

	/**
	 * Bookings without a `pipelinqContactId` are silently skipped (slice
	 * 06 labels them "not linked").
	 *
	 * @return void
	 */
	public function testMissingPipelinqContactIdSkipsPublish(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Success,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'confirmed',
				appointment: [
					'appointmentId' => 'booking-x',
					// pipelinqContactId omitted.
				]
			)
		);

		self::assertCount(0, $publishCalls);
		self::assertCount(0, $enqueueCalls);
		self::assertCount(0, $adminCalls);

	}//end testMissingPipelinqContactIdSkipsPublish()

	/**
	 * Non-Appointment schemas are ignored — the ObjectTransitionedEvent
	 * fires for every schema in OR.
	 *
	 * @return void
	 */
	public function testNonAppointmentSchemaIsIgnored(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Success,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $this->recordingLogger()
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'completed',
				appointment: [
					'appointmentId' => 'whatever',
					'pipelinqContactId' => 'pl-contact-42',
				],
				schema: 'invoice'
			)
		);

		self::assertCount(0, $publishCalls);
		self::assertCount(0, $enqueueCalls);
		self::assertCount(0, $adminCalls);

	}//end testNonAppointmentSchemaIsIgnored()

	/**
	 * The success path emits a DEBUG log entry that names the event
	 * type, the booking id, and the linked contact id.
	 *
	 * @return void
	 */
	public function testSuccessLogsAtDebug(): void {
		$publishCalls = [];
		$enqueueCalls = [];
		$adminCalls = [];
		[$adapter, $queue, $notifier] = $this->deps(
			outcome: TimelinePublishOutcome::Success,
			publishCalls: $publishCalls,
			enqueueCalls: $enqueueCalls,
			adminCalls: $adminCalls
		);

		$logger = $this->recordingLogger();
		$listener = new BookingLifecycleTransitionListener(
			pipelinq: $adapter,
			retryQueue: $queue,
			adminAlerts: $notifier,
			logger: $logger
		);

		$listener->handle(
			$this->transitionEvent(
				to: 'confirmed',
				appointment: [
					'appointmentId' => 'booking-log-1',
					'pipelinqContactId' => 'pl-contact-1',
				]
			)
		);

		$debug = array_values(
			array_filter(
				$logger->records,
				static fn (array $r): bool => $r['level'] === \Psr\Log\LogLevel::DEBUG
			)
		);
		self::assertNotEmpty($debug);
		self::assertStringContainsString('published', $debug[0]['message']);
		self::assertSame(TimelineEventDto::TYPE_BOOKING_CONFIRMED, $debug[0]['context']['type']);
		self::assertSame('booking-log-1', $debug[0]['context']['externalId']);

	}//end testSuccessLogsAtDebug()

}//end class
