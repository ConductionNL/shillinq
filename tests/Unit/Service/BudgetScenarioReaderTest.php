<?php

/**
 * Unit tests for BudgetScenarioReader.
 *
 * Covers `budget-scenarios` REQ-BSC-007: the assembled context bundle
 * (scenarios, modifiers grouped by scenarioId, CashflowRecurring rows,
 * BudgetLines, LedgerGroups) and the exactly-5-`findAll()`-call query
 * budget, independent of modifier/LedgerGroup count.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetScenarioReader;
use OCA\Shillinq\Tests\Unit\Service\Support\CallCountingObjectServiceDecorator;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the reader's context-assembly and query-budget behaviour.
 */
final class BudgetScenarioReaderTest extends TestCase {

	/**
	 * Build the reader over a seeded fixture store, returning both the
	 * reader and the call-counting decorator so tests can assert on
	 * `$decorator->findAllCalls`.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return array{0: BudgetScenarioReader, 1: CallCountingObjectServiceDecorator}
	 */
	private function buildReader(array $data): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$decorator = new CallCountingObjectServiceDecorator(new FilteredObjectServiceStub($data));

		$reader = new BudgetScenarioReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
		);

		return [$reader, $decorator];
	}//end buildReader()

	/**
	 * A fixture: one administration, one scenario with two modifiers, one
	 * CashflowRecurring row, one LedgerGroup, one BudgetLine.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function fixture(): array {
		return [
			'BudgetScenario' => [
				[
					'id' => 'scn-1', '@self' => ['id' => 'scn-1'], 'administrationId' => 'adm-1',
					'name' => 'Sell product X', 'isDefault' => true, 'status' => 'active',
				],
			],
			'BudgetScenarioModifier' => [
				[
					'id' => 'mod-1', 'scenarioId' => 'scn-1', 'modifierType' => 'RECURRING_END',
					'targetRecurId' => 'rec-hosting', 'effectiveDate' => '2027-09-01',
				],
				[
					'id' => 'mod-2', 'scenarioId' => 'scn-1', 'modifierType' => 'LEDGER_AMOUNT_DELTA',
					'targetLedgerGroupId' => 'lg-1', 'amountDeltaCents' => -500000, 'effectiveDate' => '2027-09-01',
				],
			],
			'CashflowRecurring' => [
				[
					'recurId' => 'rec-hosting', 'administrationId' => 'adm-1', 'accountNumberExpense' => '4200',
					'standardAmount' => 250.0, 'frequency' => 'MONTHLY', 'validFrom' => '2026-01-01', 'validTo' => null,
				],
			],
			'LedgerGroup' => [
				[
					'id' => 'lg-1', '@self' => ['id' => 'lg-1', 'slug' => 'ledger-group-vla-liq'],
					'administrationId' => 'adm-1', 'code' => 'VLA-LIQ', 'name' => 'Liquide middelen',
				],
			],
			'BudgetLine' => [
				['id' => 'bl-1', 'annualBudgetId' => 'ab-1', 'ledgerGroupId' => 'lg-1', 'source' => 'manual', 'month01Amount' => 100000],
			],
		];
	}//end fixture()

	/**
	 * Modifiers are grouped by their owning scenarioId.
	 *
	 * @return void
	 */
	public function testModifiersAreGroupedByScenarioId(): void {
		[$reader] = $this->buildReader($this->fixture());

		$context = $reader->loadContext('adm-1', ['ab-1']);

		$this->assertCount(2, $context['modifiersByScenarioId']['scn-1']);

	}//end testModifiersAreGroupedByScenarioId()

	/**
	 * Only scenarios belonging to the requested administration are loaded.
	 *
	 * @return void
	 */
	public function testScenariosAreScopedToAdministration(): void {
		$data = $this->fixture();
		$data['BudgetScenario'][] = [
			'id' => 'scn-2',
			'@self' => ['id' => 'scn-2'],
			'administrationId' => 'adm-OTHER',
			'name' => 'Other admin scenario',
			'isDefault' => false,
			'status' => 'draft',
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		$this->assertCount(1, $context['scenarios']);
		$this->assertSame('scn-1', $context['scenarios'][0]['id']);

	}//end testScenariosAreScopedToAdministration()

	/**
	 * Modifiers belonging to a scenario NOT in this administration are not
	 * loaded (the `scenarioId: {in: [...]}` filter is scoped to the
	 * administration's own scenario ids).
	 *
	 * @return void
	 */
	public function testModifiersAreScopedToTheAdministrationsOwnScenarios(): void {
		$data = $this->fixture();
		$data['BudgetScenarioModifier'][] = [
			'id' => 'mod-other',
			'scenarioId' => 'scn-OTHER',
			'modifierType' => 'RECURRING_END',
			'targetRecurId' => 'rec-other',
			'effectiveDate' => '2027-09-01',
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		$allModifiers = array_merge(...array_values($context['modifiersByScenarioId']));
		$this->assertCount(2, $allModifiers);

	}//end testModifiersAreScopedToTheAdministrationsOwnScenarios()

	/**
	 * BudgetLines are loaded via the `{annualBudgetId: {in: [...]}}` filter.
	 *
	 * @return void
	 */
	public function testBudgetLinesAreLoadedByInFilter(): void {
		$data = $this->fixture();
		$data['BudgetLine'][] = [
			'id' => 'bl-2',
			'annualBudgetId' => 'ab-OTHER',
			'ledgerGroupId' => 'lg-1',
			'source' => 'manual',
			'month01Amount' => 999900,
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', ['ab-1']);

		$this->assertCount(1, $context['budgetLines']);
		$this->assertSame('bl-1', $context['budgetLines'][0]['id']);

	}//end testBudgetLinesAreLoadedByInFilter()

	/**
	 * With no scenarios for the administration, the modifier read is
	 * skipped entirely (empty scenarioIds), and the query budget drops
	 * accordingly.
	 *
	 * @return void
	 */
	public function testNoScenariosMeansModifiersAreNotQueried(): void {
		$data = $this->fixture();
		$data['BudgetScenario'] = [];

		[$reader, $decorator] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', []);

		$this->assertSame([], $context['scenarios']);
		$this->assertSame([], $context['modifiersByScenarioId']);
		// BudgetScenario + CashflowRecurring + LedgerGroup = 3 calls; the
		// BudgetScenarioModifier call is skipped (no scenario ids), and
		// BudgetLine is skipped (no annualBudgetIds requested).
		$this->assertSame(3, $decorator->findAllCalls);

	}//end testNoScenariosMeansModifiersAreNotQueried()

	/**
	 * The full read issues exactly 5 `findAll()` calls (REQ-BSC-007).
	 *
	 * @return void
	 */
	public function testFullReadIssuesExactlyFiveFindAllCalls(): void {
		[$reader, $decorator] = $this->buildReader($this->fixture());

		$reader->loadContext('adm-1', ['ab-1']);

		$this->assertSame(5, $decorator->findAllCalls);

	}//end testFullReadIssuesExactlyFiveFindAllCalls()

	/**
	 * Doubling the number of modifiers, LedgerGroups and CashflowRecurring
	 * rows in the fixture does not add any further `findAll()` calls — the
	 * bound is on CALL COUNT, not data volume (REQ-BSC-007's own scenario:
	 * "20 modifiers issues exactly 5 store queries").
	 *
	 * @return void
	 */
	public function testCallCountDoesNotScaleWithDataVolume(): void {
		$data = $this->fixture();
		for ($i = 0; $i < 20; $i++) {
			$data['BudgetScenarioModifier'][] = [
				'id' => 'mod-extra-' . $i,
				'scenarioId' => 'scn-1',
				'modifierType' => 'LEDGER_AMOUNT_DELTA',
				'targetLedgerGroupId' => 'lg-1',
				'amountDeltaCents' => -1000,
				'effectiveDate' => '2027-0' . (($i % 9) + 1) . '-01',
			];
		}

		[$reader, $decorator] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1', ['ab-1']);

		$this->assertCount(22, $context['modifiersByScenarioId']['scn-1']);
		$this->assertSame(5, $decorator->findAllCalls);

	}//end testCallCountDoesNotScaleWithDataVolume()
}//end class
