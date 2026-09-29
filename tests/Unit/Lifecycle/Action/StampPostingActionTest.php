<?php

/**
 * The handler that stamps a ledger transaction as it posts.
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
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\StampPostingAction;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * GLTransaction.post persists the stamps the guard judged it by, so the
 * posted entry still meets the ledger rules when an auditor reads it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StampPostingActionTest extends TestCase {

	/**
	 * Build the action with or without a session user.
	 *
	 * @param string|null $uid The acting user id, or null for no session.
	 *
	 * @return StampPostingAction
	 */
	private function action(?string $uid): StampPostingAction {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return new StampPostingAction($session);
	}//end action()

	/**
	 * The posting user is on the audit trail and the entry is locked.
	 *
	 * @return void
	 */
	public function testThePostIsStampedWithThePostingUser(): void {
		$result = $this->action('alice')->execute(
			['id' => 'gl-1', 'postingDate' => '2026-09-20', 'state' => 'posted'],
			['id' => 'gl-1', 'postingDate' => '2026-09-20', 'state' => 'draft'],
			[],
			StampPostingAction::class
		);

		self::assertTrue($result['postingLocked']);
		self::assertTrue($result['integrityVerified']);
		self::assertSame('2036-12-31', $result['retentionUntil']);
		self::assertSame('alice', $result['auditTrail'][0]['user']);
		self::assertSame('post', $result['auditTrail'][0]['action']);
	}//end testThePostIsStampedWithThePostingUser()

	/**
	 * A post outside a session (a job, occ) is recorded as the system.
	 *
	 * @return void
	 */
	public function testAPostWithoutASessionIsRecordedAsTheSystem(): void {
		$result = $this->action(null)->execute(['id' => 'gl-1', 'postingDate' => '2026-09-20'], [], [], StampPostingAction::class);

		self::assertSame('system', $result['auditTrail'][0]['user']);
	}//end testAPostWithoutASessionIsRecordedAsTheSystem()
}//end class
