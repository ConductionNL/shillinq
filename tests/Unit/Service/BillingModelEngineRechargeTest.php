<?php

/**
 * BillingModelEngineRechargeTest
 *
 * A ticked pass-through item becomes a line of its cost plus the locked
 * markup, at the VAT rate of the main supply, or outside the VAT base when
 * it is a disbursement.
 *
 * @category  Tests
 * @package   OCA\Shillinq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BillingModelEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Recharge lines of the billing model engine.
 */
#[CoversClass(BillingModelEngine::class)]
final class BillingModelEngineRechargeTest extends TestCase {

	/**
	 * 12.5 hours at EUR 95, at 21 percent.
	 *
	 * @var list<array<string,mixed>>
	 */
	private const HOURS = [
		['timeEntryId' => 'h-1', 'resourceType' => 'consultant', 'hours' => 12.5, 'rateCents' => 9500, 'rateApplied' => ['rateCents' => 9500], 'vatRate' => 21.0],
	];

	/**
	 * A pass-through item row as PassThroughBilling builds it.
	 *
	 * @param string     $id           The item id.
	 * @param int        $costCents    The cost.
	 * @param float|null $rate         The locked markup rate (fraction).
	 * @param int        $markupCents  The locked markup.
	 * @param bool       $disbursement Whether it is a disbursement.
	 *
	 * @return array<string,mixed>
	 */
	private static function item(string $id, int $costCents, ?float $rate, int $markupCents, bool $disbursement=false): array {
		return [
			'expenseId' => $id,
			'description' => $id,
			'costAmountCents' => $costCents,
			'vatRate' => 9.0,
			'recharge' => [
				'schema' => 'Receipt',
				'claimId' => 'c-1',
				'costAmountCents' => $costCents,
				'markupRuleId' => $rate === null ? null : 'rule-travel',
				'markupRate' => $rate,
				'markupAmountCents' => $markupCents,
				'disbursement' => $disbursement,
			],
		];
	}//end item()

	/**
	 * The EUR 42.80 train receipt bought at 9 percent with a 10 percent rule
	 * becomes EUR 47.08 at the hours' 21 percent, with its cost and markup kept.
	 *
	 * @return void
	 */
	public function testTrainReceiptIsBilledAtCostPlusMarkupAtTheMainSupplyRate(): void {
		$lines = (new BillingModelEngine())->calculateTAndM(
			timeEntries: self::HOURS,
			expenses: [self::item(id: 'r-train', costCents: 4280, rate: 0.10, markupCents: 428)]
		);

		self::assertCount(2, $lines);
		self::assertSame(118750, $lines[0]['costAmountCents']);
		$train = $lines[1];
		self::assertSame('expense', $train['sourceType']);
		self::assertSame('r-train', $train['sourceId']);
		self::assertSame(4708, $train['costAmountCents']);
		self::assertSame(10.0, $train['markup']);
		self::assertSame(21.0, $train['vatRate']);
		self::assertSame(
			[
				'schema' => 'Receipt',
				'claimId' => 'c-1',
				'costAmount' => 42.8,
				'markupRuleId' => 'rule-travel',
				'markupRate' => 0.10,
				'markupAmount' => 4.28,
				'vatTreatment' => 'main-supply',
				'vatMention' => null,
			],
			$train['recharge']
		);
	}//end testTrainReceiptIsBilledAtCostPlusMarkupAtTheMainSupplyRate()

	/**
	 * Parking without a rule is passed on at cost, still at the main supply rate.
	 *
	 * @return void
	 */
	public function testItemWithoutARuleIsBilledAtCost(): void {
		$lines = (new BillingModelEngine())->calculateTAndM(
			timeEntries: self::HOURS,
			expenses: [self::item(id: 'r-parking', costCents: 1250, rate: null, markupCents: 0)]
		);

		self::assertSame(1250, $lines[1]['costAmountCents']);
		self::assertSame(0.0, $lines[1]['markup']);
		self::assertNull($lines[1]['recharge']['markupRuleId']);
		self::assertSame(21.0, $lines[1]['vatRate']);
	}//end testItemWithoutARuleIsBilledAtCost()

	/**
	 * A land registry extract ticked as a disbursement is outside the VAT base.
	 *
	 * @return void
	 */
	public function testDisbursementIsBilledWithoutVatAndCarriesTheMention(): void {
		$lines = (new BillingModelEngine())->calculateTAndM(
			timeEntries: self::HOURS,
			expenses: [self::item(id: 'r-kadaster', costCents: 370, rate: null, markupCents: 0, disbursement: true)]
		);

		self::assertSame(370, $lines[1]['costAmountCents']);
		self::assertSame(0.0, $lines[1]['vatRate']);
		self::assertSame('disbursement', $lines[1]['recharge']['vatTreatment']);
		self::assertSame('Verschotten, buiten de btw-grondslag', $lines[1]['recharge']['vatMention']);
	}//end testDisbursementIsBilledWithoutVatAndCarriesTheMention()

	/**
	 * The main supply rate is the first non-expense line's; without one it is the default.
	 *
	 * @return void
	 */
	public function testMainSupplyRateFollowsTheFirstServiceLine(): void {
		$engine = new BillingModelEngine();
		$lowHours = [['timeEntryId' => 'h-2', 'resourceType' => 'teacher', 'hours' => 1.0, 'rateCents' => 5000, 'rateApplied' => ['rateCents' => 5000], 'vatRate' => 9.0]];

		$atLow = $engine->calculateTAndM(timeEntries: $lowHours, expenses: [self::item(id: 'r-1', costCents: 1000, rate: null, markupCents: 0)]);
		$noService = $engine->calculateTAndM(timeEntries: [], expenses: [self::item(id: 'r-2', costCents: 1000, rate: null, markupCents: 0)]);

		self::assertSame(9.0, $atLow[1]['vatRate']);
		self::assertSame(21.0, $noService[0]['vatRate']);
	}//end testMainSupplyRateFollowsTheFirstServiceLine()

	/**
	 * A whole claim (no recharge) keeps its own rate and gets no recharge record.
	 *
	 * @return void
	 */
	public function testAWholeClaimKeepsItsOwnRate(): void {
		$lines = (new BillingModelEngine())->calculateTAndM(
			timeEntries: self::HOURS,
			expenses: [['expenseId' => 'c-9', 'description' => 'Claim', 'costAmountCents' => 1000, 'vatRate' => 9.0]]
		);

		self::assertSame(9.0, $lines[1]['vatRate']);
		self::assertSame(1000, $lines[1]['costAmountCents']);
		self::assertArrayNotHasKey('recharge', $lines[1]);
	}//end testAWholeClaimKeepsItsOwnRate()
}//end class
