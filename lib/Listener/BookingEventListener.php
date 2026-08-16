<?php

/**
 * Booking Event Listener
 *
 * Subscribes to OpenRegister booking lifecycle events (created, changed,
 * cancelled) and delegates to BookingNotificationService for trigger
 * evaluation and notification dispatch per REQ-BNT-001.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Service\BookingNotificationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for OpenRegister booking object lifecycle events and fires notifications.
 *
 * OpenRegister dispatches generic ObjectCreated / ObjectUpdated / ObjectDeleted
 * events for all schema objects. This listener filters for objects whose schema
 * is "Booking" (or "booking") and maps them to the four booking event types.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-6
 */
class BookingEventListener implements IEventListener {

	/**
	 * Schema slugs this listener responds to.
	 */
	private const BOOKING_SCHEMAS = ['Booking', 'booking'];

	/**
	 * Constructor.
	 *
	 * @param BookingNotificationService $notificationService The notification service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private BookingNotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a booking lifecycle event dispatched by OpenRegister.
	 *
	 * Extracts the booking payload from the event and delegates to the
	 * notification service's evaluateEventTrigger method.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-6
	 */
	public function handle(Event $event): void {
		$eventType = $this->resolveEventType(event: $event);
		if ($eventType === null) {
			return;
		}

		$booking = $this->extractBooking(event: $event);
		if (empty($booking) === true) {
			return;
		}

		$this->logger->info(
			'Shillinq: booking event received',
			['eventType' => $eventType, 'bookingId' => ($booking['id'] ?? 'unknown')]
		);

		$this->notificationService->evaluateEventTrigger(eventType: $eventType, booking: $booking);

	}//end handle()

	/**
	 * Determine the booking event type from the dispatched event.
	 *
	 * Returns null if the event is not a booking lifecycle event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return string|null Event type string, or null if not applicable.
	 */
	private function resolveEventType(Event $event): ?string {
		$className = get_class(object: $event);
		$schema = '';

		// Extract schema from event if available.
		if (method_exists(object_or_class: $event, method: 'getSchema') === true) {
			$schema = (string)$event->getSchema();
		}

		if (in_array(needle: $schema, haystack: self::BOOKING_SCHEMAS, strict: true) === false) {
			return null;
		}

		return match (true) {
			str_contains(haystack: $className, needle: 'ObjectCreated') => 'created',
			str_contains(haystack: $className, needle: 'ObjectUpdated') => 'changed',
			str_contains(haystack: $className, needle: 'ObjectDeleted') => 'cancelled',
			default => null,
		};

	}//end resolveEventType()

	/**
	 * Extract the booking data array from the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<mixed> Booking payload, or empty array.
	 */
	private function extractBooking(Event $event): array {
		if (method_exists(object_or_class: $event, method: 'getObject') === true) {
			$object = $event->getObject();
			if (is_array(value: $object) === true) {
				return $object;
			}
		}

		return [];
	}//end extractBooking()
}//end class
