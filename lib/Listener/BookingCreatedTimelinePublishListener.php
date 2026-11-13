<?php

/**
 * Booking-created timeline publish listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 07 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Listens to OpenRegister's {@see ObjectCreatedEvent} on the Appointment
 * schema; when the new booking carries a non-empty `pipelinqContactId`,
 * builds a {@see TimelineEventDto} and synchronously publishes it via
 * {@see PipelinqContactAdapter::publishTimelineEvent()}. On failure the
 * event is handed off to the {@see TimelineRetryQueue} so the booking
 * commit is never blocked (D3 from the giant).
 *
 * The listener is intentionally event-driven so that any future booking-
 * create path (REST, OCS, or generic OR CRUD) emits the timeline event
 * without duplicate orchestration in the controllers.
 *
 * Slice 08 extends this pattern to confirmed / cancelled / completed
 * transitions on {@see ObjectTransitionedEvent}; slice 09 swaps the
 * {@see TimelineRetryQueue} binding for a persistent background-job
 * implementation.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\CustomerBridgeMetricsServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\PipelinqContactAdapterInterface;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publish a `booking.created` timeline event to pipelinq on persist.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */
final class BookingCreatedTimelinePublishListener implements IEventListener {
	/**
	 * Construct the listener with its DI deps.
	 *
	 * @param PipelinqContactAdapterInterface $pipelinq Slice-02 adapter (publishTimelineEvent).
	 * @param TimelineRetryQueue $retryQueue Async-retry queue (slice 09 binding).
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger PSR logger for fail-soft observability.
	 * @param CustomerBridgeMetricsServiceInterface|null $metrics Optional metrics aggregator (slice 11); NULL-safe for existing tests.
	 */
	public function __construct(
		private readonly PipelinqContactAdapterInterface $pipelinq,
		private readonly TimelineRetryQueue $retryQueue,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly ?CustomerBridgeMetricsServiceInterface $metrics = null,
	) {

	}//end __construct()

	/**
	 * Handle an {@see ObjectCreatedEvent}.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		try {
			$this->publishCreated(event: $event);
		} catch (Throwable $e) {
			// The booking commit must NEVER fail because of a downstream
			// publish issue. Log + continue; the queue+adapter already
			// carry full fail-soft semantics, this is the catch-all for
			// an unexpected coding error in the payload construction.
			$this->logger->error(
				'BookingCreatedTimelinePublishListener: publish errored',
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
	 * Publish the created appointment when it is linked to a pipelinq contact.
	 *
	 * @param ObjectCreatedEvent $event The creation event.
	 *
	 * @return void
	 */
	private function publishCreated(ObjectCreatedEvent $event): void {
		$appointment = $this->createdAppointment(event: $event);
		// Without a linked contact (slice 06 labels these "not linked")
		// there is nothing to publish to pipelinq.
		if ($appointment === null || $this->fields()->contactId(appointment: $appointment) === '') {
			return;
		}

		if ($this->fields()->externalId(appointment: $appointment) === '') {
			$this->logger->warning(
				'BookingCreatedTimelinePublishListener: missing appointmentId — skipping publish',
				[
					'app' => AppIdentity::APP_ID,
				]
			);
			return;
		}

		$this->publish(dto: $this->buildEvent(appointment: $appointment));

	}//end publishCreated()

	/**
	 * The created object when it is an Appointment row, else null.
	 *
	 * @param ObjectCreatedEvent $event The creation event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function createdAppointment(ObjectCreatedEvent $event): array|null {
		$entity = $event->getObject();
		if ($entity === null || $entity->getSchema() === null) {
			return null;
		}

		if ($this->fields()->isAppointmentSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity)) === false) {
			return null;
		}

		$appointment = $entity->getObject();
		if (is_array($appointment) === false) {
			return null;
		}

		return $appointment;

	}//end createdAppointment()

	/**
	 * Publish synchronously; on failure hand off to the async retry queue so
	 * the booking commit can proceed regardless (D3).
	 *
	 * @param TimelineEventDto $dto The event.
	 *
	 * @return void
	 */
	private function publish(TimelineEventDto $dto): void {
		if ($this->pipelinq->publishTimelineEvent(event: $dto) === true) {
			$this->logger->debug(
				'BookingCreatedTimelinePublishListener: published',
				[
					'app' => AppIdentity::APP_ID,
					'externalId' => $dto->externalId(),
					'contactId' => $dto->contactId(),
				]
			);
			return;
		}

		$this->retryQueue->enqueue(event: $dto);
		$this->metrics?->recordTimelinePublishDeferred();

	}//end publish()

	/**
	 * Compose a {@see TimelineEventDto} from the persisted appointment row,
	 * with the fixed metadata of {@see $this->fields()->metadata()}.
	 *
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return TimelineEventDto
	 */
	private function buildEvent(array $appointment): TimelineEventDto {
		return new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CREATED,
			externalId: $this->fields()->externalId(appointment: $appointment),
			timestamp: $this->resolveTimestamp(appointment: $appointment),
			contactId: $this->fields()->contactId(appointment: $appointment),
			metadata: $this->fields()->metadata(appointment: $appointment)
		);

	}//end buildEvent()

	/**
	 * Choose the event timestamp: prefer the appointment's `createdAt`
	 * field, falling back to "now" when the field is absent or unparseable.
	 *
	 * The timestamp is always normalised to UTC by the DTO renderer;
	 * here we only need to produce a parseable DateTimeImmutable.
	 *
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return DateTimeImmutable
	 */
	private function resolveTimestamp(array $appointment): DateTimeImmutable {
		$createdAt = trim((string)($appointment['createdAt'] ?? ''));
		if ($createdAt !== '') {
			try {
				return new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));
			} catch (\Exception $e) {
				// Fall through to "now" below.
				$this->logger->debug(
					'BookingCreatedTimelinePublishListener: createdAt unparseable, falling back to now',
					[
						'app' => AppIdentity::APP_ID,
						'reason' => $e->getMessage(),
					]
				);
			}
		}

		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end resolveTimestamp()
}//end class
