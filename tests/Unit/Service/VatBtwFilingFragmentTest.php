<?php

/**
 * Unit tests for the bookkeeping-vat-btw-filing register fragment.
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
 * @spec openspec/changes/bookkeeping-vat-btw-filing/specs/bookkeeping-vat-btw-filing.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T3 VAT/BTW filing fragment is valid JSON, declares the three
 * VAT registers with the declared lifecycle + aggregations, seeds objects that
 * resolve to those schemas, and merges additively onto the monolith without
 * colliding with the pre-existing VatReturn (BTW-aangifte) schema (ADR-037).
 */
final class VatBtwFilingFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-vat-btw-filing.json';

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
	 * Load and decode the fragment JSON.
	 *
	 * @return array<mixed> The decoded fragment.
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * Return the fragment's seed objects (kept under components.objects, the
	 * shape OR's ConfigurationImportHandler consumes — $data['components']['objects']).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function seedObjects(): array {
		$data = $this->fragment();
		return ($data['components']['objects'] ?? []);
	}//end seedObjects()

	/**
	 * The fragment file is present and valid JSON with a components.schemas block.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
		// Seed objects live under components.objects (OR ImportHandler reads $data['components']['objects']).
		self::assertArrayHasKey('objects', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the three VAT registers from the spec.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresVatRegisters(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['BtwAangifte', 'VATDeclaration', 'VATLine'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}
	}//end testFragmentDeclaresVatRegisters()

	/**
	 * VATReturn declares the draft → submitted → verified → filed lifecycle on
	 * statusCode with all four transitions (REQ-VAT-005, REQ-VAT-008).
	 *
	 * @return void
	 */
	public function testVatReturnDeclaresLifecycle(): void {
		$vatReturn = $this->fragment()['components']['schemas']['BtwAangifte'];
		self::assertArrayHasKey('x-openregister-lifecycle', $vatReturn);

		$lifecycle = $vatReturn['x-openregister-lifecycle'];
		self::assertSame('statusCode', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		foreach (['draft', 'submitted', 'verified', 'filed'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Missing state $state");
		}

		foreach (['submit', 'verify', 'file', 'rebase'] as $transition) {
			self::assertArrayHasKey($transition, $lifecycle['transitions'], "Missing transition $transition");
		}

		// The statusCode enum mirrors the lifecycle states exactly.
		self::assertSame(
			['draft', 'submitted', 'verified', 'filed'],
			$vatReturn['properties']['statusCode']['enum']
		);
	}//end testVatReturnDeclaresLifecycle()

	/**
	 * VATReturn declares the VAT reconciliation aggregations sourced from
	 * VATLine (REQ-VAT-002, REQ-VAT-011) — no PHP service per ADR-031.
	 *
	 * The aggregation block exposes a single `totalsByReturn` rollup that
	 * sums VATLine.vatAmount / taxableAmount grouped per return, producing
	 * collected, paid and taxable totals via conditional sum operations,
	 * plus a derived `vatBalance` expression op (totalVATPaid − totalVATCollected).
	 *
	 * @return void
	 */
	public function testVatReturnDeclaresReconciliationAggregations(): void {
		$vatReturn = $this->fragment()['components']['schemas']['BtwAangifte'];
		self::assertArrayHasKey('x-openregister-aggregations', $vatReturn);

		$aggregations = $vatReturn['x-openregister-aggregations'];
		self::assertArrayHasKey('totalsByReturn', $aggregations);

		$totals = $aggregations['totalsByReturn'];

		// `from`, not `source`. AggregationRunner reads `from` and nothing else,
		// so `source` never switched this onto VATLine at all.
		self::assertSame('VATLine', $totals['from']);
		self::assertArrayNotHasKey('source', $totals, '`source` is not an engine key');

		// The per-return correlation is a groupBy DIMENSION now. `returnId:
		// "@self.id"` needed a parent row that no caller supplies, so it stayed a
		// literal string and matched nothing.
		self::assertContains('returnId', $totals['groupBy']);
		self::assertArrayNotHasKey('returnId', ($totals['filter'] ?? []));

		// NOW TRANSLATED. This was pinned as untranslatable in the previous pass,
		// for two reasons that have both since been removed: `vatBalance` is an
		// `expression` op, which the engine had no equivalent for until derived
		// metrics landed (openregister #2941), and the conditions were SQL-ish
		// STRINGS where computeMetrics() takes a filter OBJECT.
		self::assertArrayNotHasKey('operations', $totals, '`operations` is not an engine key');
		self::assertArrayHasKey('metrics', $totals);

		$byAlias = [];
		foreach ($totals['metrics'] as $metric) {
			$byAlias[$metric['as']] = $metric;
		}
		self::assertArrayHasKey('totalVATCollected', $byAlias);
		self::assertArrayHasKey('totalVATPaid', $byAlias);

		// The string condition became a filter OBJECT, and the field lost its
		// schema prefix — `from` resolves bare names on VATLine.
		self::assertSame(['type' => 'collected'], $byAlias['totalVATCollected']['condition']);
		self::assertSame('vatAmount', $byAlias['totalVATCollected']['field']);

		// The derived metric names only aliases declared BEFORE it. Anything
		// else raises at run time, which validate-registers now refuses at
		// declaration time.
		self::assertArrayHasKey('vatBalance', $byAlias);
		self::assertSame('expression', $byAlias['vatBalance']['metric']);
		foreach (preg_split('/[^A-Za-z_]+/', $byAlias['vatBalance']['expression']) as $ident) {
			if ($ident === '' || $ident === 'min' || $ident === 'max') {
				continue;
			}

			self::assertArrayHasKey(
				$ident,
				$byAlias,
				'a derived metric may only name aliases this aggregation declares'
			);
		}
	}//end testVatReturnDeclaresReconciliationAggregations()

	/**
	 * Every seed object references one of the three VAT schemas under the
	 * shillinq register, with a stable slug.
	 *
	 * @return void
	 */
	public function testSeedObjectsResolveToFragmentSchemas(): void {
		$data = $this->fragment();
		$defined = array_keys($data['components']['schemas']);
		$seeds = $this->seedObjects();

		self::assertNotEmpty($seeds);
		$slugs = [];
		foreach ($seeds as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertContains($object['@self']['schema'], $defined, 'Seed references undefined schema');
			self::assertArrayHasKey('slug', $object['@self']);
			$slugs[] = $object['@self']['slug'];
		}

		// Slugs are unique so re-import is idempotent.
		self::assertSame(count($slugs), count(array_unique($slugs)), 'Seed slugs must be unique');
	}//end testSeedObjectsResolveToFragmentSchemas()

	/**
	 * Seed VATLine rows are structurally consistent: every row declares a
	 * non-negative taxable + VAT amount, a non-negative VAT rate, and a
	 * known line type. KOR (rate=0) and reverse-charge lines are seeded
	 * with the operator-stated amount the lifecycle uses for aggregation;
	 * the spec requires multiple seed lines to exercise the BTW return
	 * generator over the rate grid (REQ-VAT-010).
	 *
	 * @return void
	 */
	public function testSeedVatLinesAreInternallyConsistent(): void {
		$objects = $this->seedObjects();
		$lineCount = 0;
		foreach ($objects as $object) {
			if ($object['@self']['schema'] !== 'VATLine') {
				continue;
			}

			$lineCount++;
			self::assertContains(
				$object['type'],
				['collected', 'paid', 'reverse-charge'],
				'VAT line type must be one of the declared categories'
			);
			self::assertGreaterThanOrEqual(0.0, (float)$object['taxableAmount']);
			self::assertGreaterThanOrEqual(0.0, (float)$object['vatAmount']);
			self::assertGreaterThanOrEqual(0.0, (float)$object['taxRate']);

			if ($object['type'] === 'reverse-charge') {
				self::assertTrue($object['reverseChargeApplicable']);
			}
		}

		self::assertGreaterThanOrEqual(5, $lineCount, 'Spec requires multiple seed VAT lines');
	}//end testSeedVatLinesAreInternallyConsistent()

	/**
	 * Merging the fragment onto the monolith adds the three VAT registers
	 * without dropping any existing schema — including the distinct
	 * pre-existing VatReturn (BTW-aangifte) schema (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyWithoutCollision(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemaCountBefore = count($base['components']['schemas']);
		// Base monolith keeps its seed objects at the top level; the fragment
		// ships them under components.objects (the shape OR's import handler
		// consumes). Both are valid input keys for the importer.
		$baseObjectCount = count(($base['objects'] ?? []));
		$fragObjectCount = count(($frag['components']['objects'] ?? []));

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('BtwAangifte', $schemas);
		self::assertArrayHasKey('VATDeclaration', $schemas);
		self::assertArrayHasKey('VATLine', $schemas);

		// No existing schema dropped.
		foreach (array_keys($base['components']['schemas']) as $existing) {
			self::assertArrayHasKey($existing, $schemas, "Existing schema $existing must survive merge");
		}

		// The three new schemas are net-additive.
		self::assertSame($schemaCountBefore + 3, count($schemas));

		// The base's top-level objects list survives unchanged (the fragment
		// does not touch it) and the fragment's components.objects survive in
		// the merged components block (disjoint-union property).
		self::assertSame($baseObjectCount, count(($merged['objects'] ?? [])));
		self::assertSame($fragObjectCount, count(($merged['components']['objects'] ?? [])));
	}//end testFragmentMergesAdditivelyWithoutCollision()
}//end class
