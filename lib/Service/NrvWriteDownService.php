<?php

/**
 * NRV Write-Down Service
 *
 * Period-end lower-of-cost-or-net-realisable-value adjustment for
 * inventory, mandated by RJ 220.301 and IAS 2.9 ("inventories shall be
 * measured at the lower of cost and net realisable value"). FIFO or
 * moving-average alone cannot produce a compliant balance sheet: when the
 * expected selling price less costs to complete and sell (NRV) falls below
 * carrying cost, the shortfall MUST be expensed and inventory written down.
 *
 * For one active `InventoryValuation` snapshot and an operator-supplied NRV
 * per unit, the service:
 *
 *   - computes `writeDown = (unitCost − nrvPerUnit) × quantity` **only when
 *     `nrvPerUnit < unitCost`**; when `nrvPerUnit >= unitCost` it is a
 *     strict no-op — the lower-of rule NEVER writes inventory UP (no
 *     reversal above historical cost, no gain recognition);
 *   - posts ONE balanced `GLTransaction` via
 *     {@see InventoryGlAdjustmentPoster}: debit inventory write-down expense
 *     (default `7050`), credit Inventory asset (default `1300`);
 *   - re-marks the snapshot to NRV (`unitCost = nrvPerUnit`,
 *     `totalValue = quantity × nrvPerUnit`, `status = adjusted`).
 *
 * LIFO is deliberately NOT supported anywhere in this register — IAS 2.25
 * prohibits it; the two costing methods remain FIFO and weighted average.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lower-of-cost-or-NRV period-end write-down + balanced GL (ADR-031 exception).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
class NrvWriteDownService {

	/**
	 * App-config key for the inventory asset account (RGS 3.5 default '1300').
	 */
	public const CFG_INVENTORY_ACCOUNT = 'inventory_account';

	/**
	 * App-config key for the inventory write-down expense account (RGS default '7050').
	 */
	public const CFG_WRITEDOWN_ACCOUNT = 'inventory_writedown_account';

	/**
	 * Documented default inventory asset account.
	 */
	private const DEFAULT_INVENTORY_ACCOUNT = '1300';

	/**
	 * Documented default write-down expense account (afwaardering voorraden).
	 */
	private const DEFAULT_WRITEDOWN_ACCOUNT = '7050';

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for account numbers + register slug.
	 * @param InventoryGlAdjustmentPoster $poster Shared balanced-posting adapter.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs full payloads.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly InventoryGlAdjustmentPoster $poster,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Apply lower-of-cost-or-NRV to one InventoryValuation snapshot.
	 *
	 * @param array<string,mixed> $valuation The active InventoryValuation snapshot (cost basis).
	 * @param float $nrvPerUnit Net realisable value per unit (operator input).
	 * @param string $periodId Period identifier (e.g. 2026-Q4).
	 * @param string $postingDate ISO yyyy-mm-dd; empty defaults to today.
	 *
	 * @return array<string,mixed> Result envelope: 'posted' bool, 'writeDownCents', 'posting', 'valuation'.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function writeDown(
		array $valuation,
		float $nrvPerUnit,
		string $periodId,
		string $postingDate = '',
	): array {
		$unitCost = (float)($valuation['unitCost'] ?? 0);
		$quantity = (float)($valuation['quantity'] ?? 0);

		// Lower-of-cost-or-NRV: NEVER write up. NRV at or above cost is a no-op.
		if ($nrvPerUnit >= $unitCost) {
			return [
				'posted' => false,
				'writeDownCents' => 0,
				'valuation' => $valuation,
				'message' => 'NRV >= cost — no write-down (lower-of-cost-or-NRV never writes up)',
			];
		}

		$writeDownCents = (int)round((($unitCost - $nrvPerUnit) * $quantity) * 100);
		if ($writeDownCents <= 0) {
			return [
				'posted' => false,
				'writeDownCents' => 0,
				'valuation' => $valuation,
				'message' => 'write-down rounds to zero — nothing to post',
			];
		}

		$administrationId = (string)($valuation['administrationId'] ?? '');
		$itemId = (string)($valuation['productId'] ?? '');
		$effectiveDate = date('Y-m-d');
		if ($postingDate !== '') {
			$effectiveDate = substr($postingDate, 0, 10);
		}

		$sourceReference = 'inventory';
		if ($itemId !== '') {
			$sourceReference = $itemId;
		}

		$posting = $this->poster->post(
			administrationId: $administrationId,
			debitAccount: $this->writeDownAccount(),
			creditAccount: $this->inventoryAccount(),
			amountCents: $writeDownCents,
			journalCode: 'NRV',
			description: $this->describe(itemId: $itemId, writeDownCents: $writeDownCents, nrvPerUnit: $nrvPerUnit),
			sourceReference: $sourceReference,
			postingDate: $effectiveDate,
			periodId: $periodId
		);

		if (((bool)($posting['posted'] ?? false)) !== true) {
			$this->logger->warning(
				'NrvWriteDownService: write-down computed but GL post did not succeed',
				[
					'productId' => $itemId,
					'writeDownCents' => $writeDownCents,
					'reason' => (string)($posting['message'] ?? ''),
				]
			);

			return [
				'posted' => false,
				'writeDownCents' => $writeDownCents,
				'posting' => $posting,
				'valuation' => $valuation,
				'message' => 'write-down computed but GL post failed: ' . ((string)($posting['message'] ?? '')),
			];
		}

		// Re-mark the snapshot to NRV.
		$newTotalCents = (int)round(($nrvPerUnit * $quantity) * 100);
		$valuation['unitCost'] = round($nrvPerUnit, 4);
		$valuation['totalValue'] = round(($newTotalCents / 100), 2);
		$valuation['status'] = 'adjusted';
		$savedValuation = $this->saveValuation(data: $valuation);

		return [
			'posted' => true,
			'writeDownCents' => $writeDownCents,
			'posting' => $posting,
			'valuation' => $savedValuation,
			'message' => 'inventory written down to NRV',
		];

	}//end writeDown()

	/**
	 * Run lower-of-cost-or-NRV across every active snapshot for an
	 * administration, driven by an operator-supplied NRV map keyed by
	 * productId.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $periodId Period identifier.
	 * @param array<string,float> $nrvBySku Map productId => NRV per unit.
	 * @param string $postingDate ISO yyyy-mm-dd; empty defaults to today.
	 *
	 * @return array<string,mixed> Batch envelope: 'writeDownCount', 'totalWriteDownCents', 'results'.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function runForAdministration(
		string $administrationId,
		string $periodId,
		array $nrvBySku,
		string $postingDate = '',
	): array {
		$results = [];
		$writeDownCount = 0;
		$totalCents = 0;

		foreach ($this->activeValuations(administrationId: $administrationId) as $valuation) {
			$itemId = (string)($valuation['productId'] ?? '');
			if ($itemId === '' || array_key_exists($itemId, $nrvBySku) === false) {
				continue;
			}

			$result = $this->writeDown(
				valuation: $valuation,
				nrvPerUnit: (float)$nrvBySku[$itemId],
				periodId: $periodId,
				postingDate: $postingDate
			);
			$results[] = $result;
			if (((bool)($result['posted'] ?? false)) === true) {
				$writeDownCount++;
				$totalCents += (int)($result['writeDownCents'] ?? 0);
			}
		}

		return [
			'administrationId' => $administrationId,
			'periodId' => $periodId,
			'writeDownCount' => $writeDownCount,
			'totalWriteDownCents' => $totalCents,
			'results' => $results,
		];

	}//end runForAdministration()

	/**
	 * Load every active InventoryValuation snapshot for an administration.
	 *
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function activeValuations(string $administrationId): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryValuation')
			->findAll(
				[
					'filters' => [
						'status' => 'active',
						'administrationId' => $administrationId,
					],
				]
			);

		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->asArray(row: $row);
		}

		return $out;
	}//end activeValuations()

	/**
	 * Persist an InventoryValuation snapshot.
	 *
	 * @param array<string,mixed> $data Snapshot data.
	 *
	 * @return array<string,mixed>
	 */
	private function saveValuation(array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryValuation')
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
		// is_array() test was constant — asArray() is the only path that runs.
		return $this->asArray(row: $saved);
	}//end saveValuation()

	/**
	 * Compose the human-readable GL description.
	 *
	 * @param string $itemId Product id.
	 * @param int $writeDownCents Write-down in cents.
	 * @param float $nrvPerUnit NRV per unit.
	 *
	 * @return string
	 */
	private function describe(string $itemId, int $writeDownCents, float $nrvPerUnit): string {
		return sprintf(
			'NRV write-down — %s — EUR %s (to NRV %s/unit)',
			$itemId,
			number_format(($writeDownCents / 100), 2, ',', ''),
			number_format($nrvPerUnit, 4, ',', '')
		);

	}//end describe()

	/**
	 * Resolve the inventory asset account.
	 *
	 * @return string
	 */
	private function inventoryAccount(): string {
		$configured = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				self::CFG_INVENTORY_ACCOUNT,
				self::DEFAULT_INVENTORY_ACCOUNT
			)
		);
		if ($configured === '') {
			return self::DEFAULT_INVENTORY_ACCOUNT;
		}

		return $configured;
	}//end inventoryAccount()

	/**
	 * Resolve the inventory write-down expense account.
	 *
	 * @return string
	 */
	private function writeDownAccount(): string {
		$configured = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				self::CFG_WRITEDOWN_ACCOUNT,
				self::DEFAULT_WRITEDOWN_ACCOUNT
			)
		);
		if ($configured === '') {
			return self::DEFAULT_WRITEDOWN_ACCOUNT;
		}

		return $configured;
	}//end writeDownAccount()

	/**
	 * Normalise an OR Object / array to a plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		throw new RuntimeException('NrvWriteDownService: unsupported row type from ObjectService');
	}//end asArray()

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
