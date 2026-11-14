<?php

/**
 * Unit tests for WbsoTransactionGuard.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\WbsoTransactionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A draft transaction with an ISO date and a non-negative amount may be
 * posted; only a posted transaction may be reversed.
 *
 * @covers \OCA\Shillinq\Lifecycle\WbsoTransactionGuard
 */
final class WbsoTransactionGuardTest extends TestCase {

	/**
	 * The guard with a logger that expects the given debug lines.
	 *
	 * @param array<int, string> $expected The debug messages, in order.
	 *
	 * @return WbsoTransactionGuard
	 */
	private function guard(array $expected): WbsoTransactionGuard {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(count($expected)))
			->method('debug')
			->willReturnCallback(
				function (string $message) use (&$expected): void {
					$this->assertSame(array_shift($expected), $message);
				}
			);

		return new WbsoTransactionGuard(logger: $logger);

	}//end guard()

	/**
	 * Valid drafts post.
	 *
	 * @return void
	 */
	public function testAValidDraftPosts(): void {
		$guard = $this->guard([]);
		$this->assertTrue($guard->canPost(['status' => 'draft', 'transactionDate' => '2026-03-31', 'amount' => '1250.50']));
		$this->assertTrue($guard->canPost(['status' => 'draft', 'transactionDate' => '2026-03-31', 'amount' => 0]));

	}//end testAValidDraftPosts()

	/**
	 * Each defect refuses with its own reason.
	 *
	 * @param array<string, mixed> $record The transaction.
	 * @param string               $reason The logged reason.
	 *
	 * @return void
	 */
	#[DataProvider('refusedPosts')]
	public function testARefusedPostLogsWhy(array $record, string $reason): void {
		$this->assertFalse($this->guard(['WbsoTransactionGuard: canPost rejected — ' . $reason])->canPost($record));

	}//end testARefusedPostLogsWhy()

	/**
	 * Transactions that may not post, and why.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedPosts(): array {
		$draft = ['status' => 'draft', 'transactionDate' => '2026-03-31', 'amount' => 10];
		return [
			'already posted'   => [['status' => 'posted'] + $draft, 'not in draft'],
			'no status'        => [array_diff_key($draft, ['status' => 1]), 'not in draft'],
			'Dutch date order' => [['transactionDate' => '31-03-2026'] + $draft, 'malformed transactionDate'],
			'date with time'   => [['transactionDate' => '2026-03-31T10:00'] + $draft, 'malformed transactionDate'],
			'no date'          => [array_diff_key($draft, ['transactionDate' => 1]), 'malformed transactionDate'],
			'negative amount'  => [['amount' => -0.01] + $draft, 'invalid amount'],
			'text amount'      => [['amount' => 'ten'] + $draft, 'invalid amount'],
			'no amount'        => [array_diff_key($draft, ['amount' => 1]), 'invalid amount'],
		];

	}//end refusedPosts()

	/**
	 * Only posted transactions reverse.
	 *
	 * @return void
	 */
	public function testOnlyAPostedTransactionReverses(): void {
		$this->assertTrue($this->guard([])->canReverse(['status' => 'posted']));
		$guard = $this->guard(['WbsoTransactionGuard: canReverse rejected — not in posted', 'WbsoTransactionGuard: canReverse rejected — not in posted']);
		$this->assertFalse($guard->canReverse(['status' => 'reversed']));
		$this->assertFalse($guard->canReverse([]));

	}//end testOnlyAPostedTransactionReverses()
}//end class
