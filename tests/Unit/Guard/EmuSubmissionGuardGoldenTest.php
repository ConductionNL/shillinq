<?php

/**
 * Golden test for EmuSubmissionGuard::requireApproval.
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

use OCA\Shillinq\Guard\EmuSubmissionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every submission decision and the info line of each refusal.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class EmuSubmissionGuardGoldenTest extends TestCase {

	/**
	 * A draft with a computed saldo and a reconciliation that did not fail may be submitted.
	 *
	 * @return void
	 */
	public function testDecisions(): void {
		$lines  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context=[]) use (&$lines): void {
				$lines[] = [$message, $context];
			}
		);
		$guard = new EmuSubmissionGuard($logger);
		$cases = [
			[['status' => 'draft', 'emuBalanceCalculated' => 0], true],
			[['status' => 'draft', 'emuBalanceCalculated' => ['S13' => 1], 'bbvReconciliationCheck' => 'passed'], true],
			[['status' => 'draft', 'emuBalanceCalculated' => 0, 'bbvReconciliationCheck' => 'failed'], false],
			[['status' => 'draft', 'emuBalanceCalculated' => null], false],
			[['status' => 'draft'], false],
			[['status' => 'submitted', 'emuBalanceCalculated' => 5], false],
			[[], false],
		];
		$seen  = [];
		foreach ($cases as [$report]) {
			$seen[] = $guard->requireApproval(emuReport: $report);
		}

		self::assertSame(expected: array_column($cases, 1), actual: $seen);
		$message  = 'EmuSubmissionGuard: indiening blocked';
		$expected = [
			[$message, ['status' => 'draft', 'saldoComputed' => true, 'aansluitingscontrole' => 'failed']],
			[$message, ['status' => 'draft', 'saldoComputed' => false, 'aansluitingscontrole' => 'non-executed']],
			[$message, ['status' => 'draft', 'saldoComputed' => false, 'aansluitingscontrole' => 'non-executed']],
			[$message, ['status' => 'submitted', 'saldoComputed' => true, 'aansluitingscontrole' => 'non-executed']],
			[$message, ['status' => '', 'saldoComputed' => false, 'aansluitingscontrole' => 'non-executed']],
		];
		self::assertSame(expected: $expected, actual: $lines);
	}//end testDecisions()
}//end class
