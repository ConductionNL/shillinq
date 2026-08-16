<?php

/**
 * Unit tests for the TimelineEventDto value type.
 *
 * Slice 07 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Verifies the fixed payload contract (type, externalId, timestamp,
 * contactId, metadata), the CloudEvents 1.0 envelope (ADR-037), the
 * constructor validation, and the deterministic ISO-8601 UTC timestamp
 * rendering.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
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

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use PHPUnit\Framework\TestCase;

/**
 * Pure value-type tests for the timeline publish DTO.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
 */
final class TimelineEventDtoTest extends TestCase {

	/**
	 * `toPayload()` renders the exact fixed contract from design.md
	 * — type, externalId, timestamp, contactId, metadata — in UTC.
	 *
	 * @return void
	 */
	public function testToPayloadMatchesFixedContract(): void {
		$dto = new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CREATED,
			externalId: 'booking-abc-123',
			timestamp: new DateTimeImmutable('2026-06-06T12:34:56+02:00'),
			contactId: 'pl-contact-42',
			metadata: [
				'bookingNumber' => 'booking-abc-123',
				'service' => 'haircut',
				'guestCount' => 1,
			]
		);

		$payload = $dto->toPayload();

		self::assertSame(
			[
				'type' => 'booking.created',
				'externalId' => 'booking-abc-123',
				'timestamp' => '2026-06-06T10:34:56Z',
				'contactId' => 'pl-contact-42',
				'metadata' => [
					'bookingNumber' => 'booking-abc-123',
					'service' => 'haircut',
					'guestCount' => 1,
				],
			],
			$payload
		);

	}//end testToPayloadMatchesFixedContract()

	/**
	 * The CloudEvents 1.0 envelope is rendered with the canonical
	 * `specversion`/`source`/`type`/`subject`/`time`/`data` fields and
	 * carries the raw payload as `data`.
	 *
	 * @return void
	 */
	public function testToCloudEventWrapsPayloadInCloudEventsEnvelope(): void {
		$dto = new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CREATED,
			externalId: 'booking-abc-123',
			timestamp: new DateTimeImmutable('2026-06-06T12:34:56Z', new DateTimeZone('UTC')),
			contactId: 'pl-contact-42'
		);

		$cloudEvent = $dto->toCloudEvent();

		self::assertSame('1.0', $cloudEvent['specversion']);
		self::assertSame('/shillinq/bookings', $cloudEvent['source']);
		self::assertSame('booking.created', $cloudEvent['type']);
		self::assertSame('booking-abc-123', $cloudEvent['subject']);
		self::assertSame('2026-06-06T12:34:56Z', $cloudEvent['time']);
		self::assertSame('application/json', $cloudEvent['datacontenttype']);
		self::assertSame($dto->toPayload(), $cloudEvent['data']);
		self::assertSame(
			'booking.created:booking-abc-123:2026-06-06T12:34:56Z',
			$cloudEvent['id'],
			'CloudEvents id must be deterministic to support downstream dedupe.'
		);

	}//end testToCloudEventWrapsPayloadInCloudEventsEnvelope()

	/**
	 * The constructor rejects empty type / externalId / contactId so
	 * downstream code is never asked to serialise an unidentifiable
	 * event.
	 *
	 * @param string $type Type.
	 * @param string $externalId External id.
	 * @param string $contactId Contact id.
	 *
	 * @dataProvider invalidFieldsProvider
	 *
	 * @return void
	 */
	public function testConstructorRejectsEmptyIdentifyingFields(
		string $type,
		string $externalId,
		string $contactId,
	): void {
		$this->expectException(InvalidArgumentException::class);

		new TimelineEventDto(
			type: $type,
			externalId: $externalId,
			timestamp: new DateTimeImmutable('now'),
			contactId: $contactId
		);

	}//end testConstructorRejectsEmptyIdentifyingFields()

	/**
	 * Data set for {@see self::testConstructorRejectsEmptyIdentifyingFields()}.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalidFieldsProvider(): array {
		return [
			'empty type' => ['', 'id', 'contact'],
			'whitespace type' => ['   ', 'id', 'contact'],
			'empty externalId' => ['booking.created', '', 'contact'],
			'empty contactId' => ['booking.created', 'id', ''],
		];

	}//end invalidFieldsProvider()

}//end class
