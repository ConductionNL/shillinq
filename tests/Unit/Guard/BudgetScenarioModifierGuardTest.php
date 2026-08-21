<?php

/**
 * Unit tests for BudgetScenarioModifierGuard.
 *
 * Covers `budget-scenarios` REQ-BSC-004: the same-`recurId` conflict rule
 * (two RECURRING_* modifiers in the same scenario targeting the same
 * `targetRecurId` are rejected outright), that different `recurId`s and a
 * `LEDGER_AMOUNT_DELTA` alongside a `RECURRING_*` modifier both coexist, and
 * the per-type required-field consistency checks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\BudgetScenarioModifierGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the BudgetScenarioModifier save precondition.
 */
final class BudgetScenarioModifierGuardTest extends TestCase {

	/**
	 * Build the guard over a seeded in-memory OpenRegister.
	 *
	 * @param array<int,array<string,mixed>> $existingModifiers Seeded BudgetScenarioModifier rows.
	 *
	 * @return BudgetScenarioModifierGuard
	 */
	private function buildGuard(array $existingModifiers): BudgetScenarioModifierGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new BudgetScenarioModifierGuard(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: new InMemoryObjectServiceStub(['BudgetScenarioModifier' => $existingModifiers]),
		);
	}//end buildGuard()

	/**
	 * A second RECURRING_* modifier in the same scenario targeting the same
	 * recurId is rejected.
	 *
	 * @return void
	 */
	public function testRejectsSecondModifierOnSameRecurIdInSameScenario(): void {
		$existing = [
			[
				'id' => 'mod-1',
				'scenarioId' => 'scn-1',
				'modifierType' => 'RECURRING_END',
				'targetRecurId' => 'rec-hosting',
				'effectiveDate' => '2027-09-01',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'mod-2',
			'scenarioId' => 'scn-1',
			'modifierType' => 'RECURRING_AMOUNT_CHANGE',
			'targetRecurId' => 'rec-hosting',
			'newStandardAmount' => 100.0,
			'effectiveDate' => '2027-03-01',
		];

		$this->assertFalse($guard->validateOnSave($candidate));

	}//end testRejectsSecondModifierOnSameRecurIdInSameScenario()

	/**
	 * Two RECURRING_* modifiers targeting DIFFERENT recurIds in the same
	 * scenario both save.
	 *
	 * @return void
	 */
	public function testAcceptsDifferentRecurIdsInSameScenario(): void {
		$existing = [
			[
				'id' => 'mod-1',
				'scenarioId' => 'scn-1',
				'modifierType' => 'RECURRING_END',
				'targetRecurId' => 'rec-a',
				'effectiveDate' => '2027-09-01',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'mod-2',
			'scenarioId' => 'scn-1',
			'modifierType' => 'RECURRING_END',
			'targetRecurId' => 'rec-b',
			'effectiveDate' => '2027-09-01',
		];

		$this->assertTrue($guard->validateOnSave($candidate));

	}//end testAcceptsDifferentRecurIdsInSameScenario()

	/**
	 * The same recurId in a DIFFERENT scenario does not conflict — the rule
	 * is scoped per scenario.
	 *
	 * @return void
	 */
	public function testAcceptsSameRecurIdInDifferentScenario(): void {
		$existing = [
			[
				'id' => 'mod-1',
				'scenarioId' => 'scn-1',
				'modifierType' => 'RECURRING_END',
				'targetRecurId' => 'rec-hosting',
				'effectiveDate' => '2027-09-01',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'mod-2',
			'scenarioId' => 'scn-2',
			'modifierType' => 'RECURRING_END',
			'targetRecurId' => 'rec-hosting',
			'effectiveDate' => '2027-09-01',
		];

		$this->assertTrue($guard->validateOnSave($candidate));

	}//end testAcceptsSameRecurIdInDifferentScenario()

	/**
	 * A LEDGER_AMOUNT_DELTA alongside a RECURRING_* modifier in the same
	 * scenario both coexist — the conflict rule only applies to
	 * RECURRING_*-vs-RECURRING_* on the same recurId.
	 *
	 * @return void
	 */
	public function testAcceptsLedgerAmountDeltaAlongsideRecurringModifier(): void {
		$existing = [
			[
				'id' => 'mod-1',
				'scenarioId' => 'scn-1',
				'modifierType' => 'RECURRING_END',
				'targetRecurId' => 'rec-hosting',
				'effectiveDate' => '2027-09-01',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'mod-2',
			'scenarioId' => 'scn-1',
			'modifierType' => 'LEDGER_AMOUNT_DELTA',
			'targetLedgerGroupId' => 'lg-1',
			'amountDeltaCents' => -500000,
			'effectiveDate' => '2027-09-01',
		];

		$this->assertTrue($guard->validateOnSave($candidate));

	}//end testAcceptsLedgerAmountDeltaAlongsideRecurringModifier()

	/**
	 * A RECURRING_* modifier missing targetRecurId is rejected.
	 *
	 * @return void
	 */
	public function testRejectsRecurringModifierMissingTargetRecurId(): void {
		$guard = $this->buildGuard([]);

		$candidate = [
			'id' => 'mod-1',
			'scenarioId' => 'scn-1',
			'modifierType' => 'RECURRING_END',
			'effectiveDate' => '2027-09-01',
		];

		$this->assertFalse($guard->validateOnSave($candidate));

	}//end testRejectsRecurringModifierMissingTargetRecurId()

	/**
	 * A RECURRING_AMOUNT_CHANGE missing newStandardAmount is rejected.
	 *
	 * @return void
	 */
	public function testRejectsRecurringAmountChangeMissingNewStandardAmount(): void {
		$guard = $this->buildGuard([]);

		$candidate = [
			'id' => 'mod-1',
			'scenarioId' => 'scn-1',
			'modifierType' => 'RECURRING_AMOUNT_CHANGE',
			'targetRecurId' => 'rec-hosting',
			'effectiveDate' => '2027-09-01',
		];

		$this->assertFalse($guard->validateOnSave($candidate));

	}//end testRejectsRecurringAmountChangeMissingNewStandardAmount()

	/**
	 * A LEDGER_AMOUNT_DELTA missing targetLedgerGroupId is rejected.
	 *
	 * @return void
	 */
	public function testRejectsLedgerAmountDeltaMissingTargetLedgerGroupId(): void {
		$guard = $this->buildGuard([]);

		$candidate = [
			'id' => 'mod-1',
			'scenarioId' => 'scn-1',
			'modifierType' => 'LEDGER_AMOUNT_DELTA',
			'amountDeltaCents' => -500000,
			'effectiveDate' => '2027-09-01',
		];

		$this->assertFalse($guard->validateOnSave($candidate));

	}//end testRejectsLedgerAmountDeltaMissingTargetLedgerGroupId()

	/**
	 * A LEDGER_AMOUNT_DELTA missing amountDeltaCents is rejected.
	 *
	 * @return void
	 */
	public function testRejectsLedgerAmountDeltaMissingAmountDeltaCents(): void {
		$guard = $this->buildGuard([]);

		$candidate = [
			'id' => 'mod-1',
			'scenarioId' => 'scn-1',
			'modifierType' => 'LEDGER_AMOUNT_DELTA',
			'targetLedgerGroupId' => 'lg-1',
			'effectiveDate' => '2027-09-01',
		];

		$this->assertFalse($guard->validateOnSave($candidate));

	}//end testRejectsLedgerAmountDeltaMissingAmountDeltaCents()

	/**
	 * The candidate record itself, if already present among the queried
	 * rows (re-saving an unchanged modifier), is not treated as its own
	 * conflict.
	 *
	 * @return void
	 */
	public function testDoesNotTreatItselfAsAConflict(): void {
		$existing = [
			[
				'id' => 'mod-1',
				'scenarioId' => 'scn-1',
				'modifierType' => 'RECURRING_END',
				'targetRecurId' => 'rec-hosting',
				'effectiveDate' => '2027-09-01',
			],
		];
		$guard = $this->buildGuard($existing);

		$candidate = [
			'id' => 'mod-1',
			'scenarioId' => 'scn-1',
			'modifierType' => 'RECURRING_END',
			'targetRecurId' => 'rec-hosting',
			'effectiveDate' => '2027-09-01',
		];

		$this->assertTrue($guard->validateOnSave($candidate));

	}//end testDoesNotTreatItselfAsAConflict()
}//end class
