<?php

/**
 * Pass-Through Markup Resolver
 *
 * The one rule for which PassThroughMarkupRule applies to a pass-through
 * expense, and what markup it gives (sales-time-and-expense-billing D3):
 * customer and category, then customer, then category, then the global
 * default, within the administration and the item's fiscal year; a percentage
 * rule gives cost times the rate, a fixed rule its value, and no rule gives no
 * markup, so the item is passed on at cost (REQ-STEB-004, REQ-STEB-005).
 * Pure: the caller reads the rules, so the lifecycle guard and the submit-time
 * lock share one rule.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

/**
 * Picks the markup rule for an expense and computes its markup.
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.1
 */
class PassThroughMarkupResolver {

	/**
	 * The rule type whose value is an amount rather than a rate.
	 */
	public const FIXED = 'fixedAmount';

	/**
	 * The filters that read the candidate rules: the administration, and for
	 * a known fiscal year only the rules effective from that year or earlier.
	 *
	 * @param string $administrationId The administration id.
	 * @param int    $fiscalYear       The fiscal year, 0 or less when unknown.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.1
	 */
	public static function filters(string $administrationId, int $fiscalYear): array {
		$filters = ['administrationId' => $administrationId];
		if ($fiscalYear > 0) {
			$filters['effectiveFromYear'] = ['lte' => $fiscalYear];
		}

		return $filters;
	}//end filters()

	/**
	 * The most specific rule that applies to the customer and category, or
	 * null. On a tie the first rule read wins.
	 *
	 * @param array<int, mixed> $rules      The candidate rules.
	 * @param string            $customerId The expense's customer.
	 * @param string            $category   The expense's category.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.1
	 */
	public function select(array $rules, string $customerId, string $category): ?array {
		$best = null;
		$bestTier = null;
		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$tier = $this->tier(rule: $rule, customerId: $customerId, category: $category);
			if ($tier !== null && ($bestTier === null || $tier < $bestTier)) {
				$best = $rule;
				$bestTier = $tier;
			}
		}

		return $best;
	}//end select()

	/**
	 * The markup a rule gives on a cost, rounded to the cent: the fixed value
	 * for a fixed rule, else cost times the rate.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param float                $cost The expense's cost in the base currency.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.1
	 */
	public function markup(array $rule, float $cost): float {
		$value = (float)($rule['markupValue'] ?? 0.0);
		if (($rule['markupType'] ?? '') === self::FIXED) {
			return round(num: $value, precision: 2);
		}

		return round(num: ($cost * $value), precision: 2);
	}//end markup()

	/**
	 * What to lock on a pass-through item: the rule that applied, the rate
	 * (for a percentage rule) and the markup. Without a rule the item is
	 * passed on at cost and says so: no rule, no rate, no markup.
	 *
	 * @param array<int, mixed> $rules      The candidate rules.
	 * @param string            $customerId The expense's customer.
	 * @param string            $category   The expense's category.
	 * @param float             $cost       The expense's cost in the base currency.
	 *
	 * @return array{markupRuleId: string|null, markupRateApplied: float|null, markupAmountCalculated: float}
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.1
	 */
	public function resolve(array $rules, string $customerId, string $category, float $cost): array {
		$rule = $this->select(rules: $rules, customerId: $customerId, category: $category);
		if ($rule === null) {
			return ['markupRuleId' => null, 'markupRateApplied' => null, 'markupAmountCalculated' => 0.0];
		}

		$rate = null;
		if (($rule['markupType'] ?? '') !== self::FIXED) {
			$rate = (float)($rule['markupValue'] ?? 0.0);
		}

		$id = (string)($rule['id'] ?? ($rule['uuid'] ?? ($rule['@self']['id'] ?? '')));
		$ruleId = null;
		if ($id !== '') {
			$ruleId = $id;
		}

		return [
			'markupRuleId' => $ruleId,
			'markupRateApplied' => $rate,
			'markupAmountCalculated' => $this->markup(rule: $rule, cost: $cost),
		];
	}//end resolve()

	/**
	 * A rule's priority tier for the target, lower being more specific, or
	 * null when it does not apply: 0 customer and category, 1 customer only,
	 * 2 category only, 3 global.
	 *
	 * @param array<string, mixed> $rule       The rule.
	 * @param string               $customerId The target customer.
	 * @param string               $category   The target category.
	 *
	 * @return int|null
	 */
	private function tier(array $rule, string $customerId, string $category): ?int {
		$ruleCustomer = (string)($rule['targetCustomerId'] ?? '');
		$ruleCategory = (string)($rule['targetCategory'] ?? '');
		if (($ruleCustomer !== '' && $ruleCustomer !== $customerId) || ($ruleCategory !== '' && $ruleCategory !== $category)) {
			return null;
		}

		$tier = 0;
		if ($ruleCustomer === '') {
			$tier += 2;
		}

		if ($ruleCategory === '') {
			$tier += 1;
		}

		return $tier;
	}//end tier()
}//end class
