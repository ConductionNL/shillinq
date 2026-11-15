<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\PublicSectorIpsasGasbChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the PublicSectorIpsasGasbChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\PublicSectorIpsasGasbChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class PublicSectorIpsasGasbChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'PublicSectorStatement' => [
			'ipsas-1-21-complete-set' => 'FTT',
			'ipsas-2-18-classify-activities' => 'FTT',
			'ipsas-12-15-lower-cost-nrv' => 'FTT',
			'ipsas-12-17-distribution-replacement-cost' => 'FTT',
			'ipsas-19-35-no-contingent-liability' => 'FTT',
			'ipsas-21-52-recognise-impairment' => 'FTT',
			'ipsas-21-54-loss-in-surplus-deficit' => 'FTT',
			'ipsas-23-44-condition-creates-liability' => 'FTT',
			'ipsas-23-59-tax-on-taxable-event' => 'FTT',
			'ipsas-24-14-budget-comparison' => 'FTT',
			'ipsas-35-40-eliminate-intragroup' => 'TTT',
			'ipsas-39-11-short-term-benefits' => 'FTT',
			'ipsas-41-10-recognise-on-becoming-party' => 'FTT',
			'ipsas-43-23-right-of-use-model' => 'FTT',
			'ipsas-43-27-lease-liability-pv' => 'FTT',
			'ipsas-47-36-taxes-on-taxable-event' => 'FTT',
			'gasb-34-dual-reporting-model' => 'FTT',
			'gasb-34-govwide-economic-resources' => 'FTT',
			'gasb-34-mda-required-rsi' => 'FTT',
			'gasb-34-modified-accrual-govfunds' => 'FTT',
			'gasb-34-major-fund-criteria' => 'FTT',
			'gasb-54-five-classifications' => 'FTT',
			'gasb-84-four-fund-types' => 'FTT',
			'gasb-87-single-lease-model' => 'FTT',
			'gasb-87-lessee-recognition' => 'FTT',
			'gasb-87-short-term-exception' => 'FTT',
			'gasb-96-sbita-recognition' => 'FTT',
			'gasb-96-short-term-exception' => 'FTT',
			'gasb-103-budgetary-comparison-rsi' => 'FTT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (PublicSectorIpsasGasbChecks::checks() as $type => $rules) {
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
		$seed = [PublicSectorIpsasGasbChecks::seedSpec()];
		$seed[] = PublicSectorIpsasGasbChecks::seedObjects();
		$this->assertSame('a05e1710484a460facb0e5dec6ed984d', md5(serialize($seed)));

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
		$defaults = (PublicSectorIpsasGasbChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((PublicSectorIpsasGasbChecks::seedObjects()[$type] ?? []) as $object) {
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
