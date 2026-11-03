<?php

/**
 * Shillinq inventory demo seeds
 *
 * Seeds the inventory demo records (barcodes, lots, valuations, stock and the
 * GL account configuration) from the files under lib/Settings/seeds/.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;
use Psr\Log\LoggerInterface;

/**
 * Seeds the inventory demo records through OpenRegister's ObjectService.
 *
 * SettingsService runs these seeds and supplies the OpenRegister availability
 * check and the configured default administration.
 *
 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
 */
class InventorySeedService {

	/**
	 * Result message when OpenRegister is missing.
	 *
	 * @var string
	 */
	private const NO_OPENREGISTER = 'OpenRegister is not installed or enabled.';

	/**
	 * Constructor for the InventorySeedService.
	 *
	 * @param SettingsService $settings The settings service
	 * @param SeedImporter $importer The seed file importer
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settings,
		private SeedImporter $importer,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed demo Barcode records from inventory-barcodes-demo.json, idempotently.
	 *
	 * Reads `lib/Settings/seeds/inventory-barcodes-demo.json` and imports
	 * Barcode records via OpenRegister's ObjectService. Deduplication key is
	 * `(barcode, uomCode)` per REQ-SKU-011 — re-running the repair step never
	 * creates duplicates, preserving operator edits. The seeded barcodes
	 * reference inventory-product-catalog demo SKUs (DV-KAT-SENIOR-2KG,
	 * VIT-C-1000MG-100CT); when those Products are absent the barcodes still
	 * load and become discoverable once the products land.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-15
	 */
	public function seedInventoryBarcodes(): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		$file = $this->importer->read(fileName: 'inventory-barcodes-demo.json');
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		$barcodes = ($file['data']['barcodes'] ?? []);
		if (empty($barcodes) === true) {
			return $this->importer->nothingSeeded(message: 'Seed file contains no barcodes, nothing to import.');
		}

		// Deduplication key (barcode, uomCode): idempotent re-runs per REQ-SKU-011.
		$match = static fn ($barcode): array => [
			'barcode' => ($barcode['barcode'] ?? ''),
			'uomCode' => ($barcode['uomCode'] ?? ''),
		];

		return $this->importer->import(
			import: new SeedImport(schema: 'Barcode', rows: $barcodes, match: $match),
			messages: new SeedMessages(
				seededLog: 'Shillinq: inventory barcodes seeded',
				seededMessage: 'Inventory barcodes seeded successfully.',
				failedLog: 'Shillinq: inventory barcode seeding failed',
			)
		);
	}//end seedInventoryBarcodes()

	/**
	 * Seed demo InventoryLot records from inventory-lots-demo.json, idempotently.
	 *
	 * Reads `lib/Settings/seeds/inventory-lots-demo.json` and imports
	 * InventoryLot records via OpenRegister's ObjectService. Deduplication key
	 * is `(administrationId, lotNumber)` per REQ-LOT-002 — re-running the
	 * repair step never creates duplicates, preserving operator edits. The
	 * seeded lots reference inventory-product-catalog demo SKUs; when those
	 * Products are absent the lots still load and become discoverable once
	 * the products land. Skips silently when no default administration is
	 * configured (C2 — prevents "default" contamination).
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
	 */
	public function seedInventoryLots(): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		$file = $this->importer->read(fileName: 'inventory-lots-demo.json');
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		$lots = ($file['data']['lots'] ?? []);
		if (empty($lots) === true) {
			return $this->importer->nothingSeeded(message: 'Seed file contains no lots, nothing to import.');
		}

		$settings = $this->settings->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');
		if ($administrationId === '') {
			return $this->importer->nothingSeeded(message: 'No default administration configured, skipping lot seed.');
		}

		// Deduplication key (administrationId, lotNumber): idempotent re-runs per REQ-LOT-002.
		$match = static fn ($lot): array => [
			'administrationId' => $administrationId,
			'lotNumber' => ($lot['lotNumber'] ?? ''),
		];

		return $this->importer->import(
			import: new SeedImport(schema: 'InventoryLot', rows: $lots, match: $match, administrationId: $administrationId),
			messages: new SeedMessages(
				seededLog: 'Shillinq: inventory lots seeded',
				seededMessage: 'Inventory lots seeded successfully.',
				failedLog: 'Shillinq: inventory lot seeding failed',
			)
		);
	}//end seedInventoryLots()

	/**
	 * Seed example InventoryValuation records from
	 * `inventory-valuation-examples.json`, idempotently per administration.
	 *
	 * Deduplication key per REQ-INV-005 is
	 * `(productId, warehouse, status=active, administrationId)`. When an
	 * active snapshot for the same tuple already exists the seed entry is
	 * skipped, preserving operator edits and preventing duplicate active
	 * snapshots from triggering the uniqueness validation.
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string,mixed> Result with success/seeded/skipped/message.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-12
	 */
	public function seedInventoryValuationExamples(string $administrationId): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		if ($administrationId === '') {
			return $this->importer->failed(message: 'administrationId is required for InventoryValuation seed.');
		}

		$file = $this->importer->read(fileName: 'inventory-valuation-examples.json');
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		$valuations = ($file['data']['valuations'] ?? []);
		if (empty($valuations) === true) {
			return $this->importer->nothingSeeded(message: 'Seed file contains no valuations, nothing to import.');
		}

		// Dedup key (productId, warehouse, status=active, administrationId) per REQ-INV-005.
		$match = static function ($valuation) use ($administrationId): ?array {
			$productId = (string) ($valuation['productId'] ?? '');
			$warehouse = (string) ($valuation['warehouse'] ?? '');
			if ($productId === '' || $warehouse === '') {
				return null;
			}

			return [
				'productId' => $productId,
				'warehouse' => $warehouse,
				'status' => 'active',
				'administrationId' => $administrationId,
			];
		};

		return $this->importer->import(
			import: new SeedImport(schema: 'InventoryValuation', rows: $valuations, match: $match, administrationId: $administrationId),
			messages: new SeedMessages(
				seededLog: 'Shillinq: inventory valuation examples seeded',
				seededMessage: 'Inventory valuation examples seeded successfully.',
				failedLog: 'Shillinq: inventory valuation example seeding failed',
			)
		);
	}//end seedInventoryValuationExamples()

	/**
	 * Seed demo InventoryStock records from the three location seed files,
	 * idempotently per administration.
	 *
	 * Reads `lib/Settings/seeds/stock-amsterdam.json`,
	 * `lib/Settings/seeds/stock-rotterdam.json` and
	 * `lib/Settings/seeds/stock-utrecht.json` and imports InventoryStock rows
	 * via OpenRegister's ObjectService. Deduplication key is
	 * `(administrationId, productSku, locationCode)` per REQ-IST-002 +
	 * REQ-IST-009 — re-running the repair step never creates duplicates and
	 * operator edits to quantities persist across upgrades. Skips silently
	 * when no default administration is configured (C2 — prevents "default"
	 * contamination of real tenant data).
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function seedInventoryStockExamples(string $administrationId): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		if ($administrationId === '') {
			return $this->importer->nothingSeeded(message: 'No default administration configured, skipping stock seed.');
		}

		$allRows = $this->readStockRows();
		if (empty($allRows) === true) {
			return $this->importer->nothingSeeded(message: 'No InventoryStock seed rows found.');
		}

		// Deduplication key (administrationId, productSku, locationCode): REQ-IST-002.
		$match = static function ($row) use ($administrationId): ?array {
			$productSku = ($row['productSku'] ?? '');
			$locationCode = ($row['locationCode'] ?? '');
			if ($productSku === '' || $locationCode === '') {
				return null;
			}

			return [
				'administrationId' => $administrationId,
				'productSku' => $productSku,
				'locationCode' => $locationCode,
			];
		};

		return $this->importer->import(
			import: new SeedImport(schema: 'InventoryStock', rows: $allRows, match: $match, administrationId: $administrationId),
			messages: new SeedMessages(
				seededLog: 'Shillinq: InventoryStock seeded',
				seededMessage: 'InventoryStock seeded successfully.',
				failedLog: 'Shillinq: InventoryStock seeding failed',
			)
		);
	}//end seedInventoryStockExamples()

	/**
	 * The stock rows of the three demo warehouse files.
	 *
	 * A missing, unreadable or invalid file is logged as a warning and left out.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	private function readStockRows(): array {
		$seedFiles = [
			__DIR__ . '/../Settings/seeds/stock-amsterdam.json',
			__DIR__ . '/../Settings/seeds/stock-rotterdam.json',
			__DIR__ . '/../Settings/seeds/stock-utrecht.json',
		];

		$allRows = [];
		foreach ($seedFiles as $seedPath) {
			if (file_exists($seedPath) === false) {
				$this->logger->warning(
					'Shillinq: InventoryStock seed file missing, skipping',
					['file' => basename($seedPath)]
				);
				continue;
			}

			$content = file_get_contents($seedPath);
			if ($content === false) {
				$this->logger->warning(
					'Shillinq: failed to read InventoryStock seed file',
					['file' => basename($seedPath)]
				);
				continue;
			}

			$data = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->warning(
					'Shillinq: failed to parse InventoryStock seed file',
					[
						'file' => basename($seedPath),
						'error' => json_last_error_msg(),
					]
				);
				continue;
			}

			$rows = ($data['stock'] ?? []);
			foreach ($rows as $row) {
				$allRows[] = $row;
			}
		}//end foreach

		return $allRows;
	}//end readStockRows()

	/**
	 * Seed the default `InventoryGLConfig` record for an administration
	 * (inventory-cogs-posting REQ-CG-001 + Task 11).
	 *
	 * Loads the RGS 3.5 MKB defaults (7000 Kostprijs revenue / 1400
	 * Voorraden / 1800 GR/IR clearing / 7100 Voorraadmutaties) from
	 * `seeds/inventory-gl-config-2026.json` and writes one row per
	 * administration via ObjectService. Idempotent: deduplicates on
	 * administrationId so re-runs preserve operator overrides (the
	 * config is administration-scoped — one active row per tenant).
	 * Skipped when administrationId is empty (C2 contamination guard).
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string,mixed> Result with success flag, seeded + skipped counts, message.
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
	 */
	public function seedInventoryGLConfig(string $administrationId): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		if ($administrationId === '') {
			return $this->importer->failed(message: 'administrationId must not be empty.');
		}

		$fileName = 'inventory-gl-config-2026.json';
		$file = $this->importer->read(
			fileName: $fileName,
			messages: [
				'missing' => 'Seed file not found: ' . __DIR__ . '/../Settings/seeds/' . $fileName,
				'unreadable' => 'Failed to read seed file.',
				'invalid' => 'Failed to parse seed file: ',
			]
		);
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		$configs = ($file['data']['configs'] ?? []);
		if (empty($configs) === true) {
			return $this->importer->nothingSeeded(message: 'No InventoryGLConfig entries in seed file.');
		}

		// One configuration per administration.
		$match = static fn (): array => ['administrationId' => $administrationId];

		return $this->importer->import(
			import: new SeedImport(schema: 'InventoryGLConfig', rows: $configs, match: $match, administrationId: $administrationId),
			messages: new SeedMessages(
				seededLog: 'Shillinq: InventoryGLConfig seeded',
				seededMessage: 'InventoryGLConfig seeded successfully.',
				failedLog: 'Shillinq: InventoryGLConfig seeding failed',
				logContext: ['administrationId' => $administrationId],
			)
		);
	}//end seedInventoryGLConfig()
}//end class
