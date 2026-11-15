<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetMonthFields;
use OCA\Shillinq\Service\LedgerGroupEntryKey;
use PHPUnit\Framework\TestCase;

/**
 * Pins the budget line month fields and the LedgerGroup entry key.
 *
 * @covers \OCA\Shillinq\Service\BudgetMonthFields
 * @covers \OCA\Shillinq\Service\LedgerGroupEntryKey
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 */
final class BudgetMonthFieldsTest extends TestCase {

	/**
	 * Months 1-12 map to their field; anything else to January's.
	 *
	 * @return void
	 */
	public function testMonthField(): void {
		self::assertCount(12, BudgetMonthFields::FIELDS);
		self::assertSame('month01Amount', BudgetMonthFields::of(1));
		self::assertSame('month07Amount', BudgetMonthFields::of(7));
		self::assertSame('month12Amount', BudgetMonthFields::of(12));
		self::assertSame('month01Amount', BudgetMonthFields::of(0));
		self::assertSame('month01Amount', BudgetMonthFields::of(13));

	}//end testMonthField()

	/**
	 * The id wins, the slug is the fallback, and nothing set (or no entry) is null.
	 *
	 * @return void
	 */
	public function testEntryKey(): void {
		self::assertSame('g-1', LedgerGroupEntryKey::of(['id' => 'g-1', 'slug' => 'personeel']));
		self::assertSame('personeel', LedgerGroupEntryKey::of(['id' => '', 'slug' => 'personeel']));
		self::assertNull(LedgerGroupEntryKey::of(['id' => '', 'slug' => '']));
		self::assertNull(LedgerGroupEntryKey::of([]));
		self::assertNull(LedgerGroupEntryKey::of(null));

	}//end testEntryKey()
}//end class
