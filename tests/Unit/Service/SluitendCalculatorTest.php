<?php

/**
 * Unit tests for SluitendCalculator.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SluitendCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the sluitend-criterium and toezichtregime computation (REQ-008, REQ-011, D5).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SluitendCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var SluitendCalculator
	 */
	private SluitendCalculator $calculator;

	/**
	 * Set up the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new SluitendCalculator();

	}//end setUp()

	/**
	 * REQ-008: a year with baten ≥ lasten and a positive reëel saldo is sluitend.
	 *
	 * @return void
	 */
	public function testYearSluitendWhenStructureelAndReeelHold(): void {
		$result = $this->calculator->evaluateYear(
			year: ['revenueStructural' => 1000.0, 'expensesStructural' => 900.0],
			nominalDevelopment: 2.0
		);

		// Saldo structureel = 100; reëel uplift = 2% of 900 = 18; saldo reëel = 82.
		self::assertSame(100.0, $result['balanceStructural']);
		self::assertSame(82.0, $result['saldoReëel']);
		self::assertTrue($result['structurallyBalanced']);
		self::assertTrue($result['sluitendReëel']);
		self::assertTrue($result['sluitend']);

	}//end testYearSluitendWhenStructureelAndReeelHold()

	/**
	 * REQ-008: a year with lasten > baten is not sluitend; the parent flags fail.
	 *
	 * @return void
	 */
	public function testYearNotSluitendWhenLastenExceedBaten(): void {
		$result = $this->calculator->evaluateYear(
			year: ['revenueStructural' => 1000.0, 'expensesStructural' => 1100.0],
			nominalDevelopment: 2.0
		);

		self::assertSame(-100.0, $result['balanceStructural']);
		self::assertFalse($result['structurallyBalanced']);
		self::assertFalse($result['sluitend']);

	}//end testYearNotSluitendWhenLastenExceedBaten()

	/**
	 * A structureel-positive year can still fail reëel once the nominale uplift bites.
	 *
	 * @return void
	 */
	public function testYearStructureelSluitendButReeelFails(): void {
		// Saldo structureel = 10; uplift = 5% of 1000 = 50; saldo reëel = -40.
		$result = $this->calculator->evaluateYear(
			year: ['revenueStructural' => 1010.0, 'expensesStructural' => 1000.0],
			nominalDevelopment: 5.0
		);

		self::assertTrue($result['structurallyBalanced']);
		self::assertFalse($result['sluitendReëel']);
		self::assertFalse($result['sluitend']);

	}//end testYearStructureelSluitendButReeelFails()

	/**
	 * REQ-011: the overall flags hold only when every year holds.
	 *
	 * @return void
	 */
	public function testBegrotingSluitendOnlyWhenAllYearsHold(): void {
		$years = [
			['revenueStructural' => 1000.0, 'expensesStructural' => 900.0],
			['revenueStructural' => 1020.0, 'expensesStructural' => 950.0],
		];

		$flags = $this->calculator->evaluateBegroting(years: $years, nominalDevelopment: 2.0);
		self::assertTrue($flags['structurallyBalanced']);
		self::assertTrue($flags['sluitendReëel']);

	}//end testBegrotingSluitendOnlyWhenAllYearsHold()

	/**
	 * One failing year drops the overall structural flag (REQ-008 all-quantifier).
	 *
	 * @return void
	 */
	public function testBegrotingNotSluitendWhenOneYearFails(): void {
		$years = [
			['revenueStructural' => 1000.0, 'expensesStructural' => 900.0],
			['revenueStructural' => 800.0, 'expensesStructural' => 950.0],
		];

		$flags = $this->calculator->evaluateBegroting(years: $years, nominalDevelopment: 2.0);
		self::assertFalse($flags['structurallyBalanced']);

	}//end testBegrotingNotSluitendWhenOneYearFails()

	/**
	 * An empty meerjarenraming is fail-closed (not sluitend).
	 *
	 * @return void
	 */
	public function testEmptyBegrotingIsNotSluitend(): void {
		$flags = $this->calculator->evaluateBegroting(years: [], nominalDevelopment: 2.0);
		self::assertFalse($flags['structurallyBalanced']);
		self::assertFalse($flags['sluitendReëel']);

	}//end testEmptyBegrotingIsNotSluitend()

	/**
	 * D5: full conformity yields repressief toezicht.
	 *
	 * @return void
	 */
	public function testRepressiefWhenFullyConform(): void {
		$regime = $this->calculator->determineToezichtRegime(
			structurallyBalanced: true,
			sluitendReeel: true,
			historyResultaten: [100.0, 50.0, 80.0, 20.0],
			weerstandsverhouding: 1.4
		);
		self::assertSame('repressief', $regime);

	}//end testRepressiefWhenFullyConform()

	/**
	 * D5: a non-sluitende begroting yields preventief toezicht.
	 *
	 * @return void
	 */
	public function testPreventiefWhenNotSluitend(): void {
		$regime = $this->calculator->determineToezichtRegime(
			structurallyBalanced: false,
			sluitendReeel: true,
			weerstandsverhouding: 1.4
		);
		self::assertSame('preventief', $regime);

	}//end testPreventiefWhenNotSluitend()

	/**
	 * D5: a sustained 4-year tekort yields preventief even when sluitend.
	 *
	 * @return void
	 */
	public function testPreventiefOnSustainedTekort(): void {
		$regime = $this->calculator->determineToezichtRegime(
			structurallyBalanced: true,
			sluitendReeel: true,
			historyResultaten: [-10.0, -20.0, -30.0, -5.0],
			weerstandsverhouding: 1.2
		);
		self::assertSame('preventief', $regime);

	}//end testPreventiefOnSustainedTekort()

	/**
	 * D5: a negative weerstandsverhouding (vermogenstekort) yields artikel-12.
	 *
	 * @return void
	 */
	public function testArtikel12OnNegativeWeerstandsverhouding(): void {
		$regime = $this->calculator->determineToezichtRegime(
			structurallyBalanced: true,
			sluitendReeel: true,
			weerstandsverhouding: -0.2
		);
		self::assertSame('artikel-12', $regime);

	}//end testArtikel12OnNegativeWeerstandsverhouding()

	/**
	 * Integer-cent arithmetic avoids IEEE-754 drift on the reëel correction.
	 *
	 * @return void
	 */
	public function testIntegerCentsAvoidDrift(): void {
		// 0.1 + 0.2 baten/lasten that would drift in float — result must be exact.
		$result = $this->calculator->evaluateYear(
			year: ['revenueStructural' => 0.3, 'expensesStructural' => 0.3],
			nominalDevelopment: 0.0
		);
		self::assertSame(0.0, $result['balanceStructural']);
		self::assertTrue($result['structurallyBalanced']);

	}//end testIntegerCentsAvoidDrift()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
