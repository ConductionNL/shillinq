<?php

/**
 * Unit tests for LockPassThroughMarkupAction — the markup locked onto a claim's items at submit.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\LockPassThroughMarkupAction;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The submitted claim's pass-through items carry the rule, the rate and the
 * amount, and a later rule change leaves them as they were.
 */
final class LockPassThroughMarkupActionTest extends TestCase {

	/** @var array<int, array{schema:string, object:array<string,mixed>}> */
	private array $saved = [];

	private function store(array $rules): InMemoryObjectServiceStub {
		return new InMemoryObjectServiceStub(
			data: [
				'Receipt' => [
					['id' => 'r-train', 'administrationId' => 'adm-1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker', 'category' => 'travel', 'amountInBaseCurrency' => 42.80],
					['id' => 'r-park', 'administrationId' => 'adm-1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker', 'category' => 'parking', 'amountInBaseCurrency' => 12.50],
					['id' => 'r-own', 'administrationId' => 'adm-1', 'settlementMode' => 'reimbursable', 'linkedCustomerId' => 'anker', 'category' => 'travel', 'amountInBaseCurrency' => 20.00],
				],
				'MileageEntry' => [
					['id' => 'm-1', 'administrationId' => 'adm-1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker', 'totalAmount' => 23.00],
				],
				'PassThroughMarkupRule' => $rules,
			],
			saveSink: $this->saved
		);
	}

	private function action(InMemoryObjectServiceStub $store): LockPassThroughMarkupAction {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new LockPassThroughMarkupAction($store, $config, $this->createMock(LoggerInterface::class));
	}

	private function claim(): array {
		return ['id' => 'claim-1', 'administrationId' => 'adm-1', 'receiptIds' => ['r-train', 'r-park', 'r-own', ''], 'mileageIds' => ['m-1']];
	}

	private function savedItem(string $id): ?array {
		foreach ($this->saved as $write) {
			if (($write['object']['id'] ?? null) === $id) {
				return $write['object'];
			}
		}

		return null;
	}

	public function testEachPassThroughItemCarriesTheRuleRateAndAmountItGotAtSubmit(): void {
		$store = $this->store(
			[['id' => 'rule-travel', 'administrationId' => 'adm-1', 'targetCustomerId' => 'anker', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.10]]
		);

		$claim = $this->claim();
		$this->assertSame($claim, $this->action($store)->execute($claim, [], [], LockPassThroughMarkupAction::NAME));

		$train = $this->savedItem('r-train');
		$this->assertSame('rule-travel', $train['markupRuleId']);
		$this->assertSame(0.10, $train['markupRateApplied']);
		$this->assertSame(4.28, $train['markupAmountCalculated']);
		$this->assertSame(42.80, $train['amountInBaseCurrency']);

		// Mileage matches on travel, the category a mileage line resolves to.
		$mileage = $this->savedItem('m-1');
		$this->assertSame('rule-travel', $mileage['markupRuleId']);
		$this->assertSame(2.3, $mileage['markupAmountCalculated']);
	}

	public function testAPassThroughItemNoRuleAppliesToIsBilledAtCost(): void {
		$store = $this->store(
			[['id' => 'rule-travel', 'administrationId' => 'adm-1', 'targetCustomerId' => 'anker', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.10]]
		);

		$this->action($store)->execute($this->claim(), [], [], LockPassThroughMarkupAction::NAME);

		$parking = $this->savedItem('r-park');
		$this->assertNull($parking['markupRuleId']);
		$this->assertNull($parking['markupRateApplied']);
		$this->assertSame(0.0, $parking['markupAmountCalculated']);
	}

	public function testAReimbursableItemIsLeftAlone(): void {
		$this->action($this->store([]))->execute($this->claim(), [], [], LockPassThroughMarkupAction::NAME);

		$this->assertNull($this->savedItem('r-own'));
		$this->assertSame(['r-train', 'r-park', 'm-1'], array_map(static fn (array $w): string => $w['object']['id'], $this->saved));
	}

	public function testALaterRuleChangeLeavesTheLockedItems(): void {
		$rules = [['id' => 'rule-travel', 'administrationId' => 'adm-1', 'targetCustomerId' => 'anker', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.10]];
		$store = $this->store($rules);
		$this->action($store)->execute($this->claim(), [], [], LockPassThroughMarkupAction::NAME);

		// The rule moves to 25 percent after submission; nothing re-runs the lock.
		$store->saveObject(object: ['id' => 'rule-travel', 'administrationId' => 'adm-1', 'targetCustomerId' => 'anker', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.25], schema: 'PassThroughMarkupRule');

		$train = $store->setRegister('shillinq')->setSchema('Receipt')->find('r-train')->jsonSerialize();
		$this->assertSame(0.10, $train['markupRateApplied']);
		$this->assertSame(4.28, $train['markupAmountCalculated']);
	}

	public function testAFixedMarkupIsWrittenAsAnAmountWithoutARate(): void {
		$store = $this->store(
			[['id' => 'rule-flat', 'administrationId' => 'adm-1', 'targetCustomerId' => 'anker', 'markupType' => 'fixedAmount', 'markupValue' => 5.00]]
		);

		$this->action($store)->execute($this->claim(), [], [], LockPassThroughMarkupAction::NAME);

		$parking = $this->savedItem('r-park');
		$this->assertSame('rule-flat', $parking['markupRuleId']);
		$this->assertNull($parking['markupRateApplied']);
		$this->assertSame(5.0, $parking['markupAmountCalculated']);
	}
}
