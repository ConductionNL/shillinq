<?php

/**
 * Unit tests for the inventory-mobile-scanner register fragment + manifest.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the inventory-mobile-scanner register fragment is valid JSON,
 * declares all five audit schemas (MobileScannerSyncBatch, GoodsReceipt,
 * InventoryTransfer, OrderPick, InventoryCount), enforces the
 * money/quantity multipleOf 0.01 constraint per ADR-000, declares the
 * sync-batch operationType enum, and merges additively onto the monolith
 * register (ADR-037).
 */
final class InventoryMobileScannerFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/inventory-mobile-scanner.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Absolute path to the frontend manifest fragment (ADR-037).
	 *
	 * @var string
	 */
	private string $manifestPath = __DIR__ . '/../../../src/manifest.d/inventory-mobile-scanner.json';

	/**
	 * Absolute path to the PWA web manifest.
	 *
	 * @var string
	 */
	private string $webManifestPath = __DIR__ . '/../../../public/manifest.webmanifest';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Decode the fragment file to an array.
	 *
	 * @return array<string,mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * Fragment file exists and is valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * Fragment declares all five audit / dedup schemas.
	 *
	 * @return void
	 */
	public function testAllSchemasArePresent(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['MobileScannerSyncBatch', 'GoodsReceipt', 'InventoryTransfer', 'OrderPick', 'InventoryCount'] as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Fragment must declare $slug");
		}

	}//end testAllSchemasArePresent()

	/**
	 * Money / quantity fields use multipleOf 0.01 per ADR-000.
	 *
	 * @return void
	 */
	public function testQuantitiesUseTwoDecimalPrecision(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['GoodsReceipt', 'InventoryTransfer', 'OrderPick'] as $slug) {
			$props = $schemas[$slug]['properties'];
			self::assertArrayHasKey('quantity', $props, "$slug missing quantity");
			self::assertSame(0.01, $props['quantity']['multipleOf'], "$slug.quantity must use multipleOf 0.01");
		}

		$count = $schemas['InventoryCount']['properties'];
		foreach (['systemQuantity', 'physicalQuantity', 'variance'] as $field) {
			self::assertSame(0.01, $count[$field]['multipleOf'], "InventoryCount.$field must use multipleOf 0.01");
		}

	}//end testQuantitiesUseTwoDecimalPrecision()

	/**
	 * MobileScannerSyncBatch enforces the operationType enum and dispositions.
	 *
	 * @return void
	 */
	public function testSyncBatchEnumeratesOperationsAndStatuses(): void {
		$schema = $this->fragment()['components']['schemas']['MobileScannerSyncBatch'];
		$props = $schema['properties'];

		self::assertSame(
			['receive', 'transfer', 'pick', 'count'],
			$props['operationType']['enum'],
		);
		self::assertSame(
			['accepted', 'duplicate', 'rejected_permission', 'rejected_validation'],
			$props['status']['enum'],
		);
		self::assertContains('transactionId', $schema['required']);
		self::assertContains('userId', $schema['required']);
		self::assertContains('occurredAt', $schema['required']);

	}//end testSyncBatchEnumeratesOperationsAndStatuses()

	/**
	 * Transfer / pick quantities are strictly positive (exclusiveMinimum=true,
	 * minimum=0) so the schema validator rejects zero / negative values
	 * regardless of any business-logic gate.
	 *
	 * @return void
	 */
	public function testTransferAndPickRequireStrictlyPositiveQuantity(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['InventoryTransfer', 'OrderPick'] as $slug) {
			$qty = $schemas[$slug]['properties']['quantity'];
			self::assertSame(0, $qty['minimum'], "$slug.quantity must have minimum 0");
			self::assertTrue(($qty['exclusiveMinimum'] ?? false), "$slug.quantity must be exclusiveMinimum");
		}

	}//end testTransferAndPickRequireStrictlyPositiveQuantity()

	/**
	 * Fragment merges additively onto the monolith — no schemas are
	 * overwritten and the merged result still carries every fragment slug.
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		self::assertFileExists($this->registerPath);

		$base = json_decode((string)file_get_contents($this->registerPath), true);
		self::assertIsArray($base);

		$merged = $this->merge($base, $this->fragment());

		$mergedSchemas = $merged['components']['schemas'];
		foreach (['MobileScannerSyncBatch', 'GoodsReceipt', 'InventoryTransfer', 'OrderPick', 'InventoryCount'] as $slug) {
			self::assertArrayHasKey($slug, $mergedSchemas, "Merged register must carry $slug");
		}

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * The frontend manifest fragment registers all five PWA pages and the
	 * Inventory menu entry.
	 *
	 * @return void
	 */
	public function testFrontendManifestRegistersPages(): void {
		self::assertFileExists($this->manifestPath);
		$manifest = json_decode((string)file_get_contents($this->manifestPath), true);
		self::assertIsArray($manifest);

		$pageIds = array_column(($manifest['pages'] ?? []), 'id');
		foreach (['MobileScannerHome', 'MobileScannerReceive', 'MobileScannerTransfer', 'MobileScannerPick', 'MobileScannerCount'] as $expected) {
			self::assertContains($expected, $pageIds, "Manifest must register $expected");
		}

		$menuIds = array_column(($manifest['menu'] ?? []), 'id');
		self::assertContains('Inventory', $menuIds, 'Manifest must add the Inventory menu entry');

	}//end testFrontendManifestRegistersPages()

	/**
	 * PWA web manifest declares standalone display, the four shortcuts and
	 * both 192/512 icon entries per REQ-UI-003.
	 *
	 * @return void
	 */
	public function testWebManifestExposesShortcutsAndIcons(): void {
		self::assertFileExists($this->webManifestPath);
		$web = json_decode((string)file_get_contents($this->webManifestPath), true);
		self::assertIsArray($web);

		self::assertSame('standalone', $web['display']);
		self::assertSame('portrait', $web['orientation']);

		$shortcutNames = array_column(($web['shortcuts'] ?? []), 'name');
		foreach (['Receive', 'Transfer', 'Pick', 'Count'] as $shortcut) {
			self::assertContains($shortcut, $shortcutNames, "Web manifest must expose $shortcut shortcut");
		}

		$iconSizes = array_column(($web['icons'] ?? []), 'sizes');
		self::assertContains('192x192', $iconSizes, 'PWA must ship 192×192 icon');
		self::assertContains('512x512', $iconSizes, 'PWA must ship 512×512 icon');

	}//end testWebManifestExposesShortcutsAndIcons()

}//end class
