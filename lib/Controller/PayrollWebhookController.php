<?php

/**
 * Shillinq Payroll Webhook Controller
 *
 * Inbound webhook receiver for external payroll software (REQ-PAY-009).
 * Accepts CloudEvents-formatted POSTs from systems such as Nmbrs or
 * SalaryBox that have computed Deduction line items, and reflects them onto
 * the matching Payroll record. The endpoint is public (no Nextcloud session)
 * but is NOT open: every request MUST carry a valid HMAC-SHA256 signature
 * derived from a shared secret stored in app config (ADR-005). Requests
 * without a configured secret, without a signature, or with a mismatching
 * signature are rejected before any object is touched. Processing is
 * idempotent on the CloudEvent id so a redelivered event creates no
 * duplicate Deduction records.
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
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Receives signed external-payroll CloudEvents and reflects deductions onto Payroll.
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-001
 */
class PayrollWebhookController extends Controller {
	/**
	 * App config key holding the shared HMAC secret for the webhook (ADR-005).
	 */
	private const SECRET_KEY = 'payroll_webhook_secret';

	/**
	 * Request header carrying the hex HMAC-SHA256 signature of the raw body.
	 */
	private const SIGNATURE_HEADER = 'X-Shillinq-Signature';

	/**
	 * Construct the webhook controller.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig App config (shared secret + register slug).
	 * @param LoggerInterface $logger Logger for audit and fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Reject GET on the webhook endpoint with 501 Not Implemented (REQ-PAY-009).
	 *
	 * @return JSONResponse 501 — the webhook accepts POST only.
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 * Rate limit: payroll-provider integration surface — machine caller with
	 * its own credential.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function info(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Payroll webhook accepts POST with a signed CloudEvent only.'],
			statusCode: Http::STATUS_NOT_IMPLEMENTED
		);
	}//end info()

	/**
	 * Receive a signed external-payroll CloudEvent and reflect its deductions.
	 *
	 * REQ-PAY-009: validates the HMAC-SHA256 signature, then creates the
	 * Deduction records carried in the payload and transitions the Payroll to
	 * `calculated`. Idempotent on the CloudEvent id (externalReference) so a
	 * redelivered event creates no duplicates. No raw error text or stack trace
	 * is returned to the caller (ADR-005).
	 *
	 * @return JSONResponse 200 on success/idempotent replay; 400 malformed or
	 *                      bad/missing signature; 422 unknown payroll.
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function receive(): JSONResponse {
		$rawBody = (string)file_get_contents('php://input');

		if ($this->verifySignature(rawBody: $rawBody) === false) {
			// ADR-005: signature is part of the payload (not user auth), so a
			// mismatch is surfaced as 400 (malformed/untrusted payload) on this
			// #[PublicPage] route. STATUS_UNAUTHORIZED would conflict with the
			// route's public posture (gate-9 semantic-auth).
			$this->logger->warning('Shillinq payroll webhook: signature verification failed — rejected.');
			return $this->badRequest(message: 'Invalid or missing signature.');
		}

		$event = $this->decodedEvent(rawBody: $rawBody);
		if ($event instanceof JSONResponse) {
			return $event;
		}

		return $this->processed(event: $event);
	}//end receive()

	/**
	 * The decoded, complete CloudEvent, or the 400 for a malformed body or a
	 * missing id or payrollId.
	 *
	 * @param string $rawBody The verified request body.
	 *
	 * @return PayrollEvent|JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	private function decodedEvent(string $rawBody): PayrollEvent|JSONResponse {
		$event = json_decode($rawBody, true);
		if (is_array($event) === false || json_last_error() !== JSON_ERROR_NONE) {
			return $this->badRequest(message: 'Malformed request body.');
		}

		$payrollEvent = new PayrollEvent(event: $event);
		if ($payrollEvent->isComplete() === false) {
			return $this->badRequest(message: 'Missing CloudEvent id or payrollId.');
		}

		return $payrollEvent;
	}//end decodedEvent()

	/**
	 * A 400 with a message.
	 *
	 * @param string $message The message.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(
			data: ['message' => $message],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end badRequest()

	/**
	 * Apply the event: 200 with the result, 422 for an unknown payroll, or a logged 500.
	 *
	 * @param PayrollEvent $event The complete event.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	private function processed(PayrollEvent $event): JSONResponse {
		try {
			$result = $this->applyEvent(event: $event);
		} catch (\Throwable $e) {
			return $this->processingFailed(event: $event, e: $e);
		}

		return $this->appliedResponse(result: $result);
	}//end processed()

	/**
	 * Log a failed event and answer 500.
	 *
	 * @param PayrollEvent $event The event.
	 * @param \Throwable   $e     The failure.
	 *
	 * @return JSONResponse
	 */
	private function processingFailed(PayrollEvent $event, \Throwable $e): JSONResponse {
		$this->logger->error(
			'Shillinq payroll webhook: processing failed.',
			['eventId' => $event->eventId, 'exception' => $e->getMessage()]
		);
		return new JSONResponse(
			data: ['message' => 'Webhook processing failed.'],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end processingFailed()

	/**
	 * 200 with the applied result, or 422 when the payroll is unknown.
	 *
	 * @param array<string, mixed>|null $result The result (null = unknown payroll).
	 *
	 * @return JSONResponse
	 */
	private function appliedResponse(array|null $result): JSONResponse {
		if ($result === null) {
			return new JSONResponse(
				data: ['message' => 'Unknown payroll.'],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(data: $result);
	}//end appliedResponse()

	/**
	 * Verify the request's HMAC-SHA256 signature against the configured secret.
	 *
	 * Fail-closed: an unconfigured secret or a missing/short header denies the
	 * request. The comparison is constant-time (hash_equals) to resist timing
	 * attacks (ADR-005 / CWE-208).
	 *
	 * @param string $rawBody The exact raw request body that was signed.
	 *
	 * @return bool True only when a configured secret produces a matching HMAC.
	 */
	private function verifySignature(string $rawBody): bool {
		$secret = $this->appConfig->getValueString(AppIdentity::APP_ID, self::SECRET_KEY, '');
		if ($secret === '') {
			return false;
		}

		$provided = (string)$this->request->getHeader(self::SIGNATURE_HEADER);
		if ($provided === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $provided);
	}//end verifySignature()

	/**
	 * Apply a verified CloudEvent: create deductions and transition the payroll.
	 *
	 * Idempotent on the CloudEvent id — when the payroll already records this
	 * externalReference, the event is a replay and no records are created.
	 *
	 * @param PayrollEvent $event The CloudEvent: id (idempotency key), target Payroll.id and `data` envelope.
	 *
	 * @return array<string,mixed>|null Summary on success, null when the payroll is unknown.
	 */
	private function applyEvent(PayrollEvent $event): ?array {
		$run = new PayrollEventRun(register: $this->resolveRegister(), event: $event);

		$payroll = $this->findPayroll(run: $run);
		if ($payroll === null) {
			return null;
		}

		$run->payroll = $payroll;

		// Idempotency: a replay of the same CloudEvent is a no-op.
		if ($run->isReplay() === true) {
			return $this->replayedResult(run: $run);
		}

		return $this->applied(run: $run);
	}//end applyEvent()

	/**
	 * The result of a replayed event: the payroll as it stands.
	 *
	 * @param PayrollEventRun $run The run, with the payroll found.
	 *
	 * @return array<string, mixed>
	 */
	private function replayedResult(PayrollEventRun $run): array {
		return [
			'payrollId' => $run->event->payrollId,
			'status' => (string)($run->payroll['status'] ?? ''),
			'replayed' => true,
		];
	}//end replayedResult()

	/**
	 * The payroll the event is about, or null when it is unknown.
	 *
	 * NOT findAll(['filters' => ['id' => …]]) — `filters` addresses JSON
	 * properties and the entity's `id` is not one, so that shape matched
	 * nothing for every value and every inbound payroll webhook was
	 * treated as referencing an unknown Payroll.
	 *
	 * @param PayrollEventRun $run The run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findPayroll(PayrollEventRun $run): ?array {
		return ObjectIdentifier::findOne(
			scoped: $this->objectService
				->setRegister($run->register)
				->setSchema('Payroll'),
			id: $run->event->payrollId
		);
	}//end findPayroll()

	/**
	 * Write the event's deductions, mark the payroll calculated and log it.
	 *
	 * @param PayrollEventRun $run The run, with the payroll found.
	 *
	 * @return array<string, mixed> The applied result.
	 *
	 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
	 */
	private function applied(PayrollEventRun $run): array {
		$created = $this->savedDeductions(run: $run);
		$this->markCalculated(run: $run);

		$this->logger->info(
			'Shillinq payroll webhook: applied external deductions.',
			['eventId' => $run->event->eventId, 'payrollId' => $run->event->payrollId, 'deductionsCreated' => $created]
		);

		return [
			'payrollId' => $run->event->payrollId,
			'status' => 'calculated',
			'deductionsCreated' => $created,
			'replayed' => false,
		];
	}//end applied()

	/**
	 * Save one Deduction per well-formed deduction of the event.
	 *
	 * @param PayrollEventRun $run The run.
	 *
	 * @return int How many were saved.
	 */
	private function savedDeductions(PayrollEventRun $run): int {
		$created = 0;
		foreach ($run->event->deductions() as $deduction) {
			if (is_array($deduction) === false) {
				continue;
			}

			$this->objectService
				->setRegister($run->register)
				->setSchema('Deduction')
				->saveObject($this->deductionRecord(run: $run, deduction: $deduction));
			$created++;
		}

		return $created;
	}//end savedDeductions()

	/**
	 * The Deduction record of one external deduction.
	 *
	 * @param PayrollEventRun      $run       The run.
	 * @param array<string, mixed> $deduction The external deduction.
	 *
	 * @return array<string, mixed>
	 */
	private function deductionRecord(PayrollEventRun $run, array $deduction): array {
		return [
			'payrollId' => $run->event->payrollId,
			'deductionType' => (string)($deduction['deductionType'] ?? 'other'),
			'deductionName' => (string)($deduction['deductionName'] ?? ($deduction['deductionType'] ?? 'Deduction')),
			'amount' => (float)($deduction['amount'] ?? 0),
			'rate' => $this->rateOf(deduction: $deduction),
			'rateSource' => (string)($deduction['rateSource'] ?? ''),
			'taxYear' => $run->taxYear(),
			'administrationId' => $run->administrationId(),
		];
	}//end deductionRecord()

	/**
	 * The deduction's rate, null when it has none.
	 *
	 * @param array<string, mixed> $deduction The external deduction.
	 *
	 * @return float|null
	 */
	private function rateOf(array $deduction): float|null {
		if (isset($deduction['rate']) === true) {
			return (float)$deduction['rate'];
		}

		return null;
	}//end rateOf()

	/**
	 * Reflect the external calculation and stamp the idempotency reference.
	 *
	 * @param PayrollEventRun $run The run.
	 *
	 * @return void
	 */
	private function markCalculated(PayrollEventRun $run): void {
		$payroll = $run->payroll;
		$payroll['status'] = 'calculated';
		$payroll['externalReference'] = $run->event->eventId;
		$this->objectService
			->setRegister($run->register)
			->setSchema('Payroll')
			->saveObject($payroll);
	}//end markCalculated()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
