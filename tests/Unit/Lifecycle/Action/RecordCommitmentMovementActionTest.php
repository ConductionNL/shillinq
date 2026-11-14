<?php

/**
 * The lifecycle action that records a commitment movement in the commitment ledger.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\RecordCommitmentMovementAction;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The `kind` parameter picks closed or committed (the default); the movement
 * is recorded under the session user, or "system" without one, and the
 * commitment itself is returned unchanged.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\RecordCommitmentMovementAction
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RecordCommitmentMovementActionTest extends TestCase {

	private const COMMITMENT = ['id' => 'c-1', 'amount' => 500.0];

	/**
	 * A session that answers the given user, or none.
	 *
	 * @param string|null $uid The user id, or null for no session user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;

	}//end session()

	/**
	 * Without a kind, the commitment is recorded as committed by the session user.
	 *
	 * @return void
	 */
	public function testByDefaultTheCommitmentIsCommittedByTheSessionUser(): void {
		$ledger = $this->createMock(CommitmentLedger::class);
		$ledger->expects($this->never())->method('closed');
		$ledger->expects($this->once())->method('committed')->with(self::COMMITMENT, 'alice')->willReturn(3);

		$this->assertSame(self::COMMITMENT, (new RecordCommitmentMovementAction($ledger, $this->session('alice')))->execute(self::COMMITMENT, [], [], 'approve'));

	}//end testByDefaultTheCommitmentIsCommittedByTheSessionUser()

	/**
	 * Kind "closed" records the closing, not a commitment.
	 *
	 * @return void
	 */
	public function testTheClosedKindRecordsTheClosing(): void {
		$ledger = $this->createMock(CommitmentLedger::class);
		$ledger->expects($this->never())->method('committed');
		$ledger->expects($this->once())->method('closed')->with(self::COMMITMENT, 'bob')->willReturn(1);

		$this->assertSame(self::COMMITMENT, (new RecordCommitmentMovementAction($ledger, $this->session('bob')))->execute(self::COMMITMENT, [], ['kind' => 'closed'], 'close'));

	}//end testTheClosedKindRecordsTheClosing()

	/**
	 * Without a session user the movement is recorded as "system".
	 *
	 * @return void
	 */
	public function testWithoutASessionUserTheMovementIsBySystem(): void {
		$ledger = $this->createMock(CommitmentLedger::class);
		$ledger->expects($this->once())->method('closed')->with(self::COMMITMENT, 'system')->willReturn(0);

		$this->assertSame(self::COMMITMENT, (new RecordCommitmentMovementAction($ledger, $this->session(null)))->execute(self::COMMITMENT, [], ['kind' => 'closed'], 'close'));

	}//end testWithoutASessionUserTheMovementIsBySystem()

	/**
	 * Any kind other than "closed", including "committed" itself, records a commitment.
	 *
	 * @return void
	 */
	public function testAnyOtherKindCommits(): void {
		$ledger = $this->createMock(CommitmentLedger::class);
		$ledger->expects($this->never())->method('closed');
		$ledger->expects($this->exactly(2))->method('committed')->with(self::COMMITMENT, 'system')->willReturn(1);
		$action = new RecordCommitmentMovementAction($ledger, $this->session(null));

		$this->assertSame(self::COMMITMENT, $action->execute(self::COMMITMENT, [], ['kind' => 'committed'], 'approve'));
		$this->assertSame(self::COMMITMENT, $action->execute(self::COMMITMENT, [], ['kind' => 'Closed'], 'approve'));

	}//end testAnyOtherKindCommits()
}//end class
