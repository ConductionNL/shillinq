<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\InvoicingExtraChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the InvoicingExtraChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoicingExtraChecks
 * @uses \OCA\Shillinq\Standards\Checks\InvoiceParts
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoicingExtraChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'ARInvoice' => [
			'en16931-br-11' => 'FT',
			'en16931-br-cl-14' => 'TT',
			'en16931-br-cl-04' => 'FF',
			'en16931-br-cl-05' => 'TT',
			'en16931-br-cl-01' => 'FF',
			'en16931-br-cl-17' => 'FF',
			'en16931-br-cl-23' => 'FF',
			'en16931-br-27' => 'TT',
			'en16931-br-28' => 'TT',
			'en16931-br-29' => 'TT',
			'en16931-br-30' => 'TT',
			'en16931-br-co-21' => 'TT',
			'en16931-br-co-22' => 'TT',
			'en16931-br-co-23' => 'TT',
			'en16931-br-co-24' => 'TT',
			'en16931-br-15' => 'TT',
			'en16931-br-dec-18' => 'TT',
			'en16931-br-dec-16' => 'TT',
			'en16931-br-dec-17' => 'TT',
			'en16931-br-dec-15' => 'TT',
			'en16931-br-co-16' => 'TT',
			'en16931-br-53' => 'TT',
			'en16931-br-49' => 'TT',
			'en16931-br-cl-16' => 'TT',
			'en16931-br-50' => 'TT',
			'en16931-br-61' => 'TT',
			'en16931-br-62' => 'TT',
			'en16931-br-63' => 'TT',
			'en16931-br-52' => 'TT',
			'en16931-br-55' => 'TT',
			'en16931-br-54' => 'TT',
			'en16931-br-18' => 'TT',
			'en16931-br-19' => 'TT',
			'en16931-br-20' => 'TT',
			'en16931-br-56' => 'TT',
			'en16931-br-17' => 'TT',
			'en16931-br-57' => 'TT',
			'en16931-br-s-01' => 'TT',
			'en16931-br-z-01' => 'TT',
			'en16931-br-e-01' => 'TT',
			'en16931-br-ae-01' => 'TT',
			'en16931-br-ic-01' => 'TT',
			'en16931-br-g-01' => 'TT',
			'en16931-br-o-01' => 'TT',
			'en16931-br-o-11' => 'TT',
			'en16931-br-o-12' => 'TT',
			'en16931-br-o-13' => 'TT',
			'en16931-br-o-14' => 'TT',
			'en16931-br-ic-11' => 'TT',
			'en16931-br-ic-12' => 'TT',
			'vatdir-art226-4' => 'TT',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (InvoicingExtraChecks::checks() as $type => $rules) {
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
		$seed = [InvoicingExtraChecks::seedSpec()];
		$this->assertSame('79b8d8d5823e7b129c2b5e9fd6a2667f', md5(serialize($seed)));

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
		$defaults = (InvoicingExtraChecks::seedSpec()[$type] ?? []);
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
