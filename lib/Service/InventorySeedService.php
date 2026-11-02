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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Seeds the inventory demo records through OpenRegister's ObjectService.
 *
 * SettingsService runs these seeds and supplies the OpenRegister availability
 * check, the register slug and the configured default administration.
 *
 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
 */
class InventorySeedService {

	/**
	 * Constructor for the InventorySeedService.
	 *
	 * @param SettingsService $settings The settings service
	 * @param ContainerInterface $container The container
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settings,
		private ContainerInterface $container,
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
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		$seedPath = __DIR__ . '/../Settings/seeds/inventory-barcodes-demo.json';
		if (file_exists($seedPath) === false) {
			return [
				'success' => false,
				'message' => 'Seed file not found: inventory-barcodes-demo.json',
			];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return [
				'success' => false,
				'message' => 'Failed to read inventory-barcodes-demo.json',
			];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return [
				'success' => false,
				'message' => 'Failed to parse inventory-barcodes-demo.json: ' . json_last_error_msg(),
			];
		}

		$barcodes = ($data['barcodes'] ?? []);
		if (empty($barcodes) === true) {
			return [
				'success' => true,
				'message' => 'Seed file contains no barcodes, nothing to import.',
				'seeded' => 0,
				'skipped' => 0,
			];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$seeded = 0;
			$skipped = 0;

			foreach ($barcodes as $barcode) {
				// Deduplication key (barcode, uomCode) — idempotent re-runs per REQ-SKU-011.
				// ADR-022: use the real ObjectService fluent API (setRegister/setSchema/findAll).
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('Barcode')
					->findAll(
						[
							'filters' => [
								'barcode' => ($barcode['barcode'] ?? ''),
								'uomCode' => ($barcode['uomCode'] ?? ''),
							],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$objectService->saveObject(
					object: $barcode,
					register: $registerSlug,
					schema: 'Barcode',
				);
				$seeded++;
			}//end foreach

			$this->logger->info(
				'Shillinq: inventory barcodes seeded',
				['seeded' => $seeded, 'skipped' => $skipped]
			);

			return [
				'success' => true,
				'message' => 'Inventory barcodes seeded successfully.',
				'seeded' => $seeded,
				'skipped' => $skipped,
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: inventory barcode seeding failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try

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
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		$seedPath = __DIR__ . '/../Settings/seeds/inventory-lots-demo.json';
		if (file_exists($seedPath) === false) {
			return [
				'success' => false,
				'message' => 'Seed file not found: inventory-lots-demo.json',
			];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return [
				'success' => false,
				'message' => 'Failed to read inventory-lots-demo.json',
			];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return [
				'success' => false,
				'message' => 'Failed to parse inventory-lots-demo.json: ' . json_last_error_msg(),
			];
		}

		$lots = ($data['lots'] ?? []);
		if (empty($lots) === true) {
			return [
				'success' => true,
				'message' => 'Seed file contains no lots, nothing to import.',
				'seeded' => 0,
				'skipped' => 0,
			];
		}

		$settings = $this->settings->getSettings();
		$administrationId = ($settings['administration_id'] ?? '');
		if ($administrationId === '') {
			return [
				'success' => true,
				'message' => 'No default administration configured, skipping lot seed.',
				'seeded' => 0,
				'skipped' => 0,
			];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$seeded = 0;
			$skipped = 0;

			foreach ($lots as $lot) {
				// Deduplication key (administrationId, lotNumber) — idempotent re-runs per REQ-LOT-002.
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('InventoryLot')
					->findAll(
						[
							'filters' => [
								'administrationId' => $administrationId,
								'lotNumber' => ($lot['lotNumber'] ?? ''),
							],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$lot['administrationId'] = $administrationId;
				$objectService->saveObject(
					object: $lot,
					register: $registerSlug,
					schema: 'InventoryLot',
				);
				$seeded++;
			}//end foreach

			$this->logger->info(
				'Shillinq: inventory lots seeded',
				['seeded' => $seeded, 'skipped' => $skipped]
			);

			return [
				'success' => true,
				'message' => 'Inventory lots seeded successfully.',
				'seeded' => $seeded,
				'skipped' => $skipped,
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: inventory lot seeding failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try

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
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		if ($administrationId === '') {
			return [
				'success' => false,
				'message' => 'administrationId is required for InventoryValuation seed.',
			];
		}

		$seedPath = __DIR__ . '/../Settings/seeds/inventory-valuation-examples.json';
		if (file_exists($seedPath) === false) {
			return [
				'success' => false,
				'message' => 'Seed file not found: inventory-valuation-examples.json',
			];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return [
				'success' => false,
				'message' => 'Failed to read inventory-valuation-examples.json',
			];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return [
				'success' => false,
				'message' => 'Failed to parse inventory-valuation-examples.json: ' . json_last_error_msg(),
			];
		}

		$valuations = ($data['valuations'] ?? []);
		if (empty($valuations) === true) {
			return [
				'success' => true,
				'message' => 'Seed file contains no valuations, nothing to import.',
				'seeded' => 0,
				'skipped' => 0,
			];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$seeded = 0;
			$skipped = 0;

			foreach ($valuations as $valuation) {
				$productId = (string)($valuation['productId'] ?? '');
				$warehouse = (string)($valuation['warehouse'] ?? '');
				if ($productId === '' || $warehouse === '') {
					continue;
				}

				// Dedup key (productId, warehouse, status=active, administrationId) per REQ-INV-005.
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('InventoryValuation')
					->findAll(
						[
							'filters' => [
								'productId' => $productId,
								'warehouse' => $warehouse,
								'status' => 'active',
								'administrationId' => $administrationId,
							],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$valuation['administrationId'] = $administrationId;
				$objectService->saveObject(
					object: $valuation,
					register: $registerSlug,
					schema: 'InventoryValuation',
				);
				$seeded++;
			}//end foreach

			$this->logger->info(
				'Shillinq: inventory valuation examples seeded',
				['seeded' => $seeded, 'skipped' => $skipped]
			);

			return [
				'success' => true,
				'message' => 'Inventory valuation examples seeded successfully.',
				'seeded' => $seeded,
				'skipped' => $skipped,
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: inventory valuation example seeding failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try

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
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		if ($administrationId === '') {
			return [
				'success' => true,
				'message' => 'No default administration configured, skipping stock seed.',
				'seeded' => 0,
				'skipped' => 0,
			];
		}

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

		if (empty($allRows) === true) {
			return [
				'success' => true,
				'message' => 'No InventoryStock seed rows found.',
				'seeded' => 0,
				'skipped' => 0,
			];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$seeded = 0;
			$skipped = 0;

			foreach ($allRows as $row) {
				$productSku = ($row['productSku'] ?? '');
				$locationCode = ($row['locationCode'] ?? '');
				if ($productSku === '' || $locationCode === '') {
					continue;
				}

				// Deduplication key (administrationId, productSku, locationCode) — REQ-IST-002.
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('InventoryStock')
					->findAll(
						[
							'filters' => [
								'administrationId' => $administrationId,
								'productSku' => $productSku,
								'locationCode' => $locationCode,
							],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$row['administrationId'] = $administrationId;
				$objectService->saveObject(
					object: $row,
					register: $registerSlug,
					schema: 'InventoryStock',
				);
				$seeded++;
			}//end foreach

			$this->logger->info(
				'Shillinq: InventoryStock seeded',
				['seeded' => $seeded, 'skipped' => $skipped]
			);

			return [
				'success' => true,
				'message' => 'InventoryStock seeded successfully.',
				'seeded' => $seeded,
				'skipped' => $skipped,
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: InventoryStock seeding failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try

	}//end seedInventoryStockExamples()

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
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		if ($administrationId === '') {
			return [
				'success' => false,
				'message' => 'administrationId must not be empty.',
			];
		}

		$seedPath = __DIR__ . '/../Settings/seeds/inventory-gl-config-2026.json';
		if (file_exists($seedPath) === false) {
			return [
				'success' => false,
				'message' => 'Seed file not found: ' . $seedPath,
			];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return [
				'success' => false,
				'message' => 'Failed to read seed file.',
			];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return [
				'success' => false,
				'message' => 'Failed to parse seed file: ' . json_last_error_msg(),
			];
		}

		$configs = ($data['configs'] ?? []);
		if (empty($configs) === true) {
			return [
				'success' => true,
				'message' => 'No InventoryGLConfig entries in seed file.',
				'seeded' => 0,
				'skipped' => 0,
			];
		}

		$seeded = 0;
		$skipped = 0;

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			foreach ($configs as $config) {
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('InventoryGLConfig')
					->findAll(
						[
							'filters' => ['administrationId' => $administrationId],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

				$config['administrationId'] = $administrationId;

				$objectService->saveObject(
					object: $config,
					register: $registerSlug,
					schema: 'InventoryGLConfig',
				);
				$seeded++;
			}//end foreach

			$this->logger->info(
				'Shillinq: InventoryGLConfig seeded',
				[
					'administrationId' => $administrationId,
					'seeded' => $seeded,
					'skipped' => $skipped,
				]
			);

			return [
				'success' => true,
				'message' => 'InventoryGLConfig seeded successfully.',
				'seeded' => $seeded,
				'skipped' => $skipped,
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: InventoryGLConfig seeding failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try

	}//end seedInventoryGLConfig()
}//end class
