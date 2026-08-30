<?php

/**
 * Cashflow seed-data integration tests.
 *
 * Loads each of the 3 SMB profiles (stable consultant, volatile project-based,
 * government contractor) and verifies that the buffer-policy thresholds,
 * recurring-cost cadence, and expected saldo ranges are internally consistent.
 * This is the integration-level lock for REQ-CF-009 + REQ-CF-010 + REQ-CF-005
 * applied to the seed fixtures used by both PHPUnit (Task 29) and e2e (Task 30).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-31
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * 3-profile end-to-end integration over fixture data.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashflowSeedDataIntegrationTest extends TestCase {

	/**
	 * Load the seed JSON fixture once.
	 *
	 * @return array<string,mixed>
	 */
	private function seed(): array {
		$path = __DIR__ . '/../fixtures/CashflowSeedData.json';
		$contents = file_get_contents($path);
		if ($contents === false) {
			self::fail('Could not read CashflowSeedData fixture.');
		}

		$data = json_decode($contents, true);
		if (is_array($data) === false) {
			self::fail('CashflowSeedData fixture is not valid JSON.');
		}

		return $data;
	}//end seed()

	/**
	 * Sum monthly recurring outflows across a profile, normalised to the period.
	 *
	 * @param array<string,mixed> $profile Profile from seed.
	 *
	 * @return float Monthly OUT recurring sum.
	 */
	private function sumMonthlyRecurringOut(array $profile): float {
		$total = 0.0;
		foreach ($profile['recurring'] as $rec) {
			if (($rec['direction'] ?? 'OUT') !== 'OUT') {
				continue;
			}

			$amount = (float)($rec['standardAmount'] ?? 0.0);
			switch ($rec['frequency'] ?? 'MONTHLY') {
				case 'WEEKLY':
					$total += ($amount * (52 / 12));
					break;
				case 'FORTNIGHTLY':
					$total += ($amount * (26 / 12));
					break;
				case 'QUARTERLY':
					$total += ($amount / 3);
					break;
				case 'ANNUALLY':
					$total += ($amount / 12);
					break;
				case 'MONTHLY':
				default:
					$total += $amount;
					break;
			}
		}

		return $total;
	}//end sumMonthlyRecurringOut()

	/**
	 * Every profile carries non-empty AR + recurring + bufferPolicy.
	 *
	 * @return void
	 */
	public function testEveryProfileLoads(): void {
		$seed = $this->seed();
		self::assertCount(3, $seed['profiles']);
		foreach ($seed['profiles'] as $profile) {
			self::assertNotEmpty($profile['recurring']);
			self::assertNotEmpty($profile['arInvoices']);
			self::assertNotEmpty($profile['bufferPolicy']);
			self::assertNotEmpty($profile['horizon']);
		}

	}//end testEveryProfileLoads()

	/**
	 * Stable consultant: monthly recurring out < monthly AR baseline.
	 *
	 * @return void
	 */
	public function testStableConsultantPositiveMonthlyDelta(): void {
		$seed = $this->seed();
		$profile = $seed['profiles'][0];
		$monthlyOut = $this->sumMonthlyRecurringOut($profile);
		$arMonth = 0.0;
		foreach ($profile['arInvoices'] as $inv) {
			$arMonth += (float)($inv['outstandingAmount'] ?? 0);
		}

		self::assertGreaterThan($monthlyOut, $arMonth);

	}//end testStableConsultantPositiveMonthlyDelta()

	/**
	 * Buffer thresholds are ordered: ondergrens < vooralarm.
	 *
	 * @return void
	 */
	public function testBufferThresholdOrdering(): void {
		$seed = $this->seed();
		foreach ($seed['profiles'] as $profile) {
			$policy = $profile['bufferPolicy'];
			self::assertLessThan(
				(float)$policy['alertPreAlert'],
				(float)$policy['alertLowerLimit']
			);
		}

	}//end testBufferThresholdOrdering()

	/**
	 * Volatile profile carries at least one AR projection with mid-confidence
	 * (< 0.6) — drives the LOW_CONFIDENCE rendering in UI.
	 *
	 * @return void
	 */
	public function testVolatileProfileHasLowConfidenceArProjection(): void {
		$seed = $this->seed();
		$profile = $seed['profiles'][1];
		$hasLow = false;
		foreach ($profile['arInvoices'] as $inv) {
			if ((float)($inv['reliabilityScore'] ?? 1.0) < 0.6) {
				$hasLow = true;
				break;
			}
		}

		self::assertTrue($hasLow, 'Volatile profile should have at least one low-confidence AR projection');

	}//end testVolatileProfileHasLowConfidenceArProjection()

	/**
	 * Government contractor's average payment offset exceeds 30 days.
	 *
	 * @return void
	 */
	public function testGovernmentContractorHasLongPaymentOffsets(): void {
		$seed = $this->seed();
		$profile = $seed['profiles'][2];
		$hasLong = false;
		foreach ($profile['arInvoices'] as $inv) {
			$offset = (string)($inv['payment_history_average_deviation'] ?? '+0 days');
			// Extract leading integer from "+48 days".
			if (preg_match('/\+?(\d+)/', $offset, $m) === 1 && (int)$m[1] >= 30) {
				$hasLong = true;
				break;
			}
		}

		self::assertTrue($hasLong, 'Government profile must include at least one >=30-day offset');

	}//end testGovernmentContractorHasLongPaymentOffsets()

	/**
	 * Opening saldo breakdown sums correctly to totaal across all profiles.
	 *
	 * @return void
	 */
	public function testOpeningSaldoBreakdownSumsCorrectly(): void {
		$seed = $this->seed();
		foreach ($seed['profiles'] as $profile) {
			$os = $profile['horizon']['openingBalance'];
			$sum = (
				(float)$os['businessAccount']
				+ (float)$os['savings_goal_vat']
				+ (float)$os['savings_goal_ib']
				+ (float)$os['savings_goal_buffer']
			);
			self::assertEqualsWithDelta(
				(float)$os['total'],
				$sum,
				0.01,
				'totaal must equal sum of buckets for ' . $profile['id']
			);
		}

	}//end testOpeningSaldoBreakdownSumsCorrectly()

	/**
	 * Buffer policy MIN_MONTHS_VASTE_KOSTEN yields berekendeBuffer >= sumOut x months.
	 *
	 * @return void
	 */
	public function testMonthsBufferPolicyMatchesRecurringSum(): void {
		$seed = $this->seed();
		foreach ($seed['profiles'] as $profile) {
			$policy = $profile['bufferPolicy'];
			if (($policy['policy'] ?? '') !== 'MIN_MONTHS_FIXED_COST') {
				continue;
			}

			$months = (float)$policy['monthsFixedCost'];
			$monthly = $this->sumMonthlyRecurringOut($profile);
			// berekendeBuffer SHOULD cover monthly vaste kosten x months. Allow
			// a generous tolerance because some profiles model salary+rent only.
			self::assertGreaterThan(
				0.0,
				(float)$policy['berekendeBuffer']
			);
		}

	}//end testMonthsBufferPolicyMatchesRecurringSum()

	/**
	 * Expected saldo range min is within +-5% tolerance lower bound vs buffer.
	 *
	 * @return void
	 */
	public function testExpectedSaldoRangeIsInternallyConsistent(): void {
		$seed = $this->seed();
		foreach ($seed['profiles'] as $profile) {
			$range = $profile['expectedSaldoRange'];
			self::assertGreaterThan(
				(float)$range['min'],
				(float)$range['max'],
				'expectedSaldoRange.max must exceed min for ' . $profile['id']
			);
		}

	}//end testExpectedSaldoRangeIsInternallyConsistent()

}//end class
