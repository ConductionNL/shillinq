<?php

/**
 * Golden test for the OSS and KOR turnover sums.
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

use OCA\Shillinq\Guard\KorThresholdGuard;
use OCA\Shillinq\Guard\OssThresholdGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which invoices each sum counts, which field wins, and the KOR debug line.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ThresholdGuardsGoldenTest extends TestCase {

	/**
	 * One invoice per rule, each worth a distinct power of two so the sum names the counted ones.
	 *
	 * @return array<int,mixed>
	 */
	private static function invoices(): array {
		$oss  = ['ossContext' => ['ossEligible' => true]];
		$base = array_merge($oss, ['administrationId' => 'a1', 'invoiceDate' => '2025-03-01']);
		return [
			array_merge($base, ['netAmount' => 1, 'amount' => 1000000]),
			array_merge($base, ['administrationId' => 'a2', 'netAmount' => 2, 'amount' => 2]),
			array_merge($base, ['ossContext' => ['ossEligible' => 'yes'], 'netAmount' => 4, 'amount' => 4]),
			array_merge($base, ['ossContext' => 'x', 'netAmount' => 8, 'amount' => 8]),
			array_merge($base, ['ossContext' => null, 'netAmount' => 16, 'amount' => 16]),
			array_merge($base, ['invoiceDate' => '2024-12-31', 'netAmount' => 32, 'amount' => 32]),
			array_merge($oss, ['administrationId' => 'a1', 'issueDate' => '2025-01-01', 'netAmount' => 64, 'amount' => 64]),
			array_merge($base, ['invoiceDate' => '', 'issueDate' => '2025-01-01', 'netAmount' => 128, 'amount' => 128]),
			array_merge($base, ['state' => 'cancelled', 'netAmount' => 256, 'amount' => 256]),
			array_merge($base, ['status' => 'credited', 'netAmount' => 512, 'amount' => 512]),
			array_merge($base, ['state' => 'paid', 'status' => 'cancelled', 'netAmount' => 1024, 'amount' => 1024]),
			array_merge($base, ['state' => 'cancelled', 'status' => 'paid', 'netAmount' => 2048, 'amount' => 2048]),
			array_merge($base, ['documentType' => 'credit-note', 'netAmount' => 4096, 'amount' => 4096]),
			array_merge($base, ['amount' => 8192]),
			array_merge($base, ['netAmount' => '0.015']),
			array_merge($base, ['administrationId' => 7, 'netAmount' => 16384, 'amount' => 16384]),
		];
	}//end invoices()

	/**
	 * The OSS sum: own administration, OSS-eligible, in the year (an empty invoiceDate does not fall back),
	 * not cancelled or credited (state first), not a credit note, net first, rounded to cents.
	 *
	 * @return void
	 */
	public function testOssTurnover(): void {
		$guard = new OssThresholdGuard($this->createMock(LoggerInterface::class));
		$rows  = self::invoices();
		self::assertSame(expected: 9281.02, actual: $guard->currentB2cEuTurnover(invoices: $rows, administrationId: 'a1', year: 2025));
		self::assertSame(expected: 16384.0, actual: $guard->currentB2cEuTurnover(invoices: $rows, administrationId: '7', year: 2025));
		self::assertSame(expected: 32.0, actual: $guard->currentB2cEuTurnover(invoices: $rows, administrationId: 'a1', year: 2024));
	}//end testOssTurnover()

	/**
	 * The KOR sum: own administration (strict), in the year, not cancelled or credited (status first),
	 * not a credit note, gross first, unrounded; plus its debug line.
	 *
	 * @return void
	 */
	public function testKorRevenue(): void {
		$lines  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context=[]) use (&$lines): void {
				$lines[] = [$message, $context];
			}
		);
		$guard = new KorThresholdGuard($logger);
		$rows  = self::invoices();
		self::assertSame(expected: 1010332.015, actual: $guard->currentYtdRevenue(invoices: $rows, administrationId: 'a1', year: 2025));
		self::assertSame(expected: 0.0, actual: $guard->currentYtdRevenue(invoices: $rows, administrationId: '7', year: 2025));
		self::assertSame(expected: 32.0, actual: $guard->currentYtdRevenue(invoices: $rows, administrationId: 'a1', year: 2024));
		$context = ['administrationId' => 'a1', 'year' => 2025, 'invoices' => 16];
		self::assertSame(expected: ['KorThresholdGuard: currentYtdRevenue', $context], actual: $lines[0]);
		self::assertCount(3, $lines);
	}//end testKorRevenue()
}//end class
