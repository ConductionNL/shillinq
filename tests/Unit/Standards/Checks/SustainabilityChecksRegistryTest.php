<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\SustainabilityChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SustainabilityChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\SustainabilityChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class SustainabilityChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'EsrsStatement' => [
			'esrs-2-iro-2-dr-coverage' => 'FT',
			'esrs-2-mdr-t-targets' => 'FT',
			'esrs-e1-4-ghg-targets' => 'FT',
			'esrs-e1-5-energy-consumption-mix' => 'FT',
			'esrs-e1-5-energy-intensity' => 'TT',
			'esrs-e1-6-ghg-scope1' => 'FT',
			'esrs-e1-6-ghg-scope2' => 'FT',
			'esrs-e1-6-ghg-scope3' => 'FT',
			'esrs-e1-6-ghg-total' => 'FT',
			'esrs-e1-7-removals-credits' => 'FT',
			'esrs-e1-8-internal-carbon-pricing' => 'FT',
			'esrs-e2-3-pollution-targets' => 'FT',
			'esrs-e2-4-pollution-air-water-soil' => 'FT',
			'esrs-e2-5-substances-of-concern' => 'FT',
			'esrs-e3-3-water-targets' => 'FT',
			'esrs-e3-4-water-consumption' => 'FT',
			'esrs-e4-4-biodiversity-targets' => 'FT',
			'esrs-e4-5-biodiversity-impact-metrics' => 'FT',
			'esrs-e5-3-circular-targets' => 'FT',
			'esrs-e5-4-resource-inflows' => 'FT',
			'esrs-e5-5-resource-outflows-waste' => 'FT',
			'esrs-s1-6-employee-headcount' => 'FT',
			'esrs-s1-7-non-employees' => 'FT',
			'esrs-s1-8-collective-bargaining' => 'FT',
			'esrs-s1-9-diversity-metrics' => 'FT',
			'esrs-s1-14-health-safety' => 'FT',
			'esrs-s1-16-pay-gap' => 'FT',
			'esrs-s1-17-incidents-discrimination' => 'FT',
			'esrs-s2-5-vc-workers-targets' => 'FT',
			'esrs-s3-5-communities-targets' => 'FT',
			'esrs-s4-5-consumers-targets' => 'FT',
			'esrs-g1-4-corruption-incidents' => 'FT',
			'esrs-g1-6-payment-practices' => 'FT',
			'ifrs-s2-ghg-scope1' => 'FT',
			'ifrs-s2-ghg-scope2' => 'FT',
			'ifrs-s2-ghg-scope3' => 'FT',
			'ifrs-s2-financed-emissions' => 'FT',
			'ifrs-s2-transition-risk-exposure' => 'FT',
			'ifrs-s2-physical-risk-exposure' => 'FT',
			'ifrs-s2-opportunities-exposure' => 'FT',
			'ifrs-s2-capital-deployment' => 'FT',
			'ifrs-s2-internal-carbon-price' => 'FT',
			'ifrs-s2-remuneration-link' => 'FT',
			'ifrs-s2-industry-metrics' => 'FT',
			'ifrs-s2-climate-targets' => 'FT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (SustainabilityChecks::checks() as $type => $rules) {
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
		$seed = [SustainabilityChecks::seedSpec()];
		$seed[] = SustainabilityChecks::seedObjects();
		$this->assertSame('381f6c4e167ef49da824b72e6170bd97', md5(serialize($seed)));

	}//end testSeedDataIsUnchanged()

	/**
	 * Target lists and whether ESRS 2 MDR-T holds for them.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function targetLists(): array {
		$target = ['matter' => 'e1', 'baseYear' => 2020, 'targetYear' => 2030, 'value' => 50, 'baseline' => 100];
		return [
			'no targets'                 => [null, false],
			'empty list'                 => [[], false],
			'well-formed target'         => [[$target], true],
			'same base and target year'  => [[['targetYear' => 2020] + $target], true],
			'entry not an array'         => [[$target, 'x'], false],
			'unknown matter'             => [[['matter' => 'X9'] + $target], false],
			'base year not numeric'      => [[['baseYear' => 'soon'] + $target], false],
			'target year not numeric'    => [[['targetYear' => null] + $target], false],
			'value not numeric'          => [[['value' => 'half'] + $target], false],
			'baseline missing'           => [[array_diff_key($target, ['baseline' => true])], false],
			'target year before base'    => [[['targetYear' => 2019] + $target], false],
		];

	}//end targetLists()

	/**
	 * ESRS 2 MDR-T checks each target's matter, figures and year order.
	 *
	 * @param mixed $targets  The targets field.
	 * @param bool  $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('targetLists')]
	public function testTargetsAreWellFormed(mixed $targets, bool $expected): void {
		$check = SustainabilityChecks::checks()['EsrsStatement']['esrs-2-mdr-t-targets'];
		$this->assertSame($expected, $check(['targets' => $targets], ['jurisdiction' => 'NL']));

	}//end testTargetsAreWellFormed()

	/**
	 * The objects every check of a type runs on.
	 *
	 * @param string $type The object type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function samples(string $type): array {
		$samples = [[]];
		$defaults = (SustainabilityChecks::seedSpec()[$type] ?? []);
		if ($defaults !== []) {
			$samples[] = $defaults;
		}

		foreach ((SustainabilityChecks::seedObjects()[$type] ?? []) as $object) {
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
