<?php

/**
 * Unit tests for EvaluateAllocationRulesAction.
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
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\EvaluateAllocationRulesAction;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Active per-posting allocation rules add balanced lines on post (REQ-LPP-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EvaluateAllocationRulesActionTest extends TestCase {

	/**
	 * The store behind the ObjectService mock.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * The transaction being posted.
	 *
	 * @var array<string,mixed>
	 */
	private const TRANSACTION = [
		'id' => 'gl-1',
		'transactionNumber' => 'MEM-1',
		'administrationId' => 'adm-1',
		'currency' => 'EUR',
		'periodId' => '2026-03',
		'state' => 'posted',
	];

	/**
	 * Build the action over a store holding the transaction's two lines and the given rules.
	 *
	 * @param list<array<string,mixed>> $rules The AllocationRule rows.
	 *
	 * @return EvaluateAllocationRulesAction
	 */
	private function action(array $rules): EvaluateAllocationRulesAction {
		$this->store = new InMemoryObjectStore();
		$this->store->rows['GLLine'] = [
			['id' => 'l1', 'transactionId' => 'gl-1', 'lineNumber' => 1, 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 1200.0],
			['id' => 'l2', 'transactionId' => 'gl-1', 'lineNumber' => 2, 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 1200.0],
		];
		$this->store->rows['AllocationRule'] = $rules;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new EvaluateAllocationRulesAction($this->store->mock($this), $appConfig);
	}//end action()

	/**
	 * The design's rent rule: 40 percent to Burgerzaken, 60 to Sociaal Domein.
	 *
	 * @param string $state The rule's lifecycle state.
	 *
	 * @return array<string,mixed>
	 */
	private function rentRule(string $state = 'active'): array {
		return [
			'id' => 'rule-rent',
			'name' => 'Huur naar afdelingen',
			'sourceAccountPattern' => '4000-4099',
			'driver' => 'fixed-percentage',
			'targets' => [['code' => 'BZ', 'percentage' => 40.0], ['code' => 'SD', 'percentage' => 60.0]],
			'targetDimension' => 'cost-center',
			'cadence' => 'per-posting',
			'lifecycleState' => $state,
			'administrationId' => 'adm-1',
		];
	}//end rentRule()

	/**
	 * A matching rule splits the cost line, and the transaction still balances.
	 *
	 * @return void
	 */
	public function testAMatchingRuleSplitsTheCostAndStaysBalanced(): void {
		$result = $this->action([$this->rentRule()])->execute(self::TRANSACTION, [], [], EvaluateAllocationRulesAction::class);

		self::assertSame(self::TRANSACTION, $result);

		$added = $this->store->savedOf('GLLine');
		self::assertCount(3, $added);
		self::assertSame(['4000', 'credit', 1200.0], [$added[0]['accountNumber'], $added[0]['side'], $added[0]['amount']]);
		self::assertSame(['4000', 'debit', 480.0, 'BZ'], [$added[1]['accountNumber'], $added[1]['side'], $added[1]['amount'], $added[1]['costCenterCode']]);
		self::assertSame(['4000', 'debit', 720.0, 'SD'], [$added[2]['accountNumber'], $added[2]['side'], $added[2]['amount'], $added[2]['costCenterCode']]);
		self::assertSame([3, 4, 5], array_column($added, 'lineNumber'));
		self::assertSame('gl-1', $added[0]['transactionId']);

		$debit = 0.0;
		$credit = 0.0;
		foreach ($this->store->rows['GLLine'] as $line) {
			if ($line['side'] === 'debit') {
				$debit += $line['amount'];
				continue;
			}

			$credit += $line['amount'];
		}

		self::assertSame($debit, $credit, 'The transaction must still balance in total.');
	}//end testAMatchingRuleSplitsTheCostAndStaysBalanced()

	/**
	 * No active matching rule: the transaction posts with exactly its own lines.
	 *
	 * @return void
	 */
	public function testNoMatchingRuleWritesNothing(): void {
		$paused = $this->rentRule('paused');
		$elsewhere = array_merge($this->rentRule(), ['id' => 'rule-it', 'sourceAccountPattern' => '4500-4599']);

		$this->action([$paused, $elsewhere])->execute(self::TRANSACTION, [], [], EvaluateAllocationRulesAction::class);

		self::assertSame([], $this->store->saved);
	}//end testNoMatchingRuleWritesNothing()

	/**
	 * A rule already applied to this transaction is not applied again.
	 *
	 * @return void
	 */
	public function testARepeatedRunDoesNotAllocateTwice(): void {
		$action = $this->action([$this->rentRule()]);

		$action->execute(self::TRANSACTION, [], [], EvaluateAllocationRulesAction::class);
		$action->execute(self::TRANSACTION, [], [], EvaluateAllocationRulesAction::class);

		self::assertCount(3, $this->store->savedOf('GLLine'));
	}//end testARepeatedRunDoesNotAllocateTwice()

	/**
	 * An active per-posting rule whose driver needs data per period refuses by name.
	 *
	 * @return void
	 */
	public function testAPerPostingHeadcountRuleRefusesByName(): void {
		$rule = array_merge($this->rentRule(), ['driver' => 'headcount', 'targets' => [['code' => 'BZ', 'source' => 'headcount-register']]]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Huur naar afdelingen');

		$this->action([$rule])->execute(self::TRANSACTION, [], [], EvaluateAllocationRulesAction::class);
	}//end testAPerPostingHeadcountRuleRefusesByName()
}//end class
