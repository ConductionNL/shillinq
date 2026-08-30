<?php

/**
 * Unit tests for the bookkeeping-csrd-esrs register fragment.
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
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
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
 * Verifies the CSRD/ESRS fragment is valid JSON, declares the ten sustainability
 * schemas with their declarative lifecycle / calculation / aggregation metadata,
 * extends the monolith FixedAsset additively (ADR-037), and ships seed objects.
 */
final class CsrdEsrsFragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-csrd-esrs.json';

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
	 * The fragment declares the ten new sustainability schemas (REQ-CSR-001..004).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresTenSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'MaterialityAssessment',
			'IroRecord',
			'EsrsDataPoint',
			'GhgInventory',
			'EmissionSource',
			'ValueChainActor',
			'EsrsPolicy',
			'EsrsAction',
			'EsrsTarget',
			'AssuranceEngagement',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresTenSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle on `state`/`status`
	 * (design D9).
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'MaterialityAssessment' => 'state',
			'EsrsDataPoint' => 'status',
			'GhgInventory' => 'state',
			'AssuranceEngagement' => 'state',
		];
		foreach ($expected as $name => $field) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame($field, $schemas[$name]['x-openregister-lifecycle']['field']);
		}

	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * Quantitative logic is declarative: EmissionSource co2eResult is a
	 * calculation and GhgInventory rolls Scope 1/2/3 via aggregations (ADR-031).
	 *
	 * @return void
	 */
	public function testQuantitativeLogicIsDeclarative(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey('x-openregister-calculations', $schemas['EmissionSource']);
		self::assertArrayHasKey('co2eResult', $schemas['EmissionSource']['x-openregister-calculations']);

		self::assertArrayHasKey('x-openregister-calculations', $schemas['IroRecord']);
		self::assertArrayHasKey('impactScore', $schemas['IroRecord']['x-openregister-calculations']);

		$agg = $schemas['GhgInventory']['x-openregister-aggregations'];
		self::assertArrayHasKey('scope1Total', $agg);
		self::assertArrayHasKey('scope2LocationBasedTotal', $agg);
		self::assertArrayHasKey('scope2MarketBasedTotal', $agg);
		self::assertArrayHasKey('scope3ByCategory', $agg);

	}//end testQuantitativeLogicIsDeclarative()

	/**
	 * Lifecycle transitions reference the CsrdEsrsGuard where a cross-field
	 * precondition is required (ADR-031 exception path).
	 *
	 * @return void
	 */
	public function testLifecycleTransitionsReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$approveDp = $schemas['EsrsDataPoint']['x-openregister-lifecycle']['transitions']['approve'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CsrdEsrsGuard::canApproveDataPoint',
			$approveDp['requires']
		);

		$issueOpinion = $schemas['AssuranceEngagement']['x-openregister-lifecycle']['transitions']['issueOpinion'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CsrdEsrsGuard::canIssueOpinion',
			$issueOpinion['requires']
		);

	}//end testLifecycleTransitionsReferenceGuard()

	/**
	 * Merging the fragment onto the monolith adds the schemas and additively
	 * extends FixedAsset with the E1/E4 sustainability fields without dropping
	 * existing fields (design D6).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeCount = count($base['components']['schemas']['FixedAsset']['properties']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('MaterialityAssessment', $schemas);
		self::assertArrayHasKey('GhgInventory', $schemas);

		// FixedAsset extended additively (design D6).
		self::assertArrayHasKey('isBiodiversitySensitiveAreaOverlap', $schemas['FixedAsset']['properties']);
		self::assertArrayHasKey('physicalClimateRiskRating', $schemas['FixedAsset']['properties']);
		self::assertGreaterThan(
			$beforeCount,
			count($schemas['FixedAsset']['properties']),
			'FixedAsset must gain fields, not lose any'
		);
		// Pre-existing FixedAsset fields survive.
		foreach (array_keys($base['components']['schemas']['FixedAsset']['properties']) as $field) {
			self::assertArrayHasKey($field, $schemas['FixedAsset']['properties'], "FixedAsset.$field must survive merge");
		}

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register
	 * (REQ-CSR seed-data pattern).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			$schema = $object['@self']['schema'];
			self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
