<?php

/**
 * Unit tests for the bookkeeping-cost-centers-dimensions register fragment (ADR-037).
 *
 * Verifies the fragment declares the AnalyticalDimension schema with the
 * REQ-CD-006 fields, attaches the four segment-P&L aggregations to GLLine
 * (REQ-CD-004, REQ-CD-007), and ships the cost-center / project / dimension
 * seed objects so the OR RepairStep importer can pre-populate a fresh
 * administratie with realistic Dutch examples (REQ-CD-002).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-cost-centers-dimensions/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Structural tests for the cost-centers-dimensions register fragment.
 */
final class CostCentersDimensionsFragmentTest extends TestCase {
	/**
	 * Decoded fragment data.
	 *
	 * @var array<string,mixed>
	 */
	private array $fragment;

	/**
	 * Load and decode the fragment file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json';
		self::assertFileExists($path, 'Register fragment must ship in lib/Settings/register.d');
		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($decoded, 'Fragment must be valid JSON');
		$this->fragment = $decoded;
	}//end setUp()

	/**
	 * The fragment declares AnalyticalDimension with the REQ-CD-006 / REQ-ADIM-001 fields.
	 *
	 * After the cost-center / cost-object / custom-dimension ADIM merge (REQ-ADIM-001),
	 * `dimensionType` is the discriminator in `required` (replacing the earlier per-type
	 * required sets). `dataType` is a custom-dimension property and lives in `properties`
	 * but is NOT globally required (it is only relevant when dimensionType=custom).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresAnalyticalDimensionWithRequiredFields(): void {
		$schemas = ($this->fragment['components']['schemas'] ?? []);
		self::assertArrayHasKey(
			'AnalyticalDimension',
			$schemas,
			'AnalyticalDimension schema MUST be declared (REQ-CD-006)'
		);

		$dim = $schemas['AnalyticalDimension'];
		// Core fields that apply to ALL dimension types (REQ-ADIM-001).
		foreach (['code', 'name', 'dimensionType', 'administrationId', 'lifecycleState'] as $field) {
			self::assertContains(
				$field,
				$dim['required'],
				'AnalyticalDimension MUST require ' . $field
			);
			self::assertArrayHasKey($field, $dim['properties']);
		}

		// `dataType` is a custom-dimension property (only relevant when dimensionType=custom)
		// so it lives in properties but NOT in required (per REQ-ADIM-001 discriminator design).
		self::assertArrayHasKey(
			'dataType',
			$dim['properties'],
			'dataType MUST be declared in properties (REQ-CD-006 custom-dimension extensibility)'
		);

		// Custom-dimension extensibility — operator chooses a value type.
		$allowedTypes = $dim['properties']['dataType']['enum'];
		self::assertEqualsCanonicalizing(
			['string', 'integer', 'decimal', 'date', 'reference'],
			$allowedTypes,
			'dataType enum MUST match REQ-CD-006 value-type set'
		);

		self::assertArrayHasKey('isHierarchical', $dim['properties']);
		self::assertSame(
			'boolean',
			$dim['properties']['isHierarchical']['type'],
			'isHierarchical MUST be boolean (REQ-CD-007 hierarchical roll-up)'
		);
	}//end testFragmentDeclaresAnalyticalDimensionWithRequiredFields()

	/**
	 * The fragment attaches segment-P&L aggregations to GLLine (REQ-CD-004).
	 *
	 * The four declared aggregations cover the cost-center, project,
	 * cost-center-hierarchy, and free-form analytical-dimension roll-ups.
	 * Declared as `x-openregister-aggregations` on the GLLine partial overlay
	 * per ADR-037 (the base GLLine schema lives in shillinq_register.json).
	 *
	 * @return void
	 */
	public function testGlLineCarriesSegmentPnlAggregations(): void {
		$schemas = ($this->fragment['components']['schemas'] ?? []);
		self::assertArrayHasKey('GLLine', $schemas, 'GLLine overlay MUST be present');

		$aggs = $schemas['GLLine']['x-openregister-aggregations'] ?? null;
		self::assertIsArray($aggs, 'GLLine MUST declare x-openregister-aggregations');

		foreach (['byCostCenter', 'byCostCenterHierarchy', 'byProject', 'byAnalyticalDimension'] as $key) {
			self::assertArrayHasKey($key, $aggs, 'Aggregation ' . $key . ' MUST be declared');
			self::assertArrayHasKey('groupBy', $aggs[$key]);
			self::assertArrayHasKey('filter', $aggs[$key]);
		}

		// `metric`/`field`, not `source`/`sum`. AggregationRunner reads only
		// field/filter/from/groupBy/join/metric/metrics/select/where, so `sum`
		// computed nothing and `source` was inert — it is NOT the engine's
		// `from`, which switches the runner into its cross-schema path. These
		// three declare GLLine ON GLLine, so dropping `source` is the whole
		// translation. Verified live: byCostCenter returns CC-001=5000,
		// CC-002=10000, null=24200, matching the rows exactly.
		foreach (['byCostCenter', 'byProject'] as $key) {
			self::assertSame('sum', $aggs[$key]['metric'], 'Aggregation MUST declare metric=sum');
			self::assertSame('amount', $aggs[$key]['field'], 'Aggregation MUST sum the amount field');
			self::assertArrayNotHasKey('source', $aggs[$key], '`source` is not an engine key');
			self::assertArrayNotHasKey('sum', $aggs[$key], '`sum` is not an engine key; use metric+field');
		}

		// byCostCenterHierarchy groups by `AnalyticalDimension.parentCode` — a
		// field that exists only on the JOINED schema. applyJoin() runs AFTER
		// grouping, so this used to group on a column every row lacks, giving
		// one null bucket holding everything: a plausible total, not an error.
		// OpenRegister #2916 projects joined group fields onto the rows first,
		// which is what makes the declaration computable.
		self::assertSame('sum', $aggs['byCostCenterHierarchy']['metric']);
		self::assertSame('amount', $aggs['byCostCenterHierarchy']['field']);
		self::assertArrayNotHasKey(
			'source',
			$aggs['byCostCenterHierarchy'],
			'`source` is not an engine key'
		);

		// The `on` SHORTHAND ("AnalyticalDimension.code") is refused by the
		// joined-field grouping path: it names the joined side only, leaving the
		// parent key to be inferred — and inferring the JOINED field produced a
		// single '' bucket rather than one per region. The explicit map states
		// both sides: parent GLLine.costCenterCode -> joined AnalyticalDimension.code.
		self::assertSame(
			['costCenterCode' => 'code'],
			$aggs['byCostCenterHierarchy']['join']['on'],
			'`on` MUST be an explicit parent-field => joined-field map'
		);

		// byAnalyticalDimension is still untranslated: it groups by the wildcard
		// `dimensions.*`, which no engine key expresses. Tracked in #1261 and
		// pinned here so the gap stays visible.
		self::assertArrayNotHasKey(
			'metric',
			$aggs['byAnalyticalDimension'],
			'still untranslated — wildcard groupBy, see #1261'
		);

		// After the ADIM merge (REQ-ADIM-101), byCostCenter joins through the unified
		// AnalyticalDimension schema (filtered by dimensionType=cost-center) rather than
		// the retired CostCenter schema.
		self::assertSame(
			'AnalyticalDimension',
			$aggs['byCostCenter']['join']['through'],
			'byCostCenter MUST join through AnalyticalDimension (REQ-ADIM-101 re-targeting)'
		);
		self::assertSame(
			'engagement',
			$aggs['byProject']['join']['through'],
			'byProject MUST join through Project'
		);

		// Free-form dimension roll-up uses a wildcard groupBy so any operator-
		// declared AnalyticalDimension automatically contributes a group.
		self::assertSame(
			['dimensions.*'],
			$aggs['byAnalyticalDimension']['groupBy'],
			'byAnalyticalDimension MUST group by the dimensions.* wildcard for REQ-CD-006 extensibility'
		);
	}//end testGlLineCarriesSegmentPnlAggregations()

	/**
	 * The fragment ships seed cost-center + project + dimension objects
	 * (REQ-CD-002) so the RepairStep importer can pre-populate a fresh
	 * administratie with realistic examples.
	 *
	 * @return void
	 */
	public function testSeedObjectsContainRequiredExamples(): void {
		$objects = ($this->fragment['objects'] ?? []);
		self::assertNotEmpty($objects, 'Fragment MUST ship seed objects');

		$byKey = static function (array $items, string $schema, string $slug) {
			foreach ($items as $item) {
				$self = ($item['@self'] ?? []);
				if (($self['schema'] ?? '') === $schema && ($self['slug'] ?? '') === $slug) {
					return $item;
				}
			}
			return null;
		};

		// Two analytical dimensions: REGION (flat string) + PRODUCT_LINE (hierarchical).
		$region = $byKey($objects, 'AnalyticalDimension', 'dim-region');
		self::assertIsArray($region, 'REGION dimension seed MUST be present');
		self::assertSame('REGION', $region['code']);
		self::assertSame('string', $region['dataType']);
		self::assertFalse($region['isHierarchical']);

		$productLine = $byKey($objects, 'AnalyticalDimension', 'dim-productline');
		self::assertIsArray($productLine, 'PRODUCT_LINE dimension seed MUST be present');
		self::assertTrue($productLine['isHierarchical']);

		// Three Dutch cost centers covering admin + sales + logistics. After the ADIM merge
		// (REQ-ADIM-101), cost-center seed objects live under schema=AnalyticalDimension
		// with dimensionType=cost-center (the retired CostCenter schema was unified).
		foreach (['cc-admin-amsterdam', 'cc-sales-utrecht', 'cc-logistics-rotterdam'] as $slug) {
			$cc = $byKey($objects, 'AnalyticalDimension', $slug);
			self::assertIsArray($cc, 'AnalyticalDimension (cost-center) seed ' . $slug . ' MUST be present');
			self::assertSame('active', $cc['lifecycleState']);
			self::assertSame('adm-default', $cc['administrationId']);
		}

		// Two project seeds — internal-platform + WBSO research grant.
		foreach (['proj-internal-platform', 'proj-grant-research'] as $slug) {
			$proj = $byKey($objects, 'engagement', $slug);
			self::assertIsArray($proj, 'Project seed ' . $slug . ' MUST be present');
			self::assertSame('active', $proj['lifecycleState']);
			self::assertSame('adm-default', $proj['administrationId']);
		}
	}//end testSeedObjectsContainRequiredExamples()

	/**
	 * The manifest fragment declares the AnalyticalDimensions nav entry +
	 * index/detail pages (REQ-CD-005), so the operator-extensible custom
	 * dimension surface is reachable from the Bookkeeping menu.
	 *
	 * @return void
	 */
	public function testManifestFragmentDeclaresAnalyticalDimensionPages(): void {
		$path = __DIR__ . '/../../../src/manifest.d/bookkeeping-cost-centers-dimensions.json';
		self::assertFileExists($path, 'Manifest fragment MUST ship in src/manifest.d');

		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($decoded);

		// Menu carries an AnalyticalDimensions child under Bookkeeping.
		$hasNav = false;
		foreach (($decoded['menu'] ?? []) as $menu) {
			if (($menu['id'] ?? '') !== 'Bookkeeping') {
				continue;
			}
			foreach (($menu['children'] ?? []) as $child) {
				if (($child['id'] ?? '') === 'AnalyticalDimensions') {
					$hasNav = true;
					self::assertSame('AnalyticalDimensions', $child['route']);
				}
			}
		}
		self::assertTrue($hasNav, 'Bookkeeping menu MUST carry AnalyticalDimensions child');

		// Index + detail pages cover the schema.
		$pageIds = array_map(static fn ($p) => $p['id'] ?? '', ($decoded['pages'] ?? []));
		self::assertContains('AnalyticalDimensions', $pageIds);
		self::assertContains('AnalyticalDimensionDetail', $pageIds);

		foreach (($decoded['pages'] ?? []) as $page) {
			if (in_array($page['id'] ?? '', ['AnalyticalDimensions', 'AnalyticalDimensionDetail'], true)) {
				self::assertSame('AnalyticalDimension', $page['config']['schema']);
			}
		}
	}//end testManifestFragmentDeclaresAnalyticalDimensionPages()
}//end class
