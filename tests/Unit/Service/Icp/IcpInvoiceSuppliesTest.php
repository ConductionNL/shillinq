<?php

/**
 * Unit tests for IcpInvoiceSupplies: posted invoices read as ICP supplies.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Icp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Icp;

use OCA\Shillinq\Service\Icp\IcpInvoiceSupplies;
use OCA\Shillinq\Service\Tax\ReverseChargeNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The ICP statement is prepared from the books (REQ-ICR-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(IcpInvoiceSupplies::class)]
#[UsesClass(ReverseChargeNotice::class)]
final class IcpInvoiceSuppliesTest extends TestCase {

	/**
	 * A posted invoice to a Belgian business with one intra-EU line.
	 *
	 * @param array<string,mixed> $overrides Fields to replace.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(array $overrides = []): array {
		return array_merge(
			[
				'@self' => ['id' => 'uuid-101'],
				'invoiceNumber' => '2026-0101',
				'invoiceDate' => '2026-08-03',
				'lifecycleState' => 'issued',
				'buyerVatId' => 'be 0123456749',
				'netAmount' => 12500.0,
				'currency' => 'EUR',
				'invoiceLines' => [
					['lineId' => '1', 'netAmount' => 12000.0, 'vatCategory' => 'K'],
					['lineId' => '2', 'netAmount' => 500.0, 'vatCategory' => 'S'],
				],
			],
			$overrides
		);
	}//end invoice()

	/**
	 * A posted intra-EU invoice is one goods supply of its K lines.
	 *
	 * @return void
	 */
	public function testAPostedIntraEuInvoiceIsAGoodsSupplyOfItsKLines(): void {
		$this->assertSame(
			[
				[
					'invoiceId' => '2026-0101',
					'supplyDate' => '2026-08-03',
					'buyerVatId' => 'BE0123456749',
					'buyerCountry' => 'BE',
					'supplyType' => 'L',
					'amountExclVat' => 12000.0,
					'currency' => 'EUR',
					'viesValidationId' => null,
					'administrationId' => 'adm-1',
					'source' => 'invoice',
				],
			],
			(new IcpInvoiceSupplies())->fromInvoices(invoices: [self::invoice()], administrationId: 'adm-1')
		);
	}//end testAPostedIntraEuInvoiceIsAGoodsSupplyOfItsKLines()

	/**
	 * A draft, a domestic reverse charge and a standard invoice are no ICP supply.
	 *
	 * @return void
	 */
	public function testDraftsDomesticReverseChargeAndStandardInvoicesAreLeftOut(): void {
		$invoices = [
			self::invoice(['lifecycleState' => 'draft']),
			self::invoice(['invoiceLines' => [['netAmount' => 100.0, 'vatCategory' => 'AE']]]),
			self::invoice(['invoiceLines' => [['netAmount' => 100.0, 'vatCategory' => 'S']]]),
			'not a record',
		];

		$this->assertSame([], (new IcpInvoiceSupplies())->fromInvoices(invoices: $invoices, administrationId: 'adm-1'));
	}//end testDraftsDomesticReverseChargeAndStandardInvoicesAreLeftOut()

	/**
	 * A booked draft (it has a ledger transaction) counts; the overlay decides the supply type.
	 *
	 * @return void
	 */
	public function testALedgerTransactionAndTheIcpOverlayDecide(): void {
		$supplies = (new IcpInvoiceSupplies())->fromInvoices(
			invoices: [
				self::invoice([
					'lifecycleState' => 'draft',
					'glTransactionId' => 'gl-7',
					'supplyDate' => '2026-09-30T00:00:00Z',
					'buyerCountryCode' => 'de',
					'buyerVatId' => 'DE123456789',
					'invoiceLines' => [],
					'netAmount' => 3000.0,
					'icpContext' => ['treatAsIcp' => true, 'supplyType' => 's', 'viesValidationId' => 'vies-1'],
				]),
			],
			administrationId: 'adm-1'
		);

		$this->assertSame('2026-09-30', $supplies[0]['supplyDate']);
		$this->assertSame('DE', $supplies[0]['buyerCountry']);
		$this->assertSame('S', $supplies[0]['supplyType']);
		$this->assertSame(3000.0, $supplies[0]['amountExclVat']);
		$this->assertSame('vies-1', $supplies[0]['viesValidationId']);
	}//end testALedgerTransactionAndTheIcpOverlayDecide()

	/**
	 * Triangulation is T, a service nature S, a credit note negative, a VAT group K the amount.
	 *
	 * @return void
	 */
	public function testTriangulationServicesCreditNotesAndVatGroups(): void {
		$reader = new IcpInvoiceSupplies();
		$triangulation = $reader->fromInvoices(invoices: [self::invoice(['triangulation' => true])], administrationId: 'a')[0];
		$service = $reader->fromInvoices(invoices: [self::invoice(['supplyType' => 'service'])], administrationId: 'a')[0];
		$credit = $reader->fromInvoices(
			invoices: [self::invoice(['invoiceTypeCode' => '381', 'paidAmount' => 0, 'lifecycleState' => 'paid'])],
			administrationId: 'a'
		)[0];
		$group = $reader->fromInvoices(
			invoices: [
				self::invoice([
					'invoiceLines' => [],
					'vatBreakdown' => [['category' => 'K', 'taxableAmount' => 700.5], ['category' => 'S', 'taxableAmount' => 99.0]],
				]),
			],
			administrationId: 'a'
		)[0];

		$this->assertSame('T', $triangulation['supplyType']);
		$this->assertSame('S', $service['supplyType']);
		$this->assertSame(-12000.0, $credit['amountExclVat']);
		$this->assertSame(700.5, $group['amountExclVat']);
	}//end testTriangulationServicesCreditNotesAndVatGroups()

	/**
	 * Without a supply date the tax point, then the invoice date is the time of supply;
	 * without a country the VAT prefix is the country.
	 *
	 * @return void
	 */
	public function testDateAndCountryFallBack(): void {
		$supply = (new IcpInvoiceSupplies())->fromInvoices(
			invoices: [self::invoice(['taxPointDate' => '2026-07-31', 'buyerVatId' => '123'])],
			administrationId: 'a'
		)[0];

		$this->assertSame('2026-07-31', $supply['supplyDate']);
		$this->assertSame('', $supply['buyerCountry']);
	}//end testDateAndCountryFallBack()

	/**
	 * An invoice is named by its number, id and slug, once each.
	 *
	 * @return void
	 */
	public function testTheKeysOfAnInvoice(): void {
		$this->assertSame(
			['2026-0101', 'uuid-101', 'slug-101'],
			(new IcpInvoiceSupplies())->keysOf(invoice: self::invoice(['@self' => ['id' => 'uuid-101', 'slug' => 'slug-101'], 'id' => 'uuid-101']))
		);
	}//end testTheKeysOfAnInvoice()
}//end class
