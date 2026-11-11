<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\NationalReportingTailChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the NationalReportingTailChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\NationalReportingTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class NationalReportingTailChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'FinancialStatement' => [
			'bw-art365-fixed-assets-classes' => 'FF',
			'bw-art365-1-intangibles' => 'FT',
			'bw-art366-tangible-assets' => 'FT',
			'bw-art367-financial-fixed-assets' => 'FT',
			'bw-art369-current-assets' => 'FF',
			'bw-art373-equity-components' => 'FF',
			'bw-art375-debts-maturity' => 'FF',
			'rj-210-development-legal-reserve' => 'FT',
			'rj-210-intangible-max-life' => 'FT',
			'rgs-sbr-filing' => 'FF',
			'hgb-266-small-abridged-balance' => 'FF',
			'hgb-268-equity-detail' => 'FT',
			'hgb-275-gesamtkostenverfahren' => 'TT',
			'hgb-275-umsatzkostenverfahren' => 'TT',
			'hgb-257-retention' => 'FT',
			'pcg-balance-table-form' => 'FF',
			'pcg-income-statement-list' => 'FT',
			'pcg-systeme-abrege' => 'FF',
			'pcg-systeme-base' => 'FT',
			'fr-confidentiality-option' => 'FT',
			'fr-retention-period' => 'FT',
			'fec-mandatory-export' => 'FF',
			'fec-field-structure' => 'FT',
			'esef-block-tagging-notes' => 'FF',
			'esef-taxonomy-extension' => 'FF',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (NationalReportingTailChecks::checks() as $type => $rules) {
			foreach ($rules as $ruleId => $check) {
				$actual[$type][$ruleId] = self::verdicts($check, self::samples($type));
			}
		}

		$this->assertSame(self::VERDICTS, $actual);

	}//end testRegistryMatchesThePinnedVerdicts()

	/**
	 * The seed data is unchanged.
	 *
	 * @return void
	 */
	public function testSeedDataIsUnchanged(): void {
		$seed = [NationalReportingTailChecks::seedSpec()];
		$this->assertSame('7bee1d64635652b0cdb3f11ba219e44c', md5(serialize($seed)));

	}//end testSeedDataIsUnchanged()

	/**
	 * The objects every check of a type runs on.
	 *
	 * @param string $type The object type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function samples(string $type): array {
		$samples = [[]];
		$defaults = (NationalReportingTailChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		return $samples;

	}//end samples()

	/**
	 * One character per sample: T (true), F (false) or E (it threw).
	 *
	 * @param callable                         $check   The check.
	 * @param array<int, array<string, mixed>> $samples The objects.
	 *
	 * @return string
	 */
	private static function verdicts(callable $check, array $samples): string {
		$out = '';
		foreach ($samples as $object) {
			try {
				$out .= $check($object, ['jurisdiction' => 'NL']) === true ? 'T' : 'F';
			} catch (\Throwable $e) {
				$out .= 'E';
			}
		}

		return $out;

	}//end verdicts()
}//end class
