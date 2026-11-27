<?php

/**
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-cash-flow-statement
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\CashFlowCalculator;
use OCA\Shillinq\Reporting\CashFlowStatementRecord;
use OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use OCA\Shillinq\Service\RegisterBinding;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The annual accounts carry the kasstroomoverzicht of the cash flow page and
 * keep it as a CashFlowStatement (REQ-RCF-004).
 *
 * @covers \OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator
 * @uses   \OCA\Shillinq\Reporting\CashFlowStatementRecord
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Service\RecordStoreLog
 * @uses   \OCA\Shillinq\Service\RegisterBinding
 * @uses   \OCA\Shillinq\Service\SchemaRecordStore
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class AnnualAccountsCashFlowTest extends TestCase {

	/**
	 * The 2025 cash flow the page shows.
	 *
	 * @return array<string, mixed>
	 */
	private static function flow(): array {
		return [
			'administrationId' => 'adm-1',
			'from' => '2025-01-01',
			'to' => '2025-12-31',
			'method' => 'indirect',
			'operating' => ['rows' => [['label' => 'Resultaat', 'amount' => 40000.0], ['label' => 'Afschrijvingen', 'amount' => 5000.0]], 'total' => 45000.0],
			'investing' => ['rows' => [['label' => '0100 Inventaris', 'amount' => -12000.0]], 'total' => -12000.0],
			'financing' => ['rows' => [['label' => '0700 Lening', 'amount' => -8000.0]], 'total' => -8000.0],
			'unclassified' => ['rows' => [], 'total' => 0.0],
			'cash' => ['opening' => 20000.0, 'change' => 25000.0, 'closing' => 45000.0],
			'totals' => ['operating' => 45000.0, 'investing' => -12000.0, 'financing' => -8000.0, 'unclassified' => 0.0, 'netCashFlow' => 25000.0, 'cashChange' => 25000.0, 'difference' => 0.0],
		];
	}

	/**
	 * Build the document over a register and the given cash flow calculator.
	 *
	 * @param CashFlowCalculator|null       $calculator The calculator, or null when it cannot be resolved.
	 * @param InMemoryObjectServiceStub     $objects    The register.
	 *
	 * @return array<int, array<string, mixed>> The document's blocks.
	 */
	private function build(?CashFlowCalculator $calculator, InMemoryObjectServiceStub $objects): array {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		$record = new CashFlowStatementRecord(new RegisterBinding(objectService: $objects, appConfig: $config), $this->createMock(LoggerInterface::class));
		$objects->setSchema('FinancialStatement');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $calculator, $record): object {
				return match (true) {
					$id === 'OCA\\OpenRegister\\Service\\ObjectService' => $objects,
					$id === CashFlowCalculator::class && $calculator !== null => $calculator,
					$id === CashFlowStatementRecord::class => $record,
					default => throw new RuntimeException('no ' . $id),
				};
			}
		);

		$section = new ReportSection();
		(new ReflectionMethod(AnnualAccountsReportGenerator::class, 'build'))->invoke(new AnnualAccountsReportGenerator($container), $section, ['administrationId' => 'adm-1']);

		return $section->toArray();
	}

	/**
	 * The blocks from the kasstroomoverzicht heading up to the next heading.
	 *
	 * @param array<int, array<string, mixed>> $blocks The document.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function cashFlowBlocks(array $blocks): array {
		$out = [];
		$inside = false;
		foreach ($blocks as $block) {
			if (($block['type'] ?? '') === 'heading') {
				$inside = ($block['text'] === 'Kasstroomoverzicht');
				continue;
			}

			if ($inside === true && ($block['type'] ?? '') === 'amountTable') {
				$out[] = $block;
			}
		}

		return $out;
	}

	/**
	 * The document's kasstroomoverzicht carries the page's figures for the
	 * year, and a CashFlowStatement with method indirect is stored for the
	 * year's annual report.
	 *
	 * @return void
	 */
	public function testTheAnnualAccountsCarryAndKeepThePagesCashFlow(): void {
		$calculator = $this->getMockBuilder(CashFlowCalculator::class)->disableOriginalConstructor()->getMock();
		$calculator->expects(self::once())->method('between')->with('adm-1', '2025-01-01', '2025-12-31')->willReturn(self::flow());
		$objects = new InMemoryObjectServiceStub([
			'FinancialStatement' => [['id' => 'fs-1', 'administrationId' => 'adm-1', 'currency' => 'EUR', 'reportingPeriodStart' => '2025-01-01', 'reportingPeriodEnd' => '2025-12-31']],
			'AnnualReport' => [['id' => 'ar-2025', 'administrationId' => 'adm-1', 'financialYearEnd' => '2025-12-31']],
		]);

		$tables = self::cashFlowBlocks($this->build($calculator, $objects));

		self::assertSame(
			[
				['Kasstroom uit operationele activiteiten', 45000.0],
				['Kasstroom uit investeringsactiviteiten', -12000.0],
				['Kasstroom uit financieringsactiviteiten', -8000.0],
				['Aansluiting liquide middelen', 25000.0],
			],
			array_map(static fn (array $table): array => [$table['labelHead'], $table['total']['amount']], $tables)
		);
		self::assertSame([['label' => 'Resultaat', 'amount' => 40000.0], ['label' => 'Afschrijvingen', 'amount' => 5000.0]], $tables[0]['lines']);

		$kept = $objects->setSchema('CashFlowStatement')->findAll();
		self::assertCount(1, $kept);
		self::assertSame(
			['ar-2025', '2025-12-31', 'indirect', 45000.0, -12000.0, -8000.0, 25000.0],
			[$kept[0]['reportId'], $kept[0]['cashFlowDate'], $kept[0]['method'], $kept[0]['operationalCashFlow'], $kept[0]['investmentCashFlow'], $kept[0]['financingCashFlow'], $kept[0]['netMovementCashEquivalents']]
		);
	}

	/**
	 * A statement with an unclassified movement lists it, and a difference is shown, never hidden.
	 *
	 * @return void
	 */
	public function testUnclassifiedLinesAndADifferenceAreShown(): void {
		$flow = self::flow();
		$flow['unclassified'] = ['rows' => [['label' => '1900 Overig', 'amount' => 500.0]], 'total' => 500.0];
		$flow['totals']['unclassified'] = 500.0;
		$flow['totals']['difference'] = 0.01;
		$calculator = $this->getMockBuilder(CashFlowCalculator::class)->disableOriginalConstructor()->getMock();
		$calculator->method('between')->willReturn($flow);
		$objects = new InMemoryObjectServiceStub([
			'FinancialStatement' => [['id' => 'fs-1', 'currency' => 'EUR', 'reportingPeriodStart' => '2025-01-01', 'reportingPeriodEnd' => '2025-12-31']],
		]);

		$tables = self::cashFlowBlocks($this->build($calculator, $objects));

		self::assertSame('Niet ingedeeld', $tables[3]['labelHead']);
		self::assertSame([['label' => '1900 Overig', 'amount' => 500.0]], $tables[3]['lines']);
		self::assertSame(['label' => 'Verschil', 'amount' => 0.01], $tables[4]['lines'][3]);
		self::assertSame([], $objects->setSchema('CashFlowStatement')->findAll(), 'no annual report, so nothing is kept');
	}

	/**
	 * A failing calculation says so in the document.
	 *
	 * @return void
	 */
	public function testAFailingCalculationIsNoted(): void {
		$calculator = $this->getMockBuilder(CashFlowCalculator::class)->disableOriginalConstructor()->getMock();
		$calculator->method('between')->willThrowException(new RuntimeException('register down'));
		$objects = new InMemoryObjectServiceStub([
			'FinancialStatement' => [['id' => 'fs-1', 'reportingPeriodStart' => '2025-01-01', 'reportingPeriodEnd' => '2025-12-31']],
		]);

		$blocks = $this->build($calculator, $objects);
		$at = array_search(['type' => 'heading', 'text' => 'Kasstroomoverzicht', 'depth' => 2], $blocks, true);

		self::assertIsInt($at);
		self::assertSame(['type' => 'note', 'text' => 'Het kasstroomoverzicht kon niet worden berekend.'], $blocks[$at + 1]);
	}

	/**
	 * Without a reporting period there is no cash flow to show, and the calculator is not asked.
	 *
	 * @return void
	 */
	public function testNoPeriodNoSection(): void {
		$calculator = $this->getMockBuilder(CashFlowCalculator::class)->disableOriginalConstructor()->getMock();
		$calculator->expects(self::never())->method('between');
		$objects = new InMemoryObjectServiceStub(['FinancialStatement' => [['id' => 'fs-1', 'reportingPeriodEnd' => '2025-12-31']]]);

		self::assertSame([], self::cashFlowBlocks($this->build($calculator, $objects)));
		self::assertNotContains(['type' => 'heading', 'text' => 'Kasstroomoverzicht', 'depth' => 2], $this->build($calculator, $objects));
	}
}
