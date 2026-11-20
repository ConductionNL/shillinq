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
 * @spec openspec/changes/reporting-cash-flow-statement
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Statement;

use OCA\Shillinq\Reporting\CashFlowStatementRecord;
use OCA\Shillinq\Service\RegisterBinding;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The annual accounts keep their cash flow as a CashFlowStatement with method
 * indirect (REQ-RCF-004).
 *
 * @covers \OCA\Shillinq\Reporting\CashFlowStatementRecord
 * @uses   \OCA\Shillinq\Service\RecordStoreLog
 * @uses   \OCA\Shillinq\Service\RegisterBinding
 * @uses   \OCA\Shillinq\Service\SchemaRecordStore
 */
class CashFlowStatementRecordTest extends TestCase {

	private const ADM = 'adm-van-dijk';

	/**
	 * A 2025 cash flow as CashFlowCalculator::between() returns it.
	 *
	 * @return array<string, mixed>
	 */
	private static function flow(): array {
		return [
			'administrationId' => self::ADM,
			'from' => '2025-01-01',
			'to' => '2025-12-31',
			'method' => 'indirect',
			'operating' => ['rows' => [['label' => 'Resultaat', 'amount' => 40000.0], ['label' => '1300 Debiteuren', 'amount' => -5000.0]], 'total' => 35000.0],
			'investing' => ['rows' => [['label' => '0100 Inventaris', 'amount' => -12000.0]], 'total' => -12000.0],
			'financing' => ['rows' => [], 'total' => 0.0],
			'unclassified' => ['rows' => [['label' => '1900 Overig', 'amount' => 500.0]], 'total' => 500.0],
			'cash' => ['opening' => 1000.0, 'change' => 23500.0, 'closing' => 24500.0],
			'totals' => ['operating' => 35000.0, 'investing' => -12000.0, 'financing' => 0.0, 'unclassified' => 500.0, 'netCashFlow' => 23500.0, 'cashChange' => 23500.0, 'difference' => 0.0],
		];
	}

	/**
	 * The writer over an in-memory register holding the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows The rows per schema.
	 *
	 * @return array{0: CashFlowStatementRecord, 1: InMemoryObjectServiceStub}
	 */
	private function writer(array $rows): array {
		$objects = new InMemoryObjectServiceStub($rows);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		$writer = new CashFlowStatementRecord(
			new RegisterBinding(objectService: $objects, appConfig: $config),
			$this->createMock(LoggerInterface::class)
		);

		return [$writer, $objects];
	}

	/**
	 * The cash flow is stored for the annual report whose year ends on the
	 * statement's last day, with its totals and its operating, investing and
	 * financing rows; the unclassified rows have no rule category.
	 *
	 * @return void
	 */
	public function testTheCashFlowIsStoredForTheYearsAnnualReport(): void {
		[$writer, $objects] = $this->writer([
			'AnnualReport' => [
				['id' => 'ar-2024', 'administrationId' => self::ADM, 'financialYearEnd' => '2024-12-31'],
				['id' => 'ar-2025', 'administrationId' => self::ADM, 'financialYearEnd' => '2025-12-31'],
				['id' => 'ar-other', 'administrationId' => 'adm-other', 'financialYearEnd' => '2025-12-31'],
			],
		]);

		$stored = $writer->store(self::ADM, self::flow(), 'EUR');

		self::assertSame(
			[
				'reportId' => 'ar-2025',
				'cashFlowDate' => '2025-12-31',
				'method' => 'indirect',
				'currency' => 'EUR',
				'status' => 'draft',
				'operationalCashFlow' => 35000.0,
				'investmentCashFlow' => -12000.0,
				'financingCashFlow' => 0.0,
				'netMovementCashEquivalents' => 23500.0,
				'rules' => [
					['category' => 'operationeel', 'label' => 'Resultaat', 'amount' => 40000.0],
					['category' => 'operationeel', 'label' => '1300 Debiteuren', 'amount' => -5000.0],
					['category' => 'investment', 'label' => '0100 Inventaris', 'amount' => -12000.0],
				],
				'id' => 'obj-1',
			],
			$stored
		);
		self::assertSame(['CashFlowStatement'], array_column($objects->saved, 'schema'));
	}

	/**
	 * A draft kept earlier for the same report and date is brought up to date, keeping its id.
	 *
	 * @return void
	 */
	public function testAnEarlierDraftIsUpdated(): void {
		[$writer, $objects] = $this->writer([
			'AnnualReport' => [['id' => 'ar-2025', 'administrationId' => self::ADM, 'financialYearEnd' => '2025-12-31']],
			'CashFlowStatement' => [['id' => 'cfs-old', 'reportId' => 'ar-2025', 'cashFlowDate' => '2025-12-31', 'status' => 'draft', 'operationalCashFlow' => 1.0]],
		]);

		$stored = $writer->store(self::ADM, self::flow(), 'EUR');

		self::assertSame(['cfs-old', 35000.0], [$stored['id'], $stored['operationalCashFlow']]);
		self::assertCount(1, $objects->setSchema('CashFlowStatement')->findAll());
	}

	/**
	 * A final record is left as it was.
	 *
	 * @return void
	 */
	public function testAFinalRecordIsLeftAlone(): void {
		$final = ['id' => 'cfs-final', 'reportId' => 'ar-2025', 'cashFlowDate' => '2025-12-31', 'status' => 'final', 'operationalCashFlow' => 1.0];
		[$writer, $objects] = $this->writer([
			'AnnualReport' => [['id' => 'ar-2025', 'administrationId' => self::ADM, 'financialYearEnd' => '2025-12-31']],
			'CashFlowStatement' => [$final],
		]);

		self::assertSame($final, $writer->store(self::ADM, self::flow(), 'EUR'));
		self::assertSame([], $objects->saved);
	}

	/**
	 * Without an annual report for that year there is nothing to hang the record off.
	 *
	 * @return void
	 */
	public function testNothingIsStoredWithoutAnAnnualReport(): void {
		[$writer, $objects] = $this->writer([
			'AnnualReport' => [['id' => 'ar-2024', 'administrationId' => self::ADM, 'financialYearEnd' => '2024-12-31']],
		]);

		self::assertNull($writer->store(self::ADM, self::flow(), 'EUR'));
		self::assertSame([], $objects->saved);
	}
}
