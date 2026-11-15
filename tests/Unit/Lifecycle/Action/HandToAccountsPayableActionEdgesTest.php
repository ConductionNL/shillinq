<?php

/**
 * Edge cases of HandToAccountsPayableAction: refusals and the AP transaction's fallbacks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\Action\HandToAccountsPayableAction;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the hand-over refuses, and how the AP transaction fills what the invoice leaves out.
 */
#[CoversClass(HandToAccountsPayableAction::class)]
#[UsesClass(ObjectIdentifier::class)]
class HandToAccountsPayableActionEdgesTest extends TestCase {

	private ObjectServiceInterface&MockObject $objects;

	private SupplierInvoiceChecks&MockObject $checks;

	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->checks      = $this->createMock(SupplierInvoiceChecks::class);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
	}//end setUp()

	/**
	 * The action under test.
	 *
	 * @return HandToAccountsPayableAction
	 */
	private function action(): HandToAccountsPayableAction {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new HandToAccountsPayableAction($this->objects, $settings, $this->checks, $this->transitions);
	}//end action()

	/**
	 * An invoice already handed over is returned unchanged, without any lookup or write.
	 *
	 * @return void
	 */
	public function testAnInvoiceAlreadyHandedOverIsLeftAlone(): void {
		$this->checks->expects($this->never())->method('payee');
		$this->objects->expects($this->never())->method('saveObject');

		$invoice = ['id' => 'si-1', 'apTransactionId' => 'ap-9'];

		$this->assertSame($invoice, $this->action()->execute($invoice, [], [], 'hand-to-accounts-payable'));
	}//end testAnInvoiceAlreadyHandedOverIsLeftAlone()

	/**
	 * An invoice whose supplier is not recognised is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnknownSupplierIsRefused(): void {
		$this->checks->expects($this->once())->method('payee')->with('sup-x')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The invoice cannot be booked: its supplier is not recognised.');

		$this->action()->execute(['id' => 'si-1', 'supplierId' => 'sup-x', 'invoiceDate' => '2026-09-01'], [], [], 'hand-to-accounts-payable');
	}//end testAnUnknownSupplierIsRefused()

	/**
	 * A save that yields no id is refused, and no transition runs on nothing.
	 *
	 * @return void
	 */
	public function testAnUnsavedTransactionIsRefusedWithoutTransitions(): void {
		$this->checks->method('payee')->willReturn(['id' => 'sup-1']);
		$this->objects->expects($this->once())->method('saveObject')->willReturn($this->createStub(ObjectEntityInterface::class));
		$this->transitions->expects($this->never())->method('run');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The AP transaction for the invoice could not be written.');

		$this->action()->execute(['id' => 'si-1', 'supplierId' => 'sup-1', 'invoiceDate' => '2026-09-01'], [], [], 'hand-to-accounts-payable');
	}//end testAnUnsavedTransactionIsRefusedWithoutTransitions()

	/**
	 * Without lines, one line carries the net amount on the payee's default account,
	 * named after the invoice number.
	 *
	 * @return void
	 */
	public function testWithoutLinesOneLineCarriesTheNet(): void {
		$transaction = $this->action()->apTransaction(
			invoice: ['invoiceNumber' => 'F-12', 'invoiceDate' => '2026-09-01', 'totalExclVat' => 12345, 'totalVat' => 2593, 'totalInclVat' => 14938],
			payee: ['id' => 'sup-1', 'defaultExpenseAccountNumber' => ' 4300 ']
		);

		$this->assertSame([['description' => 'F-12', 'accountNumber' => '4300', 'amount' => 123.45]], $transaction['lines']);
		$this->assertSame(149.38, $transaction['totalAmount']);
		$this->assertSame(25.93, $transaction['taxAmount']);
		$this->assertSame('EUR', $transaction['currency']);
		$this->assertSame('draft', $transaction['state']);
		$this->assertSame('sup-1', $transaction['vendorId']);
		$this->assertArrayNotHasKey('sourceDocumentUri', $transaction);
		$this->assertArrayNotHasKey('paymentBlocked', $transaction);
	}//end testWithoutLinesOneLineCarriesTheNet()

	/**
	 * A line keeps its own account; a line without one takes the payee's default,
	 * and a line without a description is named after the invoice.
	 *
	 * @return void
	 */
	public function testLinesKeepTheirAccountOrTakeTheDefault(): void {
		$transaction = $this->action()->apTransaction(
			invoice: [
				'invoiceNumber' => 'F-12',
				'invoiceDate' => '2026-09-01',
				'currency' => 'USD',
				'lines' => [
					['description' => 'Paper', 'accountNumber' => '4310', 'lineExtension' => 500],
					['accountNumber' => '  ', 'lineExtension' => 250],
				],
			],
			payee: ['id' => 'sup-1', 'defaultExpenseAccountNumber' => '4300']
		);

		$this->assertSame(
			[
				['description' => 'Paper', 'accountNumber' => '4310', 'amount' => 5.0],
				['description' => 'F-12', 'accountNumber' => '4300', 'amount' => 2.5],
			],
			$transaction['lines']
		);
		$this->assertSame('USD', $transaction['currency']);
	}//end testLinesKeepTheirAccountOrTakeTheDefault()

	/**
	 * The due date is the invoice's own, else the invoice date plus the payee's term, else 30 days.
	 *
	 * @return void
	 */
	public function testTheDueDateFallsBackToThePaymentTerm(): void {
		$action = $this->action();

		$this->assertSame('2026-09-20', $action->apTransaction(invoice: ['invoiceDate' => '2026-09-01', 'dueDate' => ' 2026-09-20 '], payee: ['paymentTermDays' => 14])['dueDate']);
		$this->assertSame('2026-09-15', $action->apTransaction(invoice: ['invoiceDate' => '2026-09-01'], payee: ['paymentTermDays' => 14])['dueDate']);
		$this->assertSame('2026-10-01', $action->apTransaction(invoice: ['invoiceDate' => '2026-09-01'], payee: [])['dueDate']);
	}//end testTheDueDateFallsBackToThePaymentTerm()

	/**
	 * Without a due date or a readable invoice date the invoice cannot be booked.
	 *
	 * @return void
	 */
	public function testWithoutAnyDateTheInvoiceIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The invoice cannot be booked: it has no invoice date.');

		$this->action()->apTransaction(invoice: ['invoiceDate' => '01-09-2026'], payee: []);
	}//end testWithoutAnyDateTheInvoiceIsRefused()
}//end class
