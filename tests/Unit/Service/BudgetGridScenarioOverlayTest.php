<?php

/**
 * Unit tests for BudgetGridScenarioOverlay.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetGridScenarioOverlay;
use OCA\Shillinq\Service\BudgetScenarioEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Overlay of one scenario onto the grid's per-year slices.
 */
final class BudgetGridScenarioOverlayTest extends TestCase {

	/**
	 * Find a scenario by id or by its @self id; absent otherwise.
	 *
	 * @return void
	 */
	public function testFindScenario(): void {
		$overlay = new BudgetGridScenarioOverlay($this->createMock(BudgetScenarioEvaluator::class));
		$context = [
			'scenarios' => [
				['id' => 's1'],
				['@self' => ['id' => 's2']],
				['name' => 'no id'],
			],
		];

		self::assertSame(['id' => 's1'], $overlay->findScenario($context, 's1'));
		self::assertSame(['@self' => ['id' => 's2']], $overlay->findScenario($context, 's2'));
		self::assertNull($overlay->findScenario($context, 's3'));
		self::assertNull($overlay->findScenario($context, ''));
		self::assertNull($overlay->findScenario([], 's1'));
	}//end testFindScenario()

	/**
	 * Years without a default budget stay null; others become one synthetic
	 * line per ledger group carrying the resolved monthly amounts.
	 *
	 * @return void
	 */
	public function testApplySwapsSlicesAndKeepsEmptyYears(): void {
		$evaluator = $this->createMock(BudgetScenarioEvaluator::class);
		$evaluator->expects($this->once())
			->method('evaluate')
			->with(
				[['ledgerGroupId' => 'g1']],
				[['id' => 'g1']],
				[['recurId' => 'r']],
				[['m' => 1]],
				2026
			)
			->willReturn(
				[
					'g1|2026-01' => ['month' => '2026-01', 'ledgerGroupId' => 'g1', 'base' => 1, 'scenario' => 10, 'delta' => 9],
					'g1|2026-02' => ['month' => '2026-02', 'ledgerGroupId' => 'g1', 'base' => 2, 'scenario' => 20, 'delta' => 18],
					'g2|2026-01' => ['month' => '2026-01', 'ledgerGroupId' => 'g2', 'base' => 0, 'scenario' => 5, 'delta' => 5],
				]
			);
		$overlay = new BudgetGridScenarioOverlay($evaluator);
		$context = [
			'modifiersByScenarioId' => ['s1' => [['m' => 1]]],
			'ledgerGroups'          => [['id' => 'g1']],
			'cashflowRecurringRows' => [['recurId' => 'r']],
		];

		$result = $overlay->apply([2025 => null, 2026 => [['ledgerGroupId' => 'g1']]], $context, 's1');

		self::assertNull($result[2025]);
		self::assertSame(
			[
				['ledgerGroupId' => 'g1', 'source' => 'scenario', 'month01Amount' => 10, 'month02Amount' => 20],
				['ledgerGroupId' => 'g2', 'source' => 'scenario', 'month01Amount' => 5],
			],
			$result[2026]
		);
	}//end testApplySwapsSlicesAndKeepsEmptyYears()
}//end class
