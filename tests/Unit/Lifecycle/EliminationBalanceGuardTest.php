<?php

/**
 * Unit tests for EliminationBalanceGuard.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EliminationBalanceGuard (REQ-ICE-006).
 *
 * - A balanced journal (sum debit == sum credit) is accepted.
 * - An unbalanced journal is rejected.
 * - Float rounding is handled by integer-cent arithmetic.
 * - An empty/lineless journal is rejected (fail-closed).
 * - Supplied totals that disagree with the line sums are rejected.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EliminationBalanceGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var EliminationBalanceGuard
	 */
	private EliminationBalanceGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new EliminationBalanceGuard($this->logger);

	}//end setUp()

	/**
	 * A balanced two-line journal is accepted.
	 *
	 * @return void
	 */
	public function testBalancedJournalAccepted(): void {
		$journal = [
			'eliminationId' => 'elim-1',
			'lines' => [
				['glAccount' => '8200', 'debitAmount' => 100000.0, 'creditAmount' => 0.0],
				['glAccount' => '4400', 'debitAmount' => 0.0, 'creditAmount' => 100000.0],
			],
			'totalDebit' => 100000.0,
			'totalCredit' => 100000.0,
		];
		self::assertTrue($this->guard->isBalanced($journal));

	}//end testBalancedJournalAccepted()

	/**
	 * An unbalanced journal is rejected.
	 *
	 * @return void
	 */
	public function testUnbalancedJournalRejected(): void {
		$journal = [
			'eliminationId' => 'elim-2',
			'lines' => [
				['glAccount' => '8200', 'debitAmount' => 100000.0, 'creditAmount' => 0.0],
				['glAccount' => '4400', 'debitAmount' => 0.0, 'creditAmount' => 90000.0],
			],
		];
		self::assertFalse($this->guard->isBalanced($journal));

	}//end testUnbalancedJournalRejected()

	/**
	 * Float-rounding fragments still balance via integer-cent arithmetic.
	 *
	 * @return void
	 */
	public function testFloatRoundingBalances(): void {
		$journal = [
			'eliminationId' => 'elim-3',
			'lines' => [
				['glAccount' => 'a', 'debitAmount' => 0.1, 'creditAmount' => 0.0],
				['glAccount' => 'b', 'debitAmount' => 0.2, 'creditAmount' => 0.0],
				['glAccount' => 'c', 'debitAmount' => 0.0, 'creditAmount' => 0.3],
			],
		];
		self::assertTrue($this->guard->isBalanced($journal));

	}//end testFloatRoundingBalances()

	/**
	 * A journal with no lines is rejected (fail-closed).
	 *
	 * @return void
	 */
	public function testEmptyLinesRejected(): void {
		self::assertFalse($this->guard->isBalanced(['eliminationId' => 'elim-4', 'lines' => []]));
		self::assertFalse($this->guard->isBalanced(['eliminationId' => 'elim-5']));

	}//end testEmptyLinesRejected()

	/**
	 * Supplied totalDebit that disagrees with the line sum is rejected.
	 *
	 * @return void
	 */
	public function testInconsistentTotalDebitRejected(): void {
		$journal = [
			'eliminationId' => 'elim-6',
			'lines' => [
				['glAccount' => '8200', 'debitAmount' => 100000.0, 'creditAmount' => 0.0],
				['glAccount' => '4400', 'debitAmount' => 0.0, 'creditAmount' => 100000.0],
			],
			'totalDebit' => 99000.0,
			'totalCredit' => 100000.0,
		];
		self::assertFalse($this->guard->isBalanced($journal));

	}//end testInconsistentTotalDebitRejected()

	/**
	 * Non-array lines entries are skipped without throwing.
	 *
	 * @return void
	 */
	public function testNonArrayLineSkipped(): void {
		$journal = [
			'eliminationId' => 'elim-7',
			'lines' => [
				['glAccount' => '8200', 'debitAmount' => 50.0, 'creditAmount' => 0.0],
				'not-an-array',
				['glAccount' => '4400', 'debitAmount' => 0.0, 'creditAmount' => 50.0],
			],
		];
		self::assertTrue($this->guard->isBalanced($journal));

	}//end testNonArrayLineSkipped()
}//end class
