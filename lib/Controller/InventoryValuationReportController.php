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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
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
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class InventoryValuationReportController extends Controller {

	/**
	 * Identifier validation pattern shared across parameters.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\- ]{1,64}$/';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param InventoryValuationReportService $report The valuation reporting service.
	 * @param AdministrationContextService $context Admin-membership / IDOR context.
	 * @param LoggerInterface $logger Logger; never leaks stack traces.
	 */
	public function __construct(
		IRequest $request,
		private readonly InventoryValuationReportService $report,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$asOf = trim((string)$this->request->getParam('as_of', ''));
		$sku = trim((string)$this->request->getParam('sku', ''));
		$warehouse = trim((string)$this->request->getParam('warehouse', ''));
		$wantAgeing = ((string)$this->request->getParam('ageing', '') === '1');

		if ($administrationId === '' || $asOf === '') {
			return new JSONResponse(
				['error' => 'administration_id and as_of are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match(self::ID_PATTERN, $administrationId) !== 1
			|| preg_match('/^\d{4}-\d{2}-\d{2}([T ].*)?$/', $asOf) !== 1
			|| ($sku !== '' && preg_match(self::ID_PATTERN, $sku) !== 1)
			|| ($warehouse !== '' && preg_match(self::ID_PATTERN, $warehouse) !== 1)
		) {
			return new JSONResponse(
				['error' => 'Invalid administration_id, as_of, sku or warehouse'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			if ($this->context->canAccess(administrationId: $administrationId) === false) {
				return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
			}

			$skuFilter = null;
			if ($sku !== '') {
				$skuFilter = $sku;
			}

			$warehouseFilter = null;
			if ($warehouse !== '') {
				$warehouseFilter = $warehouse;
			}

			$valuation = $this->report->valuationAsOf(
				administrationId: $administrationId,
				asOfDate: $asOf,
				sku: $skuFilter,
				warehouse: $warehouseFilter
			);

			$payload = ['valuation' => $valuation];
			if ($wantAgeing === true && $sku !== '' && $warehouse !== '') {
				$payload['ageing'] = $this->report->ageing(
					administrationId: $administrationId,
					asOfDate: $asOf,
					sku: $sku,
					warehouse: $warehouse
				);
			}

			return new JSONResponse($payload, Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryValuationReportController: failed to compute valuation report',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Failed to compute inventory valuation report'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end report()
}//end class
