<?php

/**
 * Inventory Adjustment Controller
 *
 * Write endpoints for the two inventory balance-sheet-correctness
 * adjustments: landed-cost capitalisation and lower-of-cost-or-NRV
 * write-down. Both post ONE balanced GLTransaction via their services.
 *
 * Authentication: #[NoAdminRequired] (any authenticated user). The
 * administration scope is validated by AdministrationContextService — a
 * non-member sees a masked 404 (ADR-005, no IDOR). Bodies are validated
 * before any data-layer access.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\LandedCostAllocationService;
use OCA\Shillinq\Service\NrvWriteDownService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * POST /api/inventory/landed-cost + POST /api/inventory/nrv-writedown.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
class InventoryAdjustmentController extends Controller {

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param LandedCostAllocationService $landedCost The landed-cost allocation service.
	 * @param NrvWriteDownService $nrv The NRV write-down service.
	 * @param AdministrationContextServiceInterface $context Admin-membership / IDOR context.
	 * @param LoggerInterface $logger Logger; never leaks stack traces.
	 */
	public function __construct(
		IRequest $request,
		private readonly LandedCostAllocationService $landedCost,
		private readonly NrvWriteDownService $nrv,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Allocate a receipt's landed cost across its lines and capitalise it.
	 *
	 * Body: { administration_id, receipt_reference, landed_cost_cents, basis? }.
	 *
	 * @return JSONResponse
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive (the scan only recognises
	 * guard calls named `authorize*`/`require*`/`ensure*` and does not match
	 * `canAccess(`). `LandedCostAllocationService::allocate()` also filters
	 * every downstream read/write by the caller-verified `administrationId`.
	 * No guard change needed.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function landedCost(): JSONResponse {
		$guard = $this->guard();
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$allocation = new LandedCostRequest(request: $this->request);
		$refusal = $this->landedCostRefusal(allocation: $allocation);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->allocateOrFail(allocation: $allocation);

	}//end landedCost()

	/**
	 * Run lower-of-cost-or-NRV for an administration + period, driven by an
	 * operator-supplied NRV-per-unit map keyed by productId (SKU).
	 *
	 * Body: { administration_id, period_id, nrv_by_sku: { <sku>: <nrvPerUnit>, ... } }.
	 *
	 * @return JSONResponse
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive. `NrvWriteDownService::
	 * runForAdministration()` also filters every downstream read/write by
	 * the caller-verified `administrationId`. No guard change needed.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function nrvWriteDown(): JSONResponse {
		$guard = $this->guard();
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$writeDown = new NrvWriteDownRequest(request: $this->request);
		$refusal = $this->nrvRefusal(writeDown: $writeDown);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->writeDownOrFail(writeDown: $writeDown);

	}//end nrvWriteDown()

	/**
	 * Shared authentication guard — returns a 401 JSONResponse when the
	 * request is unauthenticated, null otherwise.
	 *
	 * @return JSONResponse|null
	 */
	private function guard(): ?JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end guard()

	/**
	 * A 400 for a missing or malformed allocation, otherwise null.
	 *
	 * @param LandedCostRequest $allocation The allocation.
	 *
	 * @return JSONResponse|null
	 */
	private function landedCostRefusal(LandedCostRequest $allocation): ?JSONResponse {
		if ($allocation->isComplete() === false) {
			return new JSONResponse(
				['error' => 'administration_id and receipt_reference are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($allocation->isWellFormed() === false) {
			return new JSONResponse(
				['error' => 'Invalid administration_id, receipt_reference, landed_cost_cents or basis'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;

	}//end landedCostRefusal()

	/**
	 * Allocate the landed cost; any failure is a logged 500.
	 *
	 * @param LandedCostRequest $allocation The allocation.
	 *
	 * @return JSONResponse
	 */
	private function allocateOrFail(LandedCostRequest $allocation): JSONResponse {
		try {
			return $this->allocate(allocation: $allocation);
		} catch (\Throwable $e) {
			return $this->landedCostFailure(allocation: $allocation, failure: $e);
		}

	}//end allocateOrFail()

	/**
	 * Allocate the landed cost in an administration the caller may access.
	 *
	 * @param LandedCostRequest $allocation The allocation.
	 *
	 * @return JSONResponse
	 */
	private function allocate(LandedCostRequest $allocation): JSONResponse {
		$refusal = $this->accessRefusal(administrationId: $allocation->administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->landedCost->allocate(
			administrationId: $allocation->administrationId,
			receiptReference: $allocation->receiptReference,
			landedCostCents: $allocation->landedCostCents,
			basis: $allocation->basis
		);

		return new JSONResponse($result, Http::STATUS_OK);

	}//end allocate()

	/**
	 * Log a failed allocation and answer a 500 without its details.
	 *
	 * @param LandedCostRequest $allocation The allocation.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function landedCostFailure(LandedCostRequest $allocation, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'InventoryAdjustmentController: landed-cost allocation failed',
			['administrationId' => $allocation->administrationId, 'exception' => $failure->getMessage()]
		);

		return new JSONResponse(
			['error' => 'Failed to allocate landed cost'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end landedCostFailure()

	/**
	 * A 400 for a missing or malformed write-down, otherwise null.
	 *
	 * @param NrvWriteDownRequest $writeDown The write-down.
	 *
	 * @return JSONResponse|null
	 */
	private function nrvRefusal(NrvWriteDownRequest $writeDown): ?JSONResponse {
		if ($writeDown->isComplete() === false) {
			return new JSONResponse(
				['error' => 'administration_id, period_id and a non-empty nrv_by_sku map are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($writeDown->isWellFormed() === false) {
			return new JSONResponse(
				['error' => 'Invalid administration_id or period_id'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->nrvMapRefusal(writeDown: $writeDown);

	}//end nrvRefusal()

	/**
	 * A 400 when an NRV entry has an invalid SKU or a non-numeric value.
	 *
	 * @param NrvWriteDownRequest $writeDown The write-down.
	 *
	 * @return JSONResponse|null
	 */
	private function nrvMapRefusal(NrvWriteDownRequest $writeDown): ?JSONResponse {
		if ($writeDown->nrvBySku() === null) {
			return new JSONResponse(
				['error' => 'nrv_by_sku must map valid SKUs to numeric NRV-per-unit values'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;

	}//end nrvMapRefusal()

	/**
	 * Run the write-down; any failure is a logged 500.
	 *
	 * @param NrvWriteDownRequest $writeDown The write-down.
	 *
	 * @return JSONResponse
	 */
	private function writeDownOrFail(NrvWriteDownRequest $writeDown): JSONResponse {
		try {
			return $this->writeDown(writeDown: $writeDown);
		} catch (\Throwable $e) {
			return $this->nrvFailure(writeDown: $writeDown, failure: $e);
		}

	}//end writeDownOrFail()

	/**
	 * Run the write-down in an administration the caller may access.
	 *
	 * @param NrvWriteDownRequest $writeDown The write-down.
	 *
	 * @return JSONResponse
	 */
	private function writeDown(NrvWriteDownRequest $writeDown): JSONResponse {
		$refusal = $this->accessRefusal(administrationId: $writeDown->administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->nrv->runForAdministration(
			administrationId: $writeDown->administrationId,
			periodId: $writeDown->periodId,
			nrvBySku: (array)$writeDown->nrvBySku()
		);

		return new JSONResponse($result, Http::STATUS_OK);

	}//end writeDown()

	/**
	 * Log a failed write-down and answer a 500 without its details.
	 *
	 * @param NrvWriteDownRequest $writeDown The write-down.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function nrvFailure(NrvWriteDownRequest $writeDown, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'InventoryAdjustmentController: NRV write-down run failed',
			['administrationId' => $writeDown->administrationId, 'exception' => $failure->getMessage()]
		);

		return new JSONResponse(
			['error' => 'Failed to run NRV write-down'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end nrvFailure()

	/**
	 * A masked 404 for an administration the caller may not access.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse|null
	 */
	private function accessRefusal(string $administrationId): ?JSONResponse {
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;

	}//end accessRefusal()
}//end class
