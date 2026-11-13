<?php

/**
 * Stock Ledger Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Read-only stock-ledger aggregation service per REQ-SM-005 and
 * REQ-SM-009. Two responsibilities:
 *
 *   - quantityForLocation()  — recompute InventoryStock.quantity from
 *     the StockMove ledger: initialStock + SUM(destination posted) -
 *     SUM(source posted), excluding cancelled rows. Used by the Stock
 *     Levels Dashboard reconciliation pass + the JSON drill-down
 *     endpoint backing the Stock Ledger detail view.
 *
 *   - reservedForLocation()  — SUM(quantity WHERE lifecycleState=draft
 *     AND sourceLocationId=location) so the Available = quantity -
 *     reservedQty breakdown on the InventoryStock detail (REQ-SM-009)
 *     and the Reserved Stock index (REQ-SM-008) can render the same
 *     number end-to-end without divergence.
 *
 *   - traceLocation()        — drill-down listing of every posted,
 *     non-cancelled StockMove that contributes to a (sku, location,
 *     administration) balance, with a running cumulative total per
 *     REQ-SM-005 — the data the Stock Ledger detail view renders.
 *
 * No persistence. All reads via the real OR ObjectService API
 * (find / findAll). Integer-cent arithmetic.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * REQ-SM-005 + REQ-SM-009 read-side aggregation for the Stock Ledger
 * and Reserved Stock manifest views.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 */
class StockLedgerService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs full payloads.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Recompute the on-hand quantity at a bin location for an SKU from the
	 * StockMove ledger per REQ-SM-005.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Bin location id.
	 * @param string $sku Product SKU.
	 * @param float $initialStock Optional initial-stock seed (default 0).
	 *
	 * @return float Recomputed on-hand quantity (rounded to 2 decimals).
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function quantityForLocation(
		string $administrationId,
		string $locationId,
		string $sku,
		float $initialStock = 0.0,
	): float {
		$key = new StockLocationKey(administrationId: $administrationId, locationId: $locationId);
		$key->sku = $sku;

		try {
			return $this->fromCents(cents: $this->onHandCents(key: $key, initialStock: $initialStock));
		} catch (\Throwable $e) {
			$this->logger->error('StockLedgerService: quantityForLocation failed', $key->failureContext(error: $e));
			return 0.0;
		}

	}//end quantityForLocation()

	/**
	 * Sum reserved quantity (draft moves whose source is this location for
	 * this SKU) per REQ-SM-009.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Bin location id.
	 * @param string $sku Product SKU.
	 *
	 * @return float Reserved quantity (rounded to 2 decimals).
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function reservedForLocation(string $administrationId, string $locationId, string $sku): float {
		$key = new StockLocationKey(administrationId: $administrationId, locationId: $locationId);
		$key->sku = $sku;

		try {
			$reservedCents = 0;
			foreach ($this->draftMovesForSource(key: $key) as $move) {
				$reservedCents += $this->cents(value: ($move['quantity'] ?? 0));
			}

			return $this->fromCents(cents: $reservedCents);
		} catch (\Throwable $e) {
			$this->logger->error('StockLedgerService: reservedForLocation failed', $key->failureContext(error: $e));
			return 0.0;
		}

	}//end reservedForLocation()

	/**
	 * Build the drill-down trace for the Stock Ledger detail view: every
	 * posted, non-cancelled StockMove touching (sku, location) with a
	 * running cumulative total. Returned rows are ordered chronologically
	 * by postedAt ASC so the operator can audit the balance per REQ-SM-005.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Bin location id.
	 * @param string $sku Product SKU.
	 *
	 * @return array<int,array<string,mixed>> Trace rows: {movementNumber, postedAt, movementType, sign, quantity, runningTotal}.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function traceLocation(string $administrationId, string $locationId, string $sku): array {
		$key = new StockLocationKey(administrationId: $administrationId, locationId: $locationId);
		$key->sku = $sku;

		try {
			$moves = $this->chronological(moves: $this->postedMovesForLocation(key: $key));
			return $this->traceRows(moves: $moves, locationId: $locationId);
		} catch (\Throwable $e) {
			$this->logger->error('StockLedgerService: traceLocation failed', $key->failureContext(error: $e));
			return [];
		}

	}//end traceLocation()

	/**
	 * On-hand cents: the seed, plus moves into the location, minus moves out of it.
	 *
	 * @param StockLocationKey $key          The SKU at its location.
	 * @param float            $initialStock The initial-stock seed.
	 *
	 * @return int
	 */
	private function onHandCents(StockLocationKey $key, float $initialStock): int {
		$netCents = $this->cents(value: $initialStock);
		foreach ($this->postedMovesForLocation(key: $key) as $move) {
			$cents = $this->cents(value: ($move['quantity'] ?? 0));
			if (((string)($move['destinationLocationId'] ?? '')) === $key->locationId) {
				$netCents += $cents;
			}

			if (((string)($move['sourceLocationId'] ?? '')) === $key->locationId) {
				$netCents -= $cents;
			}
		}

		return $netCents;

	}//end onHandCents()

	/**
	 * The moves ordered by postedAt, oldest first.
	 *
	 * @param array<int,array<string,mixed>> $moves StockMoves.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function chronological(array $moves): array {
		usort(
			$moves,
			static fn (array $left, array $right): int => strcmp(
				(string)($left['postedAt'] ?? ''),
				(string)($right['postedAt'] ?? '')
			)
		);

		return $moves;

	}//end chronological()

	/**
	 * Trace rows with the location-side sign and a running cumulative total.
	 *
	 * @param array<int,array<string,mixed>> $moves      Chronological StockMoves.
	 * @param string                         $locationId The traced bin location.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function traceRows(array $moves, string $locationId): array {
		$trace = [];
		$runningCents = 0;
		foreach ($moves as $move) {
			$cents = $this->cents(value: ($move['quantity'] ?? 0));
			$sign = $this->moveSign(move: $move, locationId: $locationId);
			$runningCents += $this->signedCents(sign: $sign, cents: $cents);
			$trace[] = $this->traceIdentity(move: $move, sign: $sign) + [
				'quantity' => $this->fromCents(cents: $cents),
				'runningTotal' => $this->fromCents(cents: $runningCents),
			];
		}

		return $trace;

	}//end traceRows()

	/**
	 * '-' for a move out of the location, '+' otherwise.
	 *
	 * @param array<string,mixed> $move       A StockMove.
	 * @param string              $locationId The traced bin location.
	 *
	 * @return string
	 */
	private function moveSign(array $move, string $locationId): string {
		if (((string)($move['sourceLocationId'] ?? '')) === $locationId) {
			return '-';
		}

		return '+';

	}//end moveSign()

	/**
	 * The cents a move adds to the running total under its sign.
	 *
	 * @param string $sign  '+' or '-'.
	 * @param int    $cents The move's absolute cents.
	 *
	 * @return int
	 */
	private function signedCents(string $sign, int $cents): int {
		if ($sign === '-') {
			return -$cents;
		}

		return $cents;

	}//end signedCents()

	/**
	 * The identifying fields of a trace row, with its sign.
	 *
	 * @param array<string,mixed> $move A StockMove.
	 * @param string              $sign '+' or '-'.
	 *
	 * @return array<string,mixed>
	 */
	private function traceIdentity(array $move, string $sign): array {
		return [
			'id' => ($move['id'] ?? null),
			'movementNumber' => ($move['movementNumber'] ?? null),
			'postedAt' => ($move['postedAt'] ?? null),
			'movementType' => ($move['movementType'] ?? null),
			'sign' => $sign,
		];

	}//end traceIdentity()

	/**
	 * Fetch all posted, non-cancelled StockMoves touching the (sku, location)
	 * pair on either side. Combines two findAll() queries (source + destination)
	 * and de-duplicates by id.
	 *
	 * @param StockLocationKey $key The SKU at its location.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function postedMovesForLocation(StockLocationKey $key): array {
		if ($key->isIncomplete() === true) {
			return [];
		}

		$sourceSide = $this->postedMovesOnSide(key: $key, sideField: 'sourceLocationId');
		$destinationSide = $this->postedMovesOnSide(key: $key, sideField: 'destinationLocationId');

		return $this->uniqueById(moves: array_merge($sourceSide, $destinationSide));
	}//end postedMovesForLocation()

	/**
	 * The posted StockMoves of the SKU with the location on one side.
	 *
	 * @param StockLocationKey $key       The SKU at its location.
	 * @param string           $sideField 'sourceLocationId' or 'destinationLocationId'.
	 *
	 * @return array<int,mixed>
	 */
	private function postedMovesOnSide(StockLocationKey $key, string $sideField): array {
		$base = [
			'administrationId' => $key->administrationId,
			'itemId' => $key->sku,
			'lifecycleState' => 'posted',
		];

		// ADR-084: findAll() is declared `: array` — never null, always an array.
		return $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(['filters' => array_merge($base, [$sideField => $key->locationId])]);

	}//end postedMovesOnSide()

	/**
	 * The array moves with an id, de-duplicated by id (the last one wins).
	 *
	 * @param array<int,mixed> $moves Moves from both sides.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function uniqueById(array $moves): array {
		$byId = [];
		foreach ($moves as $move) {
			if (is_array($move) === false) {
				continue;
			}

			$id = (string)($move['id'] ?? ($move['@self']['id'] ?? ''));
			if ($id !== '') {
				$byId[$id] = $move;
			}
		}

		return array_values($byId);

	}//end uniqueById()

	/**
	 * Fetch all draft StockMoves whose source is this (sku, location).
	 *
	 * @param StockLocationKey $key The SKU at its location.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function draftMovesForSource(StockLocationKey $key): array {
		if ($key->isIncomplete() === true) {
			return [];
		}

		// ADR-084: findAll() is declared `: array` — never null, always an array.
		return $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(
				[
					'filters' => [
						'administrationId' => $key->administrationId,
						'sourceLocationId' => $key->locationId,
						'itemId' => $key->sku,
						'lifecycleState' => 'draft',
					],
				]
			);
	}//end draftMovesForSource()

	/**
	 * Convert a money/quantity value to integer cents (multipleOf 0.01).
	 *
	 * @param mixed $value Schema number (float or int).
	 *
	 * @return int
	 */
	private function cents(mixed $value): int {
		if (is_int($value) === true) {
			return ($value * 100);
		}

		return (int)round(((float)$value) * 100);
	}//end cents()

	/**
	 * Convert integer cents back to a 2-decimal float.
	 *
	 * @param int $cents Integer cents.
	 *
	 * @return float
	 */
	private function fromCents(int $cents): float {
		return ((float)$cents / 100.0);
	}//end fromCents()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
