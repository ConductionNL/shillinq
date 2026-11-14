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
use PHPUnit\Framework\TestCase;

/**
 * Ending a profile writes its cancellation record.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\RecordCancellationAction
 * @uses   \OCA\Shillinq\Lifecycle\CancellationReasonGuard
 */
class RecordCancellationActionTest extends TestCase {

	/**
	 * The action with the clock at 2026-09-15 10:00 UTC.
	 *
	 * @return RecordCancellationAction
	 */
	private function action(): RecordCancellationAction {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-15T10:00:00', new DateTimeZone('UTC')));

		return new RecordCancellationAction(time: $time);
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
	 * A customer end keeps what the portal set and fills not-given.
	 *
	 * @return void
	 */
	public function testACustomerEndKeepsThePortalsMomentAndDate(): void {
		$saved = $this->action()->execute(
			[
				'cancellation' => ['cancelledBy' => 'customer', 'requestedAt' => '2026-09-14T08:00:00+00:00', 'effectiveDate' => '2026-09-30'],
				'nextRunDate' => '2026-12-01',
			],
			[],
			[],
			'endFromActive'
		);

		$this->assertSame('not-given', $saved['cancellation']['reasonCode']);
		$this->assertNull($saved['cancellation']['reasonText']);
		$this->assertSame('customer', $saved['cancellation']['cancelledBy']);
		$this->assertSame('2026-09-14T08:00:00+00:00', $saved['cancellation']['requestedAt']);
		$this->assertSame('2026-09-30', $saved['cancellation']['effectiveDate']);
	}//end testACustomerEndKeepsThePortalsMomentAndDate()

	/**
	 * A staff end ignores a preset that claims to be the customer's moment.
	 *
	 * @return void
	 */
	public function testAStaffEndDoesNotTakeAPresetMoment(): void {
		$saved = $this->action()->execute(
			['cancellationReasonCode' => 'other', 'cancellation' => ['requestedAt' => '2020-01-01T00:00:00+00:00']],
			[],
			[],
			'endFromPaused'
		);

		$this->assertSame('staff', $saved['cancellation']['cancelledBy']);
		$this->assertSame('2026-09-15T10:00:00+00:00', $saved['cancellation']['requestedAt']);
		$this->assertSame('2026-09-15', $saved['cancellation']['effectiveDate']);
	}//end testAStaffEndDoesNotTakeAPresetMoment()

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
}//end class
