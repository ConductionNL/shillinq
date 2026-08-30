<?php

/**
 * Unit tests for the bookkeeping-consolidation-commercial register fragment.
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
 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
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
 * Verifies the consolidation fragment is valid JSON, declares the ten
 * consolidation schemas with their declarative lifecycle / aggregation /
 * calculation metadata, merges additively onto the monolith (ADR-037), and
 * ships seed objects that target only declared schemas.
 */
final class ConsolidationFragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-consolidation-commercial.json';

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
	 * The fragment declares the ten new consolidation schemas (REQ-CONS-001..010).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresTenSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'ConsolidationGroup',
			'GroupEntity',
			'IntercompanyRelation',
			'ConsolidationPeriod',
			'EliminationEntry',
			'TranslationAdjustment',
			'MinorityInterest',
			'Goodwill',
			'ConsolidatedBalance',
			'ConsolidatedIncomeStatement',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresTenSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle on the right
	 * field (status for the period/elimination, state for groups/output).
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'ConsolidationGroup' => 'state',
			'GroupEntity' => 'state',
			'ConsolidationPeriod' => 'status',
			'EliminationEntry' => 'reviewStatus',
			'ConsolidatedBalance' => 'state',
			'ConsolidatedIncomeStatement' => 'state',
		];
		foreach ($expected as $name => $field) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame($field, $schemas[$name]['x-openregister-lifecycle']['field']);
		}

	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * Quantitative logic is declarative: Goodwill.goodwillAmount and
	 * MinorityInterest.closingBalance are calculations; ConsolidationPeriod rolls
	 * elimination totals via aggregations (ADR-031).
	 *
	 * @return void
	 */
	public function testQuantitativeLogicIsDeclarative(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey('x-openregister-calculations', $schemas['Goodwill']);
		self::assertArrayHasKey('goodwillAmount', $schemas['Goodwill']['x-openregister-calculations']);

		self::assertArrayHasKey('x-openregister-calculations', $schemas['MinorityInterest']);
		self::assertArrayHasKey('closingBalance', $schemas['MinorityInterest']['x-openregister-calculations']);

		$agg = $schemas['ConsolidationPeriod']['x-openregister-aggregations'];
		self::assertArrayHasKey('totalEliminationCount', $agg);
		self::assertArrayHasKey('totalEliminationAmount', $agg);

	}//end testQuantitativeLogicIsDeclarative()

	/**
	 * Lifecycle transitions reference the ConsolidationGuard where a cross-field
	 * precondition is required (ADR-031 exception path).
	 *
	 * @return void
	 */
	public function testLifecycleTransitionsReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$close = $schemas['ConsolidationPeriod']['x-openregister-lifecycle']['transitions']['close'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ConsolidationGuard::canClosePeriod',
			$close['requires']
		);

		$approve = $schemas['EliminationEntry']['x-openregister-lifecycle']['transitions']['approve'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ConsolidationGuard::canApproveElimination',
			$approve['requires']
		);

		$finalize = $schemas['ConsolidatedBalance']['x-openregister-lifecycle']['transitions']['finalize'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ConsolidationGuard::canFinalizeBalance',
			$finalize['requires']
		);

	}//end testLifecycleTransitionsReferenceGuard()

	/**
	 * Merging the fragment onto the monolith adds the consolidation schemas
	 * without dropping any pre-existing schema (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeSchemas = array_keys($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('ConsolidationGroup', $schemas);
		self::assertArrayHasKey('ConsolidatedIncomeStatement', $schemas);

		// Pre-existing schemas survive the merge.
		foreach ($beforeSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Pre-existing schema $name must survive merge");
		}

		self::assertGreaterThan(
			count($beforeSchemas),
			count($schemas),
			'Merge must add schemas, not lose any'
		);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register.
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
