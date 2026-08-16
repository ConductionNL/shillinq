<?php

/**
 * Unit tests for CancellationService.
 *
 * Covers every Scenario in the bookings-cancellation-rules spec that is
 * realisable as pure logic: full-refund / late-fee bracket / fixed-fee /
 * no-refund refund calculation, double-cancellation prevention (409), invalid
 * reason rejection, and UTC date handling.
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
 * @spec openspec/specs/bookings-cancellation-rules/spec.md#requirement-cancellation-service-layer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\CancellationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the cancellation fee/refund engine and cancellation mutation.
 *
 * @spec openspec/specs/bookings-cancellation-rules/spec.md#requirement-cancellation-service-layer
 */
final class CancellationServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var CancellationService
	 */
	private CancellationService $service;

	/**
	 * Set up the service with a null-ish logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = new CancellationService(logger: $logger);

	}//end setUp()

	/**
	 * Build a yoga-style policy snapshot: free until 2 days out, 20% after.
	 *
	 * @return array<string, mixed> Policy snapshot.
	 */
	private function yogaPolicy(): array {
		return [
			'minNoticeDays' => 2,
			'lateFeeBrackets' => [
				['daysBeforeStart' => 2, 'feeType' => 'percentage', 'feeAmount' => 0],
				['daysBeforeStart' => 0, 'feeType' => 'percentage', 'feeAmount' => 20],
			],
		];
	}//end yogaPolicy()

	/**
	 * Build a UTC instant from an ISO string.
	 *
	 * @param string $iso ISO-8601 timestamp.
	 *
	 * @return DateTimeImmutable Instant in UTC.
	 */
	private function utc(string $iso): DateTimeImmutable {
		return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
	}//end utc()

	/**
	 * Scenario "Appointment is Cancelled Within Notice Period": cancelling
	 * 2+ days before start yields a full refund.
	 *
	 * @return void
	 */
	public function testFullRefundWhenCancelledWithinNotice(): void {
		$appointment = [
			'appointmentCost' => 10000,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => $this->yogaPolicy(),
		];

		$refund = $this->service->calculateRefund(appointment: $appointment, cancelledAt: $this->utc(iso: '2026-05-26T10:00:00Z'));
		self::assertSame(expected: 10000, actual: $refund);

	}//end testFullRefundWhenCancelledWithinNotice()

	/**
	 * Scenario "Appointment is Cancelled After Notice Period": ~28h before
	 * start matches the 20% bracket → €80 refund on a €100 appointment.
	 *
	 * @return void
	 */
	public function testPartialRefundWhenCancelledAfterNotice(): void {
		$appointment = [
			'appointmentCost' => 10000,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => $this->yogaPolicy(),
		];

		$refund = $this->service->calculateRefund($appointment, $this->utc(iso: '2026-05-27T10:00:00Z'));
		self::assertSame(expected: 8000, actual: $refund);

	}//end testPartialRefundWhenCancelledAfterNotice()

	/**
	 * Scenario "Fee Calculation with Fixed-Amount Bracket": €50 fixed fee on a
	 * €100 appointment cancelled 6h before start → €50 refund.
	 *
	 * @return void
	 */
	public function testFixedFeeBracket(): void {
		$appointment = [
			'appointmentCost' => 10000,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => [
				'lateFeeBrackets' => [
					['daysBeforeStart' => 0, 'feeType' => 'fixed', 'feeAmount' => 5000],
				],
			],
		];

		$refund = $this->service->calculateRefund(appointment: $appointment, cancelledAt: $this->utc(iso: '2026-05-28T08:00:00Z'));
		self::assertSame(expected: 5000, actual: $refund);

	}//end testFixedFeeBracket()

	/**
	 * Scenario "No Refund for Too-Late Cancellation": 100% fee → €0 refund.
	 *
	 * @return void
	 */
	public function testNoRefundForFullFeeBracket(): void {
		$appointment = [
			'appointmentCost' => 10000,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => [
				'lateFeeBrackets' => [
					['daysBeforeStart' => 0, 'feeType' => 'percentage', 'feeAmount' => 100],
				],
			],
		];

		$refund = $this->service->calculateRefund(appointment: $appointment, cancelledAt: $this->utc(iso: '2026-05-28T13:00:00Z'));
		self::assertSame(expected: 0, actual: $refund);

	}//end testNoRefundForFullFeeBracket()

	/**
	 * Percentage rounding is half-up to the nearest cent (design risk 3):
	 * 33% of €100.01 (10001 cents) = 3300.33 → 3300; refund = 6701.
	 *
	 * @return void
	 */
	public function testPercentageRoundingHalfUp(): void {
		$appointment = [
			'appointmentCost' => 10001,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => [
				'lateFeeBrackets' => [
					['daysBeforeStart' => 0, 'feeType' => 'percentage', 'feeAmount' => 33],
				],
			],
		];

		$refund = $this->service->calculateRefund(appointment: $appointment, cancelledAt: $this->utc(iso: '2026-05-28T13:00:00Z'));
		self::assertSame(expected: 6701, actual: $refund);

	}//end testPercentageRoundingHalfUp()

	/**
	 * A policy with no fee brackets yields a full refund.
	 *
	 * @return void
	 */
	public function testNoBracketsFullRefund(): void {
		$appointment = [
			'appointmentCost' => 4200,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => ['lateFeeBrackets' => []],
		];

		$refund = $this->service->calculateRefund(appointment: $appointment, cancelledAt: $this->utc(iso: '2026-05-28T13:00:00Z'));
		self::assertSame(expected: 4200, actual: $refund);

	}//end testNoBracketsFullRefund()

	/**
	 * A zero-cost appointment never produces a refund.
	 *
	 * @return void
	 */
	public function testZeroCostZeroRefund(): void {
		$appointment = [
			'appointmentCost' => 0,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => $this->yogaPolicy(),
		];

		$refund = $this->service->calculateRefund(appointment: $appointment, cancelledAt: $this->utc(iso: '2026-05-27T10:00:00Z'));
		self::assertSame(expected: 0, actual: $refund);

	}//end testZeroCostZeroRefund()

	/**
	 * UTC handling: a cancellation expressed in a non-UTC zone resolves to the
	 * same whole-day distance as its UTC equivalent.
	 *
	 * @return void
	 */
	public function testTimezoneNormalisedToUtc(): void {
		$appointment = [
			'appointmentCost' => 10000,
			'startTime' => '2026-05-28T14:00:00+00:00',
			'appliedPolicy' => $this->yogaPolicy(),
		];

		// 2026-05-27T12:00:00+02:00 == 2026-05-27T10:00:00Z → ~28h before start → 20% bracket.
		$amsterdam = new DateTimeImmutable('2026-05-27T12:00:00', new DateTimeZone('Europe/Amsterdam'));
		self::assertSame(expected: 8000, actual: $this->service->calculateRefund($appointment, $amsterdam));

	}//end testTimezoneNormalisedToUtc()

	/**
	 * Scenario "Validate Cancellation Prevents Double-Cancellation": an already
	 * cancelled appointment is rejected with the already_cancelled code (→ 409).
	 *
	 * @return void
	 */
	public function testValidateRejectsAlreadyCancelled(): void {
		$result = $this->service->validateCancellation(
			appointment: ['cancelledAt' => '2026-05-26T14:00:00Z'],
			reason: 'customer_request'
		);

		self::assertFalse(condition: $result['allowed']);
		self::assertSame(expected: 'already_cancelled', actual: $result['code']);

	}//end testValidateRejectsAlreadyCancelled()

	/**
	 * An unknown reason is rejected by validation.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnknownReason(): void {
		$result = $this->service->validateCancellation(appointment: [], reason: 'not_a_real_reason');
		self::assertFalse(condition: $result['allowed']);
		self::assertSame(expected: 'invalid_reason', actual: $result['code']);

	}//end testValidateRejectsUnknownReason()

	/**
	 * A fresh appointment with a valid reason passes validation.
	 *
	 * @return void
	 */
	public function testValidateAllowsFreshAppointment(): void {
		$result = $this->service->validateCancellation(appointment: [], reason: 'no_show');
		self::assertTrue(condition: $result['allowed']);

	}//end testValidateAllowsFreshAppointment()

	/**
	 * The initiateCancellation method snapshots cancelledAt / cancelledReason /
	 * refundAmount / refundStatus without mutating the input array.
	 *
	 * @return void
	 */
	public function testInitiateCancellationSnapshotsFields(): void {
		$appointment = [
			'appointmentCost' => 10000,
			'startTime' => '2026-05-28T14:00:00Z',
			'appliedPolicy' => $this->yogaPolicy(),
		];

		$cancelled = $this->service->initiateCancellation(
			appointment: $appointment,
			reason: 'double_booked',
			cancelledAt: $this->utc(iso: '2026-05-27T10:00:00Z')
		);

		self::assertSame(expected: '2026-05-27T10:00:00Z', actual: $cancelled['cancelledAt']);
		self::assertSame(expected: 'double_booked', actual: $cancelled['cancelledReason']);
		self::assertSame(expected: 8000, actual: $cancelled['refundAmount']);
		self::assertSame(expected: CancellationService::REFUND_STATUS_PENDING, actual: $cancelled['refundStatus']);
		// Input untouched (audit before/after diffing relies on this).
		self::assertArrayNotHasKey(key: 'cancelledAt', array: $appointment);

	}//end testInitiateCancellationSnapshotsFields()

	/**
	 * InitiateCancellation throws when the appointment is already cancelled.
	 *
	 * @return void
	 */
	public function testInitiateCancellationThrowsOnAlreadyCancelled(): void {
		$this->expectException(exception: \InvalidArgumentException::class);
		$this->service->initiateCancellation(
			appointment: ['cancelledAt' => '2026-05-26T14:00:00Z'],
			reason: 'customer_request'
		);

	}//end testInitiateCancellationThrowsOnAlreadyCancelled()
}//end class
