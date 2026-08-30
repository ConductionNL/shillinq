<?php

/**
 * Appointment Created Listener.
 *
 * Reacts to OpenRegister's ObjectCreatedEvent for the `Appointment` schema
 * and — when the new appointment was self-booked (status =
 * `pending_confirmation`) — issues a fresh ConfirmationToken and dispatches
 * the confirmation email through openconnector. Admin-created appointments
 * (status starts as `confirmed`) are ignored per REQ-BCF-010.
 *
 * The listener is intentionally event-driven (rather than wired into the
 * controllers that POST appointments) so that any future create path
 * — REST, OCS, OR generic CRUD — automatically issues the token without
 * duplicate logic.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\Booking\ConfirmationTokenService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Issue a confirmation token + send the email when a new self-service
 * appointment is created (REQ-BCF-001/003/010).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-11
 */
class AppointmentCreatedListener implements IEventListener {
	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param ConfirmationTokenService $tokens Token + email dispatcher.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft.
	 */
	public function __construct(
		private readonly ConfirmationTokenService $tokens,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			if ($entity === null || $entity->getSchema() === null) {
				return;
			}

			$schema = $this->schemaResolver->schemaSlug(entity: $entity);
			if ($this->isAppointmentSchema(schema: $schema) === false) {
				return;
			}

			$appointment = $entity->getObject();
			if (is_array($appointment) === false) {
				return;
			}

			if ((string)($appointment['status'] ?? '') !== 'pending_confirmation') {
				// Admin-created bookings start as `confirmed` and skip the
				// confirmation flow per REQ-BCF-010.
				return;
			}

			$appointmentId = (string)($appointment['appointmentId'] ?? '');
			if ($appointmentId === '') {
				return;
			}

			$customer = [
				'id' => (string)($appointment['customerId'] ?? ''),
				'userId' => (string)($appointment['customerUserId'] ?? ''),
				'name' => (string)($appointment['customerName'] ?? ''),
				'email' => (string)($appointment['customerEmail'] ?? ''),
			];

			$this->tokens->issueAndSend($appointment, $customer);
		} catch (Throwable $e) {
			// Never fail an appointment create because of a downstream
			// email/token failure — log + continue. Operators can resend.
			$this->logger->error(
				'AppointmentCreatedListener: token issuance failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Whether the supplied schema string identifies the Appointment schema
	 * — schema strings may be plain slugs or numeric IDs depending on OR
	 * config.
	 *
	 * @param string $schema Schema identifier from the entity.
	 *
	 * @return bool TRUE when the schema is "Appointment".
	 */
	private function isAppointmentSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === 'appointment' || str_ends_with($normalised, '/appointment'));
	}//end isAppointmentSchema()
}//end class
