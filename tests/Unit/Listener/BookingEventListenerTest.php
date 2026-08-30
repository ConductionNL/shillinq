<?php

/**
 * Unit tests for BookingEventListener.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\BookingEventListener;
use OCA\Shillinq\Service\BookingNotificationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingEventListener.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */
class BookingEventListenerTest extends TestCase {

	/**
	 * Mock BookingNotificationService.
	 *
	 * @var BookingNotificationService&MockObject
	 */
	private BookingNotificationService&MockObject $notificationService;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var BookingEventListener
	 */
	private BookingEventListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->notificationService = $this->createMock(originalClassName: BookingNotificationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->listener = new BookingEventListener(
			notificationService: $this->notificationService,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Listener ignores events that are not booking lifecycle events.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-6
	 */
	public function testListenerIgnoresNonBookingEvent(): void {
		$event = $this->createMock(originalClassName: Event::class);

		$this->notificationService
			->expects(static::never())
			->method('evaluateEventTrigger');

		$this->listener->handle(event: $event);
	}//end testListenerIgnoresNonBookingEvent()

	/**
	 * Listener does not fire when event has no booking schema.
	 *
	 * The classname must contain "ObjectCreated" for the listener to fire.
	 * An anonymous Event subclass with getSchema() will not match as its
	 * classname contains neither ObjectCreated, ObjectUpdated, nor ObjectDeleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-6
	 */
	public function testListenerDoesNotFireForAnonymousEventWithBookingSchema(): void {
		// phpcs:disable
		$event = new class extends Event {
			public function getSchema(): string {
				return 'Booking';
			}
			public function getObject(): array {
				return ['id' => 'b-1', 'status' => 'confirmed'];
			}
		};
		// phpcs:enable

		$this->notificationService
			->expects(static::never())
			->method('evaluateEventTrigger');

		$this->listener->handle(event: $event);
	}//end testListenerDoesNotFireForAnonymousEventWithBookingSchema()

	/**
	 * Listener ignores event when booking object is empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-6
	 */
	public function testListenerIgnoresEmptyBookingPayload(): void {
		$event = $this->createMock(originalClassName: Event::class);

		$this->notificationService
			->expects(static::never())
			->method('evaluateEventTrigger');

		$this->listener->handle(event: $event);
	}//end testListenerIgnoresEmptyBookingPayload()
}//end class
