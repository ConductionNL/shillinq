<?php

/**
 * Unit tests for AssignInvoiceNumberAction.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\AssignInvoiceNumberAction;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SIIC-001: an issued invoice takes the next number of its series; a
 * self-billed one keeps the customer's.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\AssignInvoiceNumberAction
 */
class AssignInvoiceNumberActionTest extends TestCase {
	/**
	 * A draft being issued.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private function issuing(array $extra = []): array {
		return array_merge(
			['id' => 'inv-1', 'administrationId' => 'adm-vd', 'invoiceDate' => '2026-03-14', 'grossAmount' => 1210.0, 'lifecycleState' => 'issued'],
			$extra
		);
	}//end issuing()

	/**
	 * The issuing invoice gets the sales series' next number of its invoice date's year.
	 *
	 * @return void
	 */
	public function testTheIssuingInvoiceGetsTheNextSalesNumber(): void {
		$sequences = $this->createMock(InvoiceNumberSequenceService::class);
		$sequences->expects($this->once())->method('next')
			->with('adm-vd', InvoiceNumberSequenceService::KIND_SALES, 2026)
			->willReturn('2026-0042');

		$issued = (new AssignInvoiceNumberAction(sequences: $sequences))->execute($this->issuing(), [], [], 'issue');

		$this->assertSame('2026-0042', $issued['invoiceNumber']);
		$this->assertSame(1210.0, $issued['grossAmount']);
	}//end testTheIssuingInvoiceGetsTheNextSalesNumber()

	/**
	 * A provisional draft number is replaced by the series number.
	 *
	 * @return void
	 */
	public function testAProvisionalDraftNumberIsReplaced(): void {
		$sequences = $this->createMock(InvoiceNumberSequenceService::class);
		$sequences->method('next')->willReturn('2026-0043');

		$issued = (new AssignInvoiceNumberAction(sequences: $sequences))
			->execute($this->issuing(['invoiceNumber' => 'DRAFT-20260314-ab12cd34']), [], [], 'issue');

		$this->assertSame('2026-0043', $issued['invoiceNumber']);
	}//end testAProvisionalDraftNumberIsReplaced()

	/**
	 * A self-billed invoice keeps the customer's number and the series does not advance.
	 *
	 * @return void
	 */
	public function testASelfBilledInvoiceKeepsTheCustomersNumber(): void {
		$sequences = $this->createMock(InvoiceNumberSequenceService::class);
		$sequences->expects($this->never())->method('next');
		$action = new AssignInvoiceNumberAction(sequences: $sequences);

		foreach ([true, 1, '1', 'true'] as $flag) {
			$invoice = $this->issuing(['invoiceNumber' => 'GV-2026-0815', 'selfBilled' => $flag]);
			$this->assertSame($invoice, $action->execute($invoice, [], [], 'issue'));
			$this->assertFalse(AssignInvoiceNumberAction::takesNumber(invoice: $invoice));
		}

		$this->assertTrue(AssignInvoiceNumberAction::takesNumber(invoice: $this->issuing(['selfBilled' => false])));
	}//end testASelfBilledInvoiceKeepsTheCustomersNumber()

	/**
	 * A credit note takes its number from the credit series.
	 *
	 * @return void
	 */
	public function testACreditNoteTakesTheCreditSeries(): void {
		$sequences = $this->createMock(InvoiceNumberSequenceService::class);
		$sequences->expects($this->once())->method('next')
			->with('adm-vd', InvoiceNumberSequenceService::KIND_CREDIT, 2025)
			->willReturn('CN-2025-0004');

		$issued = (new AssignInvoiceNumberAction(sequences: $sequences))
			->execute($this->issuing(['invoiceType' => 'credit-note', 'invoiceDate' => '2025-12-31']), [], [], 'issue');

		$this->assertSame('CN-2025-0004', $issued['invoiceNumber']);
	}//end testACreditNoteTakesTheCreditSeries()

	/**
	 * Without a readable invoice date the current year is the fiscal year.
	 *
	 * @return void
	 */
	public function testWithoutAnInvoiceDateTheCurrentYearCounts(): void {
		$sequences = $this->createMock(InvoiceNumberSequenceService::class);
		$sequences->expects($this->once())->method('next')
			->with('adm-vd', InvoiceNumberSequenceService::KIND_SALES, (int)date('Y'))
			->willReturn('x');

		(new AssignInvoiceNumberAction(sequences: $sequences))->execute($this->issuing(['invoiceDate' => 'soon']), [], [], 'issue');
	}//end testWithoutAnInvoiceDateTheCurrentYearCounts()
}//end class
