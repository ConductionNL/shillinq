<?php

/**
 * Golden test for EliminationBalanceGuard::isBalanced.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\EliminationBalanceGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result and every log record per journal.
 *
 * Regenerate with ELIMINATION_BALANCE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class EliminationBalanceGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/elimination-balance-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$lines = [
			['debitAmount' => '100.005'],
			['creditAmount' => 60.0],
			'junk',
			['creditAmount' => '40.01', 'debitAmount' => 0],
		];
		$journals = [
			'noLines' => ['eliminationId' => 'E-1'],
			'emptyLines' => ['lines' => []],
			'nonArrayLines' => ['lines' => 'x'],
			'balanced' => ['eliminationId' => 'E-2', 'lines' => $lines],
			'unbalanced' => ['eliminationId' => 'E-3', 'lines' => [['debitAmount' => 10], ['creditAmount' => 9.99]]],
			'totalsAgree' => ['lines' => $lines, 'totalDebit' => '100.01', 'totalCredit' => 100.01],
			'debitTotalOff' => ['lines' => $lines, 'totalDebit' => 100.0],
			'creditTotalOff' => ['lines' => $lines, 'totalDebit' => 100.01, 'totalCredit' => 100.02],
			'onlyJunk' => ['lines' => ['junk']],
		];

		$actual = [];
		foreach ($journals as $name => $journal) {
			$logger = new class extends AbstractLogger {

				public array $records = [];

				public function log($level, \Stringable|string $message, array $context = []): void {
					$this->records[] = [(string) $level, (string) $message, $context];
				}
			};
			$actual[$name] = [
				'result' => (new EliminationBalanceGuard($logger))->isBalanced($journal),
				'logs' => $logger->records,
			];
		}

		if (getenv('ELIMINATION_BALANCE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}
}
