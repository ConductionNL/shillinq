<?php

/**
 * Lease Reassessment Controller
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\LeaseReassessmentService;
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
	 * @param LeaseReassessmentService $reassessmentService The remeasurement service.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope (ADR-005).
	 * @param IUserSession $userSession User-session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to the client).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) administrationContext is the canonical name fleet-wide.
	 */
	public function __construct(
		IRequest $request,
		private readonly LeaseReassessmentService $reassessmentService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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

		[$leaseId, $administrationId] = $scope;

		$newPaymentAmount = (float)$this->request->getParam('new_payment_amount', 0);
		if ($newPaymentAmount <= 0.0) {
			return new JSONResponse(
				['error' => 'new_payment_amount must be a positive amount'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->run(
			record: fn (): ?array => $this->reassessmentService->recordIndexationEvent(
				leaseContractId: $leaseId,
				administrationId: $administrationId,
				newPaymentAmount: $newPaymentAmount,
				triggerDescription: $this->stringParam(name: 'trigger_description'),
				approver: $this->stringParam(name: 'approver'),
			)
		);

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

		[$leaseId, $administrationId] = $scope;

		$options = $this->request->getParam('extension_options', []);
		if (is_array($options) === false) {
			return new JSONResponse(
				['error' => 'extension_options must be an array'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->run(
			record: fn (): ?array => $this->reassessmentService->recordExtensionOptionReassessment(
				leaseContractId: $leaseId,
				administrationId: $administrationId,
				updatedExtensionOptions: $options,
				triggerDescription: $this->stringParam(name: 'trigger_description'),
				approver: $this->stringParam(name: 'approver'),
			)
		);

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

		[$leaseId, $administrationId] = $scope;

		$newTerms = $this->request->getParam('new_terms', []);
		if (is_array($newTerms) === false || $newTerms === []) {
			return new JSONResponse(
				['error' => 'new_terms must be a non-empty object'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$approach = $this->stringParam(name: 'approach');
		if ($approach === '') {
			$approach = 'catch-up-adjustment';
		}

		return $this->run(
			record: fn (): ?array => $this->reassessmentService->recordModification(
				leaseContractId: $leaseId,
				administrationId: $administrationId,
				newTerms: $newTerms,
				approach: $approach,
				triggerDescription: $this->stringParam(name: 'trigger_description'),
				approver: $this->stringParam(name: 'approver'),
			)
		);

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

		[$leaseId, $administrationId] = $scope;

		$recoverableValueRaw = $this->request->getParam('recoverable_value', null);
		if ($recoverableValueRaw === null || is_numeric($recoverableValueRaw) === false) {
			return new JSONResponse(
				['error' => 'recoverable_value must be a number'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->run(
			record: fn (): ?array => $this->reassessmentService->recordImpairment(
				leaseContractId: $leaseId,
				administrationId: $administrationId,
				recoverableValue: (float)$recoverableValueRaw,
				triggerDescription: $this->stringParam(name: 'trigger_description'),
				approver: $this->stringParam(name: 'approver'),
			)
		);

	}//end impairment()

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
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$leaseId = $this->scopeParam(name: 'lease_id');
		if ($leaseId === '') {
			return new JSONResponse(['error' => 'lease_id is required'], Http::STATUS_BAD_REQUEST);
		}

		return [$leaseId, $administrationId];
	}//end resolveScope()

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
			$this->logger->error(
				'LeaseReassessmentController: failed to record the lease remeasurement',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Could not record the lease remeasurement'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($event === null) {
			return new JSONResponse(['error' => 'Lease not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($event, Http::STATUS_CREATED);
	}//end run()

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
