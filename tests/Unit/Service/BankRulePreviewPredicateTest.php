<?php

/**
 * Characterisation tests for the BankRulePreviewService predicate evaluation.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the per-predicate outcome, the breakdown tally, the sample and the
 * rule pick of the bank rule preview, branch by branch.
 */
final class BankRulePreviewPredicateTest extends TestCase {

	/**
	 * Preview one rule of predicates against one line.
	 *
	 * @param list<mixed>         $predicates The predicates.
	 * @param array<string,mixed> $line       The bank line.
	 * @param string|null         $anchorDate The anchor date.
	 *
	 * @return array<string,mixed> The preview result.
	 */
	private function previewOne(array $predicates, array $line, ?string $anchorDate = null): array {
		return (new BankRulePreviewService())->previewRule(
			rule: ['predicates' => $predicates],
			candidateLines: [array_merge(['id' => 'L'], $line)],
			anchorDate: $anchorDate
		);

	}//end previewOne()

	/**
	 * Predicate, line, anchor, expected match, expected breakdown.
	 *
	 * @return array<string,array{0:list<mixed>,1:array<string,mixed>,2:?string,3:bool,4:array<int,int>}> Cases.
	 */
	public static function predicateCases(): array {
		$iban = 'NL91 ABNA 0417 1643 00';
		return [
			'exact amount within half a cent'  => [[['op' => 'exact-amount', 'amount' => -10.004]], ['amount' => 10.0], null, true, [1]],
			'exact amount off by a cent'       => [[['op' => 'exact-amount', 'amount' => 10.01]], ['amount' => 10.0], null, false, [0]],
			'exact amount default zero'        => [[['op' => 'exact-amount']], [], null, true, [1]],
			'range lower bound inclusive'      => [[['op' => 'amount-range', 'min' => 10, 'max' => 20]], ['amount' => -10.0], null, true, [1]],
			'range upper bound inclusive'      => [[['op' => 'amount-range', 'min' => 10, 'max' => 20]], ['amount' => 20.0], null, true, [1]],
			'range above'                      => [[['op' => 'amount-range', 'min' => 10, 'max' => 20]], ['amount' => 20.01], null, false, [0]],
			'reference falls back to narrative' => [[['op' => 'reference-match', 'pattern' => 'INV/1']], ['narrative' => 'pay INV/1'], null, true, [1]],
			'reference empty pattern'          => [[['op' => 'reference-match']], ['reference' => 'x'], null, false, [0]],
			'reference nothing to match'       => [[['op' => 'reference-match', 'pattern' => 'x']], [], null, false, [0]],
			'fuzzy default threshold passes'   => [[['op' => 'counterparty-fuzzy', 'name' => 'Acme Holding']], ['counterpartyName' => 'Acme Holdings'], null, true, [1]],
			'fuzzy below threshold'            => [[['op' => 'counterparty-fuzzy', 'name' => 'Globex', 'threshold' => 0.9]], ['counterpartyName' => 'Globus'], null, false, [0]],
			'fuzzy without line name'          => [[['op' => 'counterparty-fuzzy', 'name' => 'Acme']], [], null, false, [0]],
			'fuzzy without rule name'          => [[['op' => 'counterparty-fuzzy']], ['counterpartyName' => 'Acme'], null, false, [0]],
			'fuzzy punctuation only'           => [[['op' => 'counterparty-fuzzy', 'name' => '...', 'threshold' => 0.0]], ['counterpartyName' => 'Acme'], null, true, [1]],
			'iban spacing and case'            => [[['op' => 'counterparty-iban', 'iban' => $iban]], ['counterpartyIban' => 'nl91abna0417164300'], null, true, [1]],
			'iban missing on line'             => [[['op' => 'counterparty-iban', 'iban' => $iban]], [], null, false, [0]],
			'iban missing on rule'             => [[['op' => 'counterparty-iban']], ['counterpartyIban' => $iban], null, false, [0]],
			'unknown op fails closed'          => [[['op' => 'telepathy']], ['amount' => 1.0], null, false, [0]],
			'missing op fails closed'          => [[[]], ['amount' => 1.0], null, false, [0]],
			'non-array predicate fails'        => [['exact-amount', ['op' => 'exact-amount', 'amount' => 1]], ['amount' => 1.0], null, false, [0, 1]],
			'window inside'                    => [[['op' => 'date-window', 'days' => 3]], ['valueDate' => '2026-04-04'], '2026-04-01', true, [1]],
			'window outside'                   => [[['op' => 'date-window', 'days' => 2]], ['valueDate' => '2026-04-04'], '2026-04-01', false, [0]],
			'window bad line date'             => [[['op' => 'date-window', 'days' => 2]], ['valueDate' => 'not-a-date'], '2026-04-01', false, [0]],
			'window bad date with a pass'      => [[['op' => 'exact-amount', 'amount' => 0], ['op' => 'date-window']], ['valueDate' => 'nope'], '2026-04-01', false, [1, 0]],
			'window without anchor, plus pass' => [[['op' => 'exact-amount', 'amount' => 5], ['op' => 'date-window', 'days' => 1]], ['amount' => 5.0, 'valueDate' => '2026-04-04'], null, true, [1, 0]],
			'window empty anchor alone'        => [[['op' => 'date-window', 'days' => 1]], ['valueDate' => '2026-04-04'], '', false, [0]],
			'window without line date alone'   => [[['op' => 'date-window', 'days' => 1]], [], '2026-04-04', false, [0]],
		];

	}//end predicateCases()

	/**
	 * Each predicate case yields the pinned match and breakdown.
	 *
	 * @param list<mixed>         $predicates The predicates.
	 * @param array<string,mixed> $line       The bank line.
	 * @param string|null         $anchorDate The anchor date.
	 * @param bool                $matches    The expected match.
	 * @param array<int,int>      $breakdown  The expected breakdown tally.
	 *
	 * @return void
	 */
	#[DataProvider('predicateCases')]
	public function testPredicateOutcome(array $predicates, array $line, ?string $anchorDate, bool $matches, array $breakdown): void {
		$result = $this->previewOne(predicates: $predicates, line: $line, anchorDate: $anchorDate);

		self::assertSame($matches ? ['L'] : [], $result['matchedLineIds']);
		self::assertSame($breakdown, $result['predicateBreakdown']);

	}//end testPredicateOutcome()

	/**
	 * Tally, id fallback, non-array lines and sample cap.
	 *
	 * @return void
	 */
	public function testPreviewTallyIdsAndSample(): void {
		$lines = ['garbage', ['uuid' => 'U1', 'amount' => 5.0], ['amount' => 5.0], ['id' => 'X', 'amount' => 6.0]];
		for ($i = 0; $i < 25; $i++) {
			$lines[] = ['id' => 'M' . $i, 'amount' => 5.0];
		}

		$result = (new BankRulePreviewService())->previewRule(
			rule: ['predicates' => [['op' => 'exact-amount', 'amount' => 5], ['op' => 'amount-range', 'min' => 0, 'max' => 5.5]]],
			candidateLines: $lines
		);

		self::assertSame(27, $result['matchedCount']);
		self::assertSame('U1', $result['matchedLineIds'][0]);
		self::assertSame('', $result['matchedLineIds'][1]);
		self::assertSame(29, $result['totalEvaluated']);
		self::assertCount(20, $result['sample']);
		self::assertSame([0 => 27, 1 => 27], $result['predicateBreakdown']);

	}//end testPreviewTallyIdsAndSample()

	/**
	 * A rule without a predicates list matches nothing and tallies nothing.
	 *
	 * @return void
	 */
	public function testRuleWithoutPredicatesList(): void {
		$result = (new BankRulePreviewService())->previewRule(
			rule: ['predicates' => 'exact-amount'],
			candidateLines: [['id' => 'A', 'amount' => 0.0]]
		);

		self::assertSame([], $result['matchedLineIds']);
		self::assertSame([], $result['predicateBreakdown']);

	}//end testRuleWithoutPredicatesList()

	/**
	 * Rule pick: skips junk and empty rules, lowest priority wins, ties keep
	 * the first rule, defaults fill the projection.
	 *
	 * @return void
	 */
	public function testSuggestForLinePickAndDefaults(): void {
		$match = [['op' => 'exact-amount', 'amount' => 7]];
		$rules = [
			'junk',
			['predicates' => [], 'priority' => 1, 'ruleName' => 'empty'],
			['predicates' => [['op' => 'exact-amount', 'amount' => 8]], 'priority' => 1, 'ruleName' => 'miss'],
			['predicates' => $match, 'ruleName' => 'default-priority'],
			['predicates' => $match, 'priority' => 50, 'ruleName' => 'first-50', 'targetGlAccount' => ''],
			['predicates' => $match, 'priority' => 50, 'ruleName' => 'second-50', 'id' => 'r2'],
		];

		$suggestion = (new BankRulePreviewService())->suggestForLine(line: ['amount' => 7.0], activeRules: $rules);

		self::assertSame(
			[
				'matchingRuleId' => null,
				'ruleName' => 'first-50',
				'targetType' => 'gl-transaction',
				'targetGlAccount' => null,
				'confidence' => 1.0,
			],
			$suggestion
		);

	}//end testSuggestForLinePickAndDefaults()

	/**
	 * A picked rule projects its own id, type, account and confidence.
	 *
	 * @return void
	 */
	public function testSuggestForLineProjectsRuleFields(): void {
		$rules = [
			[
				'id' => 42,
				'predicates' => [['op' => 'exact-amount', 'amount' => 7]],
				'targetType' => 'payable',
				'targetGlAccount' => 4100,
				'confidenceScore' => '0.7',
			],
		];

		$suggestion = (new BankRulePreviewService())->suggestForLine(line: ['amount' => 7.0], activeRules: $rules);

		self::assertSame(
			[
				'matchingRuleId' => '42',
				'ruleName' => '',
				'targetType' => 'payable',
				'targetGlAccount' => '4100',
				'confidence' => 0.7,
			],
			$suggestion
		);

	}//end testSuggestForLineProjectsRuleFields()

	/**
	 * No matching rule, no suggestion; a date-window rule never matches here.
	 *
	 * @return void
	 */
	public function testSuggestForLineWithoutMatch(): void {
		$rules = [['predicates' => [['op' => 'date-window', 'days' => 5]], 'priority' => 1]];

		self::assertNull((new BankRulePreviewService())->suggestForLine(line: ['valueDate' => '2026-01-01'], activeRules: $rules));

	}//end testSuggestForLineWithoutMatch()
}//end class
