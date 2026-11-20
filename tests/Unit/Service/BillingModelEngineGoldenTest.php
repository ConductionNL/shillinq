<?php

/**
 * Golden test for every BillingModelEngine calculation.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-billing-model-logic-tm-fixed-fee-milestone-retainer-mixed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BillingModelEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full line drafts, float/int shapes included.
 *
 * Regenerate with BILLING_ENGINE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BillingModelEngineGoldenTest extends TestCase {

	/**
	 * Golden fixture path.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__.'/fixtures/billing-model-engine.golden.json';

	/**
	 * Every calculation matches the golden output.
	 *
	 * @return void
	 */
	public function testCalculationsMatchGolden(): void {
		$engine = new BillingModelEngine();
		$expenses = [
			[
				'expenseId' => 7,
				'description' => 'Train',
				'costAmountCents' => '1250',
			],
			[
				'expenseId' => 'e2',
				'description' => 'Hotel',
				'costAmountCents' => 9000,
				'vatRate' => 9,
			],
		];
		$entries = [
			[
				'timeEntryId' => 1,
				'resourceType' => 'senior_consultant',
				'hours' => '1.5',
				'rateCents' => 12000,
				'rateApplied' => ['rateCents' => 12000],
			],
			[
				'timeEntryId' => 'te-2',
				'resourceType' => 'junior',
				'hours' => 2,
				'rateCents' => '8000',
				'vatRate' => 0,
			],
			[
				'timeEntryId' => 'te-3',
				'resourceType' => 'senior_consultant',
				'hours' => 0.25,
				'rateCents' => 12000,
			],
			[
				'timeEntryId' => 'te-4',
				'resourceType' => 'senior_consultant',
				'hours' => 1,
				'rateCents' => 15000,
			],
		];
		$retainer = [
			'label' => 'Support',
			'monthlyAmountCents' => '50000',
			'overageHoursThreshold' => 10,
			'overageHourlyRateCents' => 9950,
			'effectiveDate' => '2026-01-01',
		];
		$readings = [
			[
				'readingId' => 'r1',
				'description' => 'API calls',
				'billableUnits' => '1200',
				'unitPriceCents' => '2',
				'costAmountCents' => 2400,
				'vatRate' => 21,
				'ratePlanId' => 'rp-1',
				'ratingMethod' => 'tiered',
				'meterId' => 'm1',
				'resourceType' => 'api',
				'unit' => 'call',
				'periodStart' => '2026-01-01',
				'periodEnd' => '2026-01-31',
			],
			[],
		];

		$actual = [
			'tm' => $engine->calculateTAndM($entries, $expenses),
			'tmMarkup' => $engine->calculateTAndM($entries, [], 12.5),
			'tmEmpty' => $engine->calculateTAndM([], $expenses, 5.0),
			'fixed' => $engine->calculateFixedFee(100000, 'Website', $expenses, 12),
			'milestone' => $engine->calculateMilestone(
				[
					'milestoneId' => 'ms-1',
					'milestoneName' => 'Go-live',
					'milestoneCompletedAt' => '2026-02-01',
					'milestoneBudgetCents' => 25000,
				],
				$expenses
			),
			'retainerUnder' => $engine->calculateRetainer($retainer, '2026-02', 10.0, $expenses),
			'retainerOver' => $engine->calculateRetainer($retainer, '2026-02', 12.75, $expenses),
			'retainerNoThreshold' => $engine->calculateRetainer(['overageHoursThreshold' => null] + $retainer, '2026-02', 99.0),
			'retainerNoRate' => $engine->calculateRetainer(['overageHourlyRateCents' => null] + $retainer, '2026-02', 99.0),
			'mixed' => $engine->calculateMixed($retainer, '2026-03', 11.0, 7500, 'Setup', $expenses),
			'mixedNoSetup' => $engine->calculateMixed($retainer, '2026-03', 1.0, 0, 'Setup', $expenses),
			'usage' => $engine->calculateUsage($readings, $expenses),
		];

		if (getenv('BILLING_ENGINE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(json_encode($golden, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual, JSON_PRESERVE_ZERO_FRACTION));

	}//end testCalculationsMatchGolden()
}//end class
