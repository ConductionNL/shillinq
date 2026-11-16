<?php

/**
 * ReceiptInputTest — receipt id lists, string lists and quantities.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ReceiptInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers OCA\Shillinq\Service\ReceiptInput.
 */
#[CoversClass(ReceiptInput::class)]
final class ReceiptInputTest extends TestCase {

	/**
	 * PO ids are trimmed, de-duplicated and reindexed; empties are dropped.
	 *
	 * @return void
	 */
	public function testPoIdsAreTrimmedAndDistinct(): void {
		self::assertSame(['po-1', 'po-2'], ReceiptInput::poIds(rawIds: [' po-1 ', '', 'po-2', 'po-1', '  ']));

	}//end testPoIdsAreTrimmedAndDistinct()

	/**
	 * A scalar PO id input is no list at all.
	 *
	 * @return void
	 */
	public function testAScalarPoIdInputIsEmpty(): void {
		self::assertSame([], ReceiptInput::poIds(rawIds: 'po-1'));

	}//end testAScalarPoIdInputIsEmpty()

	/**
	 * String lists keep duplicates and order but drop empties.
	 *
	 * @return void
	 */
	public function testStringsKeepDuplicatesAndDropEmpties(): void {
		self::assertSame(['a', 'b', 'a', '7'], ReceiptInput::strings(input: ['a', ' b ', '', 'a', 7]));
		self::assertSame([], ReceiptInput::strings(input: null));

	}//end testStringsKeepDuplicatesAndDropEmpties()

	/**
	 * Quantities round to thousandths and never go negative.
	 *
	 * @return void
	 */
	public function testQuantitiesRoundToThousandthsAndClampAtZero(): void {
		self::assertSame(1.235, ReceiptInput::quantity(value: '1.2346'));
		self::assertSame(3.0, ReceiptInput::quantity(value: 3));
		self::assertSame(0.0, ReceiptInput::quantity(value: -2.5));
		self::assertSame(0.0, ReceiptInput::quantity(value: 'n/a'));

	}//end testQuantitiesRoundToThousandthsAndClampAtZero()
}//end class
