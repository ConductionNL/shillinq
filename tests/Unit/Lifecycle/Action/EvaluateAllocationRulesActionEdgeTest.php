<?php

/**
 * Edge cases of EvaluateAllocationRulesAction: the refusals that name the
 * rule, fixed-amount splits, the account-pattern grammar and the rows the
 * action leaves alone.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\EvaluateAllocationRulesAction;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Allocation rule edge cases.
 */
#[CoversClass(EvaluateAllocationRulesAction::class)]
final class EvaluateAllocationRulesActionEdgeTest extends TestCase {

	private const TRANSACTION = [
		'id' => 'gl-1',
		'transactionNumber' => 'MEM-1',
		'administrationId' => 'adm-1',
		'currency' => 'EUR',
		'periodId' => '2026-03',
		'state' => 'posted',
	];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectStore|null
	 */
	private ?InMemoryObjectStore $store = null;

	/**
	 * Build the action over rules and ledger lines.
	 *
	 * @param array<int, array<string, mixed>> $rules The allocation rules
	 * @param array<int, array<string, mixed>> $lines The transaction's lines
	 * @param string $register The configured register
	 *
	 * @return EvaluateAllocationRulesAction
	 */
	private function action(array $rules, array $lines = [], string $register = 'shillinq'): EvaluateAllocationRulesAction {
		$this->store = new InMemoryObjectStore();
		$this->store->rows['GLLine'] = ($lines !== [] ? $lines : [
			['id' => 'l1', 'transactionId' => 'gl-1', 'lineNumber' => 1, 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 1000.0],
			['id' => 'l2', 'transactionId' => 'gl-1', 'lineNumber' => 2, 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 1000.0],
		]);
		$this->store->rows['AllocationRule'] = $rules;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register' ? $register : $default)
		);

		return new EvaluateAllocationRulesAction($this->store->mock($this), $appConfig);

	}//end action()

	/**
	 * An active per-posting rule.
	 *
	 * @param array<string, mixed> $over Overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(array $over = []): array {
		return array_merge(
			[
				'id' => 'rule-1',
				'name' => 'Kantoorkosten',
				'sourceAccountPattern' => '4000',
				'driver' => 'fixed-amount',
				'targets' => [['code' => 'P-1', 'amount' => 250.0], ['code' => 'P-2', 'amount' => 0]],
				'targetDimension' => 'project',
				'cadence' => 'per-posting',
				'lifecycleState' => 'active',
				'administrationId' => 'adm-1',
			],
			$over
		);

	}//end rule()

	/**
	 * Run the action and answer the lines it added.
	 *
	 * @param EvaluateAllocationRulesAction $action The action
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function added(EvaluateAllocationRulesAction $action): array {
		$action->execute(self::TRANSACTION, [], [], EvaluateAllocationRulesAction::class);
		return $this->store->savedOf('GLLine');

	}//end added()

	/**
	 * A transaction without an id is refused.
	 *
	 * @return void
	 */
	public function testATransactionWithoutIdIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot apply allocation rules to a transaction without an id.');

		$this->action([self::rule()])->execute(['administrationId' => 'adm-1'], [], [], EvaluateAllocationRulesAction::class);

	}//end testATransactionWithoutIdIsRefused()

	/**
	 * A fixed-amount rule books the amount off the source account and onto the project; a zero target adds no line.
	 *
	 * @return void
	 */
	public function testAFixedAmountRuleMovesOnlyThatAmount(): void {
		$added = $this->added($this->action([self::rule()]));

		self::assertCount(2, $added);
		self::assertSame(
			['4000', 'credit', 250.0, 'Allocation Kantoorkosten', 'EUR', '2026-03', 'adm-1'],
			[$added[0]['accountNumber'], $added[0]['side'], $added[0]['amount'], $added[0]['description'], $added[0]['currency'], $added[0]['periodId'], $added[0]['administrationId']]
		);
		self::assertSame(['debit', 250.0, 'P-1', 'Allocation Kantoorkosten to P-1'], [$added[1]['side'], $added[1]['amount'], $added[1]['projectCode'], $added[1]['description']]);
		self::assertSame(['allocationRuleId' => 'rule-1', 'allocationSourceLineId' => 'l1'], $added[1]['dimensions']);
		self::assertSame([3, 4], array_column($added, 'lineNumber'));

	}//end testAFixedAmountRuleMovesOnlyThatAmount()

	/**
	 * A rule whose targets add up to nothing writes nothing.
	 *
	 * @return void
	 */
	public function testANothingToAllocateRuleWritesNothing(): void {
		$added = $this->added($this->action([self::rule(['targets' => [['code' => 'P-1', 'amount' => 0]]])]));

		self::assertSame([], $added);

	}//end testANothingToAllocateRuleWritesNothing()

	/**
	 * Each refusal names the rule and its problem.
	 *
	 * @return void
	 */
	public function testRefusalsNameTheRule(): void {
		$cases = [
			[self::rule(['targetDimension' => 'region']), 'Allocation rule "Kantoorkosten" has an unknown target dimension.'],
			[self::rule(['targets' => ['not-a-target']]), 'Allocation rule "Kantoorkosten" has no targets.'],
			[self::rule(['targets' => [['code' => 'P-1', 'amount' => 1000.01]]]), 'Allocation rule "Kantoorkosten" allocates more than the source line holds.'],
			[self::rule(['name' => null]), 'Allocation rule "rule-1" has an unknown target dimension.', ['targetDimension' => '']],
		];

		foreach ($cases as $case) {
			$rule = array_merge($case[0], ($case[2] ?? []));
			try {
				$this->added($this->action([$rule]));
				self::fail('Expected a refusal: ' . $case[1]);
			} catch (RuntimeException $e) {
				self::assertSame($case[1], $e->getMessage());
			}
		}

	}//end testRefusalsNameTheRule()

	/**
	 * A matched line with an unknown side is refused rather than booked one-sided.
	 *
	 * @return void
	 */
	public function testALineWithAnUnknownSideIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Allocation rule "Kantoorkosten" matched a line with side "sideways".');

		$this->added(
			$this->action(
				[self::rule()],
				[['id' => 'l1', 'transactionId' => 'gl-1', 'lineNumber' => 1, 'accountNumber' => '4000', 'side' => 'sideways', 'amount' => 1000.0]]
			)
		);

	}//end testALineWithAnUnknownSideIsRefused()

	/**
	 * The pattern takes prefixes, ranges and exact numbers, separated by commas; blanks and non-numeric accounts never match a range.
	 *
	 * @return void
	 */
	public function testAccountPatternGrammar(): void {
		$lines = [
			['id' => 'l1', 'transactionId' => 'gl-1', 'lineNumber' => 1, 'accountNumber' => '4710', 'side' => 'debit', 'amount' => 10.0],
			['id' => 'l2', 'transactionId' => 'gl-1', 'lineNumber' => 2, 'accountNumber' => '4500', 'side' => 'debit', 'amount' => 10.0],
			['id' => 'l3', 'transactionId' => 'gl-1', 'lineNumber' => 3, 'accountNumber' => '6000', 'side' => 'debit', 'amount' => 10.0],
			['id' => 'l4', 'transactionId' => 'gl-1', 'lineNumber' => 4, 'accountNumber' => '45A0', 'side' => 'debit', 'amount' => 10.0],
			['id' => 'l5', 'transactionId' => 'gl-1', 'lineNumber' => 5, 'side' => 'debit', 'amount' => 10.0],
			['id' => 'l6', 'transactionId' => 'gl-1', 'lineNumber' => 6, 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 50.0],
		];
		$rule = self::rule(['sourceAccountPattern' => ' , 47*, 4400 - 4599, 6000', 'targets' => [['code' => 'P-1', 'amount' => 1]]]);

		$added = $this->added($this->action([$rule], $lines));

		$sources = array_values(array_unique(array_map(static fn (array $row): string => $row['dimensions']['allocationSourceLineId'], $added)));
		self::assertSame(['l1', 'l2', 'l3'], $sources);
		self::assertSame([7, 8, 9, 10, 11, 12], array_column($added, 'lineNumber'));

	}//end testAccountPatternGrammar()

	/**
	 * A rule without an id, or one already applied to the transaction, is skipped; the applied line is not a source.
	 *
	 * @return void
	 */
	public function testRulesWithoutIdOrAlreadyAppliedAreSkipped(): void {
		$lines = [
			['id' => 'l1', 'transactionId' => 'gl-1', 'lineNumber' => 1, 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 1000.0],
			['id' => 'l9', 'transactionId' => 'MEM-1', 'lineNumber' => 9, 'accountNumber' => '4000', 'side' => 'credit', 'amount' => 5.0, 'dimensions' => ['allocationRuleId' => 'rule-done']],
		];
		$rules = [
			self::rule(['id' => '']),
			self::rule(['id' => 'rule-done']),
			self::rule(['id' => 'rule-new', 'targets' => [['code' => 'P-1', 'amount' => 1]]]),
		];

		$added = $this->added($this->action($rules, $lines));

		self::assertSame(['rule-new', 'rule-new'], array_map(static fn (array $row): string => $row['dimensions']['allocationRuleId'], $added));
		self::assertSame(['l1', 'l1'], array_map(static fn (array $row): string => $row['dimensions']['allocationSourceLineId'], $added));
		self::assertSame([10, 11], array_column($added, 'lineNumber'));

	}//end testRulesWithoutIdOrAlreadyAppliedAreSkipped()
}//end class
