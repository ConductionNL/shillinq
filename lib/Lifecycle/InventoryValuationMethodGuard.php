<?php

/**
 * Inventory Valuation Method Guard
 *
 * Thin ADR-031 lifecycle guard for the InventoryValuation schema. One
 * predicate — `checkZeroStock()` — referenced by:
 *
 *   - the `methodChange` self-loop transition (FIFO <-> average) per
 *     REQ-INV-006: switching method while stock is on hand would
 *     re-value existing cost layers and distort COGS, so it is blocked
 *     until quantity = 0;
 *   - the `obsoleteFromActive` / `obsoleteFromAdjusted` transitions per
 *     REQ-INV-009: marking a snapshot obsolete is only meaningful when
 *     no stock remains;
 *   - the `validations.onUpdate.methodChangeRequiresZeroStock` rule
 *     (defence-in-depth so a generic OR CRUD patch of
 *     `valuationMethod` cannot bypass the transition guard).
 *
 * Fail-closed: any exception denies the transition. ADR-031
 * §"PHP guards remain a legitimate seam" — the declarative DSL cannot
 * compose the cross-state-machine predicate with the configurable
 * shillinq error vocabulary that the operator-facing UI surfaces.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
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
 * Zero-stock precondition guard for InventoryValuation transitions
 * (REQ-INV-006 + REQ-INV-009).
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 */
class InventoryValuationMethodGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Predicate: the InventoryValuation's on-hand quantity is zero.
	 *
	 * Returns true (transition permitted) when quantity <= 0. Returns
	 * false (transition denied) when quantity > 0 OR on any exception
	 * (fail-closed).
	 *
	 * @param array<string,mixed> $valuation The InventoryValuation record.
	 *
	 * @return bool True when on-hand quantity is zero.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
	 */
	public function checkZeroStock(array $valuation): bool {
		try {
			$quantity = (float)($valuation['quantity'] ?? 0);
			if ($quantity > 0) {
				$this->logger->info(
					'InventoryValuationMethodGuard: transition denied — non-zero stock',
					[
						'productId' => ($valuation['productId'] ?? null),
						'warehouse' => ($valuation['warehouse'] ?? null),
						'quantity' => $quantity,
						'valuationMethod' => ($valuation['valuationMethod'] ?? null),
					]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryValuationMethodGuard: checkZeroStock failed — denying transition (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end checkZeroStock()

	/**
	 * Predicate: no other active InventoryValuation snapshot exists for
	 * the same (productId, warehouse, administrationId) tuple per
	 * REQ-INV-005.
	 *
	 * Permits the create/transition when no other active row is found
	 * OR when the only match is the snapshot itself (by id). Denies on
	 * duplicate; fail-closed on exception.
	 *
	 * @param array<string,mixed> $proposed The proposed InventoryValuation record.
	 *
	 * @return bool True when uniqueness is respected.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-14
	 */
	public function checkUniqueActiveSnapshot(array $proposed): bool {
		try {
			$productId = (string)($proposed['productId'] ?? '');
			$warehouse = (string)($proposed['warehouse'] ?? '');
			$administrationId = (string)($proposed['administrationId'] ?? '');
			$selfId = (string)($proposed['id'] ?? ($proposed['@self']['id'] ?? ''));

			// Snapshots without the tuple coordinates are accepted — the
			// schema-level required-fields validation handles those cases.
			if ($productId === '' || $warehouse === '' || $administrationId === '') {
				return true;
			}

			$existing = $this->objectService
				->setRegister($this->register())
				->setSchema('InventoryValuation')
				->findAll(
					[
						'filters' => [
							'productId' => $productId,
							'warehouse' => $warehouse,
							'status' => 'active',
							'administrationId' => $administrationId,
						],
					]
				);

			foreach ($existing as $row) {
				$rowId = '';
				if (is_array($row) === true) {
					$rowId = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
				} elseif (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$data = $row->jsonSerialize();
					if (is_array($data) === true) {
						$rowId = (string)($data['id'] ?? ($data['@self']['id'] ?? ''));
					}
				}

				if ($rowId !== '' && $rowId === $selfId) {
					continue;
				}

				$this->logger->info(
					'InventoryValuationMethodGuard: duplicate active snapshot blocked',
					[
						'productId' => $productId,
						'warehouse' => $warehouse,
						'administrationId' => $administrationId,
					]
				);
				return false;
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryValuationMethodGuard: checkUniqueActiveSnapshot failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end checkUniqueActiveSnapshot()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
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
}//end class
