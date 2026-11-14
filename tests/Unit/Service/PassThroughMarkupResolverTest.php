<?php

/**
 * Unit tests for PassThroughMarkupResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PassThroughMarkupResolver;
use PHPUnit\Framework\TestCase;

/**
 * REQ-STEB-004 / REQ-STEB-005: the most specific rule wins (customer and
 * category, customer, category, global), a percentage rule gives cost times
 * the rate, a fixed rule its value, and no rule passes the expense on at cost.
 *
 * @covers \OCA\Shillinq\Service\PassThroughMarkupResolver
 */
final class PassThroughMarkupResolverTest extends TestCase {

	/**
	 * The four tiers for Het Anker and category travel, least specific first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rules(): array {
		return [
			['id' => 'global', 'targetCustomerId' => '', 'targetCategory' => '', 'markupType' => 'percentage', 'markupValue' => 0.05],
			['id' => 'travel', 'targetCustomerId' => '', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.08],
			['id' => 'anker', 'targetCustomerId' => 'het-anker', 'targetCategory' => '', 'markupType' => 'percentage', 'markupValue' => 0.10],
			['id' => 'anker-travel', 'targetCustomerId' => 'het-anker', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.10],
			['id' => 'other-customer', 'targetCustomerId' => 'de-linde', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.50],
			['id' => 'other-category', 'targetCustomerId' => 'het-anker', 'targetCategory' => 'hotel', 'markupType' => 'percentage', 'markupValue' => 0.50],
		];
	}//end rules()

	public function testEachTierWinsOverTheLessSpecificOnes(): void {
		$resolver = new PassThroughMarkupResolver();
		$rules    = self::rules();

		$this->assertSame('anker-travel', $resolver->select(rules: $rules, customerId: 'het-anker', category: 'travel')['id']);
		$this->assertSame('anker', $resolver->select(rules: array_slice($rules, 0, 3), customerId: 'het-anker', category: 'travel')['id']);
		$this->assertSame('travel', $resolver->select(rules: array_slice($rules, 0, 2), customerId: 'het-anker', category: 'travel')['id']);
		$this->assertSame('global', $resolver->select(rules: array_slice($rules, 0, 1), customerId: 'het-anker', category: 'travel')['id']);
		$this->assertSame('anker', $resolver->select(rules: $rules, customerId: 'het-anker', category: 'parking')['id']);
		$this->assertSame('travel', $resolver->select(rules: array_reverse($rules), customerId: 'nieuw', category: 'travel')['id']);
	}//end testEachTierWinsOverTheLessSpecificOnes()

	public function testARuleForAnotherCustomerOrCategoryNeverApplies(): void {
		$resolver = new PassThroughMarkupResolver();
		$scoped   = array_slice(self::rules(), 4);

		$this->assertNull($resolver->select(rules: $scoped, customerId: 'het-anker', category: 'travel'));
		$this->assertNull($resolver->select(rules: [], customerId: 'het-anker', category: 'travel'));
		$this->assertNull($resolver->select(rules: ['not a rule', null], customerId: 'het-anker', category: 'travel'));
	}//end testARuleForAnotherCustomerOrCategoryNeverApplies()

	public function testOnATieTheFirstRuleReadWins(): void {
		$first  = ['id' => 'first', 'targetCustomerId' => 'het-anker', 'targetCategory' => ''];
		$second = ['id' => 'second', 'targetCustomerId' => 'het-anker', 'targetCategory' => ''];

		$this->assertSame('first', (new PassThroughMarkupResolver())->select(rules: [$first, $second], customerId: 'het-anker', category: 'travel')['id']);
	}//end testOnATieTheFirstRuleReadWins()

	public function testAPercentageRuleGivesCostTimesTheRateAndAFixedRuleItsValue(): void {
		$resolver = new PassThroughMarkupResolver();

		$this->assertSame(4.28, $resolver->markup(rule: ['markupType' => 'percentage', 'markupValue' => 0.10], cost: 42.80));
		$this->assertSame(0.01, $resolver->markup(rule: ['markupType' => 'percentage', 'markupValue' => 0.15], cost: 0.05));
		$this->assertSame(2.5, $resolver->markup(rule: ['markupType' => 'fixedAmount', 'markupValue' => 2.499], cost: 1000.0));
		$this->assertSame(0.0, $resolver->markup(rule: [], cost: 42.80));
	}//end testAPercentageRuleGivesCostTimesTheRateAndAFixedRuleItsValue()

	public function testTheLockedValuesNameTheRuleTheRateAndTheMarkup(): void {
		$resolver = new PassThroughMarkupResolver();

		$this->assertSame(
			['markupRuleId' => 'anker-travel', 'markupRateApplied' => 0.10, 'markupAmountCalculated' => 4.28],
			$resolver->resolve(rules: self::rules(), customerId: 'het-anker', category: 'travel', cost: 42.80)
		);
		$this->assertSame(
			['markupRuleId' => 'parking-fee', 'markupRateApplied' => null, 'markupAmountCalculated' => 1.5],
			$resolver->resolve(rules: [['@self' => ['id' => 'parking-fee'], 'markupType' => 'fixedAmount', 'markupValue' => 1.5]], customerId: 'het-anker', category: 'parking', cost: 12.50)
		);
	}//end testTheLockedValuesNameTheRuleTheRateAndTheMarkup()

	public function testWithoutARuleTheExpenseIsPassedOnAtCostAndSaysSo(): void {
		$this->assertSame(
			['markupRuleId' => null, 'markupRateApplied' => null, 'markupAmountCalculated' => 0.0],
			(new PassThroughMarkupResolver())->resolve(rules: array_slice(self::rules(), 4), customerId: 'het-anker', category: 'parking', cost: 12.50)
		);
	}//end testWithoutARuleTheExpenseIsPassedOnAtCostAndSaysSo()

	public function testTheRulesAreReadForTheAdministrationAndTheYear(): void {
		$this->assertSame(['administrationId' => 'adm-1', 'effectiveFromYear' => ['lte' => 2026]], PassThroughMarkupResolver::filters(administrationId: 'adm-1', fiscalYear: 2026));
		$this->assertSame(['administrationId' => 'adm-1'], PassThroughMarkupResolver::filters(administrationId: 'adm-1', fiscalYear: 0));
	}//end testTheRulesAreReadForTheAdministrationAndTheYear()
}//end class
