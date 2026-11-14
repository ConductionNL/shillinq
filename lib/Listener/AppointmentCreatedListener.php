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
use OCA\Shillinq\Kernel\Contracts\ConfirmationTokenServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
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
	 * @param ConfirmationTokenServiceInterface $tokens Token + email dispatcher.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft.
	 */
	public function __construct(
		private readonly ConfirmationTokenServiceInterface $tokens,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
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
			$appointment = $this->pendingAppointment(event: $event);
			if ($appointment !== null) {
				$this->tokens->issueAndSend($appointment, $this->customerOf(appointment: $appointment));
			}
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
	 * The created Appointment when it awaits customer confirmation.
	 *
	 * @param ObjectCreatedEvent $event The created-object event.
	 *
	 * @return array<string,mixed>|null The appointment, or null for anything else.
	 */
	private function pendingAppointment(ObjectCreatedEvent $event): array|null {
		$entity = $event->getObject();
		if ($entity === null || $entity->getSchema() === null
			|| $this->isAppointmentSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity)) === false
		) {
			return null;
		}

		$appointment = $entity->getObject();
		if (is_array($appointment) === false || $this->awaitsConfirmation(appointment: $appointment) === false) {
			return null;
		}

		return $appointment;

	}//end pendingAppointment()

	/**
	 * Whether the appointment has an id and is pending customer confirmation.
	 *
	 * Admin-created bookings start as `confirmed` and skip the confirmation
	 * flow per REQ-BCF-010.
	 *
	 * @param array<string,mixed> $appointment The appointment.
	 *
	 * @return bool True when a confirmation token must be sent.
	 */
	private function awaitsConfirmation(array $appointment): bool {
		return (string)($appointment['status'] ?? '') === 'pending_confirmation'
			&& (string)($appointment['appointmentId'] ?? '') !== '';

	}//end awaitsConfirmation()

	/**
	 * The customer the confirmation goes to, as the token service expects it.
	 *
	 * @param array<string,mixed> $appointment The appointment.
	 *
	 * @return array<string,string> The customer id, user id, name and email.
	 */
	private function customerOf(array $appointment): array {
		return [
			'id' => (string)($appointment['customerId'] ?? ''),
			'userId' => (string)($appointment['customerUserId'] ?? ''),
			'name' => (string)($appointment['customerName'] ?? ''),
			'email' => (string)($appointment['customerEmail'] ?? ''),
		];

	}//end customerOf()

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
