<?php

/**
 * Characterisation tests for the BudgetGridCalculator computed-row formula grammar.
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
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetGridCalculator;
use OCA\Shillinq\Service\BudgetVsActualsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every branch of the `+`/`-` chain and the `a / b` percent formula.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetGridFormulaTest extends TestCase {

	/**
	 * Formula rows and the expected value.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int|float|null}> Cases.
	 */
	public static function formulas(): array {
		return [
			'chain'                   => [['formula' => 'omzet - kostprijs-van-de-omzet + overig'], 70],
			'single operand'          => [['formula' => '  omzet  '], 100],
			'tight minus is a code'   => [['formula' => 'omzet-overig'], null],
			'missing operand'         => [['formula' => 'omzet + onbekend'], null],
			'null operand'            => [['formula' => 'omzet + leeg'], null],
			'empty term'              => [['formula' => 'omzet +  + overig'], null],
			'empty formula'           => [['formula' => '   '], null],
			'no formula key'          => [[], null],
			'float operand'           => [['formula' => 'omzet - fractie'], 99.5],
			'percent'                 => [['formula' => 'overig / omzet', 'asPercent' => true], 0.1],
			'percent spaced'          => [['formula' => ' omzet/overig ', 'asPercent' => 1], 10],
			'percent zero divisor'    => [['formula' => 'omzet / nul', 'asPercent' => true], null],
			'percent null operand'    => [['formula' => 'leeg / omzet', 'asPercent' => true], null],
			'percent without divisor' => [['formula' => 'omzet', 'asPercent' => true], null],
			'percent extra slash'     => [['formula' => 'omzet / overig / x', 'asPercent' => true], null],
		];

	}//end formulas()

	/**
	 * Each formula evaluates to the pinned value.
	 *
	 * @param array<string, mixed> $row      The computed-row fields.
	 * @param int|float|null       $expected The expected value.
	 *
	 * @return void
	 */
	#[DataProvider('formulas')]
	public function testFormula(array $row, int|float|null $expected): void {
		$values = ['omzet' => 100, 'kostprijs-van-de-omzet' => 40, 'overig' => 10, 'leeg' => null, 'nul' => 0, 'nulf' => 0.0, 'fractie' => 0.5];
		$result = (new BudgetGridCalculator(new BudgetVsActualsCalculator()))->evaluateComputedRows([['code' => 'r'] + $row], $values);

		self::assertSame(['r' => $expected], $result);

	}//end testFormula()

	/**
	 * Only an integer zero divisor answers null; a float 0.0 divisor reaches
	 * the division and throws (pinned as current behaviour).
	 *
	 * @return void
	 */
	public function testFloatZeroDivisorThrows(): void {
		$this->expectException(\DivisionByZeroError::class);

		(new BudgetGridCalculator(new BudgetVsActualsCalculator()))->evaluateComputedRows(
			[['code' => 'r', 'formula' => 'a / b', 'asPercent' => true]],
			['a' => 1, 'b' => 0.0]
		);

	}//end testFloatZeroDivisorThrows()

	/**
	 * Rows without a code are skipped; later rows see earlier results.
	 *
	 * @return void
	 */
	public function testRowsChainInDeclarationOrder(): void {
		$result = (new BudgetGridCalculator(new BudgetVsActualsCalculator()))->evaluateComputedRows(
			[
				['code' => '', 'formula' => 'a'],
				['formula' => 'a'],
				['code' => 'b', 'formula' => 'a + a'],
				['code' => 'c', 'formula' => 'b - a'],
				['code' => 'd', 'formula' => 'c / b', 'asPercent' => true],
			],
			['a' => 4]
		);

		self::assertSame(['b' => 8, 'c' => 4, 'd' => 0.5], $result);

	}//end testRowsChainInDeclarationOrder()
}//end class
