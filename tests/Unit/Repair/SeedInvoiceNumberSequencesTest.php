<?php

/**
 * Unit tests for SeedInvoiceNumberSequences.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use DateTimeImmutable;
use OCA\Shillinq\Repair\SeedInvoiceNumberSequences;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Lock\ILockingProvider;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SIIC-001 migration: each series starts after the numbers already in use.
 *
 * @covers \OCA\Shillinq\Repair\SeedInvoiceNumberSequences
 * @uses   \OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService
 * @uses   \OCA\Shillinq\Service\Sales\InvoiceNumberPattern
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class SeedInvoiceNumberSequencesTest extends TestCase {
	/**
	 * Run the step over the given records on 1 October 2026.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data   The records per schema.
	 * @param string                                          $output Receives the step's info line.
	 *
	 * @return InMemoryObjectServiceStub The store after the run.
	 */
	private function runStep(array $data, string &$output = ''): InMemoryObjectServiceStub {
		$store = new InMemoryObjectServiceStub(data: $data);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$sequences = new InvoiceNumberSequenceService(
			objectService: $store,
			registerLocation: $location,
			locks: $this->createMock(ILockingProvider::class),
			lockPause: 0
		);

		$io = $this->createMock(IOutput::class);
		$io->method('info')->willReturnCallback(static function (string $message) use (&$output): void {
			$output = $message;
		});
		$io->expects($this->never())->method('warning');

		(new SeedInvoiceNumberSequences(
			sequences: $sequences,
			objectService: $store,
			registerLocation: $location,
			logger: $this->createMock(LoggerInterface::class),
			today: new DateTimeImmutable('2026-10-01')
		))->run($io);

		return $store;
	}//end runStep()

	/**
	 * The series rows the store holds after the run, keyed `<administration>/<kind>`.
	 *
	 * @param InMemoryObjectServiceStub $store The store.
	 *
	 * @return array<string, array{int, int}> Fiscal year and last value per series.
	 */
	private function series(InMemoryObjectServiceStub $store): array {
		$series = [];
		foreach ($store->setSchema('InvoiceNumberSequence')->findAll([]) as $row) {
			$series[$row['administrationId'] . '/' . $row['kind']] = [$row['fiscalYear'], $row['lastValue']];
		}

		ksort($series);
		return $series;
	}//end series()

	/**
	 * Sales starts after the highest issued number of this year; drafts, other years and typed numbers do not count.
	 *
	 * @return void
	 */
	public function testEachSeriesStartsAfterTheHighestNumberInUse(): void {
		$output = '';
		$store = $this->runStep(
			data: [
				'Administration' => [['id' => 'adm-vd'], ['id' => 'adm-empty']],
				'ARInvoice' => [
					['id' => 'a1', 'administrationId' => 'adm-vd', 'invoiceNumber' => '2026-0041', 'lifecycleState' => 'paid'],
					['id' => 'a2', 'administrationId' => 'adm-vd', 'invoiceNumber' => '2026-0040', 'lifecycleState' => 'issued'],
					['id' => 'a3', 'administrationId' => 'adm-vd', 'invoiceNumber' => '2026-0090', 'lifecycleState' => 'draft'],
					['id' => 'a4', 'administrationId' => 'adm-vd', 'invoiceNumber' => '2025-0300', 'lifecycleState' => 'issued'],
					['id' => 'a5', 'administrationId' => 'adm-vd', 'invoiceNumber' => 'F-77', 'lifecycleState' => 'issued'],
					['id' => 'a6', 'administrationId' => 'adm-empty', 'invoiceNumber' => '2026-0500', 'lifecycleState' => 'draft'],
				],
				'BillableInvoice' => [
					['id' => 'b1', 'administrationId' => 'adm-vd', 'invoiceNumber' => 'BIL-2026-0007', 'lifecycleState' => 'draft'],
					['id' => 'b2', 'administrationId' => 'adm-vd', 'invoiceNumber' => 'BIL-2026-0003'],
				],
			],
			output: $output
		);

		$this->assertSame(
			['adm-empty/billable' => [2026, 0], 'adm-empty/sales' => [2026, 0], 'adm-vd/billable' => [2026, 7], 'adm-vd/sales' => [2026, 41]],
			$this->series(store: $store)
		);
		$this->assertSame('Shillinq: 4 invoice number series started or raised for 2 administration(s).', $output);
	}//end testEachSeriesStartsAfterTheHighestNumberInUse()

	/**
	 * A second run changes nothing, and a series already ahead is not lowered.
	 *
	 * @return void
	 */
	public function testARerunNeverLowersASeries(): void {
		$output = '';
		$store = $this->runStep(
			data: [
				'Administration' => [['id' => 'adm-vd']],
				'ARInvoice' => [['id' => 'a1', 'administrationId' => 'adm-vd', 'invoiceNumber' => '2026-0041', 'lifecycleState' => 'issued']],
				'InvoiceNumberSequence' => [
					['id' => 's1', 'administrationId' => 'adm-vd', 'kind' => 'sales', 'pattern' => '{year}-{seq:4}', 'fiscalYear' => 2026, 'lastValue' => 45],
					['id' => 's2', 'administrationId' => 'adm-vd', 'kind' => 'billable', 'pattern' => 'BIL-{year}-{seq:4}', 'fiscalYear' => 2026, 'lastValue' => 0],
				],
			],
			output: $output
		);

		$this->assertSame(['adm-vd/billable' => [2026, 0], 'adm-vd/sales' => [2026, 45]], $this->series(store: $store));
		$this->assertSame([], $store->saved);
		$this->assertSame('Shillinq: 0 invoice number series started or raised for 1 administration(s).', $output);
	}//end testARerunNeverLowersASeries()
}//end class
