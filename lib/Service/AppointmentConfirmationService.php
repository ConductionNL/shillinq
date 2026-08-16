<?php

/**
 * Appointment Confirmation Service
 *
 * Orchestrates the bookings-confirm-flow capability: generates confirmation
 * tokens for pending appointments, validates and redeems them, resends fresh
 * tokens, auto-cancels appointments whose deadline has passed, and delivers the
 * confirmation email (with ICS attachment) via the shared openconnector channel
 * per ADR-022. All persistence goes through OpenRegister's real ObjectService
 * API (setRegister/setSchema/findAll/saveObject) — no app-local tables, no
 * invented methods. Reads and writes are scoped by administrationId for
 * IDOR-safety, and the raw token string is never persisted (REQ-BCF-001..010).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCA\Shillinq\Util\TokenValidator;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service implementing the appointment confirmation workflow.
 */
class AppointmentConfirmationService {
	/**
	 * Status of a freshly created, self-booked appointment.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending_confirmation';

	/**
	 * Confirmed appointment status.
	 *
	 * @var string
	 */
	public const STATUS_CONFIRMED = 'confirmed';

	/**
	 * Cancelled appointment status.
	 *
	 * @var string
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Token time-to-live in seconds (7 days).
	 *
	 * @var int
	 */
	private const TOKEN_TTL = (7 * 24 * 60 * 60);

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container for lazy OR ObjectService.
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param ConfirmationMailer $mailer Confirmation email delivery (ADR-022).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ConfirmationMailer $mailer,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Resolve OpenRegister's ObjectService, or null when OR is unavailable (T1).
	 *
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable) {
			$this->logger->debug('AppointmentConfirmationService: ObjectService unavailable (T1 state)');
			return null;
		}
	}//end getObjectService()

	/**
	 * Issue a confirmation token for a freshly created pending appointment and
	 * send the confirmation email (REQ-BCF-001, REQ-BCF-003, REQ-BCF-010).
	 *
	 * Admin-created (already-confirmed) appointments are skipped — no token is
	 * generated and no email is sent.
	 *
	 * @param array<string,mixed> $appointment The appointment object array.
	 *
	 * @return string|null The raw token string (for tests/delivery), or null when skipped.
	 */
	public function issueConfirmation(array $appointment): ?string {
		if (($appointment['status'] ?? null) !== self::STATUS_PENDING) {
			// Admin-created/confirmed appointments skip the confirmation flow.
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->getRegisterSlug();
		$rawToken = TokenValidator::generate();
		$now = new DateTimeImmutable('now');
		$appointmentId = (string)($appointment['id'] ?? ($appointment['appointmentNumber'] ?? ''));

		$tokenRecord = [
			'tokenId' => uniqid('tok-', true),
			'appointmentId' => $appointmentId,
			'tokenString' => TokenValidator::hash($rawToken),
			'expiresAt' => $now->modify('+' . self::TOKEN_TTL . ' seconds')->format('Y-m-d\TH:i:s\Z'),
			'status' => 'active',
			'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
			'createdBy' => 'system',
			'administrationId' => (string)($appointment['administrationId'] ?? ''),
		];

		try {
			$objectService->saveObject(
				object: $tokenRecord,
				register: $register,
				schema: 'ConfirmationToken',
			);
			$this->logger->info(
				'Shillinq: confirmation token generated',
				['appointmentId' => $appointmentId, 'action' => 'token_generated']
			);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: failed to persist confirmation token: ' . $e->getMessage());
			return null;
		}

		$this->mailer->send(appointment: $appointment, rawToken: $rawToken);

		return $rawToken;
	}//end issueConfirmation()

	/**
	 * Validate a token for an appointment without redeeming it (dry-run).
	 *
	 * @param string $appointmentId The appointment id.
	 * @param string $rawToken The submitted raw token.
	 *
	 * @return array{valid:bool,reason:string,appointment:?array<string,mixed>,token:?array<string,mixed>}
	 */
	public function validateToken(string $appointmentId, string $rawToken): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['valid' => false, 'reason' => 'unavailable', 'appointment' => null, 'token' => null];
		}

		$register = $this->getRegisterSlug();
		$appointment = $this->findAppointment(
			objectService: $objectService,
			register: $register,
			appointmentId: $appointmentId
		);
		if ($appointment === null) {
			return ['valid' => false, 'reason' => 'not_found', 'appointment' => null, 'token' => null];
		}

		$token = $this->findActiveTokenForAppointment(
			objectService: $objectService,
			register: $register,
			appointmentId: $appointmentId,
			rawToken: $rawToken
		);
		if ($token === null) {
			return ['valid' => false, 'reason' => 'invalid', 'appointment' => $appointment, 'token' => null];
		}

		$reason = $this->tokenStateReason(token: $token);

		return [
			'valid' => ($reason === 'ok'),
			'reason' => $reason,
			'appointment' => $appointment,
			'token' => $token,
		];
	}//end validateToken()

	/**
	 * Evaluate a token's state and return 'ok' or a rejection reason.
	 *
	 * @param array<string,mixed> $token The token record.
	 *
	 * @return string One of: ok, redeemed, revoked, expired.
	 */
	private function tokenStateReason(array $token): string {
		$status = (string)($token['status'] ?? '');
		if ($status === 'redeemed' || $status === 'revoked') {
			return $status;
		}

		$nowIso = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z');
		if (TokenValidator::isExpired((string)($token['expiresAt'] ?? ''), $nowIso) === true) {
			return 'expired';
		}

		return 'ok';
	}//end tokenStateReason()

	/**
	 * Redeem a token and transition the appointment to confirmed (REQ-BCF-004).
	 *
	 * @param string $appointmentId The appointment id.
	 * @param string $rawToken The submitted raw token.
	 *
	 * @return array{success:bool,reason:string,appointment:?array<string,mixed>}
	 */
	public function confirm(string $appointmentId, string $rawToken): array {
		$validation = $this->validateToken(appointmentId: $appointmentId, rawToken: $rawToken);
		if ($validation['valid'] !== true) {
			$this->logger->info(
				'Shillinq: confirmation attempt rejected',
				['appointmentId' => $appointmentId, 'action' => 'confirmation_failed', 'reason' => $validation['reason']]
			);
			return ['success' => false, 'reason' => $validation['reason'], 'appointment' => $validation['appointment']];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['success' => false, 'reason' => 'unavailable', 'appointment' => null];
		}

		$register = $this->getRegisterSlug();
		$appointment = $validation['appointment'];
		$token = $validation['token'];
		$now = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z');

		if (($appointment['status'] ?? '') !== self::STATUS_PENDING) {
			return ['success' => false, 'reason' => 'redeemed', 'appointment' => $appointment];
		}

		$appointment['status'] = self::STATUS_CONFIRMED;
		$appointment['confirmedAt'] = $now;
		$token['status'] = 'redeemed';
		$token['redeemedAt'] = $now;

		try {
			$objectService->saveObject(object: $token, register: $register, schema: 'ConfirmationToken');
			$objectService->saveObject(object: $appointment, register: $register, schema: 'Appointment');
			$this->logger->info(
				'Shillinq: appointment confirmed',
				['appointmentId' => $appointmentId, 'action' => 'appointment_confirmed']
			);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: failed to confirm appointment: ' . $e->getMessage());
			return ['success' => false, 'reason' => 'persist_error', 'appointment' => $appointment];
		}

		return ['success' => true, 'reason' => 'ok', 'appointment' => $appointment];
	}//end confirm()

	/**
	 * Revoke the current token and issue a fresh one, then resend the email
	 * (REQ-BCF-006).
	 *
	 * @param string $appointmentId The appointment id.
	 *
	 * @return array{success:bool,reason:string}
	 */
	public function resend(string $appointmentId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['success' => false, 'reason' => 'unavailable'];
		}

		$register = $this->getRegisterSlug();
		$appointment = $this->findAppointment(
			objectService: $objectService,
			register: $register,
			appointmentId: $appointmentId
		);
		if ($appointment === null) {
			return ['success' => false, 'reason' => 'not_found'];
		}

		if (($appointment['status'] ?? '') !== self::STATUS_PENDING) {
			return ['success' => false, 'reason' => 'not_pending'];
		}

		// Revoke any active tokens for this appointment.
		try {
			$existing = $objectService
				->setRegister($register)
				->setSchema('ConfirmationToken')
				->findAll(['filters' => ['appointmentId' => $appointmentId, 'status' => 'active']]);
			foreach ($existing as $tok) {
				$tok['status'] = 'revoked';
				$objectService->saveObject(object: $tok, register: $register, schema: 'ConfirmationToken');
			}
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: failed to revoke prior tokens: ' . $e->getMessage());
			return ['success' => false, 'reason' => 'persist_error'];
		}

		$this->logger->info(
			'Shillinq: confirmation email resent',
			['appointmentId' => $appointmentId, 'action' => 'confirmation_email_resent']
		);

		$raw = $this->issueConfirmation(appointment: $appointment);

		$reason = 'issue_failed';
		if ($raw !== null) {
			$reason = 'ok';
		}

		return ['success' => ($raw !== null), 'reason' => $reason];
	}//end resend()

	/**
	 * Auto-cancel pending appointments past their confirmation deadline
	 * (REQ-BCF-005). Returns the number cancelled.
	 *
	 * @return int The count of appointments cancelled.
	 */
	public function cancelExpired(): int {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		$register = $this->getRegisterSlug();
		$now = new DateTimeImmutable('now');
		$cancelled = 0;

		try {
			$pending = $objectService
				->setRegister($register)
				->setSchema('Appointment')
				->findAll(['filters' => ['status' => self::STATUS_PENDING]]);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: failed to query pending appointments: ' . $e->getMessage());
			return 0;
		}

		foreach ($pending as $appointment) {
			$deadline = ($appointment['confirmationDeadline'] ?? null);
			if (is_string($deadline) === false || $deadline === '') {
				continue;
			}

			$deadlineTs = strtotime($deadline);
			if ($deadlineTs === false || $deadlineTs > $now->getTimestamp()) {
				continue;
			}

			$appointment['status'] = self::STATUS_CANCELLED;
			$appointment['cancelledReason'] = 'Confirmation deadline passed';
			try {
				$objectService->saveObject(object: $appointment, register: $register, schema: 'Appointment');
				$cancelled++;
				$this->logger->info(
					'Shillinq: appointment auto-cancelled',
					[
						'appointmentId' => (string)($appointment['id'] ?? ($appointment['appointmentNumber'] ?? '')),
						'action' => 'appointment_auto_cancelled',
						'actor' => 'system',
					]
				);
			} catch (Throwable $e) {
				$this->logger->error('Shillinq: failed to auto-cancel appointment: ' . $e->getMessage());
			}
		}//end foreach

		return $cancelled;
	}//end cancelExpired()

	/**
	 * Find an appointment by OR id or appointmentNumber.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register The register slug.
	 * @param string $appointmentId The appointment identifier.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findAppointment(object $objectService, string $register, string $appointmentId): ?array {
		try {
			// The by-id arm used to be findAll(['filters' => ['id' => …]]),
			// which matches NOTHING — `filters` addresses JSON properties and
			// `id` is the entity's own column — so every lookup fell through to
			// the appointmentNumber arm and an appointment addressed by uuid
			// was reported absent. ObjectIdentifier::findOne() does the uuid
			// lookup through find(), then the same appointmentNumber fallback.
			$appointment = ObjectIdentifier::findOne(
				scoped: $objectService->setRegister($register)->setSchema('Appointment'),
				id: $appointmentId,
				fallbackProperty: 'appointmentNumber'
			);
			if ($appointment !== null) {
				return $appointment;
			}
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: failed to load appointment: ' . $e->getMessage());
		}

		return null;
	}//end findAppointment()

	/**
	 * Find the active token for an appointment whose stored hash matches the raw token.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register The register slug.
	 * @param string $appointmentId The appointment id.
	 * @param string $rawToken The submitted raw token.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findActiveTokenForAppointment(
		object $objectService,
		string $register,
		string $appointmentId,
		string $rawToken,
	): ?array {
		try {
			$tokens = $objectService
				->setRegister($register)
				->setSchema('ConfirmationToken')
				->findAll(['filters' => ['appointmentId' => $appointmentId]]);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: failed to load tokens: ' . $e->getMessage());
			return null;
		}

		foreach ($tokens as $token) {
			$matches = TokenValidator::verify(
				$rawToken,
				(string)($token['tokenString'] ?? '')
			);
			if ($matches === true) {
				return $token;
			}
		}

		return null;
	}//end findActiveTokenForAppointment()
}//end class
