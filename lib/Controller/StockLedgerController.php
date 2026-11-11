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

use OCA\Shillinq\AppInfo\AppIdentity;
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
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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

		$query = new StockLocationQuery(request: $this->request);
		$refusal = $this->queryRefusal(query: $query) ?? $this->accessRefusal(query: $query);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->traceLocation(query: $query);

	}//end trace()

	/**
	 * A 400 for a missing or malformed identifier, otherwise null.
	 *
	 * @param StockLocationQuery $query The query.
	 *
	 * @return JSONResponse|null
	 */
	private function queryRefusal(StockLocationQuery $query): ?JSONResponse {
		if ($query->isComplete() === false) {
			return new JSONResponse(
				['error' => 'administration_id, location_id and sku are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($query->isWellFormed() === false) {
			return new JSONResponse(
				['error' => 'Invalid identifier in administration_id / location_id / sku'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;

	}//end queryRefusal()

	/**
	 * A masked 404 for an administration the caller may not access, a logged
	 * 500 when that cannot be checked, otherwise null.
	 *
	 * @param StockLocationQuery $query The query.
	 *
	 * @return JSONResponse|null
	 */
	private function accessRefusal(StockLocationQuery $query): ?JSONResponse {
		try {
			$allowed = $this->context->canAccess(administrationId: $query->administrationId);
		} catch (\Throwable $e) {
			return $this->accessCheckFailure(query: $query, failure: $e);
		}

		return self::notFoundUnless(allowed: $allowed);

	}//end accessRefusal()

	/**
	 * Log an access check that failed and answer a 500.
	 *
	 * @param StockLocationQuery $query The query.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function accessCheckFailure(StockLocationQuery $query, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'StockLedgerController: failed to check administration access',
			[
				'administrationId' => $query->administrationId,
				'exception' => $failure->getMessage(),
			]
		);

		return new JSONResponse(
			['error' => 'Failed to authorise stock ledger'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end accessCheckFailure()

	/**
	 * The masked 404 when access is not allowed, otherwise null.
	 *
	 * @param bool $allowed Whether the caller may access the administration.
	 *
	 * @return JSONResponse|null
	 */
	private static function notFoundUnless(bool $allowed): ?JSONResponse {
		if ($allowed === false) {
			return new JSONResponse(
				['error' => 'Administration not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return null;

	}//end notFoundUnless()

	/**
	 * The on-hand, reserved and available quantity and the movement trace of
	 * the location; a failing read is a logged 500.
	 *
	 * @param StockLocationQuery $query The query.
	 *
	 * @return JSONResponse
	 */
	private function traceLocation(StockLocationQuery $query): JSONResponse {
		try {
			$balance = $this->balance(query: $query);
			$trace = $this->movements(query: $query);
		} catch (\Throwable $e) {
			return $this->traceFailure(query: $query, failure: $e);
		}

		return new JSONResponse($query->logContext() + $balance + ['trace' => $trace], Http::STATUS_OK);

	}//end traceLocation()

	/**
	 * The movement trace of the location.
	 *
	 * @param StockLocationQuery $query The query.
	 *
	 * @return array<int,mixed>
	 */
	private function movements(StockLocationQuery $query): array {
		return $this->ledger->traceLocation(
			administrationId: $query->administrationId,
			locationId: $query->locationId,
			sku: $query->sku
		);

	}//end movements()

	/**
	 * Log a trace that failed and answer a 500.
	 *
	 * @param StockLocationQuery $query The query.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function traceFailure(StockLocationQuery $query, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'StockLedgerController: failed to compute stock-ledger trace',
			$query->logContext() + ['exception' => $failure->getMessage()]
		);

		return new JSONResponse(
			['error' => 'Failed to compute stock-ledger trace'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end traceFailure()

	/**
	 * The on-hand, reserved and available quantity of the location.
	 *
	 * @param StockLocationQuery $query The query.
	 *
	 * @return array{onHand: float, reserved: float, available: float}
	 */
	private function balance(StockLocationQuery $query): array {
		$onHand = $this->ledger->quantityForLocation(
			administrationId: $query->administrationId,
			locationId: $query->locationId,
			sku: $query->sku
		);
		$reserved = $this->ledger->reservedForLocation(
			administrationId: $query->administrationId,
			locationId: $query->locationId,
			sku: $query->sku
		);

		return ['onHand' => $onHand, 'reserved' => $reserved, 'available' => ($onHand - $reserved)];

	}//end balance()
}//end class
