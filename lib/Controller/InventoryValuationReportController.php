<?php

/**
 * Inventory Valuation Report Controller
 *
 * Read-only endpoint backing the inventory valuation / ageing / turnover
 * reporting views (jaarrekening `voorraadwaarde per <as-of-date>`). Wraps
 * {@see \OCA\Shillinq\Service\InventoryValuationReportService}, which
 * replays the immutable StockMove ledger — no new persistence.
 *
 * Authentication: #[NoAdminRequired] (any authenticated user). The
 * administration scope is validated by AdministrationContextService — a
 * non-member sees a masked 404 so foreign administration ids are not
 * enumerable via a 403 oracle (ADR-005, no IDOR).
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
use OCA\Shillinq\Service\InventoryValuationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/inventory/valuation-report — value-as-of-date (+ optional ageing).
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
class InventoryValuationReportController extends Controller {

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param InventoryValuationReportService $report The valuation reporting service.
	 * @param AdministrationContextServiceInterface $context Admin-membership / IDOR context.
	 * @param LoggerInterface $logger Logger; never leaks stack traces.
	 */
	public function __construct(
		IRequest $request,
		private readonly InventoryValuationReportService $report,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Return the inventory value as of a cut-off date, optionally with an
	 * ageing breakdown for a single (sku, warehouse).
	 *
	 * Query parameters:
	 *   - administration_id (required) tenant scope.
	 *   - as_of             (required) ISO date (yyyy-mm-dd) or timestamp; inclusive.
	 *   - sku               (optional) restrict to one product.
	 *   - warehouse         (optional) restrict to one warehouse.
	 *   - ageing            (optional) '1' to include the ageing bucket breakdown (needs sku + warehouse).
	 *
	 * @return JSONResponse
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive. `InventoryValuationReportService`'s
	 * `valuationAsOf()`/`ageing()` also filter every read by the
	 * caller-verified `administrationId`. No guard change needed.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function report(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$query = new InventoryValuationQuery(request: $this->request);
		$refusal = $this->queryRefusal(query: $query);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->valuationReport(query: $query);
		} catch (\Throwable $e) {
			return $this->reportFailure(query: $query, failure: $e);
		}

	}//end report()

	/**
	 * A 400 for a missing or malformed input, otherwise null.
	 *
	 * @param InventoryValuationQuery $query The query.
	 *
	 * @return JSONResponse|null
	 */
	private function queryRefusal(InventoryValuationQuery $query): ?JSONResponse {
		if ($query->isComplete() === false) {
			return new JSONResponse(
				['error' => 'administration_id and as_of are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($query->isWellFormed() === false) {
			return new JSONResponse(
				['error' => 'Invalid administration_id, as_of, sku or warehouse'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;

	}//end queryRefusal()

	/**
	 * The valuation, plus the ageing when asked for one item, for an
	 * administration the caller may access; a masked 404 otherwise.
	 *
	 * @param InventoryValuationQuery $query The query.
	 *
	 * @return JSONResponse
	 */
	private function valuationReport(InventoryValuationQuery $query): JSONResponse {
		if ($this->context->canAccess(administrationId: $query->administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = ['valuation' => $this->valuation(query: $query)];
		if ($query->wantsAgeing() === true) {
			$payload['ageing'] = $this->report->ageing(
				administrationId: $query->administrationId,
				asOfDate: $query->asOf,
				sku: $query->sku,
				warehouse: $query->warehouse
			);
		}

		return new JSONResponse($payload, Http::STATUS_OK);

	}//end valuationReport()

	/**
	 * Log a failed report and answer a 500 without the failure's details.
	 *
	 * @param InventoryValuationQuery $query The query.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function reportFailure(InventoryValuationQuery $query, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'InventoryValuationReportController: failed to compute valuation report',
			['administrationId' => $query->administrationId, 'exception' => $failure->getMessage()]
		);

		return new JSONResponse(
			['error' => 'Failed to compute inventory valuation report'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end reportFailure()

	/**
	 * The valuation as of the date, filtered by item and warehouse when given.
	 *
	 * @param InventoryValuationQuery $query The query.
	 *
	 * @return array<string,mixed>
	 */
	private function valuation(InventoryValuationQuery $query): array {
		return $this->report->valuationAsOf(
			administrationId: $query->administrationId,
			asOfDate: $query->asOf,
			sku: $query->skuFilter(),
			warehouse: $query->warehouseFilter()
		);

	}//end valuation()
}//end class
