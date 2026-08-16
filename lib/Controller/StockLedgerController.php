<?php

/**
 * Stock Ledger Controller
 *
 * Read-only endpoint backing the Stock Ledger detail view per
 * REQ-SM-005 + REQ-SM-009. Returns the drill-down trace for a
 * (administration, location, sku) triple as well as the reconciled
 * on-hand + reserved quantities.
 *
 * Authentication: #[NoAdminRequired] (any authenticated user). The
 * administration scope is validated by AdministrationContextService
 * — non-members see 404 (masked) so foreign administration ids are
 * not enumerable via a 403 oracle.
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
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\StockLedgerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/stock-ledger — drill-down trace + reconciled balance per
 * (administration, location, sku) per REQ-SM-005.
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 */
class StockLedgerController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param StockLedgerService $ledger The ledger aggregation service.
	 * @param AdministrationContextService $context Admin-membership / IDOR context.
	 * @param LoggerInterface $logger Logger; never leaks stack traces.
	 */
	public function __construct(
		IRequest $request,
		private readonly StockLedgerService $ledger,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Return the stock-ledger trace per REQ-SM-005 + REQ-SM-009.
	 *
	 * Query parameters:
	 *   - administration_id (required) tenant scope.
	 *   - location_id       (required) bin location id.
	 *   - sku               (required) product SKU.
	 *
	 * Response: {
	 *   onHand: number,           — recomputed from posted, non-cancelled moves
	 *   reserved: number,         — SUM of draft moves whose source is this location
	 *   available: number,        — onHand - reserved
	 *   trace: [{ movementNumber, postedAt, movementType, sign, quantity, runningTotal, ... }]
	 * }
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	#[NoAdminRequired]
	public function trace(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(
				['error' => 'Not authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$locationId = trim((string)$this->request->getParam('location_id', ''));
		$sku = trim((string)$this->request->getParam('sku', ''));

		if ($administrationId === '' || $locationId === '' || $sku === '') {
			return new JSONResponse(
				['error' => 'administration_id, location_id and sku are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$pattern = '/^[A-Za-z0-9_.\\-]{1,64}$/';
		if (preg_match($pattern, $administrationId) !== 1
			|| preg_match($pattern, $locationId) !== 1
			|| preg_match($pattern, $sku) !== 1
		) {
			return new JSONResponse(
				['error' => 'Invalid identifier in administration_id / location_id / sku'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockLedgerController: failed to check administration access',
				[
					'administrationId' => $administrationId,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to authorise stock ledger'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		if ($allowed === false) {
			return new JSONResponse(
				['error' => 'Administration not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		try {
			$onHand = $this->ledger->quantityForLocation(
				administrationId: $administrationId,
				locationId: $locationId,
				sku: $sku
			);
			$reserved = $this->ledger->reservedForLocation(
				administrationId: $administrationId,
				locationId: $locationId,
				sku: $sku
			);
			$available = ($onHand - $reserved);
			$trace = $this->ledger->traceLocation(
				administrationId: $administrationId,
				locationId: $locationId,
				sku: $sku
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockLedgerController: failed to compute stock-ledger trace',
				[
					'administrationId' => $administrationId,
					'locationId' => $locationId,
					'sku' => $sku,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to compute stock-ledger trace'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse(
			[
				'administrationId' => $administrationId,
				'locationId' => $locationId,
				'sku' => $sku,
				'onHand' => $onHand,
				'reserved' => $reserved,
				'available' => $available,
				'trace' => $trace,
			],
			Http::STATUS_OK
		);

	}//end trace()
}//end class
