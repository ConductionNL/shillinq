<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\InvoiceParts;
use PHPUnit\Framework\TestCase;

/**
 * Pins how the lists inside a checked invoice are read.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoiceParts
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoicePartsTest extends TestCase {

	/**
	 * A list keeps its array entries, reindexed; anything else reads as none.
	 *
	 * @return void
	 */
	public function testListsAreNormalised(): void {
		$invoice = [
			'invoiceLines' => ['x' => ['id' => 1], 'y' => 'junk', 'z' => ['id' => 2]],
			'vatBreakdown' => 'not a list',
			'payments' => [['amount' => 5]],
		];
		self::assertSame([['id' => 1], ['id' => 2]], InvoiceParts::lines($invoice));
		self::assertSame([], InvoiceParts::vatBreakdown($invoice));
		self::assertSame([['amount' => 5]], InvoiceParts::items($invoice, 'payments'));
		self::assertSame([], InvoiceParts::items($invoice, 'missing'));

	}//end testListsAreNormalised()

	/**
	 * A breakdown group's category is matched exactly.
	 *
	 * @return void
	 */
	public function testBreakdownHasCategory(): void {
		$invoice = ['vatBreakdown' => [['category' => 'S'], 'junk', ['category' => 'AE']]];
		self::assertTrue(InvoiceParts::breakdownHasCategory($invoice, 'AE'));
		self::assertFalse(InvoiceParts::breakdownHasCategory($invoice, 'ae'));
		self::assertFalse(InvoiceParts::breakdownHasCategory([], 'S'));

	}//end testBreakdownHasCategory()
}//end class
