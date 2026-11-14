<?php

/**
 * Unit tests for BookingEventListener's dispatch: which event kinds map to
 * which trigger, the booking schema check, and the payload handed on.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\BookingEventListener;
use OCA\Shillinq\Service\BookingNotificationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- the listener keys on the event's class name, so each kind needs its own small class.

/**
 * An event carrying a schema and an object, as the OpenRegister object events do.
 */
abstract class BookingDispatchFakeEvent extends Event {

	/**
	 * @param string $schema The schema name.
	 * @param mixed  $object The object payload.
	 */
	public function __construct(private string $schema, private mixed $object) {
		parent::__construct();
	}

	/**
	 * @return string
	 */
	public function getSchema(): string {
		return $this->schema;
	}

	/**
	 * @return mixed
	 */
	public function getObject(): mixed {
		return $this->object;
	}
}

/**
 * A created-object event.
 */
final class FakeObjectCreatedEvent extends BookingDispatchFakeEvent {
}

/**
 * An updated-object event.
 */
final class FakeObjectUpdatedEvent extends BookingDispatchFakeEvent {
}

/**
 * A deleted-object event.
 */
final class FakeObjectDeletedEvent extends BookingDispatchFakeEvent {
}

/**
 * Another event that carries a schema.
 */
final class FakeObjectLockedEvent extends BookingDispatchFakeEvent {
}

/**
 * Tests for BookingEventListener's dispatch.
 */
#[CoversClass(BookingEventListener::class)]
class BookingEventListenerDispatchTest extends TestCase {

	/**
	 * Event class → trigger, for both spellings of the schema.
	 *
	 * @return array<string, array{0: class-string<BookingDispatchFakeEvent>, 1: string, 2: string}>
	 */
	public static function kinds(): array {
		return [
			'created'          => [FakeObjectCreatedEvent::class, 'Booking', 'created'],
			'updated'          => [FakeObjectUpdatedEvent::class, 'booking', 'changed'],
			'deleted'          => [FakeObjectDeletedEvent::class, 'Booking', 'cancelled'],
		];
	}//end kinds()

	/**
	 * Each object event on a booking hands the booking on with its trigger.
	 *
	 * @param class-string<BookingDispatchFakeEvent> $class   The event class.
	 * @param string                                 $schema  The schema name.
	 * @param string                                 $trigger The expected trigger.
	 *
	 * @return void
	 */
	#[DataProvider('kinds')]
	public function testABookingEventIsHandedOnWithItsTrigger(string $class, string $schema, string $trigger): void {
		$booking = ['id' => 'b-1', 'status' => 'confirmed'];

		$service = $this->createMock(BookingNotificationService::class);
		$service->expects($this->once())->method('evaluateEventTrigger')->with($trigger, $booking);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Shillinq: booking event received',
			['eventType' => $trigger, 'bookingId' => 'b-1']
		);

		(new BookingEventListener(notificationService: $service, logger: $logger))->handle(new $class($schema, $booking));
	}//end testABookingEventIsHandedOnWithItsTrigger()

	/**
	 * A booking without an id is still handed on, logged as unknown.
	 *
	 * @return void
	 */
	public function testABookingWithoutAnIdIsLoggedAsUnknown(): void {
		$service = $this->createMock(BookingNotificationService::class);
		$service->expects($this->once())->method('evaluateEventTrigger')->with('created', ['status' => 'new']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Shillinq: booking event received',
			['eventType' => 'created', 'bookingId' => 'unknown']
		);

		(new BookingEventListener(notificationService: $service, logger: $logger))
			->handle(new FakeObjectCreatedEvent('Booking', ['status' => 'new']));
	}//end testABookingWithoutAnIdIsLoggedAsUnknown()

	/**
	 * Other schemas, other event kinds, and empty or non-array payloads are ignored.
	 *
	 * @return void
	 */
	public function testEverythingElseIsIgnored(): void {
		$service = $this->createMock(BookingNotificationService::class);
		$service->expects($this->never())->method('evaluateEventTrigger');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$listener = new BookingEventListener(notificationService: $service, logger: $logger);

		$listener->handle(new FakeObjectCreatedEvent('Invoice', ['id' => 'i-1']));
		$listener->handle(new FakeObjectCreatedEvent('BOOKING', ['id' => 'b-1']));
		$listener->handle(new FakeObjectLockedEvent('Booking', ['id' => 'b-1']));
		$listener->handle(new FakeObjectUpdatedEvent('Booking', []));
		$listener->handle(new FakeObjectUpdatedEvent('Booking', 'b-1'));
	}//end testEverythingElseIsIgnored()
}//end class
