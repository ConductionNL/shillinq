<?php

/**
 * Unit tests for DesinvesteringsbijtellingGuard.
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
 * @spec openspec/changes/bookkeeping-investeringsaftrek/specs/bookkeeping-investeringsaftrek/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\DesinvesteringsbijtellingGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DesinvesteringsbijtellingGuard.
 *
 * Covers REQ-INV-007/010:
 * - RvO meldingstermijn = opdrachtverleningDatum + 3 months.
 * - canSubmitDefinitief blocks after the deadline.
 * - reminderDates at deadline minus 14d and minus 3d.
 * - 5-year disposal window from aanvang kalenderjaar (art. 3.47).
 * - bijtelling = percentage x min(opbrengst, aanschafwaarde), capped.
 */
class DesinvesteringsbijtellingGuardTest extends TestCase {
	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var DesinvesteringsbijtellingGuard
	 */
	private DesinvesteringsbijtellingGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new DesinvesteringsbijtellingGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * REQ-INV-007: deadline is exactly three calendar months after the order date.
	 *
	 * @return void
	 */
	public function testMeldingDeadlineIsThreeMonths(): void {
		self::assertSame('2026-04-15', $this->guard->computeMeldingDeadline('2026-01-15'));
		self::assertSame('2026-03-30', $this->guard->computeMeldingDeadline('2025-12-30'));

	}//end testMeldingDeadlineIsThreeMonths()

	/**
	 * REQ-INV-007: malformed order date yields null (fail closed).
	 *
	 * @return void
	 */
	public function testMalformedOrderDateYieldsNull(): void {
		self::assertNull($this->guard->computeMeldingDeadline('15-01-2026'));
		self::assertNull($this->guard->computeMeldingDeadline('not-a-date'));

	}//end testMalformedOrderDateYieldsNull()

	/**
	 * REQ-INV-007: definitief is allowed on/before the deadline, blocked after.
	 *
	 * @return void
	 */
	public function testCanSubmitDefinitiefRespectsDeadline(): void {
		// Order 2026-01-15 -> deadline 2026-04-15.
		self::assertTrue($this->guard->canSubmitDefinitief('2026-01-15', '2026-04-15'));
		self::assertTrue($this->guard->canSubmitDefinitief('2026-01-15', '2026-03-01'));
		self::assertFalse($this->guard->canSubmitDefinitief('2026-01-15', '2026-04-16'));

	}//end testCanSubmitDefinitiefRespectsDeadline()

	/**
	 * REQ-INV-007: a malformed candidate date fails closed.
	 *
	 * @return void
	 */
	public function testCanSubmitDefinitiefFailsClosedOnBadInput(): void {
		self::assertFalse($this->guard->canSubmitDefinitief('2026-01-15', 'bad'));
		self::assertFalse($this->guard->canSubmitDefinitief('bad', '2026-03-01'));

	}//end testCanSubmitDefinitiefFailsClosedOnBadInput()

	/**
	 * REQ-INV-007: reminders are 14 and 3 days before the deadline.
	 *
	 * @return void
	 */
	public function testReminderDates(): void {
		// Deadline 2026-04-15 -> minus 14d = 2026-04-01, minus 3d = 2026-04-12.
		$reminders = $this->guard->reminderDates('2026-01-15');
		self::assertSame(['2026-04-01', '2026-04-12'], $reminders);

	}//end testReminderDates()

	/**
	 * REQ-INV-010: disposal-watch expiry is 1 jan of acquisitionYear + 5.
	 *
	 * @return void
	 */
	public function testDisposalWatchExpiry(): void {
		self::assertSame('2031-01-01', $this->guard->disposalWatchExpiry(2026));

	}//end testDisposalWatchExpiry()

	/**
	 * REQ-INV-010: a disposal before expiry is within the window; on/after is not.
	 *
	 * @return void
	 */
	public function testIsWithinDisposalWindow(): void {
		self::assertTrue($this->guard->isWithinDisposalWindow(2026, '2030-12-31'));
		self::assertFalse($this->guard->isWithinDisposalWindow(2026, '2031-01-01'));
		self::assertFalse($this->guard->isWithinDisposalWindow(2026, '2031-06-01'));

	}//end testIsWithinDisposalWindow()

	/**
	 * REQ-INV-010: bijtelling = percentage x min(opbrengst, aanschafwaarde).
	 *
	 * @return void
	 */
	public function testComputeBijtellingUsesLowerOfProceedsOrCost(): void {
		// EIA 40% on min(EUR 30.000 proceeds, EUR 50.000 cost) = 40% of EUR 30.000 = EUR 12.000.
		$benefitInKind = $this->guard->computeBijtelling(
			deductionPercentage: 40.0,
			revenue: 3000000,
			acquisitionValue: 5000000,
			origineleDeduction: 2000000
		);
		self::assertSame(1200000, $benefitInKind);

	}//end testComputeBijtellingUsesLowerOfProceedsOrCost()

	/**
	 * REQ-INV-010: bijtelling never exceeds the original aftrek.
	 *
	 * @return void
	 */
	public function testComputeBijtellingCappedAtOriginalAftrek(): void {
		// 40% of EUR 50.000 = EUR 20.000 but original aftrek was only EUR 15.000 -> capped.
		$benefitInKind = $this->guard->computeBijtelling(
			deductionPercentage: 40.0,
			revenue: 5000000,
			acquisitionValue: 5000000,
			origineleDeduction: 1500000
		);
		self::assertSame(1500000, $benefitInKind);

	}//end testComputeBijtellingCappedAtOriginalAftrek()

	/**
	 * REQ-INV-010: negative proceeds are floored at zero.
	 *
	 * @return void
	 */
	public function testComputeBijtellingNeverNegative(): void {
		$benefitInKind = $this->guard->computeBijtelling(
			deductionPercentage: 40.0,
			revenue: -100,
			acquisitionValue: 5000000,
			origineleDeduction: 2000000
		);
		self::assertSame(0, $benefitInKind);

	}//end testComputeBijtellingNeverNegative()
}//end class
