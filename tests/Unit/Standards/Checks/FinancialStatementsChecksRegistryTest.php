<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\FinancialStatementsChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the FinancialStatementsChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\FinancialStatementsChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class FinancialStatementsChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'FinancialStatement' => [
			'bw-art362-6-euro-language' => 'FTTTT',
			'bw-art363-aggregation' => 'FTTTT',
			'bw-art364-balance-layout' => 'FTTTT',
			'bw-art377-pnl-layout' => 'FTTTT',
			'bw-art395a-micro' => 'TTTTT',
			'bw-art396-small' => 'TTTTT',
			'bw-art397-medium' => 'TTTTT',
			'bw-art398-consistency-regime' => 'TTTTT',
			'bw-art394-filing-kvk' => 'FTTTT',
			'bw-art210-prepare-deadline' => 'FTTTT',
			'bw-art393-audit' => 'FTTTT',
			'hgb-242-financial-statements' => 'FTTTT',
			'hgb-244-language-currency' => 'FTTTT',
			'hgb-246-completeness-gross' => 'FTTTT',
			'hgb-266-balance-structure' => 'FTTTT',
			'hgb-275-pnl-vertical' => 'FTTTT',
			'hgb-267-size-classes' => 'FTTTT',
			'hgb-267-small-thresholds-2024' => 'TTTTT',
			'hgb-267-medium-thresholds-2024' => 'TTTTT',
			'hgb-267a-micro' => 'TTTTT',
			'hgb-316-audit-duty' => 'FTTTT',
			'hgb-325-disclosure-deadline' => 'FTTTT',
			'fec-balanced-entries' => 'FTTTT',
			'fr-filing-greffe' => 'FTTTT',
			'fr-audit-cac-thresholds' => 'FTTTT',
			'esef-scope-listed-only' => 'FTTTT',
			'esef-xhtml-format' => 'FTTTT',
			'esef-ixbrl-consolidated' => 'FTTTT',
			'esef-detailed-primary' => 'FTTTT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (FinancialStatementsChecks::checks() as $type => $rules) {
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
		$seed = [FinancialStatementsChecks::seedSpec()];
		$seed[] = FinancialStatementsChecks::seedObjects();
		$this->assertSame('35508e1b0b7c362c63c139d2cdd2306a', md5(serialize($seed)));

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
		$defaults = (FinancialStatementsChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((FinancialStatementsChecks::seedObjects()[$type] ?? []) as $object) {
			$samples[] = $object;
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
