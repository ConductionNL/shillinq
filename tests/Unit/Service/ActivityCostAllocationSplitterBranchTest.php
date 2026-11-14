<?php

/**
 * Branch tests for ActivityCostAllocationSplitter rule resolution and splitting.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\ActivityCostAllocationSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Pins every branch of resolveRule() and calculateSplits() (REQ-WMO-003).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ActivityCostAllocationSplitterBranchTest extends TestCase {

	/**
	 * The service under test.
	 */
	private ActivityCostAllocationSplitter $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new ActivityCostAllocationSplitter();

	}//end setUp()

	/**
	 * An empty or unparseable posting date matches no rule.
	 */
	public function testAnEmptyOrUnparseablePostingDateMatchesNothing(): void {
		$rules = [['id' => 'open']];

		self::assertNull($this->svc->resolveRule($rules, ''));
		self::assertNull($this->svc->resolveRule($rules, 'not a date'));

	}//end testAnEmptyOrUnparseablePostingDateMatchesNothing()

	/**
	 * Non-array candidates, rules starting later and rules ended earlier are passed over.
	 */
	public function testTheFirstRuleCoveringTheDateWins(): void {
		$rules = [
			'skip-me',
			['id' => 'later', 'effectiveFrom' => '2026-07-01'],
			['id' => 'ended', 'validFrom' => '2025-01-01', 'validTo' => '2026-01-31'],
			['id' => 'covering', 'validFrom' => '2026-01-01', 'effectiveTo' => '2026-12-31'],
			['id' => 'also-covering'],
		];

		self::assertSame('covering', $this->svc->resolveRule($rules, '2026-03-15')['id']);

	}//end testTheFirstRuleCoveringTheDateWins()

	/**
	 * The bounds are inclusive.
	 */
	public function testTheBoundsAreInclusive(): void {
		$rules = [['id' => 'day', 'effectiveFrom' => '2026-03-15', 'effectiveTo' => '2026-03-15']];

		self::assertSame('day', $this->svc->resolveRule($rules, '2026-03-15')['id']);

	}//end testTheBoundsAreInclusive()

	/**
	 * effectiveFrom takes precedence over validFrom, effectiveTo over validTo.
	 */
	public function testEffectiveBoundsTakePrecedence(): void {
		$rules = [
			['id' => 'a', 'effectiveFrom' => '2026-06-01', 'validFrom' => '2020-01-01'],
			['id' => 'b', 'effectiveTo' => '2026-01-01', 'validTo' => '2030-01-01'],
			['id' => 'c'],
		];

		self::assertSame('c', $this->svc->resolveRule($rules, '2026-03-15')['id']);

	}//end testEffectiveBoundsTakePrecedence()

	/**
	 * An unparseable from-date skips the rule; an unparseable to-date leaves it open-ended.
	 */
	public function testUnparseableBounds(): void {
		self::assertNull($this->svc->resolveRule([['id' => 'bad-from', 'effectiveFrom' => 'garbage']], '2026-03-15'));
		self::assertSame(
			'bad-to',
			$this->svc->resolveRule([['id' => 'bad-to', 'effectiveFrom' => '2026-01-01', 'effectiveTo' => 'garbage']], '2026-03-15')['id']
		);

	}//end testUnparseableBounds()

	/**
	 * No candidates, or none covering the date, gives null.
	 */
	public function testNoCoveringRuleGivesNull(): void {
		self::assertNull($this->svc->resolveRule([], '2026-03-15'));
		self::assertNull($this->svc->resolveRule([['effectiveTo' => '2026-01-01']], '2026-03-15'));

	}//end testNoCoveringRuleGivesNull()

	/**
	 * A rule without splits is refused.
	 */
	public function testARuleWithoutSplitsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('OverheadDistributionRule has no splits/verdeelsleutel array');

		$this->svc->calculateSplits(10.0, ['splits' => []]);

	}//end testARuleWithoutSplitsIsRefused()

	/**
	 * The verdeelsleutel alias, fallback keys, clamped ratios and skipped non-array splits.
	 */
	public function testAliasesClampingAndSkippedSplits(): void {
		$records = $this->svc->calculateSplits(
			-100.0,
			[
				'verdeelsleutel' => [
					'ignored',
					['costObjectCode' => 'K1', 'ratio' => 1.5, 'glAccount' => '4431'],
					['costObject' => 'K2', 'ratio' => -0.2, 'dimension' => 'PUBL'],
				],
			]
		);

		self::assertSame(
			[
				['costObject' => 'K1', 'ratio' => 1.0, 'amount' => -100.0, 'generalLedger' => '4431', 'dimension' => 'MO'],
				['costObject' => 'K2', 'ratio' => 0.0, 'amount' => 0.0, 'generalLedger' => null, 'dimension' => 'PUBL'],
			],
			$records
		);

	}//end testAliasesClampingAndSkippedSplits()

	/**
	 * Drift lands on the first largest split, also when an earlier entry was skipped.
	 */
	public function testDriftLandsOnTheFirstLargestSplit(): void {
		$records = $this->svc->calculateSplits(
			1.0,
			['splits' => ['ignored', ['costObject' => 'A', 'ratio' => 0.333], ['costObject' => 'B', 'ratio' => 0.333], ['costObject' => 'C', 'ratio' => 0.333]]]
		);

		self::assertSame([0.34, 0.33, 0.33], array_column($records, 'amount'));

	}//end testDriftLandsOnTheFirstLargestSplit()

	/**
	 * Over-allocation is taken back from the largest split.
	 */
	public function testOverAllocationIsTakenBack(): void {
		$records = $this->svc->calculateSplits(1.0, ['splits' => [['ratio' => 0.6], ['ratio' => 0.6]]]);

		self::assertSame([0.4, 0.6], array_column($records, 'amount'));

	}//end testOverAllocationIsTakenBack()

	/**
	 * Only non-array splits leave no records and no drift correction.
	 */
	public function testOnlyNonArraySplitsGiveNoRecords(): void {
		self::assertSame([], $this->svc->calculateSplits(5.0, ['splits' => ['x', 'y']]));

	}//end testOnlyNonArraySplitsGiveNoRecords()

	/**
	 * An override without a reason is refused.
	 */
	public function testAnOverrideWithoutReasonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Handmatige override requires a non-empty reason');

		$this->svc->composeOverride(['approvedBy' => ['a', 'b'], 'originalAllocation' => []]);

	}//end testAnOverrideWithoutReasonIsRefused()
}//end class
