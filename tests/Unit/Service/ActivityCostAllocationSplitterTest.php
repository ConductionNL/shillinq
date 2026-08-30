<?php

/**
 * Unit tests for ActivityCostAllocationSplitter.
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
 * Tests the allocation splitter (REQ-WMO-003).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ActivityCostAllocationSplitterTest extends TestCase {

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
	 * Splits a 184.00 amount via 64/36 PUBL/MO rule into 117.76 + 66.24.
	 */
	public function testCalculateSplitsBalanced(): void {
		$rule = [
			'id' => 'odr-2026',
			'splits' => [
				['costObject' => 'D-PUBL-NM-100', 'ratio' => 0.64, 'dimension' => 'PUBL', 'generalLedger' => '4431'],
				['costObject' => 'D-MO-NM-001',   'ratio' => 0.36, 'dimension' => 'MO',   'generalLedger' => '4432'],
			],
		];

		$splits = $this->svc->calculateSplits(184.0, $rule);
		self::assertCount(2, $splits);
		self::assertSame(117.76, $splits[0]['amount']);
		self::assertSame(66.24, $splits[1]['amount']);
		self::assertSame((117.76 + 66.24), 184.00);

	}//end testCalculateSplitsBalanced()

	/**
	 * Rounding drift is reconciled onto the largest split so sum = original.
	 */
	public function testCalculateSplitsReconcilesDrift(): void {
		$rule = [
			'splits' => [
				['costObject' => 'D-A', 'ratio' => 0.333, 'dimension' => 'PUBL'],
				['costObject' => 'D-B', 'ratio' => 0.333, 'dimension' => 'PUBL'],
				['costObject' => 'D-C', 'ratio' => 0.334, 'dimension' => 'MO'],
			],
		];

		$splits = $this->svc->calculateSplits(100.0, $rule);
		$sum = 0.0;
		foreach ($splits as $s) {
			$sum += (float)$s['amount'];
		}

		self::assertEqualsWithDelta(100.00, $sum, 0.0001);

	}//end testCalculateSplitsReconcilesDrift()

	/**
	 * Negative amounts (credit memos) preserve sign on each split.
	 */
	public function testCalculateSplitsPreservesSignForCreditMemos(): void {
		$rule = [
			'splits' => [
				['costObject' => 'D-A', 'ratio' => 0.7, 'dimension' => 'PUBL'],
				['costObject' => 'D-B', 'ratio' => 0.3, 'dimension' => 'MO'],
			],
		];

		$splits = $this->svc->calculateSplits(-200.0, $rule);
		self::assertSame(-140.00, $splits[0]['amount']);
		self::assertSame(-60.00, $splits[1]['amount']);

	}//end testCalculateSplitsPreservesSignForCreditMemos()

	/**
	 * resolveRule picks the geldende rule for the posting date.
	 */
	public function testResolveRuleByPostingDate(): void {
		$candidates = [
			['id' => 'r-2024', 'effectiveFrom' => '2024-01-01', 'effectiveTo' => '2024-12-31'],
			['id' => 'r-2025', 'effectiveFrom' => '2025-01-01', 'effectiveTo' => '2025-12-31'],
			['id' => 'r-2026', 'effectiveFrom' => '2026-01-01'],
		];

		self::assertSame('r-2024', $this->svc->resolveRule($candidates, '2024-06-15')['id']);
		self::assertSame('r-2026', $this->svc->resolveRule($candidates, '2026-04-01')['id']);
		self::assertNull($this->svc->resolveRule($candidates, '2023-01-01'));

	}//end testResolveRuleByPostingDate()

	/**
	 * Compose builds a complete allocation record.
	 */
	public function testComposeAllocationRecord(): void {
		$rule = [
			'id' => 'odr-2026',
			'splits' => [
				['costObject' => 'D-PUBL-NM-100', 'ratio' => 0.64, 'dimension' => 'PUBL'],
				['costObject' => 'D-MO-NM-001',   'ratio' => 0.36, 'dimension' => 'MO'],
			],
		];

		$allocation = $this->svc->compose([
			'journalEntryId' => 'je-001',
			'commercialActivityId' => 'ca-001',
			'originalAmount' => 184.00,
			'rule' => $rule,
			'postingDate' => '2026-03-15',
			'administrationId' => 'adm-tilburg',
		]);

		self::assertSame('je-001', $allocation['journalEntryId']);
		self::assertSame('ca-001', $allocation['commercialActivityId']);
		self::assertSame('odr-2026', $allocation['allocationKey']);
		self::assertTrue($allocation['automaticApplied']);
		self::assertNull($allocation['handmatigeOverride']);
		self::assertSame('active', $allocation['status']);
		self::assertCount(2, $allocation['splits']);

	}//end testComposeAllocationRecord()

	/**
	 * Override requires exactly 2 distinct approvers.
	 */
	public function testComposeOverrideRequiresTwoDistinctApprovers(): void {
		$original = [
			'id' => 'aca-orig',
			'journalEntryId' => 'je-001',
			'commercialActivityId' => 'ca-001',
			'originalAmount' => 184.00,
			'allocationKey' => 'odr-2026',
			'postingDate' => '2026-03-15',
			'administrationId' => 'adm-tilburg',
		];

		$this->expectException(InvalidArgumentException::class);
		$this->svc->composeOverride([
			'originalAllocation' => $original,
			'approvedBy' => ['user-a'],
			'reason' => 'reason',
			'newSplits' => [],
		]);

	}//end testComposeOverrideRequiresTwoDistinctApprovers()

	/**
	 * Override with valid 2-eye + reason produces an active replacement marked manual.
	 */
	public function testComposeOverrideHappyPath(): void {
		$original = [
			'id' => 'aca-orig',
			'journalEntryId' => 'je-001',
			'commercialActivityId' => 'ca-001',
			'originalAmount' => 184.00,
			'allocationKey' => 'odr-2026',
			'postingDate' => '2026-03-15',
			'administrationId' => 'adm-tilburg',
		];

		$override = $this->svc->composeOverride([
			'originalAllocation' => $original,
			'approvedBy' => ['concerncontroller', 'griffier'],
			'reason' => 'Wrong rule applied; switching to D-PUBL only',
			'newSplits' => [['costObject' => 'D-PUBL', 'ratio' => 1.0, 'amount' => 184.00, 'dimension' => 'PUBL']],
		]);

		self::assertFalse($override['automaticApplied']);
		self::assertSame(['concerncontroller', 'griffier'], $override['handmatigeOverride']['approvedBy']);
		self::assertSame('aca-orig', $override['handmatigeOverride']['replacesId']);
		self::assertSame('active', $override['status']);

	}//end testComposeOverrideHappyPath()

	/**
	 * Materialise splits to GL line entries with debit/credit sides.
	 */
	public function testMaterialiseSplitsToGlLines(): void {
		$splits = [
			['costObject' => 'D-PUBL', 'ratio' => 0.64, 'amount' => 117.76, 'dimension' => 'PUBL', 'generalLedger' => '4431'],
			['costObject' => 'D-MO',   'ratio' => 0.36, 'amount' => 66.24,  'dimension' => 'MO',   'generalLedger' => '4432'],
		];

		$entries = $this->svc->materialiseSplits($splits);
		self::assertCount(2, $entries);
		self::assertSame('4431', $entries[0]['generalLedger']);
		self::assertSame(117.76, $entries[0]['amount']);
		self::assertSame('debit', $entries[0]['side']);

	}//end testMaterialiseSplitsToGlLines()

}//end class
