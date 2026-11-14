<?php

/**
 * Unit tests for PeppolBisOrderMapper and LogPurchaseOrderMailer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PurchaseOrder
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

namespace OCA\Shillinq\Tests\Unit\Service\PurchaseOrder;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Service\PurchaseOrder\LogPurchaseOrderMailer;
use OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Peppol BIS order carries both parties' endpoints, the contract tags and
 * one line per array line; the log mailer logs the dispatch.
 *
 * @covers \OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper
 * @covers \OCA\Shillinq\Service\PurchaseOrder\LogPurchaseOrderMailer
 */
final class PeppolBisOrderMapperTest extends TestCase {

	/**
	 * Participant ids and the scheme and id they become.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function participants(): array {
		return [
			'scheme and id' => [' 0106:12345678 ', '0106', '12345678'],
			'no scheme' => ['12345678', '9999', '12345678'],
			'empty scheme' => [' :12345678', '9999', '12345678'],
			'empty' => ['  ', '9999', ''],
			'id with a colon' => ['9925:NL:001', '9925', 'NL:001'],
		];
	}//end participants()

	/**
	 * Each party's endpoint carries its scheme and id; without a scheme the
	 * fallback scheme is used.
	 *
	 * @param string $participant The participant id.
	 * @param string $scheme      The scheme.
	 * @param string $id          The id.
	 *
	 * @dataProvider participants
	 *
	 * @return void
	 */
	public function testEndpointsCarryTheSchemeAndId(string $participant, string $scheme, string $id): void {
		$xpath = $this->order(['poNumber' => 'PO-1'], buyer: $participant, supplier: '0088:5790000000001');

		$buyer = $xpath->query('//cac:BuyerCustomerParty/cac:Party/cbc:EndpointID')->item(0);
		$this->assertSame($scheme, $buyer->getAttribute('schemeID'));
		$this->assertSame($id, $buyer->textContent);

		$seller = $xpath->query('//cac:SellerSupplierParty/cac:Party/cbc:EndpointID')->item(0);
		$this->assertSame(['0088', '5790000000001'], [$seller->getAttribute('schemeID'), $seller->textContent]);
	}//end testEndpointsCarryTheSchemeAndId()

	/**
	 * Lines that are not arrays are skipped; quantities are written without
	 * trailing zeros and zero as 0; a missing line total is quantity × price.
	 *
	 * @return void
	 */
	public function testLinesAndQuantities(): void {
		$xpath = $this->order(
			[
				'poNumber' => 'PO-2',
				'currency' => 'USD',
				'lines' => [
					['lineNumber' => 1, 'productCode' => 'A-1', 'quantity' => 2.5, 'unitPrice' => 4],
					'not a line',
					['lineNumber' => 2, 'productCode' => 'B-2', 'quantity' => 0, 'unitPrice' => 3, 'lineTotal' => 0],
					['lineNumber' => 3, 'productCode' => 'C-3', 'quantity' => 1.125, 'unitPrice' => 8, 'lineTotal' => 9],
				],
			]
		);

		$rows = [];
		foreach ($xpath->query('//cac:OrderLine/cac:LineItem') as $item) {
			$rows[] = [
				$xpath->evaluate('string(cbc:ID)', $item),
				$xpath->evaluate('string(cbc:Quantity)', $item),
				$xpath->evaluate('string(cbc:LineExtensionAmount)', $item),
				$xpath->evaluate('string(cbc:LineExtensionAmount/@currencyID)', $item),
			];
		}

		$this->assertSame(
			[
				['1', '2.5', '10.00', 'USD'],
				['2', '0', '0.00', 'USD'],
				['3', '1.125', '9.00', 'USD'],
			],
			$rows
		);
	}//end testLinesAndQuantities()

	/**
	 * Notes and contract tags appear only when given, escaped.
	 *
	 * @return void
	 */
	public function testNotesAndContractTags(): void {
		$with = $this->order(['poNumber' => 'PO-3', 'notes' => 'Deliver <before> 9 & ring', 'costCenter' => 'KP-12', 'projectCode' => 'PRJ-7']);
		$this->assertSame('Deliver <before> 9 & ring', $with->evaluate('string(/o:Order/cbc:Note)'));
		$this->assertSame('KP-12', $with->evaluate('string(//cac:Contract/cbc:ID)'));
		$this->assertSame('PRJ-7', $with->evaluate('string(//cac:Contract/cac:ContractDocumentReference/cbc:ID)'));

		$only = $this->order(['poNumber' => 'PO-4', 'projectCode' => 'PRJ-7']);
		$this->assertSame(0, $only->query('//cac:Contract/cbc:ID')->length);
		$this->assertSame('PRJ-7', $only->evaluate('string(//cac:Contract/cac:ContractDocumentReference/cbc:ID)'));

		$without = $this->order(['poNumber' => 'PO-5', 'notes' => '  ']);
		$this->assertSame(0, $without->query('/o:Order/cbc:Note')->length);
		$this->assertSame(0, $without->query('//cac:Contract')->length);
		$this->assertSame('EUR', $without->evaluate('string(/o:Order/cbc:DocumentCurrencyCode)'));
	}//end testNotesAndContractTags()

	/**
	 * The log mailer logs the administration, order number and supplier.
	 *
	 * @return void
	 */
	public function testTheLogMailerLogsTheDispatch(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'shillinq.purchase_order.mailer.dispatch',
			['administrationId' => 'adm-1', 'poNumber' => 'PO-9', 'supplierId' => '']
		);

		(new LogPurchaseOrderMailer(logger: $logger))->sendPurchaseOrderEmail('adm-1', ['poNumber' => 'PO-9']);
		(new LogPurchaseOrderMailer())->sendPurchaseOrderEmail('adm-1', ['poNumber' => 'PO-9']);
	}//end testTheLogMailerLogsTheDispatch()

	/**
	 * The order XML for a purchase order, as an XPath with the UBL prefixes.
	 *
	 * @param array<string, mixed> $purchaseOrder The order.
	 * @param string               $buyer         The buyer participant.
	 * @param string               $supplier      The supplier participant.
	 *
	 * @return DOMXPath
	 */
	private function order(array $purchaseOrder, string $buyer = '0106:1', string $supplier = '0106:2'): DOMXPath {
		$xml = (new PeppolBisOrderMapper())->toUblOrderXml($purchaseOrder, $buyer, $supplier, '2026-11-13');
		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml));
		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('o', 'urn:oasis:names:specification:ubl:schema:xsd:Order-2');
		$xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
		$xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

		return $xpath;
	}//end order()
}//end class
