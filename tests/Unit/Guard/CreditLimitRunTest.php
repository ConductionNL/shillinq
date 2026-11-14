<?php

/**
 * Unit tests for CreditLimitRun.
 *
 * The value object behind one ARInvoice credit-limit check: what it reads
 * from the invoice, the CustomerMaster and paged ARInvoice queries it builds,
 * and which invoices count toward the customer's outstanding receivable —
 * including the self-exclusion that stops a re-issue double-counting itself.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
 *
 * @covers \OCA\Shillinq\Guard\CreditLimitRun
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\CreditLimitRun;
use PHPUnit\Framework\TestCase;

final class CreditLimitRunTest extends TestCase {
	/**
	 * Reads the customer, administration, invoice and total from the invoice.
	 *
	 * @return void
	 */
	public function testReadsTheInvoiceFields(): void {
		$run = new CreditLimitRun(
			[
				'customerNumber' => 'CUST-1',
				'administrationId' => 'ADM-1',
				'invoiceNumber' => 'INV-9',
				'totalCents' => 12345,
			],
			'shillinq',
		);

		$this->assertSame('shillinq', $run->registerSlug);
		$this->assertSame('CUST-1', $run->customerNumber);
		$this->assertSame('ADM-1', $run->administrationId);
		$this->assertSame('INV-9', $run->invoiceNumber);
		$this->assertSame(12345, $run->totalCents);
	}//end testReadsTheInvoiceFields()

	/**
	 * Coerces missing and loosely-typed fields to empty strings and zero.
	 *
	 * @return void
	 */
	public function testCoercesMissingAndLooseFields(): void {
		$run = new CreditLimitRun([], 'reg');

		$this->assertSame('reg', $run->registerSlug);
		$this->assertSame('', $run->customerNumber);
		$this->assertSame('', $run->administrationId);
		$this->assertSame('', $run->invoiceNumber);
		$this->assertSame(0, $run->totalCents);

		$coerced = new CreditLimitRun(
			['customerNumber' => 42, 'totalCents' => '678'],
			'reg',
		);
		$this->assertSame('42', $coerced->customerNumber);
		$this->assertSame(678, $coerced->totalCents);
	}//end testCoercesMissingAndLooseFields()

	/**
	 * The customer query filters by customer and administration, limit one.
	 *
	 * @return void
	 */
	public function testCustomerQuery(): void {
		$run = new CreditLimitRun(
			['customerNumber' => 'C', 'administrationId' => 'A'],
			'reg',
		);

		$this->assertSame(
			[
				'filters' => ['customerNumber' => 'C', 'administrationId' => 'A'],
				'limit' => 1,
			],
			$run->customerQuery(),
		);
	}//end testCustomerQuery()

	/**
	 * The invoice page query uses the same filters, the page size and a
	 * zero-based offset computed from the 1-based page.
	 *
	 * @return void
	 */
	public function testInvoicePageQueryOffsets(): void {
		$run = new CreditLimitRun(
			['customerNumber' => 'C', 'administrationId' => 'A'],
			'reg',
		);

		$first = $run->invoicePageQuery(1);
		$this->assertSame(['customerNumber' => 'C', 'administrationId' => 'A'], $first['filters']);
		$this->assertSame(CreditLimitRun::PAGE_SIZE, $first['limit']);
		$this->assertSame(0, $first['offset']);

		$this->assertSame(CreditLimitRun::PAGE_SIZE, $run->invoicePageQuery(2)['offset']);
		$this->assertSame((3 * CreditLimitRun::PAGE_SIZE), $run->invoicePageQuery(4)['offset']);
	}//end testInvoicePageQueryOffsets()

	/**
	 * An outstanding status that is not the invoice being issued counts.
	 *
	 * @return void
	 */
	public function testCountsAnOutstandingOtherInvoice(): void {
		$run = new CreditLimitRun(['invoiceNumber' => 'INV-SELF'], 'reg');

		foreach (['draft', 'issued', 'overdue', 'disputed'] as $status) {
			$this->assertTrue(
				$run->countsTowardOutstanding(['status' => $status, 'invoiceNumber' => 'INV-OTHER']),
				$status.' should count',
			);
		}
	}//end testCountsAnOutstandingOtherInvoice()

	/**
	 * A paid, written-off or unknown status never counts.
	 *
	 * @return void
	 */
	public function testDoesNotCountNonOutstandingStatuses(): void {
		$run = new CreditLimitRun(['invoiceNumber' => 'INV-SELF'], 'reg');

		foreach (['paid', 'written_off', 'cancelled', ''] as $status) {
			$this->assertFalse(
				$run->countsTowardOutstanding(['status' => $status, 'invoiceNumber' => 'INV-OTHER']),
				$status.' should not count',
			);
		}
	}//end testDoesNotCountNonOutstandingStatuses()

	/**
	 * The invoice being issued is excluded from its own outstanding sum.
	 *
	 * @return void
	 */
	public function testExcludesTheInvoiceBeingIssued(): void {
		$run = new CreditLimitRun(['invoiceNumber' => 'INV-SELF'], 'reg');

		$this->assertFalse(
			$run->countsTowardOutstanding(['status' => 'issued', 'invoiceNumber' => 'INV-SELF']),
		);
	}//end testExcludesTheInvoiceBeingIssued()

	/**
	 * With no invoice being issued ('' excludes nothing), an outstanding line
	 * with a blank invoice number still counts.
	 *
	 * @return void
	 */
	public function testEmptyIssuedInvoiceExcludesNothing(): void {
		$run = new CreditLimitRun([], 'reg');

		$this->assertTrue(
			$run->countsTowardOutstanding(['status' => 'issued', 'invoiceNumber' => '']),
		);
		$this->assertTrue(
			$run->countsTowardOutstanding(['status' => 'overdue']),
		);
	}//end testEmptyIssuedInvoiceExcludesNothing()
}//end class
