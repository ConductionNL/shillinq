<?php

/**
 * Unit tests for BankRulePreviewService (REQ-BR-011).
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
 * @spec openspec/changes/bank-rule-automation-ux/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BankRulePreviewService;
use PHPUnit\Framework\TestCase;

/**
 * Proves the dry-run preview matches the RIGHT lines and NONE of the wrong
 * ones, and that a saved rule suggests its GL account with priority precedence.
 *
 * @spec openspec/changes/bank-rule-automation-ux/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
 */
final class BankRulePreviewServiceTest extends TestCase {

	/**
	 * Subject.
	 *
	 * @var BankRulePreviewService
	 */
	private BankRulePreviewService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new BankRulePreviewService();

	}//end setUp()

	/**
	 * Five candidate lines; a three-predicate rule must match exactly L1.
	 *
	 * @return void
	 */
	public function testDryRunMatchesRightLinesAndNoneWrong(): void {
		$lines = [
			['id' => 'L1', 'amount' => 500.0, 'reference' => 'INV-C-2026-0001', 'counterpartyName' => 'Acme B.V.'],
			['id' => 'L2', 'amount' => 500.0, 'reference' => 'INV-C-2026-0002', 'counterpartyName' => 'Acme B.V.'],
			['id' => 'L3', 'amount' => 500.0, 'reference' => 'INV-C-2026-0001', 'counterpartyName' => 'Globex'],
			['id' => 'L4', 'amount' => 99.0, 'reference' => 'INV-C-2026-0001', 'counterpartyName' => 'Acme B.V.'],
			['id' => 'L5', 'amount' => 500.0, 'reference' => '', 'counterpartyName' => 'Acme B.V.'],
		];

		$rule = [
			'predicates' => [
				['op' => 'exact-amount', 'amount' => 500],
				['op' => 'reference-match', 'pattern' => 'INV-C-2026-0001'],
				['op' => 'counterparty-fuzzy', 'name' => 'Acme BV', 'threshold' => 0.8],
			],
		];

		$result = $this->service->previewRule(rule: $rule, candidateLines: $lines);

		self::assertSame(['L1'], $result['matchedLineIds']);
		self::assertSame(1, $result['matchedCount']);
		self::assertSame(5, $result['totalEvaluated']);

	}//end testDryRunMatchesRightLinesAndNoneWrong()

	/**
	 * Debit lines match on their absolute amount (bank signs are irrelevant to
	 * the operator's rule).
	 *
	 * @return void
	 */
	public function testExactAmountMatchesOnAbsoluteValue(): void {
		$lines = [['id' => 'D1', 'amount' => -500.0, 'reference' => 'x']];
		$rule = ['predicates' => [['op' => 'exact-amount', 'amount' => 500]]];
		$result = $this->service->previewRule(rule: $rule, candidateLines: $lines);

		self::assertSame(['D1'], $result['matchedLineIds']);

	}//end testExactAmountMatchesOnAbsoluteValue()

	/**
	 * counterparty-iban + amount-range: exact IBAN (case-insensitive) in range
	 * matches; same IBAN out of range does not (spec scenario).
	 *
	 * @return void
	 */
	public function testCounterpartyIbanAndAmountRange(): void {
		$lines = [
			['id' => 'A', 'amount' => 450.0, 'counterpartyIban' => 'nl91abna0417164300'],
			['id' => 'B', 'amount' => 5000.0, 'counterpartyIban' => 'NL91ABNA0417164300'],
			['id' => 'C', 'amount' => 450.0, 'counterpartyIban' => 'NL00OTHER0000000000'],
		];
		$rule = [
			'predicates' => [
				['op' => 'counterparty-iban', 'iban' => 'NL91ABNA0417164300'],
				['op' => 'amount-range', 'min' => 100, 'max' => 2000],
			],
		];

		$result = $this->service->previewRule(rule: $rule, candidateLines: $lines);

		self::assertSame(['A'], $result['matchedLineIds']);

	}//end testCounterpartyIbanAndAmountRange()

	/**
	 * date-window matches within N days of the supplied anchor, and excludes
	 * out-of-window lines.
	 *
	 * @return void
	 */
	public function testDateWindowWithAnchor(): void {
		$lines = [
			['id' => 'near', 'amount' => 100.0, 'valueDate' => '2026-04-05'],
			['id' => 'far', 'amount' => 100.0, 'valueDate' => '2026-05-20'],
		];
		$rule = ['predicates' => [['op' => 'date-window', 'days' => 7]]];

		$result = $this->service->previewRule(rule: $rule, candidateLines: $lines, anchorDate: '2026-04-03');

		self::assertSame(['near'], $result['matchedLineIds']);

	}//end testDateWindowWithAnchor()

	/**
	 * A date-window predicate with NO anchor is indeterminate and must not by
	 * itself produce a match (no false positives).
	 *
	 * @return void
	 */
	public function testDateWindowWithoutAnchorNeverMatchesAlone(): void {
		$lines = [['id' => 'x', 'amount' => 100.0, 'valueDate' => '2026-04-05']];
		$rule = ['predicates' => [['op' => 'date-window', 'days' => 7]]];
		$result = $this->service->previewRule(rule: $rule, candidateLines: $lines, anchorDate: null);

		self::assertSame([], $result['matchedLineIds']);
		self::assertSame(0, $result['matchedCount']);

	}//end testDateWindowWithoutAnchorNeverMatchesAlone()

	/**
	 * An invalid regex fails closed (no PHP warning, no match).
	 *
	 * @return void
	 */
	public function testInvalidRegexFailsClosed(): void {
		$lines = [['id' => 'x', 'amount' => 1.0, 'reference' => 'anything']];
		$rule = ['predicates' => [['op' => 'reference-match', 'pattern' => '([unclosed']]];
		$result = $this->service->previewRule(rule: $rule, candidateLines: $lines);

		self::assertSame([], $result['matchedLineIds']);

	}//end testInvalidRegexFailsClosed()

	/**
	 * An empty predicate list matches nothing (never everything).
	 *
	 * @return void
	 */
	public function testEmptyPredicatesMatchNothing(): void {
		$lines = [['id' => 'x', 'amount' => 1.0]];
		$result = $this->service->previewRule(rule: ['predicates' => []], candidateLines: $lines);

		self::assertSame(0, $result['matchedCount']);

	}//end testEmptyPredicatesMatchNothing()

	/**
	 * A saved rule suggests its GL account on a matching line; the lowest
	 * `priority` value wins; an unknown IBAN yields null (spec scenario).
	 *
	 * @return void
	 */
	public function testSuggestForLinePicksHighestPriorityGlAccount(): void {
		$rules = [
			[
				'id' => 'r-low',
				'ruleName' => 'Acme low',
				'priority' => 50,
				'targetType' => 'gl-transaction',
				'targetGlAccount' => '4500',
				'predicates' => [['op' => 'counterparty-iban', 'iban' => 'NL91ABNA0417164300']],
			],
			[
				'id' => 'r-high',
				'ruleName' => 'Acme high',
				'priority' => 10,
				'targetType' => 'gl-transaction',
				'targetGlAccount' => '4000',
				'predicates' => [['op' => 'counterparty-iban', 'iban' => 'NL91ABNA0417164300']],
			],
		];

		$line = ['id' => 'bl', 'amount' => 200.0, 'counterpartyIban' => 'NL91ABNA0417164300'];
		$suggestion = $this->service->suggestForLine(line: $line, activeRules: $rules);

		self::assertNotNull($suggestion);
		self::assertSame('4000', $suggestion['targetGlAccount']);
		self::assertSame('r-high', $suggestion['matchingRuleId']);

		// Unknown IBAN → no suggestion.
		$none = $this->service->suggestForLine(
			line: ['id' => 'bl2', 'amount' => 200.0, 'counterpartyIban' => 'NL00UNKNOWN000000000'],
			activeRules: $rules,
		);
		self::assertNull($none);

	}//end testSuggestForLinePicksHighestPriorityGlAccount()
}//end class
