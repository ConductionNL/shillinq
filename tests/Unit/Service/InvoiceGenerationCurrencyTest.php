<?php

/**
 * Unit tests for the invoice currency of InvoiceGenerationService: taken
 * from the customer, else the administration's functional currency, else
 * EUR, and never from another administration's customer (REQ-SID-004).
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
 * @spec openspec/changes/sales-invoice-document/specs/sales-invoice-document/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\BillingModelEngine;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RetainerResolver;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Service\VATCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The generator over an in-memory register with real collaborators.
 */
final class InvoiceGenerationCurrencyTest extends TestCase {
	/**
	 * The generator over the given customers and administrations.
	 *
	 * @param array<int,array<string,mixed>> $customers       CustomerMaster rows.
	 * @param array<int,array<string,mixed>> $administrations Administration rows.
	 *
	 * @return InvoiceGenerationService
	 */
	private function service(array $customers, array $administrations = []): InvoiceGenerationService {
		$store = new InMemoryObjectServiceStub(
			[
				'CustomerMaster' => $customers,
				'Administration' => $administrations,
				'UrenRegistratie' => [
					['id' => 'te-1', 'administrationId' => 'adm-1', 'personId' => 'user-a', 'projectId' => 'p-1', 'hours' => 40, 'date' => '2026-03-10', 'resourceType' => 'consultant'],
				],
				// The hour is priced at the blended EUR 100 of the assignment's rate card.
				'ProjectAssignment' => [
					['id' => 'pa-1', 'administrationId' => 'adm-1', 'personId' => 'user-a', 'projectId' => 'p-1', 'rateCardId' => 'rct-1', 'startDate' => '2026-01-01'],
				],
				'RateCardTemplate' => [['id' => 'rct-1', 'administrationId' => 'adm-1']],
				'RateCardVersion' => [['id' => 'rcv-1', 'templateId' => 'rct-1', 'status' => 'active', 'effectiveDate' => '2026-01-01', 'administrationId' => 'adm-1']],
				'RateSchedule' => [['id' => 'rs-1', 'versionId' => 'rcv-1', 'tier' => 'blended', 'rate' => 100, 'unit' => 'hourly', 'status' => 'active', 'effectiveDate' => '2026-01-01', 'administrationId' => 'adm-1']],
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$logger = new NullLogger();

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
			billableRates: new BillableRateService($store, $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']), $this->createMock(ITimeFactory::class)),
		);

	}//end service()

	/**
	 * Forty hours for the customer, from logged time, at the assignment's rate card rate.
	 *
	 * @return InvoiceGenerationRequest
	 */
	private static function request(): InvoiceGenerationRequest {
		return new InvoiceGenerationRequest(
			administrationId: 'adm-1',
			billingModel: 't_and_m',
			customerId: 'cust-brightside',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			timeEntryIds: ['te-1'],
		);

	}//end request()

	/**
	 * A GBP customer gets a GBP invoice and summary, as read back from the register.
	 *
	 * @return void
	 */
	public function testAPoundCustomerGetsAPoundInvoice(): void {
		$customer = ['id' => 'cust-brightside', 'administrationId' => 'adm-1', 'legalName' => 'Brightside Consulting Ltd', 'currency' => 'gbp'];
		$invoice  = $this->service([$customer])->draftInvoice(self::request());

		$this->assertSame('GBP', $invoice['currency']);
		$this->assertSame('GBP', $invoice['summary']['currency']);
		$this->assertSame(4000.0, $invoice['netAmount']);

	}//end testAPoundCustomerGetsAPoundInvoice()

	/**
	 * Without a customer currency the administration's functional currency applies.
	 *
	 * @return void
	 */
	public function testWithoutACustomerCurrencyTheAdministrationsApplies(): void {
		$invoice = $this->service(
			[['id' => 'cust-brightside', 'administrationId' => 'adm-1', 'legalName' => 'Brightside Consulting Ltd']],
			[['id' => 'adm-1', 'functionalCurrency' => 'CHF']]
		)->draftInvoice(self::request());

		$this->assertSame('CHF', $invoice['currency']);

	}//end testWithoutACustomerCurrencyTheAdministrationsApplies()

	/**
	 * With neither the invoice is in EUR, and another administration's customer never sets it.
	 *
	 * @return void
	 */
	public function testOtherwiseEurAndNeverAnotherAdministrationsCustomer(): void {
		$invoice = $this->service([['id' => 'cust-brightside', 'administrationId' => 'adm-2', 'currency' => 'GBP']])
			->draftInvoice(self::request());

		$this->assertSame('EUR', $invoice['currency']);
		$this->assertSame('EUR', $invoice['summary']['currency']);

	}//end testOtherwiseEurAndNeverAnotherAdministrationsCustomer()
}//end class
