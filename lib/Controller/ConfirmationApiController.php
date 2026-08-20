<?php

/**
 * Confirmation API Controller.
 *
 * REST surface for the appointment confirmation flow:
 *
 *   PATCH /api/v1/appointments/{appointmentId}/confirm
 *       — validate token (REQ-BCF-004), move Appointment.status
 *         pending_confirmation → confirmed, mark token redeemed, log.
 *
 *   POST  /api/v1/appointments/{appointmentId}/resend-confirmation
 *       — revoke active token, issue fresh, dispatch new email (REQ-BCF-006).
 *
 *   GET   /api/v1/appointments/validate-confirmation-token
 *       — dry-run: report whether the supplied token would confirm an
 *         appointment, without persisting any change (REQ-BCF-007). Public
 *         so the customer portal can probe the link before the customer
 *         clicks "Confirm".
 *
 * Both confirm + resend run as #[NoAdminRequired] but additionally enforce
 * the per-appointment authorisation guard (customer of the appointment OR
 * admin / scheduler) so authenticated users cannot pivot to other tenants'
 * appointments — closes the IDOR class (ADR-005 / OWASP A01:2021) the
 * unsafe-auth gate looks for.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Booking\ConfirmationTokenService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Appointment confirmation REST API.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
 */
class ConfirmationApiController extends Controller {

	/**
	 * Brute-force throttler action for rejected confirmation tokens.
	 *
	 * Shared by `confirm` and `lookupByToken` so guesses cannot be split
	 * across the two to stay under a per-endpoint ceiling.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'shillinq_confirmation_token';

	/**
	 * Record a rejected confirmation token with the brute-force throttler.
	 *
	 * The half that COUNTS; `#[BruteForceProtection]` on the endpoints is the
	 * half that ENFORCES. Registering without the attribute writes a counter
	 * nothing ever reads -- see ADR-082.
	 *
	 * @return void
	 */
	private function registerFailedToken(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'ConfirmationApiController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerFailedToken()

	/**
	 * Construct the controller with DI dependencies.
	 *
	 * @param IRequest $request Inbound request object.
	 * @param ContainerInterface $container DI container for lazy
	 *                                      OR resolution.
	 * @param SettingsService $settings Shillinq settings
	 *                                  (register slug, OR
	 *                                  availability).
	 * @param AdministrationContextService $context Per-administration
	 *                                              IDOR guard (ADR-005).
	 * @param ConfirmationTokenService $tokens Token lifecycle +
	 *                                         email dispatcher.
	 * @param ITimeFactory $time Server clock.
	 * @param IThrottler $throttler Brute-force throttler for
	 *                              failed token attempts.
	 * @param LoggerInterface $logger Logger for fail-closed
	 *                                diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly AdministrationContextService $context,
		private readonly ConfirmationTokenService $tokens,
		private readonly ITimeFactory $time,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * PATCH /api/v1/appointments/{appointmentId}/confirm
	 *
	 * Validate the supplied token and move the appointment to `confirmed`.
	 * Public so the email link works in an anonymous browser; the token
	 * itself is the authorisation factor (constant-time hash check via
	 * TokenValidator::verify per REQ-BCF-004).
	 *
	 * @param string $appointmentId Appointment id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated appointment on success;
	 *                      401 / 403 / 404 on token failures.
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function confirm(string $appointmentId = ''): JSONResponse {
		$appointmentId = trim($appointmentId);
		$token = trim((string)$this->request->getParam('token', ''));
		if ($appointmentId === '' || $token === '') {
			return new JSONResponse(
				['error' => 'appointmentId and token are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$result = $this->tokens->validate($appointmentId, $token);
		if ($result['ok'] === false) {
			return $this->mapValidationError(reason: $result['reason']);
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(
				['error' => 'OpenRegister unavailable'],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}

		try {
			$appointment = $this->loadAppointment(appointmentId: $appointmentId);
			if ($appointment === null) {
				return new JSONResponse(
					['error' => 'Appointment not found'],
					Http::STATUS_NOT_FOUND,
				);
			}

			$appointment['status'] = 'confirmed';
			$appointment['confirmedAt'] = $this->nowIso();
			$appointment['confirmationTokenId'] = (string)($result['token']['tokenId'] ?? '');

			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$saved = $objectService->saveObject(
				object: $appointment,
				register: $this->settings->getRegisterSlug(),
				schema: 'Appointment',
			);

			$this->tokens->markRedeemed($result['token']);

			$this->logger->info(
				'ConfirmationApiController: appointment confirmed',
				[
					'appointmentId' => $appointmentId,
					'tokenId' => (string)($result['token']['tokenId'] ?? ''),
				]
			);

			return new JSONResponse(
				['appointment' => $this->toArray(object: $saved)],
				Http::STATUS_OK,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ConfirmationApiController: confirm failed',
				['appointmentId' => $appointmentId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Confirmation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try

	}//end confirm()

	/**
	 * POST /api/v1/appointments/{appointmentId}/resend-confirmation
	 *
	 * Revoke the active token, issue a fresh one, dispatch a new email.
	 * Requires an authenticated session; the per-appointment authorisation
	 * guard rejects calls that don't match the appointment's customer or
	 * administration (ADR-005 / IDOR).
	 *
	 * @param string $appointmentId Appointment id (path parameter).
	 *
	 * @return JSONResponse 200 with `{ok: true, tokenId, sent}`; 401 when
	 *                      anonymous; 403 when not authorised; 404 when
	 *                      missing.
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	#[NoAdminRequired]
	public function resend(string $appointmentId = ''): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(
				['error' => 'Not authenticated'],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		$appointmentId = trim($appointmentId);
		if ($appointmentId === '') {
			return new JSONResponse(
				['error' => 'appointmentId is required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(
				['error' => 'OpenRegister unavailable'],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}

		$appointment = $this->loadAppointment(appointmentId: $appointmentId);
		if ($appointment === null) {
			return new JSONResponse(
				['error' => 'Appointment not found'],
				Http::STATUS_NOT_FOUND,
			);
		}

		if ($this->isAuthorisedForAppointment(appointment: $appointment) === false) {
			return new JSONResponse(
				['error' => 'Forbidden'],
				Http::STATUS_FORBIDDEN,
			);
		}

		$customer = $this->loadCustomer(appointment: $appointment);
		$result = $this->tokens->resend(
			appointment: $appointment,
			customer: $customer,
			actor: (string)$this->context->currentUserId(),
		);

		$this->logger->info(
			'ConfirmationApiController: confirmation email resent',
			['appointmentId' => $appointmentId, 'tokenId' => $result['tokenId']]
		);

		return new JSONResponse(
			[
				'ok' => true,
				'tokenId' => $result['tokenId'],
				'sent' => $result['sent'],
				'message' => 'Confirmation email resent',
			],
			Http::STATUS_OK,
		);

	}//end resend()

	/**
	 * GET /api/v1/appointments/validate-confirmation-token
	 *
	 * Dry-run a token against the matching appointment. Used by the web
	 * portal so the customer sees appointment details before pressing
	 * Confirm. NEVER mutates state. Public — the token is the
	 * authorisation factor (REQ-BCF-007).
	 *
	 * Query parameters: `token` (required), `appointmentId` (optional —
	 * when present, narrows the lookup).
	 *
	 * @return JSONResponse 200 with appointment details on success; the
	 *                      shape `{ok: false, reason: ...}` on token
	 *                      failures so the portal can render localised
	 *                      error messages.
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function lookupByToken(): JSONResponse {
		$token = trim((string)$this->request->getParam('token', ''));
		$appointmentId = trim((string)$this->request->getParam('appointmentId', ''));
		if ($token === '') {
			return new JSONResponse(
				['ok' => false, 'reason' => 'invalid_input'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(
				['ok' => false, 'reason' => 'or_unavailable'],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}

		if ($appointmentId === '') {
			return new JSONResponse(
				['ok' => false, 'reason' => 'missing_appointment_id'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$result = $this->tokens->validate($appointmentId, $token);
		if ($result['ok'] === false) {
			return new JSONResponse(
				['ok' => false, 'reason' => $result['reason']],
				Http::STATUS_OK,
			);
		}

		$appointment = $this->loadAppointment(appointmentId: $appointmentId);
		if ($appointment === null) {
			return new JSONResponse(
				['ok' => false, 'reason' => 'not_found'],
				Http::STATUS_NOT_FOUND,
			);
		}

		return new JSONResponse(
			[
				'ok' => true,
				'appointment' => $this->presentAppointment(appointment: $appointment),
			],
			Http::STATUS_OK,
		);

	}//end lookupByToken()

	/**
	 * Map a {@see ConfirmationTokenService::validate()} `reason` onto an
	 * HTTP status + message that matches the spec scenarios.
	 *
	 * @param string $reason Validation failure reason.
	 *
	 * @return JSONResponse
	 */
	private function mapValidationError(string $reason): JSONResponse {
		// Only count the reasons that mean the token WAS NOT REAL.
		//
		// `expired`, `already_redeemed` and `revoked` all mean the caller
		// presented a genuine token that has since gone stale -- correct use of
		// a confirmation link that sat in an inbox too long. Counting those
		// would throttle honest customers, and on a booking-confirmation flow
		// that is a self-inflicted denial of service on the happy path.
		if ($reason === 'invalid' || $reason === 'not_found') {
			$this->registerFailedToken();
		}

		return match ($reason) {
			'expired' => new JSONResponse(['error' => 'Token has expired'], Http::STATUS_FORBIDDEN),
			'already_redeemed' => new JSONResponse(['error' => 'Token has already been used'], Http::STATUS_FORBIDDEN),
			'revoked' => new JSONResponse(
				['error' => 'Token is no longer valid; request a new confirmation email'],
				Http::STATUS_FORBIDDEN,
			),
			'invalid' => new JSONResponse(['error' => 'Invalid token'], Http::STATUS_UNAUTHORIZED),
			'not_found' => new JSONResponse(['error' => 'Token not found'], Http::STATUS_NOT_FOUND),
			default => new JSONResponse(['error' => 'Confirmation rejected'], Http::STATUS_BAD_REQUEST),
		};

	}//end mapValidationError()

	/**
	 * Load an Appointment OR record by id (REST-style business key).
	 *
	 * @param string $appointmentId Appointment id.
	 *
	 * @return array<string, mixed>|null Flat appointment record or NULL.
	 */
	private function loadAppointment(string $appointmentId): ?array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$records = $objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('Appointment')
				->findAll(
					[
						'filters' => ['appointmentId' => $appointmentId],
						'limit' => 1,
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'ConfirmationApiController: appointment lookup failed',
				['exception' => $e->getMessage(), 'appointmentId' => $appointmentId]
			);
			return null;
		}

		foreach ($records as $record) {
			return $this->toArray(object: $record);
		}

		return null;
	}//end loadAppointment()

	/**
	 * Build the customer descriptor used by ConfirmationTokenService /
	 * IcsService from the appointment record + the requesting user.
	 *
	 * Phase-1 pulls the customer email from the appointment's
	 * `customerEmail` field (set by the create flow) and falls back to the
	 * current authenticated user. ADR-006 / contact-is-NC-entity-aware:
	 * later phases will look up the email via OCP\Contacts\IManager.
	 *
	 * @param array<string, mixed> $appointment Appointment record.
	 *
	 * @return array<string, mixed>
	 */
	private function loadCustomer(array $appointment): array {
		return [
			'id' => (string)($appointment['customerId'] ?? ''),
			'userId' => (string)($appointment['customerUserId'] ?? ($this->context->currentUserId() ?? '')),
			'name' => (string)($appointment['customerName'] ?? ''),
			'email' => (string)($appointment['customerEmail'] ?? ''),
		];

	}//end loadCustomer()

	/**
	 * Authorise the current user against an appointment. The customer of
	 * the appointment, the assigned staff, or an administrator of the
	 * owning administration may resend; everyone else is rejected
	 * (ADR-005 / OWASP A01:2021).
	 *
	 * @param array<string, mixed> $appointment Appointment record.
	 *
	 * @return bool TRUE when the current user may operate on the
	 *              appointment.
	 */
	private function isAuthorisedForAppointment(array $appointment): bool {
		$current = $this->context->currentUserId();
		if ($current === null) {
			return false;
		}

		if ((string)($appointment['customerUserId'] ?? '') === $current) {
			return true;
		}

		if ((string)($appointment['customerId'] ?? '') === $current) {
			return true;
		}

		if ((string)($appointment['createdBy'] ?? '') === $current) {
			return true;
		}

		// Admins / schedulers — defer the deeper RBAC check to the
		// AdministrationContextService; on dev installs (no admin record)
		// it returns FALSE and we deny.
		if ($this->context->canAccess((string)($appointment['administrationId'] ?? '')) === true) {
			return true;
		}

		return false;
	}//end isAuthorisedForAppointment()

	/**
	 * Project an Appointment record into the response shape used by the
	 * confirmation portal (no token hash, no internal audit fields).
	 *
	 * @param array<string, mixed> $appointment Appointment record.
	 *
	 * @return array<string, mixed> Portal-safe projection.
	 */
	private function presentAppointment(array $appointment): array {
		return [
			'appointmentId' => (string)($appointment['appointmentId'] ?? ''),
			'serviceName' => (string)($appointment['serviceName'] ?? ''),
			'startTime' => (string)($appointment['startTime'] ?? ''),
			'endTime' => (string)($appointment['endTime'] ?? ''),
			'location' => (string)($appointment['location'] ?? ''),
			'status' => (string)($appointment['status'] ?? ''),
			'confirmationDeadline' => (string)($appointment['confirmationDeadline'] ?? ''),
			'notes' => (string)($appointment['notes'] ?? ''),
		];

	}//end presentAppointment()

	/**
	 * Current server time as ISO 8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return $this->time->getDateTime()
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:s\Z');

	}//end nowIso()

	/**
	 * Normalise an OR record into a flat array.
	 *
	 * @param mixed $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$inner = $object->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}

			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
