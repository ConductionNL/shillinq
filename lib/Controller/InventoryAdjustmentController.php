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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
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
	 * Identifier validation pattern shared across parameters.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-:\\/ ]{1,128}$/';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param LandedCostAllocationService $landedCost The landed-cost allocation service.
	 * @param NrvWriteDownService $nrv The NRV write-down service.
	 * @param AdministrationContextService $context Admin-membership / IDOR context.
	 * @param LoggerInterface $logger Logger; never leaks stack traces.
	 */
	public function __construct(
		IRequest $request,
		private readonly LandedCostAllocationService $landedCost,
		private readonly NrvWriteDownService $nrv,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$receiptReference = trim((string)$this->request->getParam('receipt_reference', ''));
		$landedCostCents = (int)$this->request->getParam('landed_cost_cents', 0);
		$basis = trim((string)$this->request->getParam('basis', 'value'));

		if ($administrationId === '' || $receiptReference === '') {
			return new JSONResponse(
				['error' => 'administration_id and receipt_reference are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match(self::ID_PATTERN, $administrationId) !== 1
			|| preg_match(self::ID_PATTERN, $receiptReference) !== 1
			|| $landedCostCents <= 0
			|| in_array($basis, ['value', 'quantity'], true) === false
		) {
			return new JSONResponse(
				['error' => 'Invalid administration_id, receipt_reference, landed_cost_cents or basis'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			if ($this->context->canAccess(administrationId: $administrationId) === false) {
				return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
			}

			$result = $this->landedCost->allocate(
				administrationId: $administrationId,
				receiptReference: $receiptReference,
				landedCostCents: $landedCostCents,
				basis: $basis
			);

			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryAdjustmentController: landed-cost allocation failed',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Failed to allocate landed cost'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

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

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$periodId = trim((string)$this->request->getParam('period_id', ''));
		$nrvBySkuRaw = $this->request->getParam('nrv_by_sku', []);

		if ($administrationId === '' || $periodId === '' || is_array($nrvBySkuRaw) === false || $nrvBySkuRaw === []) {
			return new JSONResponse(
				['error' => 'administration_id, period_id and a non-empty nrv_by_sku map are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match(self::ID_PATTERN, $administrationId) !== 1
			|| preg_match('/^[A-Za-z0-9_.\\-]{1,32}$/', $periodId) !== 1
		) {
			return new JSONResponse(
				['error' => 'Invalid administration_id or period_id'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$nrvBySku = [];
		foreach ($nrvBySkuRaw as $sku => $nrv) {
			$skuKey = trim((string)$sku);
			if ($skuKey === '' || preg_match(self::ID_PATTERN, $skuKey) !== 1 || is_numeric($nrv) === false) {
				return new JSONResponse(
					['error' => 'nrv_by_sku must map valid SKUs to numeric NRV-per-unit values'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$nrvBySku[$skuKey] = (float)$nrv;
		}

		try {
			if ($this->context->canAccess(administrationId: $administrationId) === false) {
				return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
			}

			$result = $this->nrv->runForAdministration(
				administrationId: $administrationId,
				periodId: $periodId,
				nrvBySku: $nrvBySku
			);

			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryAdjustmentController: NRV write-down run failed',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Failed to run NRV write-down'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

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
}//end class
