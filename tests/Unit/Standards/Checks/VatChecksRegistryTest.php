<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\VatChecks;
use PHPUnit\Framework\TestCase;

/**
 * Pins the VatChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\VatChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class VatChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'ARInvoice' => [
			'vatdir-art63-tax-point-supply' => 'FF',
			'vatdir-art220-obligation-to-invoice' => 'FF',
			'vatdir-art73-taxable-amount' => 'FF',
			'vatdir-art78-taxable-amount-inclusions' => 'TT',
			'vatdir-art79-taxable-amount-exclusions' => 'TT',
			'vatdir-art93-rate-applicable' => 'TT',
			'vatdir-art96-standard-rate-single' => 'TT',
			'vatdir-art97-standard-rate-minimum' => 'TT',
			'vatdir-art98-reduced-rates' => 'TT',
			'vatdir-art226-11a-reverse-charge-mention' => 'TT',
			'vatdir-art138-exempt-mention' => 'TT',
			'vatdir-art138-ics-exemption' => 'TT',
			'vatdir-art138-vatid-substantive' => 'TT',
			'vatdir-art146-export-exemption' => 'TT',
			'vatdir-art196-reverse-charge-b2b-services' => 'TT',
			'vies-vatid-validation' => 'TT',
			'vat-rounding-line-or-total' => 'TT',
		],
		'APTransaction' => [
			'vatdir-art2-1b-ica-taxable' => 'F',
			'vatdir-art63-tax-point-supply' => 'F',
			'vatdir-art73-taxable-amount' => 'F',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (VatChecks::checks() as $type => $rules) {
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
		$seed = [VatChecks::seedSpec()];
		$this->assertSame('618061da6cb1460109df46a9139dcb97', md5(serialize($seed)));

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
		$defaults = (VatChecks::seedSpec()[$type] ?? []);
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
