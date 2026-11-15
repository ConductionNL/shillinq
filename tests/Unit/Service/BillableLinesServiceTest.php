<?php

/**
 * Tests for the billable lines one customer can be invoiced for.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BillableLinesService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Time entries are a customer's through their project; expense claims through
 * the customers they are passed on to. Both stay inside one administration.
 */
final class BillableLinesServiceTest extends TestCase {

	/**
	 * The service over a store with two customers in one administration and a
	 * second administration.
	 *
	 * @return BillableLinesService
	 */
	private function service(): BillableLinesService {
		$store = new InMemoryObjectServiceStub(
			data: [
				'engagement' => [
					['id' => 'p-a1', 'administrationId' => 'adm-1', 'customerId' => 'cust-a'],
					['id' => 'p-a2', 'administrationId' => 'adm-1', 'customerId' => 'cust-a'],
					['id' => 'p-b', 'administrationId' => 'adm-1', 'customerId' => 'cust-b'],
					['id' => 'p-x', 'administrationId' => 'adm-2', 'customerId' => 'cust-a'],
				],
				'UrenRegistratie' => [
					['id' => 't-late', 'administrationId' => 'adm-1', 'projectId' => 'p-a1', 'date' => '2026-09-20'],
					['id' => 't-early', 'administrationId' => 'adm-1', 'projectId' => 'p-a2', 'date' => '2026-09-02'],
					['id' => 't-other-customer', 'administrationId' => 'adm-1', 'projectId' => 'p-b', 'date' => '2026-09-05'],
					['id' => 't-other-admin', 'administrationId' => 'adm-2', 'projectId' => 'p-x', 'date' => '2026-09-05'],
					['id' => 't-out-of-period', 'administrationId' => 'adm-1', 'projectId' => 'p-a1', 'date' => '2026-10-01'],
					['id' => 't-no-project', 'administrationId' => 'adm-1', 'date' => '2026-09-06'],
				],
				'ExpenseClaimEntry' => [
					['id' => 'c-a', 'administrationId' => 'adm-1', 'passThroughCustomerIds' => ['cust-a'], 'submittedDate' => '2026-09-14'],
					['id' => 'c-b', 'administrationId' => 'adm-1', 'passThroughCustomerIds' => ['cust-b'], 'submittedDate' => '2026-09-14'],
					['id' => 'c-none', 'administrationId' => 'adm-1', 'submittedDate' => '2026-09-14'],
					['id' => 'c-other-admin', 'administrationId' => 'adm-2', 'passThroughCustomerIds' => ['cust-a'], 'submittedDate' => '2026-09-14'],
					['id' => 'c-late', 'administrationId' => 'adm-1', 'passThroughCustomerIds' => ['cust-a'], 'submittedDate' => '2026-08-01'],
				],
			]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? 'shillinq' : $default
		);

		return new BillableLinesService(objectService: $store, appConfig: $appConfig);
	}//end service()

	/**
	 * Only the customer's hours and passed-on claims of the period, inside the
	 * administration, are offered; hours oldest first.
	 *
	 * @return void
	 */
	public function testOffersOnlyTheCustomersLinesInTheAdministration(): void {
		$lines = $this->service()->forCustomer(
			administrationId: 'adm-1',
			customerId: 'cust-a',
			projectId: '',
			fromDate: '2026-09-01',
			toDate: '2026-09-30'
		);

		self::assertSame(['t-early', 't-late'], array_column($lines['timeEntries'], 'id'));
		self::assertSame(['c-a'], array_column($lines['expenses'], 'id'));
	}//end testOffersOnlyTheCustomersLinesInTheAdministration()

	/**
	 * A project narrows the hours; a project of another customer offers none.
	 *
	 * @return void
	 */
	public function testAProjectNarrowsAndAForeignProjectOffersNothing(): void {
		$service = $this->service();

		$own = $service->forCustomer(administrationId: 'adm-1', customerId: 'cust-a', projectId: 'p-a1', fromDate: '2026-09-01', toDate: '2026-09-30');
		self::assertSame(['t-late'], array_column($own['timeEntries'], 'id'));

		$foreign = $service->forCustomer(administrationId: 'adm-1', customerId: 'cust-a', projectId: 'p-b', fromDate: '2026-09-01', toDate: '2026-09-30');
		self::assertSame([], $foreign['timeEntries']);
	}//end testAProjectNarrowsAndAForeignProjectOffersNothing()

	/**
	 * Ids that are not the customer's billable lines in the administration are
	 * named; the customer's own are not.
	 *
	 * @return void
	 */
	public function testNamesTheLinesThatAreNotTheCustomers(): void {
		$foreign = $this->service()->foreignLineIds(
			administrationId: 'adm-1',
			customerId: 'cust-a',
			timeEntryIds: ['t-late', 't-other-customer', 't-other-admin', 't-no-project', 't-unknown'],
			expenseIds: ['c-a', 'c-b', 'c-none', 'c-other-admin']
		);

		self::assertSame(
			['t-other-customer', 't-other-admin', 't-no-project', 't-unknown', 'c-b', 'c-none', 'c-other-admin'],
			$foreign
		);
	}//end testNamesTheLinesThatAreNotTheCustomers()

	/**
	 * No customer, no lines.
	 *
	 * @return void
	 */
	public function testWithoutACustomerNothingIsOffered(): void {
		$lines = $this->service()->forCustomer(administrationId: 'adm-1', customerId: '', projectId: '', fromDate: '2026-09-01', toDate: '2026-09-30');

		self::assertSame(['timeEntries' => [], 'expenses' => []], $lines);
	}//end testWithoutACustomerNothingIsOffered()
}//end class
