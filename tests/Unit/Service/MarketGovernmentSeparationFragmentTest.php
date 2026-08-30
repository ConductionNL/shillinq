<?php

/**
 * Unit tests for the bookkeeping-market-government-separation register fragment.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md
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
 * Verifies the WMO fragment is valid JSON, declares the three Phase-1 schemas,
 * ships archived seed objects, and merges additively onto the monolith register
 * without dropping any pre-existing schema (ADR-037).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MarketGovernmentSeparationFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-market-government-separation.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

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
	 * The fragment file is present and valid JSON with a schemas block.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the three Phase-1 WMO schemas with correct purpose.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresPhase1Schemas(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schemas = $data['components']['schemas'];

		foreach (['CommercialActivity', 'IntegralCostPrice', 'ActivityCostAllocation'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame('fact', $schemas[$name]['x-openregister-purpose'], "$name must be a fact schema");
			self::assertArrayHasKey('administrationId', $schemas[$name]['properties'], "$name must carry administrationId for tenant isolation");
			self::assertContains('administrationId', $schemas[$name]['required'], "$name must require administrationId");
		}

	}//end testFragmentDeclaresPhase1Schemas()

	/**
	 * CommercialActivity carries the REQ-WMO-001 mandatory fields and the ACM melding block.
	 *
	 * @return void
	 */
	public function testCommercialActivityHasMandatoryFields(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$props = $data['components']['schemas']['CommercialActivity']['properties'];

		$mandatory = [
			'code',
			'name',
			'governingBody',
			'marketSegment',
			'concurrenten',
			'costPriceMethod',
			'costCentreCode',
			'costObjectCode',
			'isExempted',
			'acmReport',
		];
		foreach ($mandatory as $field) {
			self::assertArrayHasKey($field, $props, "CommercialActivity must declare $field");
		}

		// The kostprijsMethode is the statutory enum.
		self::assertContains('integral-costprice-art-25i', $props['costPriceMethod']['enum']);

	}//end testCommercialActivityHasMandatoryFields()

	/**
	 * IntegralCostPrice declares the six statutory cost components and the status enum.
	 *
	 * @return void
	 */
	public function testIntegralCostPriceHasSixComponents(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schema = $data['components']['schemas']['IntegralCostPrice'];
		$components = $schema['properties']['componenten']['properties'];

		$expected = [
			'directPayrollCost',
			'directMaterials',
			'directDepreciations',
			'indirecteOverhead',
			'capitalCost',
			'profitMarkup',
		];
		foreach ($expected as $component) {
			self::assertArrayHasKey($component, $components, "componenten must include $component");
		}

		self::assertSame(['voorlopig', 'final'], $schema['properties']['status']['enum']);

	}//end testIntegralCostPriceHasSixComponents()

	/**
	 * ActivityCostAllocation models a reversible split with an override block (REQ-WMO-003).
	 *
	 * @return void
	 */
	public function testActivityCostAllocationModelsReversibleSplit(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$props = $data['components']['schemas']['ActivityCostAllocation']['properties'];

		self::assertArrayHasKey('splits', $props);
		self::assertArrayHasKey('allocationKey', $props);
		self::assertArrayHasKey('automaticApplied', $props);
		self::assertArrayHasKey('handmatigeOverride', $props);
		// Status enum supports the override / reversal lifecycle.
		self::assertSame(['active', 'overridden', 'reversed'], $props['status']['enum']);
		// The override carries the 4-ogen-akkoord.
		self::assertArrayHasKey('approvedBy', $props['handmatigeOverride']['properties']);

	}//end testActivityCostAllocationModelsReversibleSplit()

	/**
	 * The fragment ships archived seed objects bound to the shillinq register.
	 *
	 * @return void
	 */
	public function testFragmentShipsArchivedSeedObjects(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertArrayHasKey('objects', $data);
		self::assertNotEmpty($data['objects']);

		foreach ($data['objects'] as $object) {
			self::assertSame('shillinq', $object['@self']['register'], 'Seed object must target the shillinq register');
			self::assertContains(
				$object['@self']['schema'],
				['CommercialActivity', 'IntegralCostPrice', 'ActivityCostAllocation'],
				'Seed object must bind to a WMO schema'
			);
			self::assertSame('archived', $object['_meta']['lifecycleState'], 'Seed objects are historical reference only (archived)');
		}

	}//end testFragmentShipsArchivedSeedObjects()

	/**
	 * Merging the fragment onto the monolith adds the schemas without dropping any
	 * pre-existing schema and without disturbing the reused AllocationRule (ADR-037 union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = json_decode((string)file_get_contents($this->fragmentPath), true);

		$schemaCountBefore = count($base['components']['schemas']);
		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		// The three Phase-1 WMO fact schemas are present.
		self::assertArrayHasKey('CommercialActivity', $schemas);
		self::assertArrayHasKey('IntegralCostPrice', $schemas);
		self::assertArrayHasKey('ActivityCostAllocation', $schemas);

		// Additive union: the merged set grows by exactly the number of fragment
		// schemas not already present in the base, and no base schema is dropped.
		// The fragment ships the full WMO schema set (the three Phase-1 fact
		// schemas plus the later-phase ACM/audit/benchmark schemas), so the delta
		// is computed from the fragment rather than hard-coded, to stay correct as
		// the consolidated monolith absorbs schemas over time.
		$fragSchemaKeys = array_keys($frag['components']['schemas']);
		$netNew = count(array_diff($fragSchemaKeys, array_keys($base['components']['schemas'])));
		self::assertSame(($schemaCountBefore + $netNew), count($schemas));
		foreach ($fragSchemaKeys as $fragName) {
			self::assertArrayHasKey($fragName, $schemas, "$fragName must be present after the fragment merge");
		}

		// Every pre-existing schema survives the merge (including the reused AllocationRule).
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must survive the fragment merge");
		}

		self::assertArrayHasKey('AllocationRule', $schemas, 'The reused OverheadDistributionRule (AllocationRule) is untouched');

		// Seed objects union onto the base objects list (ADR-037 list union).
		self::assertGreaterThanOrEqual(count($base['objects'] ?? []), count($merged['objects']));

	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
