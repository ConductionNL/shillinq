<?php

/**
 * Timeline event DTO for the pipelinq publish path.
 *
 * Slice 07 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Immutable value object representing a single timeline entry to publish
 * to pipelinq's klantbeeld-360 endpoint (POST `/api/v1/timeline`). The
 * payload contract (giant decision Risk 3) is a fixed JSON shape with
 * five fields: `type`, `externalId`, `timestamp`, `contactId`,
 * `metadata`. The {@see self::toPayload()} method renders that exact
 * shape; the {@see self::toCloudEvent()} method wraps it in the
 * CloudEvents 1.0 envelope so pipelinq receivers that consume the
 * shared event spine (ADR-037) can route it. Both forms keep the
 * adapter caller-agnostic — slice 07 uses the raw payload form for the
 * fixed `/api/v1/timeline` endpoint per the design.md contract.
 *
 * Security (ADR-005):
 *   - Metadata is caller-supplied and constrained to scalar values +
 *     scalar arrays. Tokens, secrets, and PII beyond what already
 *     reaches the timeline are NOT to be placed in metadata.
 *   - The DTO is value-only: it owns no I/O and cannot leak the bearer
 *     token (the adapter holds that).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable description of one timeline event to publish.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class TimelineEventDto {

	/**
	 * CloudEvents 1.0 spec version (ADR-037).
	 *
	 * @var string
	 */
	public const CLOUDEVENT_SPEC_VERSION = '1.0';

	/**
	 * CloudEvents `source` URI for events emitted by shillinq.
	 *
	 * @var string
	 */
	public const CLOUDEVENT_SOURCE = '/shillinq/bookings';

	/**
	 * Event type for a freshly-created booking.
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_CREATED = 'booking.created';

	/**
	 * Event type for a booking transitioning into `confirmed`.
	 *
	 * Slice 08 of the customer-bridge chain — pushed when the booking
	 * state machine transitions from any prior state into `confirmed`.
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_CONFIRMED = 'booking.confirmed';

	/**
	 * Event type for a booking transitioning into `cancelled`.
	 *
	 * Slice 08 of the customer-bridge chain — pushed when the booking
	 * is cancelled. The originating appointment row's `cancellationReason`
	 * is forwarded into the timeline event metadata (when present).
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_CANCELLED = 'booking.cancelled';

	/**
	 * Event type for a booking transitioning into `completed`.
	 *
	 * Slice 08 of the customer-bridge chain — pushed when the booking
	 * is marked complete (e.g. the appointment finished, work-done /
	 * payment-received transition).
	 *
	 * @var string
	 */
	public const TYPE_BOOKING_COMPLETED = 'booking.completed';

	/**
	 * Construct an immutable timeline event.
	 *
	 * @param string $type Event type (e.g. `booking.created`).
	 * @param string $externalId Booking external id (UUID) — the timeline entry's stable
	 *                           reference.
	 * @param DateTimeInterface $timestamp When the event happened (UTC; rendered as ISO-8601).
	 * @param string $contactId pipelinq Contact id to attach the event to.
	 * @param array<string, mixed> $metadata Arbitrary scalar metadata; never contains secrets.
	 */
	public function __construct(
		private readonly string $type,
		private readonly string $externalId,
		private readonly DateTimeInterface $timestamp,
		private readonly string $contactId,
		private readonly array $metadata = [],
	) {
		if (trim($this->type) === '') {
			throw new InvalidArgumentException('TimelineEventDto requires a non-empty type.');
		}

		if (trim($this->externalId) === '') {
			throw new InvalidArgumentException('TimelineEventDto requires a non-empty externalId.');
		}

		if (trim($this->contactId) === '') {
			throw new InvalidArgumentException('TimelineEventDto requires a non-empty contactId.');
		}

	}//end __construct()

	/**
	 * Event type accessor.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}//end type()

	/**
	 * External id accessor (the booking's stable id).
	 *
	 * @return string
	 */
	public function externalId(): string {
		return $this->externalId;
	}//end externalId()

	/**
	 * Linked pipelinq Contact id accessor.
	 *
	 * @return string
	 */
	public function contactId(): string {
		return $this->contactId;
	}//end contactId()

	/**
	 * Timestamp accessor.
	 *
	 * @return DateTimeInterface
	 */
	public function timestamp(): DateTimeInterface {
		return $this->timestamp;
	}//end timestamp()

	/**
	 * Metadata accessor (defensive copy via the array semantics PHP uses for
	 * value types).
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}//end metadata()

	/**
	 * Render the fixed JSON payload shape consumed by `POST /api/v1/timeline`.
	 *
	 * Matches the design.md contract exactly:
	 *
	 * ```json
	 * {
	 *   "type":       "booking.created",
	 *   "externalId": "<booking-uuid>",
	 *   "timestamp":  "<iso-8601>",
	 *   "contactId":  "<pipelinqContactId>",
	 *   "metadata":   { ... }
	 * }
	 * ```
	 *
	 * @return array<string, mixed>
	 */
	public function toPayload(): array {
		return [
			'type' => $this->type,
			'externalId' => $this->externalId,
			'timestamp' => $this->renderTimestamp(),
			'contactId' => $this->contactId,
			'metadata' => $this->metadata,
		];

	}//end toPayload()

	/**
	 * Render the CloudEvents 1.0 envelope (ADR-037) wrapping the payload.
	 *
	 * Provided for fan-out targets that consume the shared event spine.
	 * The pipelinq timeline endpoint itself accepts the raw payload from
	 * {@see self::toPayload()}.
	 *
	 * @return array<string, mixed>
	 */
	public function toCloudEvent(): array {
		return [
			'specversion' => self::CLOUDEVENT_SPEC_VERSION,
			'id' => $this->cloudEventId(),
			'source' => self::CLOUDEVENT_SOURCE,
			'type' => $this->type,
			'subject' => $this->externalId,
			'time' => $this->renderTimestamp(),
			'datacontenttype' => 'application/json',
			'data' => $this->toPayload(),
		];

	}//end toCloudEvent()

	/**
	 * Build a CloudEvents 1.0 unique id from `<type>:<externalId>:<timestamp>`.
	 *
	 * Deterministic so duplicate publishes can be deduplicated downstream
	 * even when the async-retry path (slice 09) re-emits.
	 *
	 * @return string
	 */
	private function cloudEventId(): string {
		return $this->type . ':' . $this->externalId . ':' . $this->renderTimestamp();
	}//end cloudEventId()

	/**
	 * Render the timestamp as an ISO-8601 string in UTC (`Y-m-d\TH:i:s\Z`).
	 *
	 * @return string
	 */
	private function renderTimestamp(): string {
		if (($this->timestamp instanceof DateTimeImmutable) === true) {
			$utc = $this->timestamp;
		} else {
			$utc = DateTimeImmutable::createFromInterface($this->timestamp);
		}

		return $utc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end renderTimestamp()
}//end class
