<?php

/**
 * Golden tests for FinalTailChecks: every predicate on passing and failing objects, and the seeds.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\FinalTailChecks;
use PHPUnit\Framework\TestCase;

/**
 * The final-tail rules keep their ids, order and verdicts.
 */
final class FinalTailChecksGoldenTest extends TestCase {

	/**
	 * The rule ids per object type, in order.
	 *
	 * @return void
	 */
	public function testRuleIds(): void {
		$this->assertSame(
			[
				'ARInvoice'         => ['en16931-br-co-19', 'en16931-br-co-20'],
				'Account'           => ['pcg-class-8-special'],
				'IossImport'        => ['ioss-import-scheme-150-eur', 'ioss-low-value-import-exemption-abolished'],
				'TravelAgentMargin' => ['vatdir-art306-margin-travel-agents'],
			],
			array_map(static fn (array $rules): array => array_keys($rules), FinalTailChecks::checks())
		);

	}//end testRuleIds()

	/**
	 * Every predicate's verdict on a table of objects.
	 *
	 * @return void
	 */
	public function testVerdicts(): void {
		$checks = FinalTailChecks::checks();
		$cases  = [
			['ARInvoice', 'en16931-br-co-19', [], true],
			['ARInvoice', 'en16931-br-co-19', ['invoicingPeriodPresent' => true], false],
			['ARInvoice', 'en16931-br-co-19', ['invoicingPeriodPresent' => 'true', 'invoicingPeriodStart' => ' '], false],
			['ARInvoice', 'en16931-br-co-19', ['invoicingPeriodPresent' => 1, 'invoicingPeriodEnd' => '2026-03-31'], true],
			['ARInvoice', 'en16931-br-co-19', ['invoicingPeriodPresent' => true, 'invoicingPeriodStart' => '2026-01-01'], true],
			['ARInvoice', 'en16931-br-co-19', ['invoicingPeriodPresent' => 'yes'], true],
			['ARInvoice', 'en16931-br-co-20', ['invoiceLines' => 'none'], true],
			['ARInvoice', 'en16931-br-co-20', ['invoiceLines' => ['x', ['periodPresent' => false]]], true],
			['ARInvoice', 'en16931-br-co-20', ['invoiceLines' => [['periodPresent' => true, 'periodEnd' => 'e'], ['periodPresent' => true]]], false],
			['Account', 'pcg-class-8-special', ['accountNumber' => ' 801 ', 'accountType' => 'memo'], true],
			['Account', 'pcg-class-8-special', ['accountNumber' => '801', 'accountType' => 'expense'], false],
			['Account', 'pcg-class-8-special', ['accountNumber' => '701', 'accountType' => 'expense'], true],
			['Account', 'pcg-class-8-special', [], true],
			['IossImport', 'ioss-import-scheme-150-eur', ['consignmentValue' => '150'], true],
			['IossImport', 'ioss-import-scheme-150-eur', ['consignmentValue' => 150.01], false],
			['IossImport', 'ioss-import-scheme-150-eur', ['consignmentValue' => 'abc'], false],
			['IossImport', 'ioss-import-scheme-150-eur', [], false],
			['IossImport', 'ioss-low-value-import-exemption-abolished', ['vatAmount' => 0.01], true],
			['IossImport', 'ioss-low-value-import-exemption-abolished', ['vatAmount' => 0], false],
			['IossImport', 'ioss-low-value-import-exemption-abolished', ['vatAmount' => null], false],
			['TravelAgentMargin', 'vatdir-art306-margin-travel-agents', ['margin' => 200, 'sellingPrice' => 1000, 'cost' => 800, 'inputVatDeducted' => false], true],
			['TravelAgentMargin', 'vatdir-art306-margin-travel-agents', ['margin' => 200.004, 'sellingPrice' => 1000, 'cost' => 800, 'inputVatDeducted' => false], true],
			['TravelAgentMargin', 'vatdir-art306-margin-travel-agents', ['margin' => 201, 'sellingPrice' => 1000, 'cost' => 800, 'inputVatDeducted' => false], false],
			['TravelAgentMargin', 'vatdir-art306-margin-travel-agents', ['margin' => 200, 'sellingPrice' => 1000, 'cost' => 800], false],
			['TravelAgentMargin', 'vatdir-art306-margin-travel-agents', ['margin' => 200, 'sellingPrice' => 1000, 'cost' => 800, 'inputVatDeducted' => 0], false],
			['TravelAgentMargin', 'vatdir-art306-margin-travel-agents', ['inputVatDeducted' => false], true],
		];

		$verdicts = array_map(static fn (array $case): bool => ($checks[$case[0]][$case[1]])($case[2]), $cases);

		$this->assertSame(array_column($cases, 3), $verdicts);

	}//end testVerdicts()

	/**
	 * The seeds pass their own rules.
	 *
	 * @return void
	 */
	public function testSeedsPassTheirRules(): void {
		$checks = FinalTailChecks::checks();
		foreach (FinalTailChecks::seedObjects() as $type => $objects) {
			foreach ($objects as $object) {
				foreach ($checks[$type] as $rule => $predicate) {
					$this->assertTrue($predicate($object), $type.' '.$rule);
				}
			}
		}

		$this->assertSame(['IossImport', 'TravelAgentMargin'], array_keys(FinalTailChecks::seedObjects()));
		$this->assertSame('7be5b9a60cd3a416be129bf32280f7a5', md5(serialize(FinalTailChecks::seedObjects())));

	}//end testSeedsPassTheirRules()
}//end class
