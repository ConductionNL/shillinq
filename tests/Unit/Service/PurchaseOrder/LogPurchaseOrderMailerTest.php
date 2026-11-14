<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PurchaseOrder;

use OCA\Shillinq\Service\PurchaseOrder\LogPurchaseOrderMailer;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderMailerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The default purchase-order mailer only records the dispatch: one info line
 * naming the administration, the PO number and the supplier, with missing
 * fields written as empty strings, and it works without a logger at all.
 *
 * @covers \OCA\Shillinq\Service\PurchaseOrder\LogPurchaseOrderMailer
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 */
final class LogPurchaseOrderMailerTest extends TestCase {

	/**
	 * The dispatch is logged once with the administration, PO number and supplier.
	 *
	 * @return void
	 */
	public function testTheDispatchIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'shillinq.purchase_order.mailer.dispatch',
				['administrationId' => 'adm-1', 'poNumber' => 'PO-2026-0042', 'supplierId' => '17']
			);

		$mailer = new LogPurchaseOrderMailer($logger);
		$this->assertInstanceOf(PurchaseOrderMailerInterface::class, $mailer);
		$mailer->sendPurchaseOrderEmail('adm-1', ['poNumber' => 'PO-2026-0042', 'supplierId' => 17, 'lines' => []]);

	}//end testTheDispatchIsLogged()

	/**
	 * A purchase order without number or supplier logs them as empty strings.
	 *
	 * @return void
	 */
	public function testMissingFieldsAreLoggedEmpty(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('shillinq.purchase_order.mailer.dispatch', ['administrationId' => 'adm-2', 'poNumber' => '', 'supplierId' => '']);

		(new LogPurchaseOrderMailer($logger))->sendPurchaseOrderEmail('adm-2', []);

	}//end testMissingFieldsAreLoggedEmpty()

	/**
	 * Without a logger the dispatch is accepted silently.
	 *
	 * @return void
	 */
	public function testWithoutALoggerNothingFails(): void {
		$this->expectNotToPerformAssertions();
		(new LogPurchaseOrderMailer())->sendPurchaseOrderEmail('adm-3', ['poNumber' => 'PO-1']);

	}//end testWithoutALoggerNothingFails()
}//end class
