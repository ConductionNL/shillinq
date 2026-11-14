<?php

/**
 * Unit tests for the 2023 refinement codes in the municipal taakveld catalogue.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-bbv-compliance/spec.md#requirement-the-taakveld-catalogue-holds-the-2023-refinement-codes-and-their-parent-req-bbv-013
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Reporting\CostSource\TaskFieldRollup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The seed is data; the roll-up that reads it is the class under test.
 */
#[CoversClass(TaskFieldRollup::class)]
final class TaakveldRefinementCatalogueTest extends TestCase {

	private const SEED = __DIR__ . '/../../../lib/Settings/seeds/bbv-taakvelden-gemeente-2025.json';

	private const OVERLAY = __DIR__ . '/../../../lib/Settings/register.d/iv3-report-takes-case-costs-by-case-type.json';

	/**
	 * The catalogue entries by code.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function catalogue(): array {
		$entries = [];
		foreach (json_decode((string)file_get_contents(self::SEED), true)['taskFields'] as $entry) {
			$entries[$entry['code']] = $entry;
		}

		return $entries;
	}//end catalogue()

	/**
	 * The 18 refinement codes, each under its pre-2023 parent.
	 *
	 * @return void
	 */
	public function testTheEighteenRefinementCodesNameTheirParent(): void {
		$expected = [
			'6.71a' => '6.71', '6.71b' => '6.71', '6.71c' => '6.71', '6.71d' => '6.71',
			'6.72a' => '6.72', '6.72b' => '6.72', '6.72c' => '6.72', '6.72d' => '6.72',
			'6.73a' => '6.72', '6.73b' => '6.72', '6.73c' => '6.72',
			'6.74a' => '6.72', '6.74b' => '6.72', '6.74c' => '6.72',
			'6.81a' => '6.81', '6.81b' => '6.81', '6.82a' => '6.82', '6.82b' => '6.82',
		];

		self::assertSame($expected, (new TaskFieldRollup())->parents());
		self::assertSame('6.72', (new TaskFieldRollup())->reportedUnder(' 6.73b '));
		self::assertSame('8.3', (new TaskFieldRollup())->reportedUnder('8.3'));
	}//end testTheEighteenRefinementCodesNameTheirParent()

	/**
	 * Every aggregatesUnder resolves to a deprecated parent that rolls up no further.
	 *
	 * @return void
	 */
	public function testEveryParentResolvesAndIsDeprecated(): void {
		$catalogue = $this->catalogue();
		foreach ($catalogue as $code => $entry) {
			if (isset($entry['aggregatesUnder']) === false) {
				continue;
			}

			$parent = $catalogue[$entry['aggregatesUnder']] ?? null;
			self::assertNotNull($parent, $code . ' rolls up to a code that is not in the catalogue');
			self::assertTrue($parent['deprecated'] ?? false, $entry['aggregatesUnder'] . ' is not marked deprecated');
			self::assertArrayNotHasKey('aggregatesUnder', $parent);
			self::assertArrayNotHasKey('deprecated', $entry, $code . ' is a refinement code and not deprecated');
		}

		self::assertSame(['6.71', '6.72', '6.81', '6.82'], array_keys(array_filter($catalogue, static fn (array $entry): bool => ($entry['deprecated'] ?? false) === true)));
	}//end testEveryParentResolvesAndIsDeprecated()

	/**
	 * Every code carries its Dutch name and an English label.
	 *
	 * @return void
	 */
	public function testNoCodeLacksItsDutchNameOrEnglishLabel(): void {
		foreach ($this->catalogue() as $code => $entry) {
			self::assertNotSame('', trim((string)($entry['name'] ?? '')), $code . ' has no name');
			self::assertNotSame('', trim((string)($entry['nameEn'] ?? '')), $code . ' has no nameEn');
		}

		self::assertSame('Gezinsgericht', $this->catalogue()['6.73b']['name']);
	}//end testNoCodeLacksItsDutchNameOrEnglishLabel()

	/**
	 * Both catalogue schemas declare the two properties, or OpenRegister drops them.
	 *
	 * @return void
	 */
	public function testBothCatalogueSchemasDeclareTheProperties(): void {
		$schemas = json_decode((string)file_get_contents(self::OVERLAY), true)['components']['schemas'];

		foreach (['BbvTaakveld', 'Taakveld'] as $schema) {
			self::assertSame('string', $schemas[$schema]['properties']['aggregatesUnder']['type']);
			self::assertSame('boolean', $schemas[$schema]['properties']['deprecated']['type']);
		}
	}//end testBothCatalogueSchemasDeclareTheProperties()

	/**
	 * An unreadable catalogue rolls nothing up.
	 *
	 * @return void
	 */
	public function testAnUnreadableCatalogueRollsNothingUp(): void {
		$rollup = new TaskFieldRollup(__DIR__ . '/does-not-exist.json');

		self::assertSame([], $rollup->parents());
		self::assertSame('6.73b', $rollup->reportedUnder('6.73b'));
	}//end testAnUnreadableCatalogueRollsNothingUp()
}//end class
