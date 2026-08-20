<?php

/**
 * Booking-created timeline publish listener.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
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
	 * @param PipelinqContactAdapter $pipelinq Slice-02 adapter (publishTimelineEvent).
	 * @param TimelineRetryQueue $retryQueue Async-retry queue (slice 09 binding).
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger PSR logger for fail-soft observability.
	 * @param CustomerBridgeMetricsService|null $metrics Optional metrics aggregator (slice 11); NULL-safe for existing tests.
	 */
	public function __construct(
		private readonly PipelinqContactAdapter $pipelinq,
		private readonly TimelineRetryQueue $retryQueue,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly ?CustomerBridgeMetricsService $metrics = null,
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
			$entity = $event->getObject();
			if ($entity === null || $entity->getSchema() === null) {
				return;
			}

			if ($this->isAppointmentSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity)) === false) {
				return;
			}

			$appointment = $entity->getObject();
			if (is_array($appointment) === false) {
				return;
			}

			$pipelinqContactId = trim((string)($appointment['pipelinqContactId'] ?? ''));
			if ($pipelinqContactId === '') {
				// Slice 06 already labels these "not linked"; nothing to
				// publish to pipelinq.
				return;
			}

			$externalId = trim((string)($appointment['appointmentId'] ?? ''));
			if ($externalId === '') {
				$this->logger->warning(
					'BookingCreatedTimelinePublishListener: missing appointmentId — skipping publish',
					[
						'app' => Application::APP_ID,
					]
				);
				return;
			}

			$dto = $this->buildEvent(
				externalId: $externalId,
				contactId: $pipelinqContactId,
				appointment: $appointment
			);

			$ok = $this->pipelinq->publishTimelineEvent(event: $dto);
			if ($ok === true) {
				$this->logger->debug(
					'BookingCreatedTimelinePublishListener: published',
					[
						'app' => Application::APP_ID,
						'externalId' => $externalId,
						'contactId' => $pipelinqContactId,
					]
				);
				return;
			}

			// Synchronous publish failed; hand off to the async retry
			// queue so the booking commit can proceed regardless (D3).
			$this->retryQueue->enqueue(event: $dto);
			$this->metrics?->recordTimelinePublishDeferred();
		} catch (Throwable $e) {
			// The booking commit must NEVER fail because of a downstream
			// publish issue. Log + continue; the queue+adapter already
			// carry full fail-soft semantics, this is the catch-all for
			// an unexpected coding error in the payload construction.
			$this->logger->error(
				'BookingCreatedTimelinePublishListener: publish errored',
				[
					'app' => Application::APP_ID,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end handle()

	/**
	 * Whether the supplied schema string identifies the Appointment schema.
	 *
	 * Mirrors {@see AppointmentCreatedListener::isAppointmentSchema()};
	 * schema strings may be plain slugs or numeric ids depending on OR
	 * config so the comparison is permissive on both fronts.
	 *
	 * @param string $schema Schema identifier from the entity.
	 *
	 * @return bool TRUE when the schema is "Appointment".
	 */
	private function isAppointmentSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === 'appointment' || str_ends_with($normalised, '/appointment'));
	}//end isAppointmentSchema()

	/**
	 * Compose a {@see TimelineEventDto} from the persisted appointment row.
	 *
	 * The metadata payload is fixed (giant Risk 3): only scalar fields
	 * the pipelinq timeline consumer is documented to render are passed
	 * through, and absent fields are emitted as empty strings (NOT
	 * dropped) so the contract stays stable across boolean-falsy
	 * absences.
	 *
	 * @param string $externalId Booking id used as the timeline externalId.
	 * @param string $contactId Linked pipelinq Contact id.
	 * @param array<string, mixed> $appointment Persisted appointment row.
	 *
	 * @return TimelineEventDto
	 */
	private function buildEvent(string $externalId, string $contactId, array $appointment): TimelineEventDto {
		$timestamp = $this->resolveTimestamp(appointment: $appointment);

		$metadata = [
			'bookingNumber' => $externalId,
			'service' => (string)($appointment['serviceId'] ?? ''),
			'startTime' => (string)($appointment['startTime'] ?? ''),
			'endTime' => (string)($appointment['endTime'] ?? ''),
			'resourceId' => (string)($appointment['resourceId'] ?? ''),
			'status' => (string)($appointment['status'] ?? ''),
		];

		return new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CREATED,
			externalId: $externalId,
			timestamp: $timestamp,
			contactId: $contactId,
			metadata: $metadata
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
						'app' => Application::APP_ID,
						'reason' => $e->getMessage(),
					]
				);
			}
		}

		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end resolveTimestamp()
}//end class
