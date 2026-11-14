<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\GlTransactionLines;
use PHPUnit\Framework\TestCase;

/**
 * Pins how a GL transaction's lines are read and when they balance.
 *
 * @covers \OCA\Shillinq\Standards\Checks\GlTransactionLines
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class GlTransactionLinesTest extends TestCase {

	/**
	 * Non-array lines read as none; non-array entries are dropped and the list is reindexed.
	 *
	 * @return void
	 */
	public function testOfNormalisesTheLines(): void {
		self::assertSame([], GlTransactionLines::of(transaction: []));
		self::assertSame([], GlTransactionLines::of(transaction: ['lines' => 'x']));
		self::assertSame(
			[['side' => 'debit'], ['side' => 'credit']],
			GlTransactionLines::of(transaction: ['lines' => ['a' => ['side' => 'debit'], 'b' => 7, 'c' => ['side' => 'credit']]])
		);

	}//end testOfNormalisesTheLines()

	/**
	 * Balanced to the cent passes; off by a cent, no lines or an unsided line fails.
	 *
	 * @return void
	 */
	public function testBalanced(): void {
		$debit = ['side' => 'debit', 'amount' => '10'];
		$credit = ['side' => 'credit', 'amount' => 10.0];
		self::assertTrue(GlTransactionLines::balanced(transaction: ['lines' => [$debit, $credit]]));
		self::assertTrue(GlTransactionLines::balanced(transaction: ['lines' => [['side' => 'debit'], ['side' => 'credit']]]));
		self::assertFalse(GlTransactionLines::balanced(transaction: ['lines' => [$debit, ['side' => 'credit', 'amount' => 10.01]]]));
		self::assertFalse(GlTransactionLines::balanced(transaction: ['lines' => []]));
		self::assertFalse(GlTransactionLines::balanced(transaction: ['lines' => [$debit, $credit, ['amount' => 0]]]));
		self::assertFalse(GlTransactionLines::balanced(transaction: ['lines' => [['side' => 'debit', 'amount' => 1], ['side' => 'debit', 'amount' => -1], ['side' => 'x']]]));

	}//end testBalanced()
}//end class
