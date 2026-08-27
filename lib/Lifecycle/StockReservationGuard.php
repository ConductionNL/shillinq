<?php

/**
 * Stock Reservation Guard
 *
 * ADR-031 exception-path service implementing the optimistic-lock
 * compare-and-swap (CAS) reservation workflow declared in
 * REQ-SM-004 + REQ-SM-005. Three operations:
 *
 *   - reserveReservation()  — invoked when a StockMove is created in
 *     `draft` with a `sourceLocationId`. Reads the InventoryStock row
 *     for (administrationId, locationId=source, sku=item) and bumps
 *     `reservedQuantity` by `move.quantity` via CAS on `version`. On
 *     CAS collision returns false; the caller surfaces a "another
 *     operator is updating this location" message.
 *
 *   - commitReservation()   — invoked on draft → posted. Decrements
 *     `reservedQuantity` by `move.quantity`; decrements source
 *     `quantity` (when source present); increments destination
 *     `quantity` (when destination present). Atomic CAS per row.
 *
 *   - releaseReservation()  — invoked on draft → cancelled. Decrements
 *     `reservedQuantity` by `move.quantity`; no on-hand change.
 *
 * All arithmetic is integer-cent (multipleOf 0.01 schema discipline)
 * so CAS comparisons remain bit-exact across IEEE-754 round-trips.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Per REQ-SM-004 reservation CAS + REQ-SM-005 stock-ledger commit.
 *
 * Referenced from inventory-stock-movement-ledger.json
 * StockMove.x-openregister-lifecycle.transitions.post.actions[1] and
 * .transitions.cancel.actions[1].
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)         Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-7
 */
class StockReservationGuard {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs the full payload.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Reserve `move.quantity` on the source InventoryStock row.
	 *
	 * Returns true when the CAS succeeded; false on collision (caller retries
	 * after re-reading the latest InventoryStock state per REQ-SM-004) or
	 * any error (fail-closed — never silently over-allocates).
	 *
	 * @param array<string,mixed> $move The draft StockMove triggering the reservation.
	 *
	 * @return bool True on successful reservation; false on collision / error.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-7
	 */
	public function reserveReservation(array $move): bool {
		try {
			$sourceLocationId = ($move['sourceLocationId'] ?? null);
			if ($sourceLocationId === null || $sourceLocationId === '') {
				// Receipt-only move: no source, no reservation.
				return true;
			}

			$cents = $this->cents(value: ($move['quantity'] ?? 0));
			if ($cents <= 0) {
				return true;
			}

			$row = $this->findInventoryStock(
				administrationId: (string)($move['administrationId'] ?? ''),
				locationId: (string)$sourceLocationId,
				sku: (string)($move['itemId'] ?? '')
			);
			if ($row === null) {
				$this->logger->info(
					'StockReservationGuard: reserve denied — no InventoryStock row',
					[
						'movementNumber' => ($move['movementNumber'] ?? null),
						'sku' => ($move['itemId'] ?? null),
						'locationId' => $sourceLocationId,
					]
				);
				return false;
			}

			$availableCents = ($this->cents(value: ($row['quantity'] ?? 0)) - $this->cents(value: ($row['reservedQuantity'] ?? 0)));
			if ($availableCents < $cents) {
				$this->logger->info(
					'StockReservationGuard: reserve denied — insufficient available',
					[
						'movementNumber' => ($move['movementNumber'] ?? null),
						'availableCents' => $availableCents,
						'requestedCents' => $cents,
					]
				);
				return false;
			}

			$newReservedCents = ($this->cents(value: ($row['reservedQuantity'] ?? 0)) + $cents);
			$patch = [
				'reservedQuantity' => $this->fromCents(cents: $newReservedCents),
				'version' => ((int)($row['version'] ?? 0) + 1),
			];

			return $this->casUpdate(row: $row, patch: $patch);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockReservationGuard: reserveReservation failed — denying (fail-closed)',
				[
					'movementNumber' => ($move['movementNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end reserveReservation()

	/**
	 * Commit the reservation: release the reserved hold, decrement source
	 * on-hand and increment destination on-hand per REQ-SM-005.
	 *
	 * @param array<string,mixed> $move The StockMove being posted.
	 *
	 * @return bool True on success; false on CAS collision or error.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-7
	 */
	public function commitReservation(array $move): bool {
		try {
			$cents = $this->cents(value: ($move['quantity'] ?? 0));
			if ($cents <= 0) {
				return true;
			}

			$administrationId = (string)($move['administrationId'] ?? '');
			$sku = (string)($move['itemId'] ?? '');
			$sourceLocationId = ($move['sourceLocationId'] ?? null);
			$destLocationId = ($move['destinationLocationId'] ?? null);

			// Source: release reservation + decrement on-hand.
			if ($sourceLocationId !== null && $sourceLocationId !== '') {
				$source = $this->findInventoryStock(
					administrationId: $administrationId,
					locationId: (string)$sourceLocationId,
					sku: $sku
				);
				if ($source === null) {
					return false;
				}

				$sourceReservedCents = $this->cents(value: ($source['reservedQuantity'] ?? 0));
				$sourceOnHandCents = $this->cents(value: ($source['quantity'] ?? 0));
				if ($sourceOnHandCents < $cents || $sourceReservedCents < $cents) {
					$this->logger->info(
						'StockReservationGuard: commit denied — source reservation/on-hand inconsistent',
						[
							'movementNumber' => ($move['movementNumber'] ?? null),
							'sourceLocation' => $sourceLocationId,
						]
					);
					return false;
				}

				$patch = [
					'reservedQuantity' => $this->fromCents(cents: $sourceReservedCents - $cents),
					'quantity' => $this->fromCents(cents: $sourceOnHandCents - $cents),
					'lastMovementDate' => date('Y-m-d'),
					'version' => ((int)($source['version'] ?? 0) + 1),
				];

				if ($this->casUpdate(row: $source, patch: $patch) === false) {
					return false;
				}
			}//end if

			// Destination: increment on-hand (create row if absent — first delivery
			// to a bin).
			if ($destLocationId !== null && $destLocationId !== '') {
				$destination = $this->findInventoryStock(
					administrationId: $administrationId,
					locationId: (string)$destLocationId,
					sku: $sku
				);
				if ($destination === null) {
					return $this->createInventoryStock(
						administrationId: $administrationId,
						locationId: (string)$destLocationId,
						sku: $sku,
						quantityCents: $cents,
						unitCost: ((float)($move['unitCost'] ?? 0))
					);
				}

				$destOnHandCents = ($this->cents(value: ($destination['quantity'] ?? 0)) + $cents);
				$patch = [
					'quantity' => $this->fromCents(cents: $destOnHandCents),
					'lastMovementDate' => date('Y-m-d'),
					'version' => ((int)($destination['version'] ?? 0) + 1),
				];

				if ($this->casUpdate(row: $destination, patch: $patch) === false) {
					return false;
				}
			}//end if

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockReservationGuard: commitReservation failed — denying (fail-closed)',
				[
					'movementNumber' => ($move['movementNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end commitReservation()

	/**
	 * Release the reservation on draft cancellation (no on-hand change).
	 *
	 * @param array<string,mixed> $move The draft StockMove being cancelled.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-7
	 */
	public function releaseReservation(array $move): bool {
		try {
			$sourceLocationId = ($move['sourceLocationId'] ?? null);
			if ($sourceLocationId === null || $sourceLocationId === '') {
				return true;
			}

			$cents = $this->cents(value: ($move['quantity'] ?? 0));
			if ($cents <= 0) {
				return true;
			}

			$row = $this->findInventoryStock(
				administrationId: (string)($move['administrationId'] ?? ''),
				locationId: (string)$sourceLocationId,
				sku: (string)($move['itemId'] ?? '')
			);
			if ($row === null) {
				// No reservation to release.
				return true;
			}

			$currentReservedCents = $this->cents(value: ($row['reservedQuantity'] ?? 0));
			$newReservedCents = max(0, ($currentReservedCents - $cents));
			$patch = [
				'reservedQuantity' => $this->fromCents(cents: $newReservedCents),
				'version' => ((int)($row['version'] ?? 0) + 1),
			];

			return $this->casUpdate(row: $row, patch: $patch);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockReservationGuard: releaseReservation failed — denying (fail-closed)',
				[
					'movementNumber' => ($move['movementNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end releaseReservation()

	/**
	 * Find the InventoryStock row for an (admin, location, sku) triple.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Bin location id.
	 * @param string $sku Product SKU.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findInventoryStock(string $administrationId, string $locationId, string $sku): ?array {
		if ($administrationId === '' || $locationId === '' || $sku === '') {
			return null;
		}

		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryStock')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'locationId' => $locationId,
						'sku' => $sku,
					],
				]
			);

		if (count($rows) === 0) {
			return null;
		}

		return $rows[0];
	}//end findInventoryStock()

	/**
	 * CAS update on the InventoryStock row. The `version` field is the
	 * optimistic-lock token; if a concurrent write bumped it the update
	 * fails per REQ-SM-004 ("Cannot reserve; another operator is updating
	 * this location").
	 *
	 * @param array<string,mixed> $row The current InventoryStock row.
	 * @param array<string,mixed> $patch The fields to update (including version+1).
	 *
	 * @return bool True on success.
	 */
	private function casUpdate(array $row, array $patch): bool {
		try {
			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($id === '') {
				return false;
			}

			// Re-read the canonical row to detect concurrent writes; abort on version
			// mismatch (CAS) so the operator retries with fresh state.
			$current = $this->objectService
				->setRegister($this->register())
				->setSchema('InventoryStock')
				->find($id);
			if ($current === null) {
				return false;
			}

			// ADR-084: find() returns an ObjectEntityInterface, so the is_array()
			// arm was unreachable and `(array)$current` cast the ENTITY — its
			// `version` key was absent, so the compare-and-set below read every
			// row as version 0 and reported a collision against any non-zero row.
			$currentArray = (array)$current->jsonSerialize();

			if ((int)($currentArray['version'] ?? 0) !== (int)($row['version'] ?? 0)) {
				$this->logger->info('StockReservationGuard: CAS collision', ['id' => $id]);
				return false;
			}

			$merged = array_merge($currentArray, $patch);
			// ADR-084: the contract declares updateObject(string $objectId, array $data,
			// bool $_rbac, bool $_multitenancy). The register/schema scope is carried by
			// the setRegister()/setSchema() chain above, so passing them here raised
			// "Unknown named parameter $register" — swallowed by the catch below, which
			// made every compare-and-set silently report a collision instead of writing.
			$this->objectService
				->setRegister($this->register())
				->setSchema('InventoryStock')
				->updateObject(objectId: $id, data: $merged);

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockReservationGuard: CAS update failed',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end casUpdate()

	/**
	 * Create an InventoryStock row for a destination location that has not
	 * yet received stock for the SKU. Initial quantity = move.quantity,
	 * reservedQuantity = 0, version = 1.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Destination bin id.
	 * @param string $sku Product SKU.
	 * @param int $quantityCents Initial on-hand in integer cents.
	 * @param float $unitCost Unit cost from the move.
	 *
	 * @return bool True on success.
	 */
	private function createInventoryStock(
		string $administrationId,
		string $locationId,
		string $sku,
		int $quantityCents,
		float $unitCost,
	): bool {
		try {
			$row = [
				'administrationId' => $administrationId,
				'locationId' => $locationId,
				'sku' => $sku,
				'quantity' => $this->fromCents(cents: $quantityCents),
				'reservedQuantity' => 0,
				'unitCost' => $unitCost,
				'lastMovementDate' => date('Y-m-d'),
				'version' => 1,
			];

			$this->objectService->saveObject(object: $row, register: $this->register(), schema: 'InventoryStock');
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockReservationGuard: createInventoryStock failed',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end createInventoryStock()

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
	 * Convert integer cents back to a float quantity with 2-decimal precision.
	 *
	 * @param int $cents Integer cents.
	 *
	 * @return float
	 */
	private function fromCents(int $cents): float {
		return ((float)$cents / 100.0);
	}//end fromCents()

	/**
	 * Resolve the register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Predicate: the proposed InventoryStock's quantityReserved does not
	 * exceed its quantityOnHand.
	 *
	 * Returns true (operation permitted) when reserved <= on-hand. Returns
	 * false (operation denied) when reserved > on-hand OR on any
	 * exception (fail-closed). Referenced by the InventoryStock schema's
	 * onCreate + onUpdate validations per REQ-IST-013 of the
	 * inventory-stock-tracking spec — separate from the StockMove
	 * reservation cycle handled by reserveReservation() /
	 * commitReservation() / releaseReservation() above. The two paths
	 * share the class because they share the same conceptual invariant
	 * ("never over-allocate reservation against on-hand") and the same
	 * fail-closed default.
	 *
	 * @param array<string,mixed> $stock The proposed InventoryStock record.
	 *
	 * @return bool True when the reservation is collateralised.
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-16
	 */
	public function checkReservationDoesNotExceedOnHand(array $stock): bool {
		try {
			$reserved = (float)($stock['quantityReserved'] ?? 0);
			$onHand = (float)($stock['quantityOnHand'] ?? 0);

			if ($reserved > $onHand) {
				$this->logger->info(
					'StockReservationGuard: InventoryStock operation denied — reservation exceeds on-hand',
					[
						'productSku' => ($stock['productSku'] ?? null),
						'locationCode' => ($stock['locationCode'] ?? null),
						'quantityReserved' => $reserved,
						'quantityOnHand' => $onHand,
					]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockReservationGuard: checkReservationDoesNotExceedOnHand failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end checkReservationDoesNotExceedOnHand()
}//end class
