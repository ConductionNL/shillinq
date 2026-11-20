<?php

/**
 * Golden test for the EmuCalculator sector saldo.
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

use OCA\Shillinq\Guard\EmuCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which lines count per sector, the cent rounding, the partial half and the debug lines.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class EmuCalculatorGoldenTest extends TestCase {

	/**
	 * Account classifier first, then the line's; excluded and unclassified lines skipped; partial lines halved.
	 *
	 * @return void
	 */
	public function testSaldo(): void {
		$lines  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context=[]) use (&$lines): void {
				$lines[] = [$message, $context];
			}
		);
		$gl = [
			['account' => ['esaClassifier' => 'S13'], 'esaClassifier' => 'S11', 'debit' => '10.005', 'credit' => 0],
			['esaClassifier' => 'S11', 'debit' => 1, 'credit' => 3.5],
			['account' => [], 'debit' => 100],
			['esaClassifier' => 'S13', 'emuInclusionRule' => 'excluded', 'debit' => 1000],
			['esaClassifier' => 'S13', 'emuInclusionRule' => 'partial', 'debit' => 0.03],
			['esaClassifier' => 'S13', 'emuInclusionRule' => 'partial', 'credit' => 0.07],
			['esaClassifier' => 'S1311', 'emuInclusionRule' => 'other', 'credit' => '2'],
			['account' => ['esaClassifier' => null], 'esaClassifier' => 'S11', 'debit' => 0.01],
		];
		$guard = new EmuCalculator($logger);
		$saldo = ['S13' => 999, 'S11' => -249, 'S1311' => -200];
		self::assertSame(expected: $saldo, actual: $guard->computeQuarterlySaldo(glLines: $gl, params: ['q' => 1]));
		self::assertSame(expected: $saldo, actual: $guard->computeAnnualSaldo(glLines: $gl, params: []));
		self::assertSame(expected: [], actual: $guard->computeAnnualSaldo(glLines: [], params: []));
		$expected = [
			['EmuCalculator: quarterly saldo', ['params' => ['q' => 1], 'lines' => 8]],
			['EmuCalculator: annual saldo', ['params' => [], 'lines' => 8]],
			['EmuCalculator: annual saldo', ['params' => [], 'lines' => 0]],
		];
		self::assertSame(expected: $expected, actual: $lines);
	}//end testSaldo()
}//end class
