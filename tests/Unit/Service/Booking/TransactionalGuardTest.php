<?php

/**
 * Unit tests for Booking\TransactionalGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies REQ-BRC-004 transactional facade contract: with no DB wired
 * (test stack) the local tracker is exercised; with a DB the calls forward
 * to IDBConnection; lockResourceRow guards must be obeyed.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-4
 */
final class TransactionalGuardTest extends TestCase {

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Local (DB=null) flow: begin → inTransaction true → commit clears.
	 *
	 * @return void
	 */
	public function testLocalLifecycleTracksFlag(): void {
		$guard = new TransactionalGuard(null, $this->logger);

		self::assertFalse($guard->inTransaction());
		$guard->beginTransaction();
		self::assertTrue($guard->inTransaction());
		$guard->commit();
		self::assertFalse($guard->inTransaction());

	}//end testLocalLifecycleTracksFlag()

	/**
	 * Local rollBack clears the flag.
	 *
	 * @return void
	 */
	public function testLocalRollBackClearsFlag(): void {
		$guard = new TransactionalGuard(null, $this->logger);

		$guard->beginTransaction();
		$guard->rollBack();
		self::assertFalse($guard->inTransaction());

	}//end testLocalRollBackClearsFlag()

	/**
	 * With no DB wired, lockResourceRow is a no-op and returns false.
	 *
	 * @return void
	 */
	public function testLocalLockResourceRowReturnsFalse(): void {
		$guard = new TransactionalGuard(null, $this->logger);

		self::assertFalse($guard->lockResourceRow('res-1'));

	}//end testLocalLockResourceRowReturnsFalse()

	/**
	 * With a DB wired, begin/commit/rollBack forward to the connection.
	 *
	 * @return void
	 */
	public function testDbLifecycleForwardsCalls(): void {
		$db = $this->createMock(IDBConnection::class);

		$db->expects(self::once())->method('beginTransaction');
		$db->method('inTransaction')->willReturn(true);
		$db->expects(self::once())->method('commit');

		$guard = new TransactionalGuard($db, $this->logger);

		$guard->beginTransaction();
		self::assertTrue($guard->inTransaction());
		$guard->commit();

	}//end testDbLifecycleForwardsCalls()

	/**
	 * commit() is a no-op when the connection isn't in a transaction.
	 *
	 * @return void
	 */
	public function testDbCommitIsNoOpWhenNoTransactionOpen(): void {
		$db = $this->createMock(IDBConnection::class);

		$db->method('inTransaction')->willReturn(false);
		$db->expects(self::never())->method('commit');

		$guard = new TransactionalGuard($db, $this->logger);
		$guard->commit(); // must not throw

	}//end testDbCommitIsNoOpWhenNoTransactionOpen()

	/**
	 * rollBack() is a no-op when no transaction is open.
	 *
	 * @return void
	 */
	public function testDbRollBackIsNoOpWhenNoTransactionOpen(): void {
		$db = $this->createMock(IDBConnection::class);

		$db->method('inTransaction')->willReturn(false);
		$db->expects(self::never())->method('rollBack');

		$guard = new TransactionalGuard($db, $this->logger);
		$guard->rollBack();

	}//end testDbRollBackIsNoOpWhenNoTransactionOpen()

	/**
	 * lockResourceRow throws a LogicException when called outside a tx.
	 *
	 * @return void
	 */
	public function testLockResourceRowRequiresTransaction(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);

		$guard = new TransactionalGuard($db, $this->logger);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('lockResourceRow must be called inside a transaction');

		$guard->lockResourceRow('res-1');

	}//end testLockResourceRowRequiresTransaction()

}//end class
