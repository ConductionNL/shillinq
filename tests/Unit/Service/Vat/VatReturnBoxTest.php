<?php

/**
 * Unit tests for VatReturnBox.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Vat;

use OCA\Shillinq\Service\Vat\VatReturnBox;
use PHPUnit\Framework\TestCase;

/**
 * A box says its declaration type, the total its VAT counts in, and the side
 * a line in it is booked on.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatReturnBoxTest extends TestCase {

	/**
	 * Input VAT is paid, reverse-charge boxes are reverse charge, sales are collected.
	 *
	 * @return void
	 */
	public function testTypeAndTotalPerBox(): void {
		$boxes = new VatReturnBox();

		$this->assertSame('paid', $boxes->type(box: '5b'));
		$this->assertSame('reverse-charge', $boxes->type(box: '4a'));
		$this->assertSame('reverse-charge', $boxes->type(box: '2a'));
		$this->assertSame('collected', $boxes->type(box: '1a'));

		$this->assertSame('paid', $boxes->totalOf(box: '5b'));
		$this->assertSame('collected', $boxes->totalOf(box: '4b'));
		$this->assertSame('collected', $boxes->totalOf(box: '1b'));
	}//end testTypeAndTotalPerBox()

	/**
	 * A line on its box's own side counts positive, on the other side negative.
	 *
	 * @return void
	 */
	public function testBookedCentsFollowsTheBoxSide(): void {
		$boxes = new VatReturnBox();

		// A sale and its VAT are credits; a credit note debits them.
		$this->assertSame(12100, $boxes->bookedCents(line: ['amount' => 121.0, 'side' => 'credit', 'vatAmountKind' => 'base'], box: '1a'));
		$this->assertSame(-2100, $boxes->bookedCents(line: ['amount' => 21.0, 'side' => 'debit', 'vatAmountKind' => 'vat'], box: '1a'));

		// Input VAT is a debit.
		$this->assertSame(2100, $boxes->bookedCents(line: ['amount' => '21.00', 'side' => 'debit', 'vatAmountKind' => 'vat'], box: '5b'));

		// A reverse-charged purchase: its base is a debit, the VAT owed on it a credit.
		$this->assertSame(10000, $boxes->bookedCents(line: ['amount' => 100, 'side' => 'debit', 'vatAmountKind' => 'base'], box: '4a'));
		$this->assertSame(2100, $boxes->bookedCents(line: ['amount' => 21, 'side' => 'credit', 'vatAmountKind' => 'vat'], box: '4a'));
		$this->assertSame(-10000, $boxes->bookedCents(line: ['amount' => 100, 'side' => 'credit', 'vatAmountKind' => 'base'], box: '4a'));
	}//end testBookedCentsFollowsTheBoxSide()
}//end class
