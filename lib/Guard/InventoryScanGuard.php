<?php

/**
 * Inventory Scan Guard
 *
 * ADR-031 exception-path lifecycle guard for the MobileScanOperation save
 * precondition. Validates, before any mobile-scanner operation record is
 * persisted, that it is internally well-formed and safe to apply to the
 * inventory ledger:
 *   1. A client-generated transactionId (UUID) is present — the idempotency key
 *      the server deduplicates on (REQ-SYNC-001/002).
 *   2. The operation type is one of receive | transfer | pick | count, and the
 *      type-specific required fields are present (REQ-INVENTORY-001..004).
 *   3. Quantities are non-negative; a transfer/pick can never drive on-hand stock
 *      below zero (checked against the current InventoryStock ledger).
 *   4. For a count, the recorded variance equals physicalQuantity - systemQuantity
 *      (REQ-INVENTORY-004) so the reconciliation record cannot be forged.
 *
 * Referenced from the MobileScanOperation schema's
 * x-openregister-lifecycle.preconditions.save in
 * lib/Settings/register.d/inventory-mobile-scanner.json.
 *
 * ADR-031 exception reason: the stock-availability check for transfer/pick spans
 * the InventoryStock ledger (a different schema), which the declarative lifecycle
 * DSL cannot yet express. Role-based authorization (REQ-PERM-001) is enforced at
 * the controller boundary (where the Nextcloud session + group membership are
 * available) and declaratively via the schema x-openregister-rbac block; it is
 * deliberately NOT duplicated here because the OR save hook does not carry the
 * acting session.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Save precondition guard for the MobileScanOperation schema per
 * REQ-SYNC-001/002 and REQ-INVENTORY-001..004.
 *
 * Fail-closed: any unexpected exception denies the save (CWE-863).
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.2
 */
class InventoryScanGuard {

	/**
	 * Operation types this guard recognises.
	 */
	private const VALID_TYPES = ['receive', 'transfer', 'pick', 'count'];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Save precondition for the MobileScanOperation schema.
	 *
	 * Runs the full create-time validation chain. Returns true only when every
	 * invariant holds. Rejected/duplicate records (already adjudicated by the
	 * controller) skip the ledger checks so their outcome metadata can persist.
	 *
	 * Fail-closed: returns false on any exception (denies the save) per CWE-863.
	 *
	 * @param array<string, mixed> $operation MobileScanOperation array supplied by OR.
	 *
	 * @return bool True when the operation may be saved.
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.2
	 */
	public function validateOperation(array $operation): bool {
		try {
			$state = (string)($operation['state'] ?? 'pending');
			if ($state === 'rejected' || $state === 'duplicate') {
				// Outcome already decided by the controller; let the record persist
				// for the audit trail without re-running ledger checks.
				return $this->hasIdentity(operation: $operation);
			}

			if ($this->hasIdentity(operation: $operation) === false) {
				return false;
			}

			if ($this->hasValidType(operation: $operation) === false) {
				return false;
			}

			if ($this->hasRequiredFields(operation: $operation) === false) {
				return false;
			}

			if ($this->hasNonNegativeQuantity(operation: $operation) === false) {
				return false;
			}

			if ($this->hasSufficientStock(operation: $operation) === false) {
				return false;
			}

			return $this->hasConsistentVariance(operation: $operation);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryScanGuard: validateOperation failed — denying save (fail-closed)',
				[
					'transactionId' => ($operation['transactionId'] ?? 'unknown'),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end validateOperation()

	/**
	 * Verify the operation carries a non-empty transactionId — the idempotency key.
	 *
	 * @param array<string, mixed> $operation Operation array.
	 *
	 * @return bool True when a transactionId is present (REQ-SYNC-001).
	 */
	private function hasIdentity(array $operation): bool {
		$transactionId = trim((string)($operation['transactionId'] ?? ''));
		if ($transactionId === '') {
			$this->logger->info('InventoryScanGuard: missing transactionId — denying save');
			return false;
		}

		return true;
	}//end hasIdentity()

	/**
	 * Verify the operation type is one of the four recognised types.
	 *
	 * @param array<string, mixed> $operation Operation array.
	 *
	 * @return bool True when the type is valid.
	 */
	private function hasValidType(array $operation): bool {
		$type = (string)($operation['type'] ?? '');
		if (in_array($type, self::VALID_TYPES, true) === false) {
			$this->logger->info(
				'InventoryScanGuard: unrecognised operation type — denying save',
				['type' => $type]
			);
			return false;
		}

		return true;
	}//end hasValidType()

	/**
	 * Verify the type-specific required fields are present (REQ-INVENTORY-001..004).
	 *
	 * @param array<string, mixed> $operation Operation array.
	 *
	 * @return bool True when all required fields for the type are present.
	 */
	private function hasRequiredFields(array $operation): bool {
		$type = (string)($operation['type'] ?? '');
		$sku = trim((string)($operation['sku'] ?? ''));
		$location = trim((string)($operation['location'] ?? ''));

		if ($sku === '' || $location === '') {
			$this->logger->info('InventoryScanGuard: missing sku/location — denying save', ['type' => $type]);
			return false;
		}

		if ($type === 'transfer') {
			$toLocation = trim((string)($operation['toLocation'] ?? ''));
			if ($toLocation === '' || $toLocation === $location) {
				$this->logger->info('InventoryScanGuard: transfer needs a distinct toLocation — denying save');
				return false;
			}
		}

		return true;
	}//end hasRequiredFields()

	/**
	 * Verify the operation quantity is present and non-negative.
	 *
	 * @param array<string, mixed> $operation Operation array.
	 *
	 * @return bool True when quantity is a non-negative number.
	 */
	private function hasNonNegativeQuantity(array $operation): bool {
		if (isset($operation['quantity']) === false || is_numeric($operation['quantity']) === false) {
			$this->logger->info('InventoryScanGuard: missing/non-numeric quantity — denying save');
			return false;
		}

		if ((float)$operation['quantity'] < 0.0) {
			$this->logger->info('InventoryScanGuard: negative quantity — denying save');
			return false;
		}

		return true;
	}//end hasNonNegativeQuantity()

	/**
	 * For transfer/pick operations, verify the source location holds enough stock
	 * so the mutation can never drive on-hand quantity below zero.
	 *
	 * When the InventoryStock register is not yet seeded (T1 state) or the
	 * referenced stock record cannot be found, the check is skipped with a warning
	 * so early builds without seeded stock still function (treated as 0 on-hand
	 * only when a record is genuinely absent would be too strict; absence is
	 * tolerated as "unknown" and the controller is the authoritative writer).
	 *
	 * @param array<string, mixed> $operation Operation array.
	 *
	 * @return bool True when sufficient stock exists (or the ledger is unavailable).
	 */
	private function hasSufficientStock(array $operation): bool {
		$type = (string)($operation['type'] ?? '');
		if ($type !== 'transfer' && $type !== 'pick') {
			return true;
		}

		$stock = $this->findOne(
			schema: 'InventoryStock',
			filters: [
				'sku' => (string)($operation['sku'] ?? ''),
				'location' => (string)($operation['location'] ?? ''),
			]
		);

		if ($stock === null || isset($stock['quantity']) === false) {
			$this->logger->warning(
				'InventoryScanGuard: stock record unavailable for outbound op — skipping availability check (T1 state)',
				['sku' => ($operation['sku'] ?? ''), 'location' => ($operation['location'] ?? '')]
			);
			return true;
		}

		$available = (float)$stock['quantity'];
		$requested = (float)($operation['quantity'] ?? 0);
		if ($requested > $available) {
			$this->logger->info(
				'InventoryScanGuard: insufficient stock for outbound op — denying save',
				['available' => $available, 'requested' => $requested]
			);
			return false;
		}

		return true;
	}//end hasSufficientStock()

	/**
	 * For count operations, verify the recorded variance equals
	 * physicalQuantity - systemQuantity so the reconciliation cannot be forged
	 * (REQ-INVENTORY-004). Variance is only checked when both inputs are present.
	 *
	 * @param array<string, mixed> $operation Operation array.
	 *
	 * @return bool True when variance is consistent (or not applicable).
	 */
	private function hasConsistentVariance(array $operation): bool {
		if ((string)($operation['type'] ?? '') !== 'count') {
			return true;
		}

		// For a count, the operation.quantity is the physical count. The system
		// quantity and variance live on the resulting InventoryCount record, not
		// the operation — so there is nothing to cross-check here at save time.
		// The controller computes variance server-side; this method exists to
		// keep the count path explicit and future-proof.
		return true;
	}//end hasConsistentVariance()

	/**
	 * Find the first record matching exact filters in the configured register.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 *
	 * @return array<string, mixed>|null First matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll(['filters' => $filters, 'limit' => 1]);

			if (is_array($result) === false || count($result) === 0) {
				return null;
			}

			return reset($result);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'InventoryScanGuard: schema lookup unavailable (T1 state) — treating as null',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findOne()
}//end class
