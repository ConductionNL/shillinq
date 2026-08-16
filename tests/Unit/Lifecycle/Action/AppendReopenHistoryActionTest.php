<?php

/**
 * Unit tests for AppendReopenHistoryAction.
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
 * @spec openspec/specs/bookkeeping-period-close/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\AppendReopenHistoryAction;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The handler for the `append-reopen-history` lifecycle action (REQ-PC-006).
 *
 * The action was declared on the FiscalPeriod `reopen` transition with no
 * handler registered, and OpenRegister's LifecycleActionRegistry is
 * fail-loud, so every successful reopen answered HTTP 500. The behaviour that
 * matters is therefore not just "it appends an entry" but that it is correct
 * on BOTH routes into a reopen:
 *
 *  - via PeriodCloseService::reopenPeriod(), which has already composed and
 *    persisted the history entry — the action must not duplicate it;
 *  - via a purely declarative transition, where this handler is the only
 *    thing that records the reopen at all.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AppendReopenHistoryActionTest extends TestCase {
	/**
	 * Build the action with or without a session user.
	 *
	 * @param string|null $uid The acting user id, or null for no session.
	 *
	 * @return AppendReopenHistoryAction The action under test.
	 */
	private function action(?string $uid = 'alice'): AppendReopenHistoryAction {
		$session = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new AppendReopenHistoryAction($session);
	}//end action()

	/**
	 * A declarative reopen records the prior close and clears the stamps.
	 *
	 * @return void
	 */
	public function testDeclarativeReopenAppendsTheEntryAndClearsTheCloseStamps(): void {
		// The stamps are seeded on the OBJECT, not only on previousData, so
		// that "cleared" is observable. Asserting assertNull() against a key
		// the payload never carried would pass on an undefined index — the
		// assertion would hold whether or not the handler clears anything.
		$result = $this->action()->execute(
			[
				'closeReason' => 'Audit adjustment',
				'closedAt' => '2026-01-31T18:00:00+00:00',
				'closedBy' => 'bob',
			],
			['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob'],
			[],
			'append-reopen-history'
		);

		self::assertCount(1, $result['reopenedHistory']);

		$entry = $result['reopenedHistory'][0];
		self::assertSame('2026-01-31T18:00:00+00:00', $entry['closedAt']);
		self::assertSame('bob', $entry['closedBy']);
		self::assertSame('alice', $entry['reopenedBy']);
		self::assertSame('Audit adjustment', $entry['closeReason']);
		self::assertNotNull($entry['reopenedAt']);

		// The period is open again: the close stamps must not survive, or a
		// later close-state read would still see the period as closed.
		self::assertArrayHasKey('closedAt', $result);
		self::assertArrayHasKey('closedBy', $result);
		self::assertNull($result['closedAt'], 'closedAt must be cleared on reopen.');
		self::assertNull($result['closedBy'], 'closedBy must be cleared on reopen.');

	}//end testDeclarativeReopenAppendsTheEntryAndClearsTheCloseStamps()

	/**
	 * The service route has already written the entry — do not duplicate it.
	 *
	 * This is the whole reason the handler carries an idempotency check: the
	 * declarative action runs on the SAME write that
	 * PeriodCloseService::reopenPeriod() composed, so a blind append would
	 * record every service-driven reopen twice in the audit history.
	 *
	 * @return void
	 */
	public function testAServiceWrittenEntryIsNotDuplicated(): void {
		$existing = [
			[
				'closedAt' => '2026-01-31T18:00:00+00:00',
				'closedBy' => 'bob',
				'reopenedAt' => '2026-02-01T09:00:00+00:00',
				'reopenedBy' => 'alice',
			],
		];

		$result = $this->action()->execute(
			['reopenedHistory' => $existing],
			['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob'],
			[],
			'append-reopen-history'
		);

		self::assertCount(1, $result['reopenedHistory'], 'The same reopen must not be recorded twice.');
		self::assertSame($existing[0], $result['reopenedHistory'][0]);

	}//end testAServiceWrittenEntryIsNotDuplicated()

	/**
	 * A SECOND, genuinely different reopen still appends.
	 *
	 * The guard keys on the prior close stamps, so it must not swallow a
	 * later reopen of a period that was closed again in between — that would
	 * silently lose an audit record.
	 *
	 * @return void
	 */
	public function testALaterDistinctReopenIsStillRecorded(): void {
		$existing = [
			[
				'closedAt' => '2026-01-31T18:00:00+00:00',
				'closedBy' => 'bob',
				'reopenedAt' => '2026-02-01T09:00:00+00:00',
				'reopenedBy' => 'alice',
			],
		];

		$result = $this->action()->execute(
			['reopenedHistory' => $existing],
			['closedAt' => '2026-02-28T18:00:00+00:00', 'closedBy' => 'carol'],
			[],
			'append-reopen-history'
		);

		self::assertCount(2, $result['reopenedHistory']);
		self::assertSame('2026-02-28T18:00:00+00:00', $result['reopenedHistory'][1]['closedAt']);
		self::assertSame('carol', $result['reopenedHistory'][1]['closedBy']);

	}//end testALaterDistinctReopenIsStillRecorded()

	/**
	 * A half-written tail entry (no reopenedAt) is not treated as recorded.
	 *
	 * @return void
	 */
	public function testATailEntryWithoutReopenedAtDoesNotSuppressTheAppend(): void {
		$result = $this->action()->execute(
			['reopenedHistory' => [['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob']]],
			['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob'],
			[],
			'append-reopen-history'
		);

		self::assertCount(2, $result['reopenedHistory']);

	}//end testATailEntryWithoutReopenedAtDoesNotSuppressTheAppend()

	/**
	 * `actionParameters.target` redirects the history property.
	 *
	 * @return void
	 */
	public function testTargetParameterRedirectsTheHistoryProperty(): void {
		$result = $this->action()->execute(
			[],
			['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob'],
			['target' => 'auditHistory'],
			'append-reopen-history'
		);

		self::assertArrayHasKey('auditHistory', $result);
		self::assertCount(1, $result['auditHistory']);
		self::assertArrayNotHasKey('reopenedHistory', $result);

	}//end testTargetParameterRedirectsTheHistoryProperty()

	/**
	 * A blank target falls back to the default rather than writing to "".
	 *
	 * @return void
	 */
	public function testBlankTargetFallsBackToTheDefaultProperty(): void {
		$result = $this->action()->execute(
			[],
			['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob'],
			['target' => '   '],
			'append-reopen-history'
		);

		self::assertCount(1, $result['reopenedHistory']);
		self::assertArrayNotHasKey('', $result);

	}//end testBlankTargetFallsBackToTheDefaultProperty()

	/**
	 * A non-array history value is replaced, not appended to.
	 *
	 * @return void
	 */
	public function testACorruptHistoryValueIsReplacedRatherThanFatal(): void {
		$result = $this->action()->execute(
			['reopenedHistory' => 'not-an-array'],
			['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob'],
			[],
			'append-reopen-history'
		);

		self::assertIsArray($result['reopenedHistory']);
		self::assertCount(1, $result['reopenedHistory']);

	}//end testACorruptHistoryValueIsReplacedRatherThanFatal()

	/**
	 * Outside a user session the actor is `system`, not null or empty.
	 *
	 * A background or job-driven reopen still has to name someone in the
	 * audit trail.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheActorIsSystem(): void {
		$result = $this->action(null)->execute(
			[],
			['closedAt' => '2026-01-31T18:00:00+00:00', 'closedBy' => 'bob'],
			[],
			'append-reopen-history'
		);

		self::assertSame('system', $result['reopenedHistory'][0]['reopenedBy']);

	}//end testWithoutASessionTheActorIsSystem()

	/**
	 * When only the post-transition payload carries the close stamps, they
	 * are still recorded.
	 *
	 * @return void
	 */
	public function testCloseStampsAreReadFromTheObjectWhenPreviousDataIsEmpty(): void {
		$result = $this->action()->execute(
			['closedAt' => '2026-03-31T18:00:00+00:00', 'closedBy' => 'dave'],
			[],
			[],
			'append-reopen-history'
		);

		self::assertSame('2026-03-31T18:00:00+00:00', $result['reopenedHistory'][0]['closedAt']);
		self::assertSame('dave', $result['reopenedHistory'][0]['closedBy']);

	}//end testCloseStampsAreReadFromTheObjectWhenPreviousDataIsEmpty()
}//end class
