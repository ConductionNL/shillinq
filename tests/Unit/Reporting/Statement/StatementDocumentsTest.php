<?php

/**
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Reporting\Statement
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Statement;

use OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator;
use OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use OCA\Shillinq\Reporting\StatementCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The documents show the figures of the on-screen statements (REQ-RFS-004).
 *
 * @covers \OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractStatementReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AccountBucket
 * @uses   \OCA\Shillinq\Reporting\Generator\StatementRun
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Reporting\StatementCalculator
 * @uses   \OCA\Shillinq\Reporting\StatementPeriods
 * @uses   \OCA\Shillinq\Service\FiscalYearContextService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class StatementDocumentsTest extends TestCase {

	/**
	 * The blocks a generator builds for a context, over the seed's calculator.
	 *
	 * @param string                    $class      The generator class.
	 * @param array<string, mixed>      $context    The generation context.
	 * @param StatementCalculator|null  $calculator The calculator; null when the container has none.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function render(string $class, array $context, ?StatementCalculator $calculator): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($calculator): object {
				if ($id === StatementCalculator::class && $calculator !== null) {
					return $calculator;
				}

				throw new RuntimeException('not in container: ' . $id);
			}
		);

		$section = new ReportSection();
		(new ReflectionMethod($class, 'build'))->invoke(new $class($container), $section, $context);

		return $section->toArray();
	}

	/**
	 * Each amount table's total, by its total label.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks.
	 *
	 * @return array<string, float>
	 */
	private static function totals(array $blocks): array {
		$totals = [];
		foreach ($blocks as $block) {
			if ($block['type'] === 'amountTable') {
				$totals[$block['total']['label']] = (float)$block['total']['amount'];
			}
		}

		return $totals;
	}

	/**
	 * Each amount table's lines, by label.
	 *
	 * @param array<int, array<string, mixed>> $blocks The blocks.
	 * @param string                           $total  The table's total label.
	 *
	 * @return array<string, float>
	 */
	private static function lines(array $blocks, string $total): array {
		foreach ($blocks as $block) {
			if ($block['type'] === 'amountTable' && $block['total']['label'] === $total) {
				return array_column($block['lines'], 'amount', 'label');
			}
		}

		return [];
	}

	/**
	 * The profit and loss document of 2026-Q1..Q3 equals the endpoint figures,
	 * not the stored account balances.
	 *
	 * @return void
	 */
	public function testTheProfitAndLossDocumentMatchesTheCalculator(): void {
		$calculator = VanDijkLedger::calculator($this, VanDijkLedger::rows());
		$expected = $calculator->resultsBetween(VanDijkLedger::ADM, '2026-01-01', '2026-09-30')['totals'];

		$blocks = $this->render(ProfitLossReportGenerator::class, ['administrationId' => VanDijkLedger::ADM, 'from' => '2026-01-01', 'to' => '2026-09-30'], $calculator);

		$totals = self::totals($blocks);
		self::assertSame($expected['revenue'], $totals['Totaal opbrengsten']);
		self::assertSame($expected['expense'], $totals['Totaal kosten']);
		self::assertSame($expected['result'], $totals['Resultaat (winst)']);
		self::assertSame(['8000 Omzet' => 180000.0], self::lines($blocks, 'Totaal opbrengsten'));
	}

	/**
	 * A period label sets the range, and a loss is labelled as one.
	 *
	 * @return void
	 */
	public function testAPeriodLabelAndALoss(): void {
		$blocks = $this->render(ProfitLossReportGenerator::class, ['administrationId' => VanDijkLedger::ADM, 'period' => '2026-Q2'], VanDijkLedger::calculator($this, VanDijkLedger::rows()));

		self::assertSame(-132000.0, self::totals($blocks)['Resultaat (verlies)']);
		self::assertSame('Periode: 2026-Q2', $blocks[0]['meta'][0]);
	}

	/**
	 * The balance sheet document on 30 September equals the page: running
	 * result in equity, both sides 174,000.
	 *
	 * @return void
	 */
	public function testTheBalanceSheetDocumentMatchesTheCalculator(): void {
		$calculator = VanDijkLedger::calculator($this, VanDijkLedger::rows());
		$expected = $calculator->balancesAsOf(VanDijkLedger::ADM, '2026-09-30')['totals'];

		$blocks = $this->render(BalanceSheetReportGenerator::class, ['administrationId' => VanDijkLedger::ADM, 'asOf' => '2026-09-30', 'period' => '2026'], $calculator);

		$totals = self::totals($blocks);
		self::assertSame($expected['assets'], $totals['Totaal activa']);
		self::assertSame($expected['equity'], $totals['Totaal eigen vermogen']);
		self::assertSame($expected['liabilities'], $totals['Totaal schulden']);
		self::assertSame($expected['liabilitiesAndEquity'], $totals['Totaal passiva']);
		self::assertSame(48000.0, self::lines($blocks, 'Totaal eigen vermogen')[StatementCalculator::RUNNING_RESULT_LABEL]);
		self::assertSame('Balans per 2026-09-30', $blocks[0]['subtitle']);
		$check = array_values(array_filter($blocks, static fn (array $block): bool => $block['type'] === 'detailsTable'))[0]['rows'];
		self::assertSame('Ja', $check['Balans sluit aan']);
	}

	/**
	 * Without asOf the balance sheet is drawn at the end of the period.
	 *
	 * @return void
	 */
	public function testTheBalanceSheetDefaultsToThePeriodEnd(): void {
		$blocks = $this->render(BalanceSheetReportGenerator::class, ['administrationId' => VanDijkLedger::ADM, 'asOf' => 'gisteren', 'period' => '2025'], VanDijkLedger::calculator($this, VanDijkLedger::rows()));

		self::assertSame('Balans per 2025-12-31', $blocks[0]['subtitle']);
		self::assertSame(90000.0, self::totals($blocks)['Totaal activa']);
	}

	/**
	 * Without a calculator both documents render empty, and say so.
	 *
	 * @return void
	 */
	public function testWithoutACalculatorTheDocumentsSayThereIsNothing(): void {
		$pnl = $this->render(ProfitLossReportGenerator::class, ['period' => '2026'], null);
		$sheet = $this->render(BalanceSheetReportGenerator::class, ['period' => '2026'], null);

		self::assertContains(['type' => 'note', 'text' => 'Er zijn geen geboekte resultaatregels gevonden voor deze administratie in deze periode.'], $pnl);
		self::assertContains(['type' => 'note', 'text' => 'Er zijn geen geboekte grootboekregels gevonden voor deze administratie tot deze datum.'], $sheet);
		self::assertSame(0.0, self::totals($sheet)['Totaal activa']);
	}
}
