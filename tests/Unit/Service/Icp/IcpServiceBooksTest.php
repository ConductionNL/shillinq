<?php

/**
 * Unit tests for IcpService reading the books: ICP supplies from posted
 * invoices and box 3b from the VAT return prepared from the books.
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
use OCA\Shillinq\Service\IcpCalculator;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction;
use OCA\Shillinq\Service\Tax\ReverseChargeNotice;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The ledger and the reconciliation read the books (REQ-ICR-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(IcpService::class)]
#[UsesClass(IcpCalculator::class)]
#[UsesClass(IcpInvoiceSupplies::class)]
#[UsesClass(ReverseChargeNotice::class)]
final class IcpServiceBooksTest extends TestCase {

	use IcpObjectStore;

	/**
	 * The service over the rows.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return IcpService
	 */
	private function service(array $rows): IcpService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		return new IcpService(
			appConfig: $config,
			calculator: new IcpCalculator(),
			objectService: $this->objectStore(rows: $rows),
			jurisdiction: $this->createMock(AdministrationJurisdiction::class),
		);
	}//end service()

	/**
	 * A posted intra-EU invoice.
	 *
	 * @param string $number The invoice number.
	 * @param float  $amount The net amount.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(string $number, float $amount): array {
		return [
			'@self' => ['id' => 'uuid-' . $number],
			'invoiceNumber' => $number,
			'administrationId' => 'adm-1',
			'invoiceDate' => '2026-08-03',
			'lifecycleState' => 'paid',
			'buyerVatId' => 'BE0123456749',
			'invoiceLines' => [['netAmount' => $amount, 'vatCategory' => 'K']],
		];
	}//end invoice()

	/**
	 * Posted invoices join the recorded supplies; an invoice a supply already
	 * names, by number or by id, is not counted twice.
	 *
	 * @return void
	 */
	public function testPostedInvoicesJoinTheRecordedSuppliesOnce(): void {
		$rows = [
			'IcpSupply' => [
				['administrationId' => 'adm-1', 'invoiceId' => '2026-0101', 'supplyDate' => '2026-08-03', 'buyerVatId' => 'BE0123456749', 'supplyType' => 'L', 'amountExclVat' => 12000.0],
				['administrationId' => 'adm-1', 'invoiceId' => 'uuid-2026-0102', 'supplyDate' => '2026-08-04', 'buyerVatId' => 'BE0123456749', 'supplyType' => 'L', 'amountExclVat' => 50.0],
			],
			'ARInvoice' => [
				self::invoice('2026-0101', 12000.0),
				self::invoice('2026-0102', 7000.0),
				self::invoice('2026-0103', 300.0),
				array_merge(self::invoice('2026-0104', 1.0), ['administrationId' => 'adm-2']),
			],
		];

		$ledger = $this->service(rows: $rows)->ledger(administrationId: 'adm-1', period: '2026-Q3');

		$this->assertSame(3, $ledger['supplyCount']);
		$this->assertSame(12350.0, $ledger['total']);
		$this->assertSame([['buyerVatId' => 'BE0123456749', 'supplyType' => 'L', 'amountExclVat' => 12350.0]], $ledger['lines']);
	}//end testPostedInvoicesJoinTheRecordedSuppliesOnce()

	/**
	 * Without a VatReturn, box 3b comes from the declarations of the filed
	 * return prepared from the books, before a draft one.
	 *
	 * @return void
	 */
	public function testBox3bComesFromTheReturnPreparedFromTheBooks(): void {
		$rows = [
			'ARInvoice' => [self::invoice('2026-0101', 12000.0)],
			'BtwAangifte' => [
				['id' => 'ret-draft', 'administrationId' => 'adm-1', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 3, 'statusCode' => 'draft'],
				['@self' => ['id' => 'ret-filed'], 'administrationId' => 'adm-1', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 3, 'statusCode' => 'filed'],
				['id' => 'ret-q2', 'administrationId' => 'adm-1', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 2, 'statusCode' => 'filed'],
			],
			'VATDeclaration' => [
				['returnId' => 'ret-filed', 'returnBox' => '3b', 'totalTaxableAmount' => 11000.0],
				['returnId' => 'ret-filed', 'returnBox' => '3b', 'totalTaxableAmount' => '999.50'],
				['returnId' => 'ret-filed', 'returnBox' => '1a', 'totalTaxableAmount' => 5000.0],
				['returnId' => 'ret-draft', 'returnBox' => '3b', 'totalTaxableAmount' => 12000.0],
			],
		];

		$outcome = $this->service(rows: $rows)->reconcile(administrationId: 'adm-1', period: '2026-Q3');

		$this->assertSame(11999.5, $outcome['rubriek3b']);
		$this->assertTrue($outcome['matches']);
		$this->assertFalse($outcome['missing']);
		$this->assertSame(0.5, $outcome['difference']);
	}//end testBox3bComesFromTheReturnPreparedFromTheBooks()

	/**
	 * A monthly period reads the month's draft return; with none, box 3b is missing.
	 *
	 * @return void
	 */
	public function testAMonthReadsTheMonthsReturnAndNoReturnIsMissing(): void {
		$rows = [
			'ARInvoice' => [self::invoice('2026-0101', 12000.0)],
			'BtwAangifte' => [['id' => 'ret-aug', 'administrationId' => 'adm-1', 'period' => 'month', 'periodYear' => 2026, 'periodNumber' => 8]],
			'VATDeclaration' => [['returnId' => 'ret-aug', 'returnBox' => '3b', 'totalTaxableAmount' => 10000.0]],
		];
		$service = $this->service(rows: $rows);

		$august = $service->reconcile(administrationId: 'adm-1', period: '2026-08');
		$september = $service->reconcile(administrationId: 'adm-1', period: '2026-09');

		$this->assertSame(10000.0, $august['rubriek3b']);
		$this->assertFalse($august['matches']);
		$this->assertSame(2000.0, $august['difference']);
		$this->assertNull($september['rubriek3b']);
		$this->assertTrue($september['missing']);
	}//end testAMonthReadsTheMonthsReturnAndNoReturnIsMissing()
}//end class
