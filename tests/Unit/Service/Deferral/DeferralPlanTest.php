<?php

/**
 * Unit tests for DeferralPlan.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Deferral
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Deferral;

use InvalidArgumentException;
use OCA\Shillinq\Service\Deferral\DeferralPlan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\Deferral\DeferralPlan
 */
final class DeferralPlanTest extends TestCase {

	public function testAnAnnualLicenceReleasesTwelveEqualShares(): void {
		$releases = DeferralPlan::releases(120000, '2026-01-01', '2026-12-31');

		$this->assertCount(12, $releases);
		$this->assertSame(array_fill(0, 12, 10000), array_column($releases, 'amountCents'));
		$this->assertSame(['2026-01', '2026-02', '2026-12'], [$releases[0]['periodId'], $releases[1]['periodId'], $releases[11]['periodId']]);
		$this->assertSame(['periodId' => '2026-02', 'periodStart' => '2026-02-01', 'periodEnd' => '2026-02-28', 'days' => 28, 'amountCents' => 10000, 'journalEntryId' => null, 'releasedAt' => null], $releases[1]);

	}//end testAnAnnualLicenceReleasesTwelveEqualShares()

	public function testPartMonthsGetTheirDayCountShareAndTheLastTakesTheRemainder(): void {
		$releases = DeferralPlan::releases(100000, '2026-01-16', '2026-04-15');

		// Weights 16/31, 1, 1, 15/30 of a total weight 3.0161…
		$this->assertSame([16, 28, 31, 15], array_column($releases, 'days'));
		$this->assertSame(['2026-01-16', '2026-02-01', '2026-03-01', '2026-04-01'], array_column($releases, 'periodStart'));
		$this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-15'], array_column($releases, 'periodEnd'));
		$this->assertSame([17112, 33155, 33155, 16578], array_column($releases, 'amountCents'));
		$this->assertSame(100000, array_sum(array_column($releases, 'amountCents')));

	}//end testPartMonthsGetTheirDayCountShareAndTheLastTakesTheRemainder()

	public function testTheRoundingRemainderLandsOnTheLastRelease(): void {
		$releases = DeferralPlan::releases(100, '2026-01-01', '2026-03-31');

		$this->assertSame([33, 33, 34], array_column($releases, 'amountCents'));

	}//end testTheRoundingRemainderLandsOnTheLastRelease()

	public function testTheSharesAlwaysAddUpToTheTotal(): void {
		foreach ([[1, '2026-01-31', '2026-02-01'], [99999, '2024-02-10', '2025-03-03'], [7, '2026-01-01', '2026-12-31'], [123456789, '2026-05-17', '2029-11-02']] as [$total, $from, $to]) {
			$releases = DeferralPlan::releases($total, $from, $to);
			$this->assertSame($total, array_sum(array_column($releases, 'amountCents')), $from . ' ' . $to);
			foreach ($releases as $release) {
				$this->assertGreaterThanOrEqual(0, $release['amountCents']);
			}
		}

	}//end testTheSharesAlwaysAddUpToTheTotal()

	public function testALeapFebruaryIsAWholeMonth(): void {
		$releases = DeferralPlan::releases(20000, '2024-02-01', '2024-03-31');

		$this->assertSame([29, 31], array_column($releases, 'days'));
		$this->assertSame([10000, 10000], array_column($releases, 'amountCents'));

	}//end testALeapFebruaryIsAWholeMonth()

	public function testSpanningSeveralPeriods(): void {
		$this->assertTrue(DeferralPlan::spansSeveralPeriods('2026-01-31', '2026-02-01'));
		$this->assertTrue(DeferralPlan::spansSeveralPeriods('2025-12-01', '2026-01-31'));
		$this->assertFalse(DeferralPlan::spansSeveralPeriods('2026-01-01', '2026-01-31'));
		$this->assertFalse(DeferralPlan::spansSeveralPeriods('2026-03-01', '2026-02-01'));

	}//end testSpanningSeveralPeriods()

	public function testUnusableInputIsRefused(): void {
		foreach ([[0, '2026-01-01', '2026-02-01'], [-5, '2026-01-01', '2026-02-01'], [100, '2026-02-01', '2026-01-31'], [100, '2026-02-30', '2026-03-31'], [100, '01-01-2026', '2026-03-31'], [100, '2026-01-01', '']] as [$total, $from, $to]) {
			try {
				DeferralPlan::releases($total, $from, $to);
				$this->fail('Expected a refusal for ' . $total . ' ' . $from . ' ' . $to);
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

	}//end testUnusableInputIsRefused()
}//end class
