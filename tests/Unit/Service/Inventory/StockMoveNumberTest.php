<?php

/**
 * Unit tests for StockMoveNumber.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Inventory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Inventory;

use OCA\Shillinq\Service\Inventory\StockMoveNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every derived movement number matches the StockMove schema's pattern.
 */
final class StockMoveNumberTest extends TestCase {

	private const PATTERN = '/^[A-Z0-9][A-Z0-9\-_]{0,31}$/';

	/**
	 * Candidates with their expected number.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function numbers(): array {
		return [
			'a delivery issue with a lower-case digest' => ['SM-DLV-fb59c1ac-000', 'SM-DLV-FB59C1AC-000'],
			'an accepted number is kept' => ['SM-CC-CC-2026-05-00001-001', 'SM-CC-CC-2026-05-00001-001'],
			'other characters become dashes' => ['SM 2026/adm.1', 'SM-2026-ADM-1'],
			'a leading dash is dropped' => ['-sm-1', 'SM-1'],
			'nothing usable' => ['//', 'SM'],
		];

	}//end numbers()

	/**
	 * The number is upper case and keeps what the schema accepts.
	 *
	 * @param string $candidate The derived number.
	 * @param string $expected  The expected number.
	 *
	 * @return void
	 */
	#[DataProvider('numbers')]
	public function testTheNumberIsInTheAcceptedForm(string $candidate, string $expected): void {
		$number = (new StockMoveNumber())->normalise(candidate: $candidate);

		self::assertSame(expected: $expected, actual: $number);
		self::assertMatchesRegularExpression(pattern: self::PATTERN, string: $number);

	}//end testTheNumberIsInTheAcceptedForm()

	/**
	 * A goods receipt number with its line id (35 characters) is cut to 32 and
	 * ends in its own digest, so two lines stay apart and a repeat stays equal.
	 *
	 * @return void
	 */
	public function testATooLongNumberIsCutAndStaysUnique(): void {
		$numbers = new StockMoveNumber();
		$lineA   = $numbers->normalise(candidate: 'SM-2026-ADM-001-000001-6F1C2D9E0000');
		$lineB   = $numbers->normalise(candidate: 'SM-2026-ADM-001-000001-7A2B3C4D0000');

		self::assertSame(expected: 32, actual: strlen($lineA));
		self::assertStringStartsWith(prefix: 'SM-2026-ADM-001-000001-', string: $lineA);
		self::assertMatchesRegularExpression(pattern: self::PATTERN, string: $lineA);
		self::assertNotSame(expected: $lineA, actual: $lineB);
		self::assertSame(expected: $lineA, actual: $numbers->normalise(candidate: 'SM-2026-ADM-001-000001-6F1C2D9E0000'));

	}//end testATooLongNumberIsCutAndStaysUnique()
}//end class
