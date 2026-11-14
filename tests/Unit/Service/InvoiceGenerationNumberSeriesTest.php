<?php

/**
 * Unit tests for the invoice number of InvoiceGenerationService: a
 * time-and-expense invoice takes the next number of the administration's
 * billable series (REQ-SIIC-001), not a count of its invoices.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\BillingModelEngine;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\RetainerResolver;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Service\VATCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The generator over an in-memory register with a real series.
 */
final class InvoiceGenerationNumberSeriesTest extends TestCase {
	/**
	 * The generator, with the billable series of adm-1 for 2026 at the given value.
	 *
	 * @param int $lastValue The series' last value.
	 *
	 * @return InvoiceGenerationService
	 */
	private function service(int $lastValue): InvoiceGenerationService {
		$store = new InMemoryObjectServiceStub(
			[
				'CustomerMaster' => [['id' => 'cust-1', 'administrationId' => 'adm-1', 'legalName' => 'Bakkerij Jansen']],
				'UrenRegistratie' => [
					['id' => 'te-1', 'administrationId' => 'adm-1', 'hours' => 8, 'date' => '2026-03-10', 'resourceType' => 'consultant'],
					['id' => 'te-2', 'administrationId' => 'adm-1', 'hours' => 4, 'date' => '2026-03-11', 'resourceType' => 'consultant'],
				],
				// Earlier invoices: a count would give BIL-2026-0003.
				'BillableInvoice' => [
					['id' => 'b-1', 'administrationId' => 'adm-1', 'invoiceNumber' => 'BIL-2026-0011'],
					['id' => 'b-2', 'administrationId' => 'adm-1', 'invoiceNumber' => 'BIL-2026-0012'],
				],
				'InvoiceNumberSequence' => [
					['id' => 'seq-b', 'administrationId' => 'adm-1', 'kind' => 'billable', 'pattern' => 'BIL-{year}-{seq:4}', 'fiscalYear' => 2026, 'lastValue' => $lastValue],
				],
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$logger = new NullLogger();
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new InvoiceGenerationService(
			$appConfig,
			$logger,
			new RateCardResolver($container, $appConfig, $logger),
			new RetainerResolver($container, $appConfig, $logger),
			new BillingModelEngine(),
			new InvoiceDeduplicationService($container, $appConfig, $logger),
			new VATCalculationService(),
			new UsageRatingCalculator(),
			objectService: $store,
			billedReadings: $this->createMock(BilledReadings::class),
			sequences: new InvoiceNumberSequenceService(objectService: $store, registerLocation: $location, locks: $this->createMock(ILockingProvider::class), lockPause: 0),
		);
	}//end service()

	/**
	 * Hours of the given entry for the customer, in March 2026.
	 *
	 * @param string $timeEntryId The time entry.
	 *
	 * @return InvoiceGenerationRequest
	 */
	private static function request(string $timeEntryId): InvoiceGenerationRequest {
		return new InvoiceGenerationRequest(
			administrationId: 'adm-1',
			billingModel: 't_and_m',
			customerId: 'cust-1',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			timeEntryIds: [$timeEntryId],
			rateCardId: 'rc-none',
		);
	}//end request()

	/**
	 * Two invoices generated one after the other take the next two numbers of the series.
	 *
	 * @return void
	 */
	public function testGeneratedInvoicesTakeTheNextNumbersOfTheBillableSeries(): void {
		$service = $this->service(lastValue: 12);

		$this->assertSame('BIL-2026-0013', $service->draftInvoice(self::request(timeEntryId: 'te-1'))['invoiceNumber']);
		$this->assertSame('BIL-2026-0014', $service->draftInvoice(self::request(timeEntryId: 'te-2'))['invoiceNumber']);
	}//end testGeneratedInvoicesTakeTheNextNumbersOfTheBillableSeries()
}//end class
