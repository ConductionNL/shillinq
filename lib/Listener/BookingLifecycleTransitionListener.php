<?php

/**
 * Booking lifecycle timeline publish listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 08 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Slice 07 published the `booking.created` event from
 * {@see \OCA\OpenRegister\Event\ObjectCreatedEvent}; slice 08 extends
 * the same publish + retry pattern to the remaining lifecycle
 * transitions:
 *
 *   - `to=confirmed` → publish `booking.confirmed`.
 *   - `to=cancelled` → publish `booking.cancelled` (cancellation reason
 *                     forwarded into the metadata when present).
 *   - `to=completed` → publish `booking.completed`.
 *
 * The listener reuses the slice-07 transport (publishWithOutcome) and
 * the slice-07 retry-queue port; it adds the slice-08 admin notifier
 * port so a 401 from pipelinq surfaces a permanent-failure alert
 * without being requeued for retry.
 *
 * Fail-soft: any downstream exception is caught and logged but never
 * bubbles up to the booking state-change itself (giant decision D3 —
 * the booking commit must NEVER fail because of a downstream publish
 * issue).
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\Pipelinq\PipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\Pipelinq\TimelinePublishOutcome;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publish `booking.confirmed` / `booking.cancelled` / `booking.completed`
 * timeline events as the Appointment lifecycle field transitions.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 */
final class BookingLifecycleTransitionListener implements IEventListener {
	/**
	 * Map of post-transition state → CloudEvents `type` for the
	 * `booking.*` family.
	 *
	 * Centralising the mapping here keeps {@see self::handle()} a flat
	 * dispatch without per-transition branches and makes the table
	 * trivially extensible should the lifecycle gain new states.
	 *
	 * @var array<string, string>
	 */
	private const TRANSITION_TYPE_MAP = [
		'confirmed' => TimelineEventDto::TYPE_BOOKING_CONFIRMED,
		'cancelled' => TimelineEventDto::TYPE_BOOKING_CANCELLED,
		'completed' => TimelineEventDto::TYPE_BOOKING_COMPLETED,
	];

	/**
	 * Construct the listener with its DI deps.
	 *
	 * @param PipelinqContactAdapter $pipelinq Slice-02 adapter (publishWithOutcome).
	 * @param TimelineRetryQueue $retryQueue Async-retry queue (slice 09 binding).
	 * @param PipelinqAdminNotifier $adminAlerts Admin alerter for permanent (401) failures.
	 * @param LoggerInterface $logger PSR logger for fail-soft observability.
	 */
	public function __construct(
		private readonly PipelinqContactAdapter $pipelinq,
		private readonly TimelineRetryQueue $retryQueue,
		private readonly PipelinqAdminNotifier $adminAlerts,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an {@see ObjectTransitionedEvent} from OpenRegister.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 * @spec openspec/changes/archive/2026-06-14-bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		try {
			$this->publishTransition(event: $event);
		} catch (Throwable $e) {
			// The booking commit must NEVER fail because of a downstream
			// publish issue (D3). Log + continue.
			$this->logger->error(
				'BookingLifecycleTransitionListener: publish errored',
				[
					'app' => AppIdentity::APP_ID,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end handle()

	/**
	 * The Appointment fields shared with the other booking timeline listener.
	 *
	 * @return BookingTimelineFields
	 */
	private function fields(): BookingTimelineFields {
		return new BookingTimelineFields();

	}//end fields()

	/**
	 * Publish an Appointment's transition to a published state when the
	 * appointment is linked to a pipelinq contact.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return void
	 */
	private function publishTransition(ObjectTransitionedEvent $event): void {
		$eventType = $this->publishedType(event: $event);
		if ($eventType === null) {
			return;
		}

		$appointment = $event->getObject()->getObject();
		// Without a linked contact (slice 06 labels these "not linked")
		// there is nothing to publish to pipelinq.
		if (is_array($appointment) === false || $this->fields()->contactId(appointment: $appointment) === '') {
			return;
		}

		if ($this->fields()->externalId(appointment: $appointment) === '') {
			$this->logger->warning(
				'BookingLifecycleTransitionListener: missing appointmentId — skipping publish',
				[
					'app' => AppIdentity::APP_ID,
					'type' => $eventType,
				]
			);
			return;
		}

		$dto = $this->buildEvent(eventType: $eventType, appointment: $appointment);
		$this->dispatchOutcome(outcome: $this->pipelinq->publishWithOutcome(event: $dto), dto: $dto);

	}//end publishTransition()

	/**
	 * The `booking.*` type an Appointment transition publishes, or null for
	 * other schemas and for states that are not published (e.g. drafted,
	 * rescheduled, pending_confirmation).
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return string|null
	 */
	private function publishedType(ObjectTransitionedEvent $event): string|null {
		if ($this->fields()->isAppointmentSchema(schema: $event->getSchema()) === false) {
			return null;
		}

		return (self::TRANSITION_TYPE_MAP[strtolower(trim($event->getTo()))] ?? null);

	}//end publishedType()

	/**
	 * Take the appropriate follow-up action for a publish outcome.
	 *
	 * - Success      → DEBUG log; nothing else.
	 * - AuthRejected → permanent failure; fire the admin notifier; DO
	 *                  NOT requeue (the next publish would fail with
	 *                  the same token).
	 * - Transient    → hand off to the retry queue (slice 09).
	 *
	 * @param TimelinePublishOutcome $outcome Outcome of the publish.
	 * @param TimelineEventDto $dto Event that was published.
	 *
	 * @return void
	 */
	private function dispatchOutcome(TimelinePublishOutcome $outcome, TimelineEventDto $dto): void {
		if ($outcome === TimelinePublishOutcome::Success) {
			$this->logger->debug(
				'BookingLifecycleTransitionListener: published',
				[
					'app' => AppIdentity::APP_ID,
					'type' => $dto->type(),
					'externalId' => $dto->externalId(),
					'contactId' => $dto->contactId(),
				]
			);
			return;
		}

		if ($outcome === TimelinePublishOutcome::AuthRejected) {
			// Permanent failure — fire the admin alert and STOP.
			// Re-queueing the event would hit the same 401 again.
			$this->adminAlerts->notifyAuthFailure(event: $dto);
			return;
		}

		// Transient failure — defer to the retry queue. The booking
		// commit has already happened; this keeps the path fail-soft.
		$this->retryQueue->enqueue(event: $dto);

	}//end dispatchOutcome()

	/**
	 * Compose a {@see TimelineEventDto} from the persisted appointment row.
	 *
	 * Uses the slice-07 metadata contract ({@see $this->fields()->metadata()})
	 * and adds a `cancellationReason` field for the `booking.cancelled` event
	 * when the appointment row carries one (REQ — slice 08 spec scenario 2).
	 *
	 * @param string               $eventType   CloudEvents `type`.
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return TimelineEventDto
	 */
	private function buildEvent(string $eventType, array $appointment): TimelineEventDto {
		$timestamp = $this->resolveTimestamp(appointment: $appointment);

		$metadata = $this->fields()->metadata(appointment: $appointment);
		$reason = trim((string)($appointment['cancellationReason'] ?? ''));
		if ($eventType === TimelineEventDto::TYPE_BOOKING_CANCELLED && $reason !== '') {
			$metadata['cancellationReason'] = $reason;
		}

		return new TimelineEventDto(
			type: $eventType,
			externalId: $this->fields()->externalId(appointment: $appointment),
			timestamp: $timestamp,
			contactId: $this->fields()->contactId(appointment: $appointment),
			metadata: $metadata
		);

	}//end buildEvent()

	/**
	 * Choose the timeline event timestamp.
	 *
	 * Prefers an explicit `updatedAt` (the lifecycle transition column
	 * most OR schemas carry), falls back to `createdAt`, and finally to
	 * "now" — always rendered UTC by {@see TimelineEventDto}.
	 *
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return DateTimeImmutable
	 */
	private function resolveTimestamp(array $appointment): DateTimeImmutable {
		foreach (['updatedAt', 'createdAt'] as $key) {
			$raw = trim((string)($appointment[$key] ?? ''));
			if ($raw === '') {
				continue;
			}

			try {
				return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
			} catch (\Exception $e) {
				$this->logger->debug(
					'BookingLifecycleTransitionListener: timestamp field unparseable',
					[
						'app' => AppIdentity::APP_ID,
						'field' => $key,
						'reason' => $e->getMessage(),
					]
				);
			}
		}

		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end resolveTimestamp()
}//end class
