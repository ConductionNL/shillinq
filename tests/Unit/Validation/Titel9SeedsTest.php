<?php

/**
 * Unit tests for the Titel 9 jaarrekening configuration seeds.
 *
 * Verifies the four config-as-data seeds (groottecategorie thresholds, balans
 * rubriek mapping, V&W model rubrieken, toelichting templates) are well-formed
 * and carry the wettelijke values the spec depends on (REQ-T9-001 .. REQ-T9-009).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-titel-9-jaarrekening/specs/bookkeeping-titel-9-jaarrekening/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Titel 9 configuration seeds.
 */
final class Titel9SeedsTest extends TestCase {

	/**
	 * Decode a seed file under lib/Settings/seeds/.
	 *
	 * @param string $name Seed file name.
	 *
	 * @return array<string,mixed>
	 */
	private function loadSeed(string $name): array {
		$path = __DIR__ . '/../../../lib/Settings/seeds/' . $name;
		self::assertFileExists($path, "Seed must exist: $name");
		$raw = file_get_contents($path);
		self::assertIsString($raw);
		$decoded = json_decode($raw, true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), "Seed must be valid JSON: $name");
		return $decoded;
	}//end loadSeed()

	/**
	 * REQ-T9-001: the groottecategorie seed carries the wettelijke thresholds and
	 * embeds the two-year rule.
	 *
	 * @return void
	 */
	public function testGroottecategorieThresholds(): void {
		$seed = $this->loadSeed('groottecategorie-classification.json');
		self::assertTrue($seed['twoYearRule']);

		$byName = [];
		foreach ($seed['categories'] as $cat) {
			$byName[$cat['name']] = $cat;
		}

		foreach (['micro', 'klein', 'middelgroot', 'groot'] as $name) {
			self::assertArrayHasKey($name, $byName, "Missing category: $name");
		}

		self::assertSame(450000, $byName['micro']['thresholds']['balanceSheetTotal']);
		self::assertSame(900000, $byName['micro']['thresholds']['netRevenue']);
		self::assertSame(10, $byName['micro']['thresholds']['averageCountEmployees']);
		self::assertSame(12000000, $byName['klein']['thresholds']['balanceSheetTotal']);
		self::assertSame(25000000, $byName['middelgroot']['thresholds']['balanceSheetTotal']);
		// 'groot' has no upper bound (REQ-T9-001).
		self::assertNull($byName['groot']['thresholds']);

	}//end testGroottecategorieThresholds()

	/**
	 * REQ-T9-009: the template matrix requires kasstroomoverzicht/bestuursverslag
	 * for middelgroot+ but not for micro/klein.
	 *
	 * @return void
	 */
	public function testTemplateMatrixReliefRules(): void {
		$matrix = $this->loadSeed('groottecategorie-classification.json')['templateMatrix'];
		self::assertFalse($matrix['micro']['kasstroomoverzicht']);
		self::assertFalse($matrix['klein']['bestuursverslag']);
		self::assertTrue($matrix['middelgroot']['kasstroomoverzicht']);
		self::assertTrue($matrix['middelgroot']['bestuursverslag']);
		self::assertTrue($matrix['middelgroot']['auditorsStatement']);

	}//end testTemplateMatrixReliefRules()

	/**
	 * REQ-T9-002: the balans rubriek catalogue covers the art. 2:373 BW rubrieken
	 * and at least three variant maps are provided (design D3).
	 *
	 * @return void
	 */
	public function testBalansRubriekMapping(): void {
		$seed = $this->loadSeed('balans-rubriek-mapping.json');
		$codes = array_column($seed['rubriekCatalogus'], 'rubrieckCode');
		foreach (['B.I', 'B.II', 'B.III', 'C.I', 'C.II', 'C.IV', 'A', 'B', 'C', 'D'] as $code) {
			self::assertContains($code, $codes, "Missing section: $code");
		}

		self::assertGreaterThanOrEqual(3, count($seed['variants']), 'At least three variant maps expected.');

	}//end testBalansRubriekMapping()

	/**
	 * REQ-T9-003: both V&W modellen are catalogued with subtotal rows.
	 *
	 * @return void
	 */
	public function testVwModelRubrieken(): void {
		$models = $this->loadSeed('vw-model-rubrieken.json')['models'];
		self::assertArrayHasKey('a-categorical', $models);
		self::assertArrayHasKey('e-functional', $models);

		foreach (['a-categorical', 'e-functional'] as $model) {
			$subtotals = array_filter(
				$models[$model]['rubrieken'],
				static fn (array $r): bool => ($r['isSubtotal'] ?? false) === true
			);
			self::assertNotEmpty($subtotals, "Model $model must declare subtotal rows.");
		}

	}//end testVwModelRubrieken()

	/**
	 * REQ-T9-004 / REQ-T9-009: the grondslagen template is mandatory for all
	 * categories; the schulden template is mandatory only for middelgroot+.
	 *
	 * @return void
	 */
	public function testToelichtingTemplatesApplicability(): void {
		$templates = $this->loadSeed('toelichting-templates.json')['templates'];
		$byName = [];
		foreach ($templates as $tpl) {
			$byName[$tpl['templateName']] = $tpl;
		}

		self::assertArrayHasKey('rj-240-grondslagen', $byName);
		self::assertTrue($byName['rj-240-grondslagen']['required']);
		self::assertSame(['micro', 'klein', 'middelgroot', 'groot'], $byName['rj-240-grondslagen']['applicableFor']);

		self::assertArrayHasKey('rj-250-schulden', $byName);
		self::assertSame(['middelgroot', 'groot'], $byName['rj-250-schulden']['applicableFor']);
		self::assertNotContains('micro', $byName['rj-250-schulden']['applicableFor']);

	}//end testToelichtingTemplatesApplicability()
}//end class
