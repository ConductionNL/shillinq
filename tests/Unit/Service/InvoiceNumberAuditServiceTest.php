<?php

/**
 * Unit tests for InvoiceNumberAuditService.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\InvoiceNumberAuditService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SIIC-004: missing and duplicate numbers in a year of issued sales
 * invoices, per administration.
 *
 * @covers \OCA\Shillinq\Service\InvoiceNumberAuditService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class InvoiceNumberAuditServiceTest extends TestCase {
	/**
	 * A service over the given invoices.
	 *
	 * @param array<int, array<string, mixed>> $invoices The ARInvoice rows.
	 *
	 * @return InvoiceNumberAuditService The service.
	 */
	private function service(array $invoices): InvoiceNumberAuditService {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new InvoiceNumberAuditService(
			objectService: new InMemoryObjectServiceStub(data: ['ARInvoice' => $invoices]),
			registerLocation: $location
		);
	}//end service()

	/**
	 * An issued invoice of administration `adm-1`.
	 *
	 * @param string $number The number.
	 * @param string $state  The lifecycle state.
	 * @param string $admin  The administration.
	 *
	 * @return array<string, string> The row.
	 */
	private function invoice(string $number, string $state = 'issued', string $admin = 'adm-1'): array {
		static $id = 0;
		$id += 1;

		return ['id' => "inv-{$id}", 'invoiceNumber' => $number, 'lifecycleState' => $state, 'administrationId' => $admin];
	}//end invoice()

	/**
	 * The scenario: 2026-0001 to 2026-0043 with 2026-0017 missing.
	 *
	 * @return void
	 */
	public function testAControllerChecks2026(): void {
		$invoices = [];
		for ($sequence = 1; $sequence <= 43; $sequence++) {
			if ($sequence !== 17) {
				$invoices[] = $this->invoice(number: sprintf('2026-%04d', $sequence));
			}
		}

		$report = $this->service(invoices: $invoices)->gaps(administrationId: 'adm-1', year: 2026);

		$this->assertSame(
			['year' => 2026, 'checked' => 42, 'first' => '2026-0001', 'last' => '2026-0043', 'missing' => ['2026-0017'], 'duplicates' => [], 'outsideSeries' => []],
			$report
		);
	}//end testAControllerChecks2026()

	/**
	 * A number issued twice is a duplicate, and the gap before the first number counts.
	 *
	 * @return void
	 */
	public function testDuplicatesAndAMissingStartAreListed(): void {
		$report = $this->service(
			invoices: [
				$this->invoice(number: '2026-0003', state: 'paid'),
				$this->invoice(number: '2026-0004', state: 'overdue'),
				$this->invoice(number: '2026-0004', state: 'disputed'),
				$this->invoice(number: ' 2026-0005 ', state: 'written-off'),
			]
		)->gaps(administrationId: 'adm-1', year: 2026);

		$this->assertSame(['2026-0001', '2026-0002'], $report['missing']);
		$this->assertSame(['2026-0004'], $report['duplicates']);
		$this->assertSame([4, '2026-0003', '2026-0005'], [$report['checked'], $report['first'], $report['last']]);
	}//end testDuplicatesAndAMissingStartAreListed()

	/**
	 * Drafts, other administrations, other years and empty numbers do not count.
	 *
	 * @return void
	 */
	public function testOnlyNumberedInvoicesOfTheAdministrationAndYearCount(): void {
		$report = $this->service(
			invoices: [
				$this->invoice(number: '2026-0001'),
				$this->invoice(number: '2026-0002', state: 'draft'),
				$this->invoice(number: '2026-0002', admin: 'adm-2'),
				$this->invoice(number: '2025-0007'),
				$this->invoice(number: ''),
				$this->invoice(number: '2026-0003'),
			]
		)->gaps(administrationId: 'adm-1', year: 2026);

		$this->assertSame(['2026-0002'], $report['missing']);
		$this->assertSame([], $report['outsideSeries']);
		$this->assertSame(2, $report['checked']);
	}//end testOnlyNumberedInvoicesOfTheAdministrationAndYearCount()

	/**
	 * A number of the year that does not follow the series is listed apart.
	 *
	 * @return void
	 */
	public function testANumberOutsideTheSeriesIsListedApart(): void {
		$report = $this->service(
			invoices: [
				$this->invoice(number: '2026-0001'),
				$this->invoice(number: 'BIL-2026-9'),
				$this->invoice(number: 'BIL-2026-9'),
				$this->invoice(number: '2026-12'),
			]
		)->gaps(administrationId: 'adm-1', year: 2026);

		$this->assertSame(['2026-12', 'BIL-2026-9'], $report['outsideSeries']);
		$this->assertSame([], $report['missing']);
	}//end testANumberOutsideTheSeriesIsListedApart()

	/**
	 * A custom pattern with literal text formats and parses the series.
	 *
	 * @return void
	 */
	public function testACustomPatternIsFollowed(): void {
		$report = $this->service(
			invoices: [
				$this->invoice(number: 'VF.2026/001'),
				$this->invoice(number: 'VF.2026/003'),
				$this->invoice(number: 'VFX2026/002'),
			]
		)->gaps(administrationId: 'adm-1', year: 2026, pattern: 'VF.{year}/{seq:3}');

		$this->assertSame(['VF.2026/002'], $report['missing']);
		$this->assertSame(['VFX2026/002'], $report['outsideSeries']);
	}//end testACustomPatternIsFollowed()

	/**
	 * A year without invoices has nothing to report.
	 *
	 * @return void
	 */
	public function testAnEmptyYearReportsNothing(): void {
		$this->assertSame(
			['year' => 2027, 'checked' => 0, 'first' => null, 'last' => null, 'missing' => [], 'duplicates' => [], 'outsideSeries' => []],
			$this->service(invoices: [$this->invoice(number: '2026-0001')])->gaps(administrationId: 'adm-1', year: 2027)
		);
	}//end testAnEmptyYearReportsNothing()

	/**
	 * More invoices than one page are all read.
	 *
	 * @return void
	 */
	public function testEveryPageIsRead(): void {
		$invoices = [];
		for ($sequence = 1; $sequence <= 1001; $sequence++) {
			$invoices[] = $this->invoice(number: sprintf('2026-%04d', $sequence));
		}

		$report = $this->service(invoices: $invoices)->gaps(administrationId: 'adm-1', year: 2026);

		$this->assertSame([1001, '2026-1001', []], [$report['checked'], $report['last'], $report['missing']]);
	}//end testEveryPageIsRead()

	/**
	 * An empty administration or a pattern without one sequence is refused.
	 *
	 * @return void
	 */
	public function testABadQueryIsRefused(): void {
		$service = $this->service(invoices: []);
		$messages = [];
		foreach ([[' ', InvoiceNumberAuditService::DEFAULT_PATTERN], ['adm-1', '{year}-x'], ['adm-1', '{seq:2}-{seq:2}']] as [$admin, $pattern]) {
			try {
				$service->gaps(administrationId: $admin, year: 2026, pattern: $pattern);
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'An invoice number check needs an administration.',
				"The invoice number pattern '{year}-x' needs exactly one {seq:N}.",
				"The invoice number pattern '{seq:2}-{seq:2}' needs exactly one {seq:N}.",
			],
			$messages
		);
	}//end testABadQueryIsRefused()
}//end class
