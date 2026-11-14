<?php

/**
 * Lease Reassessment Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change revive-lease-capabilities (shillinq#446): the missing operator
 * surface for IFRS-16 lease remeasurements.
 * {@see \OCA\Shillinq\Service\LeaseReassessmentService} fully implements the
 * four remeasurement events — indexation (REQ-LR-001), extension-option
 * reassessment (REQ-LR-002), scope/term/payment modification (REQ-LR-003)
 * and impairment (REQ-LR-004) — and each computes a balanced GL-line set,
 * but nothing ever called them: the lease liability and RoU asset never
 * moved when a contract changed. The service is dependency-injected nowhere;
 * class-injected is not method-called.
 *
 * These endpoints give each remeasurement a user-reachable, authenticated
 * write surface. The remeasurement inputs (the indexed payment, the updated
 * extension options, the modified terms, the recoverable value) are
 * event-specific form data that a lifecycle transition cannot supply, so a
 * declarative trigger is impossible here (design D3).
 *
 * Every endpoint is `#[NoAdminRequired]` with a per-administration IDOR
 * guard (ADR-005); a cross-tenant administration is masked as 404, and a
 * lease that is not visible in the caller's administration is 404 as well.
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
 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\LeaseReassessmentServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * IFRS-16 lease remeasurement write endpoints (REQ-LR-001..REQ-LR-004).
 *
 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
 */
class LeaseReassessmentController extends Controller {

	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param LeaseReassessmentServiceInterface $reassessmentService The remeasurement service.
	 * @param AdministrationContextServiceInterface $administrationContext IDOR + tenant scope (ADR-005).
	 * @param IUserSession $userSession User-session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to the client).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) administrationContext is the canonical name fleet-wide.
	 */
	public function __construct(
		IRequest $request,
		private readonly LeaseReassessmentServiceInterface $reassessmentService,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Record a CPI / fixed-percent indexation remeasurement (REQ-LR-001).
	 *
	 * POST /api/leases/reassessment/indexation
	 * Body: lease_id, administration_id, new_payment_amount,
	 *       trigger_description?, approver?
	 *
	 * Authorization: {@see resolveScope()} checks `administrationContext->canAccess()`
	 * against the request's `administration_id` and {@see LeaseReassessmentService::fetchLease()}
	 * re-scopes the lease lookup itself to that same administration — a non-member
	 * or a lease from another tenant is masked as 404 (ADR-005 Rule 3).
	 *
	 * @return JSONResponse 201 with the persisted event; 400/401/404/500.
	 *
	 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function indexation(): JSONResponse {
		$scope = $this->resolveScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$newPaymentAmount = (float)$this->request->getParam('new_payment_amount', 0);
		if ($newPaymentAmount <= 0.0) {
			return $this->badRequest(error: 'new_payment_amount must be a positive amount');
		}

		return $this->run(record: fn (): ?array => $this->recordIndexation(scope: $scope, newPaymentAmount: $newPaymentAmount));
	}//end indexation()

	/**
	 * Record an extension-option reassessment (REQ-LR-002).
	 *
	 * POST /api/leases/reassessment/extension-option
	 * Body: lease_id, administration_id, extension_options[],
	 *       trigger_description?, approver?
	 *
	 * Authorization: {@see resolveScope()} checks `administrationContext->canAccess()`
	 * against the request's `administration_id` and {@see LeaseReassessmentService::fetchLease()}
	 * re-scopes the lease lookup itself to that same administration — a non-member
	 * or a lease from another tenant is masked as 404 (ADR-005 Rule 3).
	 *
	 * @return JSONResponse 201 with the persisted event; 400/401/404/500.
	 *
	 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function extensionOption(): JSONResponse {
		$scope = $this->resolveScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$options = $this->request->getParam('extension_options', []);
		if (is_array($options) === false) {
			return $this->badRequest(error: 'extension_options must be an array');
		}

		return $this->run(record: fn (): ?array => $this->recordExtensionOptions(scope: $scope, options: $options));
	}//end extensionOption()

	/**
	 * Record a scope / term / payment modification per IFRS 16.44 (REQ-LR-003).
	 *
	 * POST /api/leases/reassessment/modification
	 * Body: lease_id, administration_id, new_terms{}, approach?,
	 *       trigger_description?, approver?
	 *
	 * Authorization: {@see resolveScope()} checks `administrationContext->canAccess()`
	 * against the request's `administration_id` and {@see LeaseReassessmentService::fetchLease()}
	 * re-scopes the lease lookup itself to that same administration — a non-member
	 * or a lease from another tenant is masked as 404 (ADR-005 Rule 3).
	 *
	 * @return JSONResponse 201 with the persisted event; 400/401/404/500.
	 *
	 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function modification(): JSONResponse {
		$scope = $this->resolveScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$newTerms = $this->request->getParam('new_terms', []);
		if (is_array($newTerms) === false || $newTerms === []) {
			return $this->badRequest(error: 'new_terms must be a non-empty object');
		}

		return $this->run(record: fn (): ?array => $this->recordModification(scope: $scope, newTerms: $newTerms));
	}//end modification()

	/**
	 * Record an impairment write-down on the RoU asset (REQ-LR-004).
	 *
	 * POST /api/leases/reassessment/impairment
	 * Body: lease_id, administration_id, recoverable_value,
	 *       trigger_description?, approver?
	 *
	 * Authorization: {@see resolveScope()} checks `administrationContext->canAccess()`
	 * against the request's `administration_id` and {@see LeaseReassessmentService::fetchLease()}
	 * re-scopes the lease lookup itself to that same administration — a non-member
	 * or a lease from another tenant is masked as 404 (ADR-005 Rule 3).
	 *
	 * @return JSONResponse 201 with the persisted event; 400/401/404/500.
	 *
	 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function impairment(): JSONResponse {
		$scope = $this->resolveScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$recoverableValueRaw = $this->request->getParam('recoverable_value', null);
		if ($recoverableValueRaw === null || is_numeric($recoverableValueRaw) === false) {
			return $this->badRequest(error: 'recoverable_value must be a number');
		}

		return $this->run(record: fn (): ?array => $this->recordImpairment(scope: $scope, recoverableValue: (float)$recoverableValueRaw));
	}//end impairment()

	/**
	 * Record the indexation, reading the free-text parameters as the call is made.
	 *
	 * @param array{0:string,1:string} $scope            The lease and administration.
	 * @param float                    $newPaymentAmount The new payment amount.
	 *
	 * @return array<string,mixed>|null
	 */
	private function recordIndexation(array $scope, float $newPaymentAmount): array|null {
		return $this->reassessmentService->recordIndexationEvent(
			leaseContractId: $scope[0],
			administrationId: $scope[1],
			newPaymentAmount: $newPaymentAmount,
			triggerDescription: $this->stringParam(name: 'trigger_description'),
			approver: $this->stringParam(name: 'approver'),
		);
	}//end recordIndexation()

	/**
	 * Record the extension-option reassessment, reading the free-text parameters as the call is made.
	 *
	 * @param array{0:string,1:string} $scope   The lease and administration.
	 * @param array<mixed>             $options The updated extension options.
	 *
	 * @return array<string,mixed>|null
	 */
	private function recordExtensionOptions(array $scope, array $options): array|null {
		return $this->reassessmentService->recordExtensionOptionReassessment(
			leaseContractId: $scope[0],
			administrationId: $scope[1],
			updatedExtensionOptions: $options,
			triggerDescription: $this->stringParam(name: 'trigger_description'),
			approver: $this->stringParam(name: 'approver'),
		);
	}//end recordExtensionOptions()

	/**
	 * Record the modification; the approach and the free-text parameters are read as the call is made, in that order.
	 *
	 * @param array{0:string,1:string} $scope    The lease and administration.
	 * @param array<mixed>             $newTerms The new terms.
	 *
	 * @return array<string,mixed>|null
	 */
	private function recordModification(array $scope, array $newTerms): array|null {
		return $this->reassessmentService->recordModification(
			leaseContractId: $scope[0],
			administrationId: $scope[1],
			newTerms: $newTerms,
			approach: $this->approach(),
			triggerDescription: $this->stringParam(name: 'trigger_description'),
			approver: $this->stringParam(name: 'approver'),
		);
	}//end recordModification()

	/**
	 * Record the impairment, reading the free-text parameters as the call is made.
	 *
	 * @param array{0:string,1:string} $scope            The lease and administration.
	 * @param float                    $recoverableValue The recoverable value.
	 *
	 * @return array<string,mixed>|null
	 */
	private function recordImpairment(array $scope, float $recoverableValue): array|null {
		return $this->reassessmentService->recordImpairment(
			leaseContractId: $scope[0],
			administrationId: $scope[1],
			recoverableValue: $recoverableValue,
			triggerDescription: $this->stringParam(name: 'trigger_description'),
			approver: $this->stringParam(name: 'approver'),
		);
	}//end recordImpairment()

	/**
	 * The modification approach, catch-up-adjustment when blank.
	 *
	 * @return string
	 */
	private function approach(): string {
		$approach = $this->stringParam(name: 'approach');
		if ($approach === '') {
			return 'catch-up-adjustment';
		}

		return $approach;
	}//end approach()

	/**
	 * A 400 with the given error.
	 *
	 * @param string $error The error.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $error): JSONResponse {
		return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * Resolve + IDOR-guard the (lease_id, administration_id) scope shared by
	 * every endpoint.
	 *
	 * @return array{0:string,1:string}|JSONResponse The validated scope, or an
	 *                                               error response (401/400/404).
	 */
	private function resolveScope(): array|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administration_id');
		$refusal          = $this->administrationRefusal(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$leaseId = $this->scopeParam(name: 'lease_id');
		if ($leaseId === '') {
			return new JSONResponse(['error' => 'lease_id is required'], Http::STATUS_BAD_REQUEST);
		}

		return [$leaseId, $administrationId];
	}//end resolveScope()

	/**
	 * The 400 for a blank or malformed administration, the masked 404 for a non-member, or null.
	 *
	 * @param string $administrationId The validated administration_id parameter, '' when blank or malformed.
	 *
	 * @return JSONResponse|null
	 */
	private function administrationRefusal(string $administrationId): JSONResponse|null {
		if ($administrationId === '') {
			return $this->badRequest(error: 'administration_id is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * Invoke a remeasurement, mapping its result to the HTTP envelope.
	 *
	 * A null result means the lease was not found in the caller's
	 * administration (ADR-005 IDOR): masked as 404. A thrown error is logged
	 * without a stack trace and surfaced as 500.
	 *
	 * @param callable():(array<string,mixed>|null) $record The remeasurement call.
	 *
	 * @return JSONResponse 201 with the persisted event; 404; 500.
	 */
	private function run(callable $record): JSONResponse {
		try {
			$event = $record();
		} catch (\Throwable $e) {
			return $this->recordFailed(exception: $e);
		}

		if ($event === null) {
			return new JSONResponse(['error' => 'Lease not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($event, Http::STATUS_CREATED);
	}//end run()

	/**
	 * Log a failed remeasurement without its stack trace and answer the 500.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function recordFailed(\Throwable $exception): JSONResponse {
		$this->logger->error(
			'LeaseReassessmentController: failed to record the lease remeasurement',
			['exception' => $exception->getMessage()]
		);
		return new JSONResponse(
			['error' => 'Could not record the lease remeasurement'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end recordFailed()

	/**
	 * Read + validate a short-slug scope parameter; '' when blank/malformed.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string The validated value, or '' when blank/malformed.
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()

	/**
	 * Read a free-text string parameter, trimmed; '' when absent.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string The trimmed value.
	 */
	private function stringParam(string $name): string {
		return trim((string)$this->request->getParam($name, ''));
	}//end stringParam()
}//end class
