<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\InvoicingTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the InvoicingTailChecks registry: every rule id in order, its verdict on an empty
 * object, on the seed defaults and on each seeded sample (T true, F false,
 * E threw), and the seed data itself.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoicingTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoicingTailChecksRegistryTest extends TestCase {

	private const VERDICTS = [
		'ARInvoice' => [
			'en16931-br-01' => 'FF',
			'en16931-br-02' => 'FF',
			'en16931-br-03' => 'FF',
			'en16931-br-04' => 'FF',
			'en16931-br-05' => 'FF',
			'en16931-br-06' => 'FF',
			'en16931-br-07' => 'FT',
			'en16931-br-08' => 'FF',
			'en16931-br-09' => 'FF',
			'en16931-br-10' => 'FF',
			'en16931-br-12' => 'FF',
			'en16931-br-13' => 'FF',
			'en16931-br-14' => 'FF',
			'en16931-br-16' => 'FF',
			'en16931-br-21' => 'FF',
			'en16931-br-22' => 'FF',
			'en16931-br-23' => 'FF',
			'en16931-br-24' => 'FF',
			'en16931-br-25' => 'FF',
			'en16931-br-26' => 'FF',
			'en16931-br-45' => 'FF',
			'en16931-br-46' => 'FF',
			'en16931-br-47' => 'FF',
			'en16931-br-48' => 'FF',
			'en16931-br-co-03' => 'TT',
			'en16931-br-co-04' => 'FF',
			'en16931-br-co-09' => 'TT',
			'en16931-br-co-10' => 'TT',
			'en16931-br-co-11' => 'TT',
			'en16931-br-co-12' => 'TT',
			'en16931-br-co-13' => 'TT',
			'en16931-br-co-14' => 'TT',
			'en16931-br-co-17' => 'FF',
			'en16931-br-co-18' => 'FF',
			'en16931-br-co-25' => 'TT',
			'en16931-br-co-26' => 'FF',
			'en16931-br-dec-09' => 'TT',
			'en16931-br-dec-10' => 'TT',
			'en16931-br-dec-11' => 'TT',
			'en16931-br-dec-12' => 'TT',
			'en16931-br-dec-13' => 'TT',
			'en16931-br-dec-14' => 'TT',
			'en16931-br-dec-01' => 'TT',
			'en16931-br-dec-02' => 'TT',
			'en16931-br-dec-05' => 'TT',
			'en16931-br-dec-06' => 'TT',
			'en16931-br-dec-19' => 'TT',
			'en16931-br-dec-20' => 'TT',
			'en16931-br-dec-23' => 'TT',
			'en16931-br-dec-24' => 'TT',
			'en16931-br-dec-25' => 'TT',
			'en16931-br-dec-27' => 'TT',
			'en16931-br-dec-28' => 'TT',
			'en16931-br-51' => 'TT',
			'en16931-br-64' => 'TT',
			'en16931-br-65' => 'TT',
			'en16931-br-cl-03' => 'FF',
			'en16931-br-s-02' => 'TT',
			'en16931-br-s-03' => 'TT',
			'en16931-br-s-04' => 'TT',
			'en16931-br-s-05' => 'TT',
			'en16931-br-s-06' => 'TT',
			'en16931-br-s-07' => 'TT',
			'en16931-br-s-08' => 'TT',
			'en16931-br-s-09' => 'TT',
			'en16931-br-s-10' => 'TT',
			'en16931-br-z-02' => 'TT',
			'en16931-br-z-03' => 'TT',
			'en16931-br-z-04' => 'TT',
			'en16931-br-z-05' => 'TT',
			'en16931-br-z-06' => 'TT',
			'en16931-br-z-07' => 'TT',
			'en16931-br-z-08' => 'TT',
			'en16931-br-z-09' => 'TT',
			'en16931-br-z-10' => 'TT',
			'en16931-br-e-02' => 'TT',
			'en16931-br-e-03' => 'TT',
			'en16931-br-e-04' => 'TT',
			'en16931-br-e-05' => 'TT',
			'en16931-br-e-06' => 'TT',
			'en16931-br-e-07' => 'TT',
			'en16931-br-e-08' => 'TT',
			'en16931-br-e-09' => 'TT',
			'en16931-br-e-10' => 'TT',
			'en16931-br-ae-02' => 'TT',
			'en16931-br-ae-03' => 'TT',
			'en16931-br-ae-04' => 'TT',
			'en16931-br-ae-05' => 'TT',
			'en16931-br-ae-06' => 'TT',
			'en16931-br-ae-07' => 'TT',
			'en16931-br-ae-08' => 'TT',
			'en16931-br-ae-09' => 'TT',
			'en16931-br-ae-10' => 'TT',
			'en16931-br-ic-02' => 'TT',
			'en16931-br-ic-03' => 'TT',
			'en16931-br-ic-04' => 'TT',
			'en16931-br-ic-05' => 'TT',
			'en16931-br-ic-06' => 'TT',
			'en16931-br-ic-07' => 'TT',
			'en16931-br-ic-08' => 'TT',
			'en16931-br-ic-09' => 'TT',
			'en16931-br-ic-10' => 'TT',
			'en16931-br-g-02' => 'TT',
			'en16931-br-g-03' => 'TT',
			'en16931-br-g-04' => 'TT',
			'en16931-br-g-05' => 'TT',
			'en16931-br-g-06' => 'TT',
			'en16931-br-g-07' => 'TT',
			'en16931-br-g-08' => 'TT',
			'en16931-br-g-09' => 'TT',
			'en16931-br-g-10' => 'TT',
			'en16931-br-o-02' => 'TT',
			'en16931-br-o-03' => 'TT',
			'en16931-br-o-04' => 'TT',
			'en16931-br-o-05' => 'TT',
			'en16931-br-o-06' => 'TT',
			'en16931-br-o-07' => 'TT',
			'en16931-br-o-08' => 'TT',
			'en16931-br-o-09' => 'TT',
			'en16931-br-o-10' => 'TT',
			'vatdir-art226-1' => 'FF',
			'vatdir-art226-2' => 'FF',
			'vatdir-art226-3' => 'FF',
			'vatdir-art226-5' => 'FF',
			'vatdir-art226-8' => 'FF',
			'vatdir-art226-9' => 'FF',
			'vatdir-art226-10' => 'FF',
		],
	];

	/**
	 * Every rule keeps its id, order and verdicts.
	 *
	 * @return void
	 */
	public function testRegistryMatchesThePinnedVerdicts(): void {
		$actual = [];
		foreach (InvoicingTailChecks::checks() as $type => $rules) {
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
		$seed = [InvoicingTailChecks::seedSpec()];
		$this->assertSame('f9b81d65d33b585a415f7cbbe12b7025', md5(serialize($seed)));

	}//end testSeedDataIsUnchanged()

	/**
	 * Invoices and whether BR-S-08 / BR-AE-08 (breakdown taxable amount
	 * reconciles per category) holds for them.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	public static function breakdowns(): array {
		$invoice = [
			'vatBreakdown' => [['category' => 'S', 'taxableAmount' => 95.0], ['category' => 'AE', 'taxableAmount' => 7.0], 'x'],
			'invoiceLines' => [['vatCategory' => 'S', 'netAmount' => 60.0], ['vatCategory' => 'S', 'netAmount' => 40.0], ['vatCategory' => 'AE', 'netAmount' => 10.0], 'x'],
			'allowances'   => [['vatCategory' => 'S', 'amount' => 10.0], ['vatCategory' => 'AE', 'amount' => 3.0]],
			'charges'      => [['vatCategory' => 'S', 'amount' => 5.0]],
		];
		return [
			'no S group'            => ['en16931-br-s-08', ['vatBreakdown' => [['category' => 'Z']]], true],
			'S reconciles'          => ['en16931-br-s-08', $invoice, true],
			'S off by a charge'     => ['en16931-br-s-08', ['charges' => []] + $invoice, false],
			'S off by an allowance' => ['en16931-br-s-08', ['allowances' => 'none'] + $invoice, false],
			'AE reconciles'         => ['en16931-br-ae-08', $invoice, true],
			'AE off by a line'      => ['en16931-br-ae-08', ['invoiceLines' => []] + $invoice, false],
		];

	}//end breakdowns()

	/**
	 * The per-category breakdown reconciliation returns the pinned verdict.
	 *
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $invoice  The invoice checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('breakdowns')]
	public function testBreakdownReconciles(string $ruleId, array $invoice, bool $expected): void {
		$check = InvoicingTailChecks::checks()['ARInvoice'][$ruleId];
		$this->assertSame($expected, $check($invoice, ['jurisdiction' => 'NL']));

	}//end testBreakdownReconciles()

	/**
	 * The objects every check of a type runs on.
	 *
	 * @param string $type The object type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function samples(string $type): array {
		$samples = [[]];
		$defaults = (InvoicingTailChecks::seedSpec()[$type] ?? []);
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
