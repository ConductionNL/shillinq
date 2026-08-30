<?php

/**
 * Unit tests for the bookkeeping-provincies-bbv-variant register fragment.
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
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the provincies-BBV fragment is valid JSON, declares the BbvProgrammeBudget schema,
 * additively overlays GLLine with the programmaStructure / programmaAssignedAt
 * fields plus the programmeBudgetVsActuals aggregation, seeds the seven canonical
 * provincie programmes, and merges onto the monolith without dropping anything
 * (ADR-037).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProvinciesBbvFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-provincies-bbv-variant.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * The seven canonical BBV provincie programmes.
	 *
	 * @var array<int, string>
	 */
	private array $canonical = [
		'ruimte',
		'mobiliteit',
		'water',
		'milieu',
		'cultuur',
		'economie',
		'bestuur',
	];

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
	 * Decode the fragment file into an array.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with components + objects.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the BbvProgrammeBudget schema with the seven-programme enum and a
	 * GLLine overlay that adds the programme assignment fields.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresBudgetAndGlLineOverlay(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey('BbvProgrammeBudget', $schemas);
		$budget = $schemas['BbvProgrammeBudget'];
		self::assertContains('totalAmount', $budget['required']);
		self::assertContains('programmeStructure', $budget['required']);
		self::assertSame($this->canonical, $budget['properties']['programmeStructure']['enum']);

		self::assertArrayHasKey('GLLine', $schemas);
		$glLine = $schemas['GLLine'];
		self::assertArrayHasKey('programmeStructure', $glLine['properties']);
		self::assertArrayHasKey('programmeAssignedAt', $glLine['properties']);
		self::assertSame($this->canonical, $glLine['properties']['programmeStructure']['enum']);
		self::assertTrue($glLine['properties']['programmeStructure']['nullable']);

	}//end testFragmentDeclaresBudgetAndGlLineOverlay()

	/**
	 * The GLLine overlay registers the ProgrammaLinkGuard save precondition and the
	 * programmeBudgetVsActuals aggregation.
	 *
	 * @return void
	 */
	public function testGlLineOverlayDeclaresGuardAndAggregation(): void {
		$glLine = $this->fragment()['components']['schemas']['GLLine'];

		self::assertSame(
			'OCA\\Shillinq\\Guard\\ProgrammaLinkGuard::validateOnSave',
			$glLine['x-openregister-lifecycle']['preconditions']['save']
		);

		self::assertArrayHasKey('programmeBudgetVsActuals', $glLine['x-openregister-aggregations']);
		$agg = $glLine['x-openregister-aggregations']['programmeBudgetVsActuals'];
		self::assertContains('programmeStructure', $agg['groupBy']);

		// `metrics`, not `operations`. AggregationRunner never read `operations`,
		// so none of these figures were produced — the aggregation returned
		// nothing rather than failing.
		self::assertArrayNotHasKey('operations', $agg, '`operations` is not an engine key');
		$byAlias = [];
		foreach ($agg['metrics'] as $metric) {
			$byAlias[$metric['as']] = $metric;
		}
		self::assertArrayHasKey('spent', $byAlias);

	}//end testGlLineOverlayDeclaresGuardAndAggregation()

	/**
	 * The fragment seeds exactly the seven canonical provincie BBVProgramma
	 * records, all with bbvVariant 'province'.
	 *
	 * @return void
	 */
	public function testFragmentSeedsSevenProvincieProgrammes(): void {
		$objects = $this->fragment()['objects'];
		$programmes = array_filter(
			$objects,
			static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'BBVProgramma'
		);

		self::assertCount(7, $programmes, 'Exactly seven provincie programmes must be seeded');

		$codes = [];
		foreach ($programmes as $programme) {
			self::assertSame('province', $programme['bbvVariant']);
			$codes[] = $programme['code'];
		}

		sort($codes);
		$expected = $this->canonical;
		sort($expected);
		self::assertSame($expected, $codes);

	}//end testFragmentSeedsSevenProvincieProgrammes()

	/**
	 * Every seeded BbvProgrammeBudget references a canonical programme and a positive amount.
	 *
	 * @return void
	 */
	public function testSeededBudgetsAreCanonical(): void {
		$budgets = array_filter(
			$this->fragment()['objects'],
			static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'BbvProgrammeBudget'
		);

		self::assertNotEmpty($budgets);
		foreach ($budgets as $budget) {
			self::assertContains($budget['programmeStructure'], $this->canonical);
			self::assertGreaterThan(0, $budget['totalAmount']);
			self::assertContains($budget['status'], ['approved', 'provisional', 'amended']);
		}

	}//end testSeededBudgetsAreCanonical()

	/**
	 * Merging the fragment onto the monolith adds BbvProgrammeBudget, preserves every existing
	 * GLLine property, and appends seed objects without dropping any (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$glPropsBefore = array_keys($base['components']['schemas']['GLLine']['properties']);
		$schemaCountBefore = count($base['components']['schemas']);
		$objectCountBefore = count($base['objects']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		// BbvProgrammeBudget added; GLLine still present.
		self::assertArrayHasKey('BbvProgrammeBudget', $schemas);
		self::assertArrayHasKey('GLLine', $schemas);

		// Only BbvProgrammeBudget is a NEW schema (GLLine is an overlay), so +1.
		self::assertCount($schemaCountBefore + 1, $schemas, 'Exactly one new schema (BbvProgrammeBudget) must be added');

		// No pre-existing schema dropped.
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must survive merge");
		}

		// Every original GLLine property survived, plus the two new ones.
		$glPropsAfter = array_keys($merged['components']['schemas']['GLLine']['properties']);
		foreach ($glPropsBefore as $prop) {
			self::assertContains($prop, $glPropsAfter, "GLLine.$prop must survive overlay");
		}

		self::assertContains('programmeStructure', $glPropsAfter);
		self::assertContains('programmeAssignedAt', $glPropsAfter);

		// Pre-existing GLLine aggregations survive alongside the new one.
		$aggs = $merged['components']['schemas']['GLLine']['x-openregister-aggregations'];
		self::assertArrayHasKey('consolidatedTrialBalance', $aggs);
		self::assertArrayHasKey('programmeBudgetVsActuals', $aggs);

		// Seed objects appended, none replaced.
		self::assertCount($objectCountBefore + count($frag['objects']), $merged['objects']);

	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
