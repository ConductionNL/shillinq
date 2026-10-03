<?php

/**
 * Unit tests for SupplierInvoiceBookingGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\SupplierInvoiceBookingGuard;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Purchasing\SupplierInvoiceChecksTest;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * REQ-PSII-002: when an invoice may be booked without an order.
 */
class SupplierInvoiceBookingGuardTest extends TestCase {

	/**
	 * The guard over the checks fixture.
	 *
	 * @return SupplierInvoiceBookingGuard
	 */
	private function guard(): SupplierInvoiceBookingGuard {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new SupplierInvoiceBookingGuard(new SupplierInvoiceChecks(new InMemoryObjectServiceStub(SupplierInvoiceChecksTest::records()), $settings));

	}//end guard()

	/**
	 * Invoice 2026-0457 without an order, one line coded to 4300.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(): array {
		return [
			'id' => 'si-0457', 'invoiceNumber' => '2026-0457', 'supplierId' => SupplierInvoiceChecksTest::DRUKKERIJ,
			'administrationId' => SupplierInvoiceChecksTest::ADMIN, 'statusCode' => 'received', 'invoiceDate' => '2026-09-01',
			'currency' => 'EUR', 'totalExclVat' => 100000, 'totalVat' => 21000, 'totalInclVat' => 121000,
			'lines' => [['lineNumber' => 1, 'description' => 'Folders', 'quantity' => 1, 'unitPrice' => 100000, 'lineExtension' => 100000, 'vatRate' => 21, 'accountNumber' => '4300']],
		];

	}//end invoice()

	/**
	 * bookWithoutOrder is declared from received, requiring this guard.
	 *
	 * @return void
	 */
	public function testTheTransitionIsDeclaredWithThisGuard(): void {
		$transition = RegisterSchema::schema('SupplierInvoice')['x-openregister-lifecycle']['transitions']['bookWithoutOrder'];

		$this->assertSame('received', $transition['from']);
		$this->assertSame('approved', $transition['to']);
		$this->assertSame(SupplierInvoiceBookingGuard::class, $transition['requires']);

	}//end testTheTransitionIsDeclaredWithThisGuard()

	/**
	 * A clean invoice without an order may be booked.
	 *
	 * @return void
	 */
	public function testACleanInvoiceMayBeBooked(): void {
		$this->assertTrue($this->guard()->check($this->invoice(), 'bookWithoutOrder', 'alice')->isAllowed());

	}//end testACleanInvoiceMayBeBooked()

	/**
	 * A line linked to a purchase order keeps the three-way match.
	 *
	 * @return void
	 */
	public function testAnOrderLinkedLineIsRefused(): void {
		$invoice = $this->invoice();
		$invoice['lines'][0]['linkedPoId'] = 'PO-2026-031';

		$result = $this->guard()->check($invoice, 'bookWithoutOrder', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('PO-2026-031', (string)$result->getMessage());

	}//end testAnOrderLinkedLineIsRefused()

	/**
	 * An unknown supplier is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownSupplierIsRefused(): void {
		$invoice = $this->invoice();
		unset($invoice['supplierId']);

		$this->assertStringContainsString('not recognised', (string)$this->guard()->check($invoice, 'bookWithoutOrder', 'alice')->getMessage());

	}//end testAnUnknownSupplierIsRefused()

	/**
	 * A repeated number needs a reason; with one it passes.
	 *
	 * @return void
	 */
	public function testADuplicateNeedsAReason(): void {
		$invoice = $this->invoice();
		$invoice['invoiceNumber'] = '2026-0455';

		$this->assertFalse($this->guard()->check($invoice, 'bookWithoutOrder', 'alice')->isAllowed());

		$invoice['duplicateAcknowledgedReason'] = 'Tweede factuur, zelfde nummer, bevestigd door leverancier';
		$this->assertTrue($this->guard()->check($invoice, 'bookWithoutOrder', 'alice')->isAllowed());

	}//end testADuplicateNeedsAReason()

	/**
	 * A different IBAN needs a reason.
	 *
	 * @return void
	 */
	public function testAnIbanMismatchNeedsAReason(): void {
		$invoice = $this->invoice();
		$invoice['payeeIban'] = 'NL02ABNA0123456789';

		$this->assertStringContainsString('IBAN', (string)$this->guard()->check($invoice, 'bookWithoutOrder', 'alice')->getMessage());

		$invoice['ibanAcknowledgedReason'] = 'Nieuwe rekening, gebeld met leverancier';
		$this->assertTrue($this->guard()->check($invoice, 'bookWithoutOrder', 'alice')->isAllowed());

	}//end testAnIbanMismatchNeedsAReason()
}//end class
