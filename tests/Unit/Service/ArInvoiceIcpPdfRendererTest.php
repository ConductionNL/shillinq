<?php

/**
 * Unit tests for ArInvoiceIcpPdfRenderer (REQ-ICP-007).
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\ArInvoiceIcpPdfRenderer;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ICP invoice PDF renderer end-to-end.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ArInvoiceIcpPdfRendererTest extends TestCase {

	/**
	 * Mock IL10N.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * The renderer under test.
	 *
	 * @var ArInvoiceIcpPdfRenderer
	 */
	private ArInvoiceIcpPdfRenderer $renderer;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $s, array $params = []): string => $s
		);
		$this->l10n->method('getLanguageCode')->willReturn('en');
		$this->renderer = new ArInvoiceIcpPdfRenderer(l10n: $this->l10n);

	}//end setUp()

	/**
	 * Rendering an invoice with treatAsIcp=false MUST omit the reverse-charge
	 * overlay but still return a complete document.
	 *
	 * @return void
	 */
	public function testRenderNonIcpInvoiceOmitsOverlay(): void {
		$invoice = [
			'invoiceNumber' => 'INV-2026-0001',
			'customerId' => 'cust-1',
			'invoiceDate' => '2026-06-15',
			'dueDate' => '2026-07-15',
			'totalAmount' => 1000.0,
			'icpContext' => ['treatAsIcp' => false],
		];

		$payload = $this->renderer->render(
			invoice: $invoice,
			customer: ['name' => 'Klant BV', 'vatNumber' => 'NL123456789B01'],
			seller: ['legalName' => 'Shillinq BV', 'vatId' => 'NL0987654321']
		);

		self::assertSame('arinvoice-INV-2026-0001.pdf', $payload['filename']);
		self::assertSame('application/pdf', $payload['mimeType']);
		self::assertStringNotContainsStringIgnoringCase('reverse-charged', $payload['html']);
		self::assertFalse($payload['icp']['treatAsIcp']);

	}//end testRenderNonIcpInvoiceOmitsOverlay()

	/**
	 * Rendering an ICP-treated invoice (goods) MUST include the reverse-charge
	 * notice, buyer VAT-ID, seller VAT-ID, and supply-type label (REQ-ICP-007).
	 *
	 * @return void
	 */
	public function testRenderIcpGoodsInvoiceIncludesReverseChargeNotice(): void {
		$invoice = [
			'invoiceNumber' => 'INV-2026-0042',
			'customerId' => 'cust-be-1',
			'invoiceDate' => '2026-06-20',
			'dueDate' => '2026-07-20',
			'totalAmount' => 25000.0,
			'icpContext' => [
				'treatAsIcp' => true,
				'supplyType' => 'L',
				'viesValidationId' => 'vies-2026-be-1',
				'triangulation' => false,
			],
		];

		$payload = $this->renderer->render(
			invoice: $invoice,
			customer: [
				'name' => 'Beneluxe SPRL',
				'vatId' => 'BE0123456789',
			],
			seller: [
				'legalName' => 'Shillinq BV',
				'vatId' => 'NL0987654321',
			]
		);

		self::assertStringContainsString('VAT reverse-charged', $payload['html']);
		self::assertStringContainsString('BE0123456789', $payload['html']);
		self::assertStringContainsString('NL0987654321', $payload['html']);
		self::assertStringContainsString('Goods', $payload['html']);
		self::assertTrue($payload['icp']['treatAsIcp']);
		self::assertSame('L', $payload['icp']['supplyType']);
		self::assertSame('BE0123456789', $payload['icp']['buyerVatId']);

	}//end testRenderIcpGoodsInvoiceIncludesReverseChargeNotice()

	/**
	 * Triangulation supplies (T) MUST report the C-party VAT-ID when present
	 * on the icpContext, not the A-party / customer's VAT-ID (REQ-ICP-006).
	 *
	 * @return void
	 */
	public function testRenderTriangulationInvoiceReportsCPartyVatId(): void {
		$invoice = [
			'invoiceNumber' => 'INV-2026-0099',
			'customerId' => 'cust-fr-1',
			'invoiceDate' => '2026-06-25',
			'dueDate' => '2026-07-25',
			'totalAmount' => 5000.0,
			'icpContext' => [
				'treatAsIcp' => true,
				'supplyType' => 'T',
				'triangulation' => true,
				'cPartyVatId' => 'FR0555666777',
			],
		];

		$payload = $this->renderer->render(
			invoice: $invoice,
			customer: [
				'name' => 'Some BV',
				'vatId' => 'DE0987654321',
			],
			seller: ['legalName' => 'Shillinq BV', 'vatId' => 'NL0987654321']
		);

		self::assertStringContainsString('FR0555666777', $payload['html']);
		self::assertStringContainsString('Triangulation', $payload['html']);
		self::assertStringContainsString('Article 141', $payload['html']);
		self::assertSame('FR0555666777', $payload['icp']['buyerVatId']);
		self::assertSame('T', $payload['icp']['supplyType']);
		self::assertTrue($payload['icp']['triangulation']);

	}//end testRenderTriangulationInvoiceReportsCPartyVatId()

	/**
	 * Rendering an ICP-treated invoice without a buyer VAT-ID MUST fail with
	 * the `icp.invoice.vatid.missing` error code per REQ-ICP-007.
	 *
	 * @return void
	 */
	public function testRenderIcpInvoiceWithoutBuyerVatIdThrows(): void {
		$invoice = [
			'invoiceNumber' => 'INV-2026-BAD',
			'customerId' => 'cust-noid',
			'invoiceDate' => '2026-06-15',
			'dueDate' => '2026-07-15',
			'totalAmount' => 1000.0,
			'icpContext' => [
				'treatAsIcp' => true,
				'supplyType' => 'S',
			],
		];

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/icp\.invoice\.vatid\.missing/');

		$this->renderer->render(
			invoice: $invoice,
			customer: ['name' => 'Customer'],
			seller: ['legalName' => 'Shillinq BV', 'vatId' => 'NL0987654321']
		);

	}//end testRenderIcpInvoiceWithoutBuyerVatIdThrows()

	/**
	 * Falling back to the legacy btwNumber works while customers migrate to
	 * the canonical vatId form (transition compatibility).
	 *
	 * @return void
	 */
	public function testRenderFallsBackToLegacyBtwNumberWhenVatIdMissing(): void {
		$invoice = [
			'invoiceNumber' => 'INV-2026-0007',
			'customerId' => 'cust-legacy',
			'invoiceDate' => '2026-06-15',
			'dueDate' => '2026-07-15',
			'totalAmount' => 800.0,
			'icpContext' => [
				'treatAsIcp' => true,
				'supplyType' => 'S',
			],
		];

		$payload = $this->renderer->render(
			invoice: $invoice,
			customer: ['name' => 'Legacy GmbH', 'vatNumber' => 'DE0987654321'],
			seller: ['legalName' => 'Shillinq BV', 'vatId' => 'NL0987654321']
		);

		self::assertStringContainsString('DE0987654321', $payload['html']);
		self::assertSame('DE0987654321', $payload['icp']['buyerVatId']);

	}//end testRenderFallsBackToLegacyBtwNumberWhenVatIdMissing()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
