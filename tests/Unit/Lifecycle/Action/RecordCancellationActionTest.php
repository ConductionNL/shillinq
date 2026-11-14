<?php

/**
 * Unit tests for RecordCancellationAction.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use DateTime;
use DateTimeZone;
use OCA\Shillinq\Lifecycle\Action\RecordCancellationAction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Ending a profile writes its cancellation record.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\RecordCancellationAction
 * @uses   \OCA\Shillinq\Lifecycle\CancellationReasonGuard
 */
class RecordCancellationActionTest extends TestCase {

	/**
	 * The action with the clock at 2026-09-15 10:00 UTC, staff signed in or nobody.
	 *
	 * @param bool $signedIn Whether a staff member is signed in.
	 *
	 * @return RecordCancellationAction
	 */
	private function action(bool $signedIn = true): RecordCancellationAction {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-15T10:00:00', new DateTimeZone('UTC')));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new RecordCancellationAction(time: $time, session: $session);
	}//end action()

	/**
	 * A staff end records the reason, staff, now and the end of the billing period.
	 *
	 * @return void
	 */
	public function testAStaffEndIsRecordedWithTheReasonAndTheEndOfThePeriod(): void {
		$saved = $this->action()->execute(
			[
				'status' => 'ended',
				'cancellationReasonCode' => 'too-expensive',
				'cancellationReasonText' => ' Te duur geworden ',
				'nextRunDate' => '2026-10-01',
			],
			['status' => 'active'],
			[],
			'endFromActive'
		);

		$this->assertSame(
			[
				'reasonCode' => 'too-expensive',
				'reasonText' => 'Te duur geworden',
				'cancelledBy' => 'staff',
				'requestedAt' => '2026-09-15T10:00:00+00:00',
				'effectiveDate' => '2026-09-30',
			],
			$saved['cancellation']
		);
		$this->assertSame('ended', $saved['status']);
	}//end testAStaffEndIsRecordedWithTheReasonAndTheEndOfThePeriod()

	/**
	 * With nobody signed in the end is the customer's, and may carry no reason.
	 *
	 * @return void
	 */
	public function testAnEndWithNobodySignedInIsTheCustomers(): void {
		$saved = $this->action(signedIn: false)->execute(['nextRunDate' => '2026-12-01'], [], [], 'endFromActive');

		$this->assertSame('not-given', $saved['cancellation']['reasonCode']);
		$this->assertNull($saved['cancellation']['reasonText']);
		$this->assertSame('customer', $saved['cancellation']['cancelledBy']);
		$this->assertSame('2026-09-15T10:00:00+00:00', $saved['cancellation']['requestedAt']);
		$this->assertSame('2026-11-30', $saved['cancellation']['effectiveDate']);
		$this->assertSame('2026-Q4', $saved['cancellationQuarter']);
	}//end testAnEndWithNobodySignedInIsTheCustomers()

	/**
	 * A profile that ran out of occurrences was not cancelled: no record.
	 *
	 * @return void
	 */
	public function testAnEndByTheProfilesOwnConditionIsNoCancellation(): void {
		$profile = ['status' => 'ended', 'remainingOccurrences' => 0];

		$this->assertSame($profile, $this->action(signedIn: false)->execute($profile, [], [], 'endFromActive'));
	}//end testAnEndByTheProfilesOwnConditionIsNoCancellation()

	/**
	 * The effective date is today when no later run is planned.
	 *
	 * @return void
	 */
	public function testTheEffectiveDateIsTodayWithoutALaterRun(): void {
		$today = new DateTime('2026-09-15T10:00:00', new DateTimeZone('UTC'));

		$this->assertSame('2026-09-15', RecordCancellationAction::effectiveDate(nextRunDate: '2026-09-15', today: $today));
		$this->assertSame('2026-09-15', RecordCancellationAction::effectiveDate(nextRunDate: null, today: $today));
		$this->assertSame('2026-09-15', RecordCancellationAction::effectiveDate(nextRunDate: 'soon', today: $today));
		$this->assertSame('2026-10-31', RecordCancellationAction::effectiveDate(nextRunDate: '2026-11-01', today: $today));
	}//end testTheEffectiveDateIsTodayWithoutALaterRun()
	/**
	 * The report fields: the normalised code, the quarter and the lost monthly amount.
	 *
	 * @return void
	 */
	public function testTheReportFieldsAreWrittenWithTheCancellation(): void {
		$saved = $this->action()->execute(
			[
				'cancellationReasonCode' => 'switched-provider',
				'nextRunDate' => '2026-10-01',
				'frequency' => 'quarterly',
				'interval' => 1,
				'lines' => [['quantity' => 2, 'unitPrice' => 150.0], ['quantity' => 1, 'unitPrice' => 50.0]],
			],
			[],
			[],
			'endFromActive'
		);

		$this->assertSame('switched-provider', $saved['cancellationReasonCode']);
		$this->assertSame('2026-Q3', $saved['cancellationQuarter']);
		$this->assertSame(116.67, $saved['cancellationMonthlyAmount']);
	}//end testTheReportFieldsAreWrittenWithTheCancellation()

	/**
	 * Quarters and monthly amounts for each cadence.
	 *
	 * @return void
	 */
	public function testQuartersAndCadences(): void {
		$this->assertSame('2026-Q1', RecordCancellationAction::quarterOf(date: '2026-03-31'));
		$this->assertSame('2026-Q2', RecordCancellationAction::quarterOf(date: '2026-04-01'));
		$this->assertSame('2026-Q4', RecordCancellationAction::quarterOf(date: '2026-12-01'));

		$line = ['lines' => [['quantity' => 1, 'unitPrice' => 120.0]]];
		$this->assertSame(120.0, RecordCancellationAction::monthlyAmount(profile: $line));
		$this->assertSame(60.0, RecordCancellationAction::monthlyAmount(profile: $line + ['frequency' => 'monthly', 'interval' => 2]));
		$this->assertSame(20.0, RecordCancellationAction::monthlyAmount(profile: $line + ['frequency' => 'semi-annually']));
		$this->assertSame(10.0, RecordCancellationAction::monthlyAmount(profile: $line + ['frequency' => 'annually']));
		$this->assertSame(520.0, RecordCancellationAction::monthlyAmount(profile: $line + ['frequency' => 'weekly']));
	}//end testQuartersAndCadences()
}//end class
