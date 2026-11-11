<?php

/**
 * Golden test for UrencriteriumGuard::currentYtdHours.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\UrencriteriumGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which hour records count toward the year-to-date total, and the debug line.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class UrencriteriumGuardGoldenTest extends TestCase {

	/**
	 * Own person (strict), work date in the year, not excluded; hours cast to float.
	 *
	 * @return void
	 */
	public function testYtdHours(): void {
		$lines  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context=[]) use (&$lines): void {
				$lines[] = [$message, $context];
			}
		);
		$records = [
			['personId' => 'p1', 'workDate' => '2026-01-02', 'hours' => 1],
			['personId' => 'p2', 'workDate' => '2026-01-02', 'hours' => 2],
			['personId' => 7, 'workDate' => '2026-01-02', 'hours' => 4],
			['personId' => 'p1', 'workDate' => '2025-12-31', 'hours' => 8],
			['personId' => 'p1', 'hours' => 16],
			['personId' => 'p1', 'workDate' => '2026-03-01', 'category' => 'excluded', 'hours' => 32],
			['personId' => 'p1', 'workDate' => '2026-03-01', 'category' => 'Excluded', 'hours' => '64.5'],
			['personId' => 'p1', 'workDate' => '2026'],
		];
		$guard   = new UrencriteriumGuard($logger);
		self::assertSame(expected: 65.5, actual: $guard->currentYtdHours(hourRecords: $records, personId: 'p1', year: 2026));
		self::assertSame(expected: 0.0, actual: $guard->currentYtdHours(hourRecords: $records, personId: '7', year: 2026));
		self::assertSame(expected: 8.0, actual: $guard->currentYtdHours(hourRecords: $records, personId: 'p1', year: 2025));
		$expected = [
			['UrencriteriumGuard: currentYtdHours', ['personId' => 'p1', 'year' => 2026, 'records' => 8]],
			['UrencriteriumGuard: currentYtdHours', ['personId' => '7', 'year' => 2026, 'records' => 8]],
			['UrencriteriumGuard: currentYtdHours', ['personId' => 'p1', 'year' => 2025, 'records' => 8]],
		];
		self::assertSame(expected: $expected, actual: $lines);
	}//end testYtdHours()
}//end class
