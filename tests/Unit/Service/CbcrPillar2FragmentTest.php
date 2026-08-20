<?php

/**
 * Unit tests for the bookkeeping-cbcr-pillar2 register fragment.
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
 * @spec openspec/changes/bookkeeping-cbcr-pillar2/specs/bookkeeping-cbcr-pillar2/spec.md
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
 * Verifies the CbCR / Pillar 2 fragment is valid JSON, declares the eight new
 * schemas with their declarative calculation / aggregation / lifecycle metadata
 * (ADR-031), merges additively onto the monolith (ADR-037), and ships seed
 * objects that target only declared schemas.
 */
final class CbcrPillar2FragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-cbcr-pillar2.json';

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
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the eight new CbCR / Pillar 2 schemas (REQ-CBC-001..010).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresEightSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'GroupEntityRegistry',
			'CbcrJurisdictionSummary',
			'Pillar2JurisdictionComputation',
			'Pillar2SafeHarbour',
			'QdmttReturn',
			'GlobeInformationReturn',
			'CbcrReturn',
			'TaxTreatyOverview',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresEightSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle on `state`
	 * (design D1 / REQ-CBC-006).
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'GroupEntityRegistry',
			'CbcrJurisdictionSummary',
			'Pillar2JurisdictionComputation',
			'QdmttReturn',
			'GlobeInformationReturn',
			'CbcrReturn',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame('state', $schemas[$name]['x-openregister-lifecycle']['field']);
		}

	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * Quantitative Pillar 2 logic is declarative: ETR, SBIE carve-out, top-up
	 * tax and GloBE income are all x-openregister-calculations (ADR-031) — no
	 * GlobeIncomeCalculator.php (REQ-CBC-003..005).
	 *
	 * @return void
	 */
	public function testPillar2LogicIsDeclarative(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$calc = $schemas['Pillar2JurisdictionComputation']['x-openregister-calculations'];
		self::assertArrayHasKey('globeIncome', $calc, 'GloBE income must be a calculation (REQ-CBC-003)');
		self::assertArrayHasKey('etrJurisdiction', $calc, 'ETR must be a calculation (REQ-CBC-004)');
		self::assertArrayHasKey('substanceBasedIncomeExclusion', $calc, 'SBIE must be a calculation (REQ-CBC-005)');
		self::assertArrayHasKey('topUpTaxAmount', $calc, 'Top-up tax must be a calculation (REQ-CBC-004)');

		// CbCR total revenue is a calculation (REQ-CBC-002).
		$summaryCalc = $schemas['CbcrJurisdictionSummary']['x-openregister-calculations'];
		self::assertArrayHasKey('totalRevenue', $summaryCalc);

		// The 7-field roll-up uses an aggregation (REQ-CBC-002 / design D3).
		self::assertArrayHasKey('x-openregister-aggregations', $schemas['CbcrJurisdictionSummary']);
	}//end testPillar2LogicIsDeclarative()

	/**
	 * No GloBE / QDMTT calculation service ships with the change (ADR-031 /
	 * proposal Out-of-Scope).
	 *
	 * @return void
	 */
	public function testNoCalculationServiceShips(): void {
		$serviceDir = __DIR__ . '/../../../lib/Service';
		foreach (['GlobeIncomeCalculator', 'CbcrService', 'QdmttService', 'Pillar2Service'] as $forbidden) {
			self::assertFileDoesNotExist("$serviceDir/$forbidden.php", "$forbidden.php must not exist (ADR-031)");
		}

	}//end testNoCalculationServiceShips()

	/**
	 * Lifecycle transitions reference the CbcrPillar2Guard where a cross-field
	 * precondition is required (ADR-031 exception path / REQ-CBC-006/010).
	 *
	 * @return void
	 */
	public function testLifecycleTransitionsReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$approveComp = $schemas['Pillar2JurisdictionComputation']['x-openregister-lifecycle']['transitions']['approve'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CbcrPillar2Guard::canApproveComputation',
			$approveComp['requires']
		);

		$reconcile = $schemas['CbcrReturn']['x-openregister-lifecycle']['transitions']['reconcile'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CbcrPillar2Guard::canReconcileCbcrReturn',
			$reconcile['requires']
		);

	}//end testLifecycleTransitionsReferenceGuard()

	/**
	 * Merging the fragment onto the monolith adds the eight schemas without
	 * dropping any existing schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeNames = array_keys($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('GroupEntityRegistry', $schemas);
		self::assertArrayHasKey('Pillar2JurisdictionComputation', $schemas);
		self::assertArrayHasKey('CbcrReturn', $schemas);

		// Pre-existing monolith schemas survive the merge.
		foreach ($beforeNames as $name) {
			self::assertArrayHasKey($name, $schemas, "Existing schema $name must survive the merge");
		}

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register, and a
	 * worked NL/DE FY2026 example is present (design Seed Data).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		$seededSchemas = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			$schema = $object['@self']['schema'];
			self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");
			$seededSchemas[$schema] = true;
		}

		self::assertArrayHasKey('GroupEntityRegistry', $seededSchemas, 'A sample group entity must be seeded');
		self::assertArrayHasKey('Pillar2JurisdictionComputation', $seededSchemas, 'A worked Pillar 2 computation must be seeded');

	}//end testSeedObjectsTargetDeclaredSchemas()

	/**
	 * The seeded DE Pillar 2 computation is internally consistent: GloBE income =
	 * commercial PBT + sum of corrections, and the corrections net to zero in the
	 * worked example (REQ-CBC-003).
	 *
	 * @return void
	 */
	public function testSeededComputationIsArithmeticallyConsistent(): void {
		$objects = $this->fragment()['components']['objects'];

		$comp = null;
		foreach ($objects as $object) {
			if ($object['@self']['schema'] === 'Pillar2JurisdictionComputation') {
				$comp = $object;
				break;
			}
		}

		self::assertNotNull($comp, 'A Pillar 2 computation seed must exist');

		$adjustmentSum = 0.0;
		foreach ($comp['globeIncomeAdjustments'] as $adjustment) {
			$adjustmentSum += (float)$adjustment['amount'];
		}

		self::assertSame((float)$comp['globeIncomeAdjustmentTotal'], $adjustmentSum, 'Adjustment total must equal the sum of lines');
		self::assertSame(
			(float)$comp['globeIncome'],
			((float)$comp['commercialProfitBeforeTax'] + $adjustmentSum),
			'GloBE income must equal commercial PBT plus the corrections'
		);

	}//end testSeededComputationIsArithmeticallyConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
