<?php

/**
 * Tests for InvoiceIngestService: a sibling app's month becomes a draft invoice.
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
 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Event\InvoiceIngestRequestedEvent;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\InvoiceIngestService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-UMB-005 scenarios on the real event and an in-memory register.
 */
final class InvoiceIngestServiceTest extends TestCase {

	/**
	 * Requests the fake draftInvoice() received.
	 *
	 * @var array<int, InvoiceGenerationRequest>
	 */
	private array $drafted = [];

	/**
	 * The invoice generator double.
	 *
	 * @var InvoiceGenerationService&MockObject
	 */
	private InvoiceGenerationService&MockObject $invoices;

	/**
	 * Build the generator double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->drafted  = [];
		$this->invoices = $this->createMock(InvoiceGenerationService::class);
		$this->invoices->method('draftInvoice')->willReturnCallback(
			function (InvoiceGenerationRequest $request): array {
				$this->drafted[] = $request;
				return ['id' => 'inv-' . count($this->drafted), 'invoiceNumber' => 'BIL-2026-000' . count($this->drafted)];
			}
		);
	}//end setUp()

	/**
	 * The service over a register seeded with the given rows.
	 *
	 * @param InMemoryObjectServiceStub $store The register.
	 *
	 * @return InvoiceIngestService
	 */
	private function service(InMemoryObjectServiceStub $store): InvoiceIngestService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new InvoiceIngestService(
			objectService: $store,
			invoices: $this->invoices,
			settings: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * A register with one customer carrying tenant t-42.
	 *
	 * @return InMemoryObjectServiceStub
	 */
	private function storeWithCustomer(): InMemoryObjectServiceStub {
		return new InMemoryObjectServiceStub(
			data: [
				'CustomerMaster' => [
					['id' => 'cm-1', 'customerId' => 'cust-9001', 'administrationId' => 'adm-1', 'externalReference' => 't-42'],
					['id' => 'cm-2', 'customerId' => 'cust-9002', 'administrationId' => 'adm-1', 'externalReference' => 't-other'],
				],
			]
		);
	}//end storeWithCustomer()

	/**
	 * A month of two priced lines for t-42.
	 *
	 * @param array<int, array<string, mixed>>|null $lines Lines to use instead.
	 *
	 * @return InvoiceIngestRequestedEvent
	 */
	private function event(?array $lines=null, string $reference='t-42'): InvoiceIngestRequestedEvent {
		return new InvoiceIngestRequestedEvent(
			sourceApp: 'dossiq',
			externalReference: $reference,
			period: '2026-09',
			lines: $lines ?? [
				['description' => 'case.created', 'quantity' => 12, 'unitPrice' => 0.5],
				['description' => 'storage.gb', 'quantity' => 3, 'unitPrice' => 2.25],
			],
		);
	}//end event()

	/**
	 * A tenant month becomes one usage invoice for the customer carrying the reference.
	 *
	 * @return void
	 */
	public function testATenantMonthBecomesADraftInvoice(): void {
		$store = $this->storeWithCustomer();
		$event = $this->event();

		$this->service(store: $store)->ingest(event: $event);

		$this->assertTrue($event->isHandled(), (string)$event->getError());
		$this->assertSame('inv-1', $event->getResult()['invoiceId']);
		$this->assertFalse($event->getResult()['duplicated']);
		$this->assertCount(1, $this->drafted);
		$request = $this->drafted[0];
		$this->assertSame('usage', $request->billingModel);
		$this->assertSame('cust-9001', $request->customerId);
		$this->assertSame('adm-1', $request->administrationId);
		$this->assertSame('2026-09-01', $request->fromDate);
		$this->assertSame('2026-09-30', $request->toDate);
		$this->assertCount(2, $request->meterReadingIds);

		$readings = $store->setSchema('MeterReading')->findAll();
		$this->assertCount(2, $readings);
		$this->assertSame('rated', $readings[0]['status']);
		$this->assertSame('cust-9001', $readings[0]['customerId']);

		$plans = $store->setSchema('UsageRatePlan')->findAll();
		$this->assertSame([50, 225], array_column($plans, 'unitPriceCents'));
		$this->assertSame(['flat', 'flat'], array_column($plans, 'ratingMethod'));

		$batches = $store->setSchema('TimeIntakeBatch')->findAll();
		$this->assertSame('dossiq:t-42:2026-09', $batches[0]['batchId']);
		$this->assertSame('inv-1', $batches[0]['invoiceId']);
	}//end testATenantMonthBecomesADraftInvoice()

	/**
	 * The same month twice answers with the same invoice and drafts nothing new.
	 *
	 * @return void
	 */
	public function testTheSameMonthTwiceAnswersWithTheSameInvoice(): void {
		$store = $this->storeWithCustomer();
		$store->setSchema('BillableInvoice')->saveObject(['id' => 'inv-1', 'invoiceNumber' => 'BIL-2026-0001']);
		$service = $this->service(store: $store);
		$service->ingest(event: $this->event());

		$again = $this->event();
		$service->ingest(event: $again);

		$this->assertTrue($again->isHandled(), (string)$again->getError());
		$this->assertTrue($again->getResult()['duplicated']);
		$this->assertSame('inv-1', $again->getResult()['invoiceId']);
		$this->assertSame('BIL-2026-0001', $again->getResult()['invoiceNumber']);
		$this->assertCount(1, $this->drafted);
		$this->assertCount(2, $store->setSchema('MeterReading')->findAll());
	}//end testTheSameMonthTwiceAnswersWithTheSameInvoice()

	/**
	 * The same month with other lines is refused rather than billed twice.
	 *
	 * @return void
	 */
	public function testTheSameMonthWithOtherLinesIsRefused(): void {
		$store   = $this->storeWithCustomer();
		$service = $this->service(store: $store);
		$service->ingest(event: $this->event());

		$changed = $this->event(lines: [['description' => 'case.created', 'quantity' => 13, 'unitPrice' => 0.5]]);
		$service->ingest(event: $changed);

		$this->assertFalse($changed->isHandled());
		$this->assertStringContainsString('different lines', (string)$changed->getError());
		$this->assertCount(1, $this->drafted);
	}//end testTheSameMonthWithOtherLinesIsRefused()

	/**
	 * No customer carries the tenant: refused naming the reference, nothing written.
	 *
	 * @return void
	 */
	public function testNoCustomerCarriesTheTenant(): void {
		$store = $this->storeWithCustomer();
		$event = $this->event(reference: 't-77');

		$this->service(store: $store)->ingest(event: $event);

		$this->assertFalse($event->isHandled());
		$this->assertStringContainsString('"t-77"', (string)$event->getError());
		$this->assertSame([], $this->drafted);
		$this->assertSame([], $store->setSchema('MeterReading')->findAll());
		$this->assertSame([], $store->setSchema('TimeIntakeBatch')->findAll());
	}//end testNoCustomerCarriesTheTenant()

	/**
	 * Two customers carrying one reference: no single customer, refused.
	 *
	 * @return void
	 */
	public function testTwoCustomersCarryingTheReferenceAreRefused(): void {
		$store = new InMemoryObjectServiceStub(
			data: [
				'CustomerMaster' => [
					['id' => 'cm-1', 'customerId' => 'c1', 'administrationId' => 'adm-1', 'externalReference' => 't-42'],
					['id' => 'cm-2', 'customerId' => 'c2', 'administrationId' => 'adm-2', 'externalReference' => 't-42'],
				],
			]
		);
		$event = $this->event();

		$this->service(store: $store)->ingest(event: $event);

		$this->assertFalse($event->isHandled());
		$this->assertStringContainsString('2 shillinq customers', (string)$event->getError());
		$this->assertSame([], $this->drafted);
	}//end testTwoCustomersCarryingTheReferenceAreRefused()

	/**
	 * A line without a readable price refuses the whole month before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnpricedLineRefusesTheWholeMonth(): void {
		$store = $this->storeWithCustomer();
		$event = $this->event(lines: [['description' => 'case.created', 'quantity' => 2, 'unitPrice' => 0.5], ['description' => 'x', 'quantity' => 1]]);

		$this->service(store: $store)->ingest(event: $event);

		$this->assertFalse($event->isHandled());
		$this->assertSame('Line 2 has no readable quantity or unit price.', $event->getError());
		$this->assertSame([], $store->setSchema('UsageRatePlan')->findAll());
	}//end testAnUnpricedLineRefusesTheWholeMonth()

	/**
	 * A period that is not a month, and a request without lines, are refused.
	 *
	 * @return void
	 */
	public function testAMalformedRequestIsRefused(): void {
		$service = $this->service(store: $this->storeWithCustomer());
		$noMonth = new InvoiceIngestRequestedEvent(sourceApp: 'dossiq', externalReference: 't-42', period: '2026-13', lines: [['quantity' => 1, 'unitPrice' => 1]]);
		$noLines = new InvoiceIngestRequestedEvent(sourceApp: 'dossiq', externalReference: 't-42', period: '2026-09', lines: []);
		$foreign = $this->event(lines: [['description' => 'a', 'quantity' => 1, 'unitPrice' => 1, 'currency' => 'USD']]);

		$service->ingest(event: $noMonth);
		$service->ingest(event: $noLines);
		$service->ingest(event: $foreign);

		$this->assertStringContainsString('not a month', (string)$noMonth->getError());
		$this->assertSame('The invoice request carries no lines.', $noLines->getError());
		$this->assertStringContainsString('EUR only', (string)$foreign->getError());
	}//end testAMalformedRequestIsRefused()

	/**
	 * An existing flat plan at the same price is reused, not duplicated.
	 *
	 * @return void
	 */
	public function testAnExistingPlanAtThatPriceIsReused(): void {
		$store = $this->storeWithCustomer();
		$store->setSchema('UsageRatePlan')->saveObject(
			['id' => 'plan-x', 'administrationId' => 'adm-1', 'resourceType' => 'case.created', 'ratingMethod' => 'flat', 'unitPriceCents' => 50]
		);

		$this->service(store: $store)->ingest(event: $this->event());

		$this->assertCount(2, $store->setSchema('UsageRatePlan')->findAll());
		$this->assertSame('plan-x', $store->setSchema('MeterReading')->findAll()[0]['ratePlanId']);
	}//end testAnExistingPlanAtThatPriceIsReused()

	/**
	 * A failing draft is refused on the event, not thrown at the asking app.
	 *
	 * @return void
	 */
	public function testAFailingDraftIsRefusedNotThrown(): void {
		$invoices = $this->createMock(InvoiceGenerationService::class);
		$invoices->method('draftInvoice')->willThrowException(new RuntimeException('VAT table missing'));
		$this->invoices = $invoices;
		$event = $this->event();

		$this->service(store: $this->storeWithCustomer())->ingest(event: $event);

		$this->assertFalse($event->isHandled());
		$this->assertStringContainsString('VAT table missing', (string)$event->getError());
	}//end testAFailingDraftIsRefusedNotThrown()
}//end class
