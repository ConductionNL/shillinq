<?php

/**
 * Confirmation API Controller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeZone;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Booking\ConfirmationTokenService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
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
use RuntimeException;
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
	 * @param RegisterLocation $registerLocation Where the register lives (slug + OpenRegister availability).
	 *                                  (register slug, OR
	 *                                  availability).
	 * @param AdministrationContextService $context Per-administration
	 *                                              IDOR guard (ADR-005).
	 * @param ConfirmationTokenService $tokens Token lifecycle +
	 *                                         email dispatcher.
	 * @param ITimeFactory $time Server clock.
	 * @param IThrottler $throttler Brute-force throttler for
	 *                              failed token attempts.
	 * @param ObjectTransitionRunner $transitions Runs the declared
	 *                                            confirm transition.
	 * @param LoggerInterface $logger Logger for fail-closed
	 *                                diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly RegisterLocation $registerLocation,
		private readonly AdministrationContextService $context,
		private readonly ConfirmationTokenService $tokens,
		private readonly ITimeFactory $time,
		private readonly IThrottler $throttler,
		private readonly ObjectTransitionRunner $transitions,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
	 * The move runs as the declared `confirmViaToken` transition, as the
	 * system: the anonymous caller holds no right on the object, the redeemed
	 * token is the check. The answer is the portal view only.
	 *
	 * @return JSONResponse 200 with the portal view of the appointment;
	 *                      401 / 403 / 404 on token failures; 409 when the
	 *                      appointment cannot be confirmed from its state.
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
			return $this->errorResponse(error: 'appointmentId and token are required', status: Http::STATUS_BAD_REQUEST);
		}

		$result = $this->tokens->validate($appointmentId, $token);
		if ($result['ok'] === false) {
			return $this->mapValidationError(reason: $result['reason']);
		}

		return $this->confirmValidated(confirmation: new TokenConfirmation(appointmentId: $appointmentId, token: $result['token']));

	}//end confirm()

	/**
	 * Confirm the appointment of a validated token; a refused transition is a
	 * logged 409, any other failure a logged 500.
	 *
	 * @param TokenConfirmation $confirmation The validated confirmation.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	private function confirmValidated(TokenConfirmation $confirmation): JSONResponse {
		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			return $this->errorResponse(error: 'OpenRegister unavailable', status: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			return $this->confirmedAppointment(confirmation: $confirmation);
		} catch (RuntimeException $e) {
			return $this->confirmRefused(confirmation: $confirmation, e: $e);
		} catch (Throwable $e) {
			return $this->confirmFailed(confirmation: $confirmation, e: $e);
		}//end try

	}//end confirmValidated()

	/**
	 * Load the appointment (404 when missing) and redeem the token on it.
	 *
	 * @param TokenConfirmation $confirmation The validated confirmation.
	 *
	 * @return JSONResponse
	 */
	private function confirmedAppointment(TokenConfirmation $confirmation): JSONResponse {
		$appointment = $this->loadAppointment(appointmentId: $confirmation->appointmentId);
		if ($appointment === null) {
			return $this->errorResponse(error: 'Appointment not found', status: Http::STATUS_NOT_FOUND);
		}

		$saved = $this->confirmTransition(confirmation: $confirmation, appointment: $appointment);
		$this->tokens->markRedeemed($confirmation->token);
		$this->logConfirmed(confirmation: $confirmation);

		return new JSONResponse(
			['appointment' => $this->presentAppointment(appointment: $this->toArray(object: $saved))],
			Http::STATUS_OK,
		);
	}//end confirmedAppointment()

	/**
	 * Log a confirmed appointment with its token.
	 *
	 * @param TokenConfirmation $confirmation The confirmation.
	 *
	 * @return void
	 */
	private function logConfirmed(TokenConfirmation $confirmation): void {
		$this->logger->info(
			'ConfirmationApiController: appointment confirmed',
			[
				'appointmentId' => $confirmation->appointmentId,
				'tokenId' => $confirmation->tokenId(),
			]
		);
	}//end logConfirmed()

	/**
	 * Run the confirmViaToken transition as system, stamped with the time and the token.
	 *
	 * @param TokenConfirmation    $confirmation The validated confirmation.
	 * @param array<string, mixed> $appointment  The appointment.
	 *
	 * @return mixed The saved appointment.
	 */
	private function confirmTransition(TokenConfirmation $confirmation, array $appointment): mixed {
		return $this->transitions->runAsSystem(
			objectId: $this->objectUuid(appointment: $appointment),
			action: 'confirmViaToken',
			data: [
				'confirmedAt' => $this->nowIso(),
				'confirmationTokenId' => $confirmation->tokenId(),
			],
		);
	}//end confirmTransition()

	/**
	 * Log a refused confirmation and answer 409.
	 *
	 * @param TokenConfirmation $confirmation The confirmation.
	 * @param RuntimeException  $e            The refusal.
	 *
	 * @return JSONResponse
	 */
	private function confirmRefused(TokenConfirmation $confirmation, RuntimeException $e): JSONResponse {
		$this->logger->warning(
			'ConfirmationApiController: confirm transition refused',
			['appointmentId' => $confirmation->appointmentId, 'exception' => $e->getMessage()]
		);
		return $this->errorResponse(error: 'This appointment can no longer be confirmed', status: Http::STATUS_CONFLICT);
	}//end confirmRefused()

	/**
	 * Log a failed confirmation and answer 500.
	 *
	 * @param TokenConfirmation $confirmation The confirmation.
	 * @param Throwable         $e            The failure.
	 *
	 * @return JSONResponse
	 */
	private function confirmFailed(TokenConfirmation $confirmation, Throwable $e): JSONResponse {
		$this->logger->error(
			'ConfirmationApiController: confirm failed',
			['appointmentId' => $confirmation->appointmentId, 'exception' => $e->getMessage()]
		);
		return $this->errorResponse(error: 'Confirmation failed', status: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end confirmFailed()

	/**
	 * An `{error}` answer.
	 *
	 * @param string $error  The error.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function errorResponse(string $error, int $status): JSONResponse {
		return new JSONResponse(['error' => $error], $status);
	}//end errorResponse()

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
		$appointmentId = trim($appointmentId);
		$refusal = $this->resendRefusal(appointmentId: $appointmentId);
		if ($refusal !== null) {
			return $refusal;
		}

		$appointment = $this->loadAppointment(appointmentId: $appointmentId);
		if ($appointment === null) {
			return $this->errorResponse(error: 'Appointment not found', status: Http::STATUS_NOT_FOUND);
		}

		if ($this->isAuthorisedForAppointment(appointment: $appointment) === false) {
			return $this->errorResponse(error: 'Forbidden', status: Http::STATUS_FORBIDDEN);
		}

		return $this->resent(appointmentId: $appointmentId, appointment: $appointment);

	}//end resend()

	/**
	 * The refusal before an appointment is loaded: 401 anonymous, 400 without
	 * an appointment, 503 without OpenRegister — in that order.
	 *
	 * @param string $appointmentId The trimmed appointment id.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function resendRefusal(string $appointmentId): ?JSONResponse {
		if ($this->context->currentUserId() === null) {
			return $this->errorResponse(error: 'Not authenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($appointmentId === '') {
			return $this->errorResponse(error: 'appointmentId is required', status: Http::STATUS_BAD_REQUEST);
		}

		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			return $this->errorResponse(error: 'OpenRegister unavailable', status: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return null;
	}//end resendRefusal()

	/**
	 * Issue a fresh token and mail it to the customer, as the current user.
	 *
	 * @param string               $appointmentId The appointment id.
	 * @param array<string, mixed> $appointment   The authorised appointment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	private function resent(string $appointmentId, array $appointment): JSONResponse {
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

		return $this->resentResponse(result: $result);
	}//end resent()

	/**
	 * The answer to a resend.
	 *
	 * @param array<string, mixed> $result The token service's resend result.
	 *
	 * @return JSONResponse
	 */
	private function resentResponse(array $result): JSONResponse {
		return new JSONResponse(
			[
				'ok' => true,
				'tokenId' => $result['tokenId'],
				'sent' => $result['sent'],
				'message' => 'Confirmation email resent',
			],
			Http::STATUS_OK,
		);
	}//end resentResponse()

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
		$refusal = $this->lookupRefusal(token: $token, appointmentId: $appointmentId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->lookedUp(token: $token, appointmentId: $appointmentId);

	}//end lookupByToken()

	/**
	 * Validate the token and present its appointment: a refused token answers
	 * 200 with its reason, an unknown appointment 404.
	 *
	 * @param string $token         The token.
	 * @param string $appointmentId The appointment id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	private function lookedUp(string $token, string $appointmentId): JSONResponse {
		$result = $this->tokens->validate($appointmentId, $token);
		if ($result['ok'] === false) {
			return $this->lookupAnswer(reason: $result['reason'], status: Http::STATUS_OK);
		}

		$appointment = $this->loadAppointment(appointmentId: $appointmentId);
		if ($appointment === null) {
			return $this->lookupAnswer(reason: 'not_found', status: Http::STATUS_NOT_FOUND);
		}

		return $this->foundAppointment(appointment: $appointment);
	}//end lookedUp()

	/**
	 * The lookup answer presenting the appointment's portal view.
	 *
	 * @param array<string, mixed> $appointment The appointment.
	 *
	 * @return JSONResponse
	 */
	private function foundAppointment(array $appointment): JSONResponse {
		return new JSONResponse(
			[
				'ok' => true,
				'appointment' => $this->presentAppointment(appointment: $appointment),
			],
			Http::STATUS_OK,
		);
	}//end foundAppointment()

	/**
	 * The refusal before the token is validated: 400 without a token, 503
	 * without OpenRegister, 400 without an appointment — in that order.
	 *
	 * @param string $token         The trimmed token.
	 * @param string $appointmentId The trimmed appointment id.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function lookupRefusal(string $token, string $appointmentId): ?JSONResponse {
		if ($token === '') {
			return $this->lookupAnswer(reason: 'invalid_input', status: Http::STATUS_BAD_REQUEST);
		}

		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			return $this->lookupAnswer(reason: 'or_unavailable', status: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($appointmentId === '') {
			return $this->lookupAnswer(reason: 'missing_appointment_id', status: Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end lookupRefusal()

	/**
	 * An `{ok: false, reason}` lookup answer.
	 *
	 * @param string $reason The reason.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function lookupAnswer(string $reason, int $status): JSONResponse {
		return new JSONResponse(['ok' => false, 'reason' => $reason], $status);
	}//end lookupAnswer()

	/**
	 * Map a {@see ConfirmationTokenService::validate()} `reason` onto an
	 * HTTP status + message that matches the spec scenarios.
	 *
	 * @param string $reason Validation failure reason.
	 *
	 * @return JSONResponse
	 */
	private function mapValidationError(string $reason): JSONResponse {
		$this->countFailedToken(reason: $reason);

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
	 * Register a brute-force attempt for a token that was never real.
	 *
	 * Only count the reasons that mean the token WAS NOT REAL.
	 *
	 * `expired`, `already_redeemed` and `revoked` all mean the caller
	 * presented a genuine token that has since gone stale -- correct use of
	 * a confirmation link that sat in an inbox too long. Counting those
	 * would throttle honest customers, and on a booking-confirmation flow
	 * that is a self-inflicted denial of service on the happy path.
	 *
	 * @param string $reason The validation failure reason.
	 *
	 * @return void
	 */
	private function countFailedToken(string $reason): void {
		if ($reason === 'invalid' || $reason === 'not_found') {
			$this->registerFailedToken();
		}
	}//end countFailedToken()

	/**
	 * Load an Appointment OR record by id (REST-style business key).
	 *
	 * @param string $appointmentId Appointment id.
	 *
	 * @return array<string, mixed>|null Flat appointment record or NULL.
	 */
	private function loadAppointment(string $appointmentId): ?array {
		try {
			$records = $this->appointmentRecords(appointmentId: $appointmentId);
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
	 * The Appointment records with the id, read without RBAC (the token, not
	 * a session, authorises a confirmation).
	 *
	 * @param string $appointmentId The appointment id.
	 *
	 * @return iterable<mixed>
	 */
	private function appointmentRecords(string $appointmentId): iterable {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		return $objectService
			->setRegister($this->registerLocation->getRegisterSlug())
			->setSchema('Appointment')
			->findAll(
				config: [
					'filters' => ['appointmentId' => $appointmentId],
					'limit' => 1,
				],
				_rbac: false,
			);
	}//end appointmentRecords()

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

		if ($this->isOwnAppointment(appointment: $appointment, userId: $current) === true) {
			return true;
		}

		// Admins / schedulers — defer the deeper RBAC check to the
		// AdministrationContextService; on dev installs (no admin record)
		// it returns FALSE and we deny.
		return $this->context->canAccess((string)($appointment['administrationId'] ?? '')) === true;
	}//end isAuthorisedForAppointment()

	/**
	 * Whether the user is the appointment's customer user, its customer, or its creator.
	 *
	 * @param array<string, mixed> $appointment The appointment.
	 * @param string               $userId      The current user.
	 *
	 * @return bool
	 */
	private function isOwnAppointment(array $appointment, string $userId): bool {
		if ((string)($appointment['customerUserId'] ?? '') === $userId) {
			return true;
		}

		if ((string)($appointment['customerId'] ?? '') === $userId) {
			return true;
		}

		return (string)($appointment['createdBy'] ?? '') === $userId;
	}//end isOwnAppointment()

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
	 * The OpenRegister uuid of an appointment record.
	 *
	 * @param array<string, mixed> $appointment Appointment record.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the record carries no uuid.
	 */
	private function objectUuid(array $appointment): string {
		$uuid = (string)($appointment['@self']['id'] ?? ($appointment['id'] ?? ''));
		if ($uuid === '') {
			throw new RuntimeException('Appointment record carries no uuid');
		}

		return $uuid;

	}//end objectUuid()

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
			return $this->objectToArray(object: $object);
		}

		return [];
	}//end toArray()

	/**
	 * An entity's data: its serialisation, else its getObject(), else its public properties.
	 *
	 * @param object $object The entity.
	 *
	 * @return array<string, mixed>
	 */
	private function objectToArray(object $object): array {
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
	}//end objectToArray()
}//end class
