<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\IfrsUsGaapChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the IfrsUsGaapChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\IfrsUsGaapChecks
 * @uses \OCA\Shillinq\Standards\Checks\CheckFields
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class IfrsUsGaapChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'FinancialStatement' => [
			'ias1-10-complete-set' => 'FTF',
			'ias1-32-no-offsetting' => 'FTT',
			'ias1-36-annual-frequency' => 'FFT',
			'ias1-38-comparative-information' => 'FTT',
			'ias7-10-classify-three-activities' => 'FTF',
			'asc230-three-categories' => 'FTF',
			'ifrs18-47-classify-into-categories' => 'FTF',
			'ifrs18-69-operating-profit-subtotal' => 'FTF',
			'ias2-25-fifo-or-weighted-average' => 'FTF',
			'ias2-25-lifo-prohibited' => 'FTF',
			'ias38-48-no-internal-goodwill' => 'FTF',
			'ias38-63-no-internal-brands' => 'FTF',
			'ias36-124-goodwill-reversal-prohibited' => 'FTF',
			'ias37-27-contingent-liability-not-recognised' => 'FTF',
			'ias21-21-initial-spot-rate' => 'FTF',
			'ias21-23a-monetary-closing-rate' => 'FTF',
			'ias21-23b-nonmonetary-historical-rate' => 'FTF',
			'ias12-12-current-tax-liability' => 'FTF',
			'ias12-53-no-discounting' => 'FTF',
			'asc740-dta-dtl-noncurrent' => 'FTF',
			'ias19-11-short-term-benefits-undiscounted' => 'FTF',
			'ifrs9-5511-sicr-30dpd-rebuttable' => 'FTF',
			'ifrs9-default-90dpd-rebuttable' => 'FTF',
			'ifrs3-45-measurement-period-one-year' => 'FTF',
			'asc805-measurement-period-one-year' => 'FTF',
			'ias28-significant-influence-20pct' => 'FTF',
			'asc323-equity-method-significant-influence' => 'FTF',
			'ifrs15-63-financing-practical-expedient' => 'FTF',
			'ifrs15-94-cost-obtaining-practical-expedient' => 'FTF',
			'ifrs16-22-recognise-rou-and-liability' => 'FTF',
			'ifrs16-37-liability-constant-rate-interest' => 'FTF',
			'asc842-short-term-exemption' => 'FTF',
			'asc210-classified-balance-sheet' => 'FTF',
			'asc220-comprehensive-income' => 'FTF',
			'asc848-sunset-date-2024' => 'TTT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (IfrsUsGaapChecks::checks() as $type => $rules) {
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
		$seed = [IfrsUsGaapChecks::seedSpec()];
		$seed[] = IfrsUsGaapChecks::seedObjects();
		$this->assertSame('2d379f45c32a747ec5230c12f296a070', md5(serialize($seed)));

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
		$defaults = (IfrsUsGaapChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((IfrsUsGaapChecks::seedObjects()[$type] ?? []) as $object) {
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
