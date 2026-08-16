<?php

/**
 * Unit tests for the inventory-cycle-count register fragment + manifest.
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
 * @spec openspec/changes/inventory-cycle-count/tasks.md
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
 * Verifies the inventory-cycle-count register fragment is valid JSON,
 * declares the three new schemas, additively extends the existing
 * StockMove.movementReason enum with cycle-count-variance, declares the
 * lifecycle states + transitions per REQ-ICC-006, declares the variance
 * threshold metadata per REQ-ICC-004, declares the manifest navigation
 * + pages per REQ-ICC-010, and merges additively onto the monolith
 * register (ADR-037).
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
// phpcs:disable Squiz.PHP.DisallowInlineIf
final class InventoryCycleCountFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/inventory-cycle-count.json';

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
	private string $manifestPath = __DIR__ . '/../../../src/manifest.d/inventory-cycle-count.json';

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
	 * Decode the register fragment file to an array.
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
	 * Decode the manifest fragment file to an array.
	 *
	 * @return array<string,mixed>
	 */
	private function manifest(): array {
		$data = json_decode((string)file_get_contents($this->manifestPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end manifest()

	/**
	 * Register fragment file exists and is valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * Fragment declares the three new cycle-count schemas per REQ-ICC-001 /
	 * REQ-ICC-002 / REQ-ICC-003 / REQ-ICC-005.
	 *
	 * @return void
	 */
	public function testCycleCountSchemasArePresent(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['InventoryCycleCount', 'InventoryCycleCountLine', 'InventoryVarianceReason'] as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Fragment must declare $slug");
		}

	}//end testCycleCountSchemasArePresent()

	/**
	 * Money/quantity fields use multipleOf 0.01 per ADR-000 + REQ-ICC-003.
	 *
	 * @return void
	 */
	public function testMoneyFieldsUseTwoDecimalPrecision(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['expectedValue', 'countedValue', 'varianceValue'] as $field) {
			self::assertSame(
				0.01,
				$schemas['InventoryCycleCount']['properties'][$field]['multipleOf'],
				"InventoryCycleCount.$field must use multipleOf 0.01"
			);
		}

		foreach ([
			'expectedQuantity',
			'countedQuantity',
			'unitCost',
			'expectedValue',
			'countedValue',
			'quantityVariance',
			'valueVariance',
		] as $field
		) {
			self::assertSame(
				0.01,
				$schemas['InventoryCycleCountLine']['properties'][$field]['multipleOf'],
				"InventoryCycleCountLine.$field must use multipleOf 0.01"
			);
		}

	}//end testMoneyFieldsUseTwoDecimalPrecision()

	/**
	 * InventoryCycleCount declares the full lifecycle per REQ-ICC-006:
	 * draft → submitted → counting → posted → reconciled, plus cancelled.
	 *
	 * @return void
	 */
	public function testCycleCountLifecycleDeclaresAllStates(): void {
		$lifecycle = $this->fragment()['components']['schemas']['InventoryCycleCount']['x-openregister-lifecycle'];
		self::assertSame('state', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		foreach (['draft', 'submitted', 'counting', 'posted', 'reconciled', 'cancelled'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "lifecycle must declare $state state");
		}

		foreach (['submit', 'begin-counting', 'post', 'reconcile', 'cancel'] as $transition) {
			self::assertArrayHasKey(
				$transition,
				$lifecycle['transitions'],
				"lifecycle must declare $transition transition"
			);
		}

	}//end testCycleCountLifecycleDeclaresAllStates()

	/**
	 * Lifecycle transitions reference the VarianceGate + CycleCountService
	 * ADR-031 exception-path entries.
	 *
	 * @return void
	 */
	public function testLifecycleReferencesGuardsAndService(): void {
		$transitions = $this->fragment()['components']['schemas']['InventoryCycleCount']['x-openregister-lifecycle']['transitions'];

		self::assertStringContainsString(
			'VarianceGate::requireValidScope',
			$transitions['submit']['requires']
		);
		self::assertStringContainsString(
			'VarianceGate::requireReasonsOnPost',
			$transitions['post']['requires']
		);
		self::assertStringContainsString(
			'VarianceGate::requireReasonsOnPost',
			$transitions['reconcile']['requires']
		);

		// Snapshot delegates to CycleCountService::snapshotScope.
		$submitActions = $transitions['submit']['actions'];
		$snapshotGuard = $submitActions[1]['actionParameters']['guard'] ?? '';
		self::assertStringContainsString('CycleCountService::snapshotScope', $snapshotGuard);

		// Reconcile delegates to CycleCountService::emitAdjustments.
		$reconcileActions = $transitions['reconcile']['actions'];
		$emitGuard = $reconcileActions[1]['actionParameters']['guard'] ?? '';
		self::assertStringContainsString('CycleCountService::emitAdjustments', $emitGuard);

	}//end testLifecycleReferencesGuardsAndService()

	/**
	 * Variance threshold defaults declared in x-openregister-metadata per
	 * REQ-ICC-004.
	 *
	 * @return void
	 */
	public function testVarianceThresholdsDeclared(): void {
		$metadata = $this->fragment()['components']['schemas']['InventoryCycleCount']['x-openregister-metadata'];
		self::assertSame(5, $metadata['quantityVarianceThresholdPercent']['default']);
		self::assertSame(500, $metadata['valueVarianceThresholdAbsolute']['default']);

	}//end testVarianceThresholdsDeclared()

	/**
	 * VarianceReason category enum is closed per REQ-ICC-005 — variance
	 * reports rely on the closed set to roll up consistently.
	 *
	 * @return void
	 */
	public function testVarianceReasonCategoryIsClosedEnum(): void {
		$enum = $this->fragment()['components']['schemas']['InventoryVarianceReason']['properties']['category']['enum'];
		self::assertSame(
			['damage', 'loss', 'obsolescence', 'error-counting', 'error-stocking', 'system-discrepancy', 'other'],
			$enum
		);

	}//end testVarianceReasonCategoryIsClosedEnum()

	/**
	 * The fragment additively extends the existing StockMove.movementReason
	 * enum with `cycle-count-variance` so REQ-ICC-007 variance posting flows
	 * through the existing inventory-stock-movement-ledger lifecycle.
	 *
	 * @return void
	 */
	public function testStockMoveReasonExtendedWithCycleCountVariance(): void {
		$stockMove = $this->fragment()['components']['schemas']['StockMove'];
		$enum = $stockMove['properties']['movementReason']['enum'];
		self::assertContains('cycle-count-variance', $enum);
		// The original codes must still be present so the deep-merge result
		// remains backward-compatible with the existing fragment.
		foreach (['normal', 'damaged', 'shrinkage', 'cancellation'] as $existing) {
			self::assertContains($existing, $enum, "Pre-existing reason code $existing must survive the merge");
		}

	}//end testStockMoveReasonExtendedWithCycleCountVariance()

	/**
	 * Seven standard reason codes are seeded per REQ-ICC-005.
	 *
	 * @return void
	 */
	public function testSeedReasonsArePresent(): void {
		$objects = $this->fragment()['objects'];
		$reasons = array_values(
			array_filter(
				$objects,
				static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'InventoryVarianceReason'
			)
		);
		self::assertCount(7, $reasons);
		$reasonIds = array_map(static fn (array $o): string => $o['reasonId'], $reasons);
		foreach (['DMG', 'OBS', 'ERR-COUNT', 'ERR-STOCK', 'THEFT', 'SYS', 'OTHER'] as $expected) {
			self::assertContains($expected, $reasonIds, "Seed must include $expected reason code");
		}

	}//end testSeedReasonsArePresent()

	/**
	 * Deep-merging the fragment onto an empty base registers all three new
	 * schemas + the additive StockMove enum extension (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentDeepMergesAdditively(): void {
		$fragment = $this->fragment();
		$merged = $this->merge(
			base: ['components' => ['schemas' => []]],
			overlay: $fragment
		);
		$schemas = $merged['components']['schemas'];

		foreach (['InventoryCycleCount', 'InventoryCycleCountLine', 'InventoryVarianceReason'] as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Merge result must contain $slug");
		}

		// The StockMove additive enum extension carries through.
		$stockMoveEnum = $schemas['StockMove']['properties']['movementReason']['enum'];
		self::assertContains('cycle-count-variance', $stockMoveEnum);

	}//end testFragmentDeepMergesAdditively()

	/**
	 * Manifest fragment file exists, is valid JSON, and declares the three
	 * navigation pages per REQ-ICC-010.
	 *
	 * @return void
	 */
	public function testManifestFragmentDeclaresThreePages(): void {
		self::assertFileExists($this->manifestPath);
		$manifest = $this->manifest();
		self::assertIsArray($manifest['pages']);

		$pageIds = array_column($manifest['pages'], 'id');
		foreach (['CycleCounts', 'CycleCountDetail', 'CountTemplates', 'VarianceReports'] as $expected) {
			self::assertContains($expected, $pageIds, "Manifest must declare $expected page");
		}

	}//end testManifestFragmentDeclaresThreePages()

	/**
	 * Manifest fragment menu children point to navigable pages per REQ-ICC-010.
	 *
	 * @return void
	 */
	public function testManifestMenuChildrenAreReachable(): void {
		$manifest = $this->manifest();
		$pageIds = array_column($manifest['pages'], 'id');

		$inventoryMenu = null;
		foreach ($manifest['menu'] as $item) {
			if (($item['id'] ?? '') === 'Inventory') {
				$inventoryMenu = $item;
				break;
			}
		}

		self::assertIsArray($inventoryMenu, 'Inventory menu must be present');
		$childRoutes = array_column($inventoryMenu['children'], 'route');
		foreach (['CycleCounts', 'CountTemplates', 'VarianceReports'] as $route) {
			self::assertContains($route, $childRoutes, "Menu must wire $route");
			self::assertContains($route, $pageIds, "Route $route must resolve to a page id");
		}

	}//end testManifestMenuChildrenAreReachable()

	/**
	 * Sanity: the live registers file is still valid JSON (the fragment is
	 * merged at install time, not pre-merged here).
	 *
	 * @return void
	 */
	public function testLiveRegisterFileIsValidJson(): void {
		self::assertFileExists($this->registerPath);
		$data = json_decode((string)file_get_contents($this->registerPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);

	}//end testLiveRegisterFileIsValidJson()
}//end class
