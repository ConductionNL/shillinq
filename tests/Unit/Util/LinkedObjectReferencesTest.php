<?php

/**
 * Unit tests for LinkedObjectReferences.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Util;

use OCA\Shillinq\Util\LinkedObjectReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the linked-object reference helpers.
 *
 * @covers \OCA\Shillinq\Util\LinkedObjectReferences
 */
final class LinkedObjectReferencesTest extends TestCase {

	/**
	 * A well-formed list becomes trimmed `register/schema/id` references, in order.
	 */
	public function testReferencesJoinsTrimmedPartsInOrder(): void {
		$list = [
			['register' => ' finance ', 'schema' => 'invoice', 'id' => 'a1', 'title' => 'ignored'],
			['register' => 'crm', 'schema' => 'contact', 'id' => 7],
		];

		self::assertSame(['finance/invoice/a1', 'crm/contact/7'], LinkedObjectReferences::references(list: $list));
		self::assertTrue(LinkedObjectReferences::isWellFormed(list: $list));

	}//end testReferencesJoinsTrimmedPartsInOrder()

	/**
	 * An empty list is well formed and has no references.
	 */
	public function testEmptyListIsWellFormed(): void {
		self::assertSame([], LinkedObjectReferences::references(list: []));
		self::assertTrue(LinkedObjectReferences::isWellFormed(list: []));

	}//end testEmptyListIsWellFormed()

	/**
	 * Inputs that cannot be stored.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedLists(): array {
		return [
			'null' => [null],
			'string' => ['finance/invoice/a1'],
			'scalar item' => [['finance/invoice/a1']],
			'missing id' => [[['register' => 'finance', 'schema' => 'invoice']]],
			'blank schema' => [[['register' => 'finance', 'schema' => '  ', 'id' => 'a1']]],
			'empty register' => [[['register' => '', 'schema' => 'invoice', 'id' => 'a1']]],
			'one bad item among good' => [
				[
					['register' => 'finance', 'schema' => 'invoice', 'id' => 'a1'],
					['register' => 'finance', 'schema' => 'invoice'],
				],
			],
		];

	}//end malformedLists()

	/**
	 * A non-list, a non-array item, or an item missing a part refuses the whole list.
	 *
	 * @param mixed $list The input.
	 */
	#[DataProvider('malformedLists')]
	public function testMalformedListHasNoReferences(mixed $list): void {
		self::assertNull(LinkedObjectReferences::references(list: $list));
		self::assertFalse(LinkedObjectReferences::isWellFormed(list: $list));

	}//end testMalformedListHasNoReferences()

}//end class
