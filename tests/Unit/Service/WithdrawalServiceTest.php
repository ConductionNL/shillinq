<?php

/**
 * Unit tests for WithdrawalService::isWithdrawable().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\WithdrawalService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Each rule of design D5, and the reason every "no" carries.
 *
 * @covers \OCA\Shillinq\Service\WithdrawalService
 */
final class WithdrawalServiceTest extends TestCase {

	/**
	 * The service with an identity translator.
	 *
	 * @return WithdrawalService
	 */
	private function service(): WithdrawalService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new WithdrawalService(l10n: $l10n);
	}//end service()

	/**
	 * M. Visser's widget booking of 2026-09-20 for 2026-10-15.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The appointment.
	 */
	private static function appointment(array $overrides = []): array {
		return array_replace(
			[
				'@self' => ['created' => '2026-09-20T09:00:00+00:00'],
				'source' => 'widget',
				'status' => 'confirmed',
				'startTime' => '2026-10-15T10:00:00Z',
				'customerName' => 'M. Visser',
			],
			$overrides
		);
	}//end appointment()

	/**
	 * Within 14 days, not started, not exempt: withdrawable, with the last day.
	 *
	 * @return void
	 */
	public function testAWidgetBookingWithinTheFourteenDaysIsWithdrawable(): void {
		$answer = $this->service()->isWithdrawable(self::appointment(), ['withdrawalExempt' => false], new DateTimeImmutable('2026-09-24T12:00:00Z'));

		$this->assertSame(['withdrawable' => true, 'code' => 'withdrawable', 'reason' => null, 'periodEndsOn' => '2026-10-03'], $answer);
	}//end testAWidgetBookingWithinTheFourteenDaysIsWithdrawable()

	/**
	 * 15 days after booking the period has ended, and the answer names the day.
	 *
	 * @return void
	 */
	public function testTheWithdrawalPeriodHasPassed(): void {
		$answer = $this->service()->isWithdrawable(self::appointment(), null, new DateTimeImmutable('2026-10-05T09:00:00Z'));

		$this->assertFalse($answer['withdrawable']);
		$this->assertSame('period-ended', $answer['code']);
		$this->assertSame('The withdrawal period ended on 2026-10-03. You can still cancel under the cancellation policy.', $answer['reason']);
	}//end testTheWithdrawalPeriodHasPassed()

	/**
	 * The fourteenth day after booking is the first one refused.
	 *
	 * @return void
	 */
	public function testTheBoundaryIsFourteenDaysAfterBooking(): void {
		$service = $this->service();

		$this->assertTrue($service->isWithdrawable(self::appointment(), null, new DateTimeImmutable('2026-10-04T08:59:59Z'))['withdrawable']);
		$this->assertFalse($service->isWithdrawable(self::appointment(), null, new DateTimeImmutable('2026-10-04T09:00:00Z'))['withdrawable']);
	}//end testTheBoundaryIsFourteenDaysAfterBooking()

	/**
	 * A service exempt with a named reason is refused with that reason; unnamed, it does not count.
	 *
	 * @return void
	 */
	public function testAnExemptServiceIsRefusedWithItsReason(): void {
		$service = $this->service();
		$now = new DateTimeImmutable('2026-09-24T12:00:00Z');

		$answer = $service->isWithdrawable(self::appointment(), ['withdrawalExempt' => true, 'withdrawalExemptionReason' => 'leisure service on a specific date'], $now);
		$this->assertSame('exempt', $answer['code']);
		$this->assertSame('This booking cannot be withdrawn: leisure service on a specific date. You can still cancel it under the cancellation policy.', $answer['reason']);

		$this->assertTrue($service->isWithdrawable(self::appointment(), ['withdrawalExempt' => true, 'withdrawalExemptionReason' => ' '], $now)['withdrawable']);
	}//end testAnExemptServiceIsRefusedWithItsReason()

	/**
	 * A started appointment is refused even within the period.
	 *
	 * @return void
	 */
	public function testAStartedAppointmentIsRefused(): void {
		$answer = $this->service()->isWithdrawable(self::appointment(['startTime' => '2026-09-22T10:00:00Z']), null, new DateTimeImmutable('2026-09-22T10:30:00Z'));

		$this->assertSame('started', $answer['code']);
	}//end testAStartedAppointmentIsRefused()

	/**
	 * A booking made by staff, or of unknown moment, has no right of withdrawal.
	 *
	 * @return void
	 */
	public function testABookingNotMadeOnlineIsRefused(): void {
		$service = $this->service();
		$now = new DateTimeImmutable('2026-09-24T12:00:00Z');

		$this->assertSame('not-booked-online', $service->isWithdrawable(self::appointment(['source' => null]), null, $now)['code']);
		$this->assertSame('not-booked-online', $service->isWithdrawable(self::appointment(['@self' => []]), null, $now)['code']);
	}//end testABookingNotMadeOnlineIsRefused()

	/**
	 * A cancelled or completed appointment is refused.
	 *
	 * @return void
	 */
	public function testABookingThatIsNoLongerOpenIsRefused(): void {
		$service = $this->service();
		$now = new DateTimeImmutable('2026-09-24T12:00:00Z');

		$this->assertSame('not-booked', $service->isWithdrawable(self::appointment(['status' => 'cancelled']), null, $now)['code']);
		$this->assertSame('not-booked', $service->isWithdrawable(self::appointment(['status' => 'completed']), null, $now)['code']);
	}//end testABookingThatIsNoLongerOpenIsRefused()
}//end class
