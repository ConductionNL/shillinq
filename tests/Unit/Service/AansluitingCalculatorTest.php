<?php

/**
 * Unit tests for AansluitingCalculator.
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
 * @spec openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AansluitingCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure tolerance/diff arithmetic (REQ-AANS-003, REQ-AANS-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AansluitingCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var AansluitingCalculator
	 */
	private AansluitingCalculator $calculator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new AansluitingCalculator();
	}//end setUp()

	/**
	 * toCents/fromCents round-trip with half-even rounding.
	 *
	 * @return void
	 */
	public function testToCentsAndFromCentsRoundTrip(): void {
		self::assertSame(460000, $this->calculator->toCents(amount: 4600.0));
		self::assertSame(150, $this->calculator->toCents(amount: 1.5));
		self::assertSame(15.0, $this->calculator->fromCents(cents: 1500));
	}//end testToCentsAndFromCentsRoundTrip()

	/**
	 * 'equal' relationship: difference = A - B.
	 *
	 * @return void
	 */
	public function testDifferenceCentsEqualRelationship(): void {
		$diff = $this->calculator->differenceCents(sourceATotal: 4600.0, sourceBTotal: 4450.0, relationship: 'equal');
		self::assertSame(15000, $diff);
	}//end testDifferenceCentsEqualRelationship()

	/**
	 * 'equal-with-sign-flip' relationship: difference = A + B (opposite sign
	 * conventions should net to ~0 when reconciled).
	 *
	 * @return void
	 */
	public function testDifferenceCentsSignFlipRelationship(): void {
		$diff = $this->calculator->differenceCents(sourceATotal: -9200.0, sourceBTotal: 9350.0, relationship: 'equal-with-sign-flip');
		self::assertSame(15000, $diff);

		$balanced = $this->calculator->differenceCents(sourceATotal: -9200.0, sourceBTotal: 9200.0, relationship: 'equal-with-sign-flip');
		self::assertSame(0, $balanced);
	}//end testDifferenceCentsSignFlipRelationship()

	/**
	 * Within-tolerance decision at, below, and above the boundary.
	 *
	 * @return void
	 */
	public function testIsWithinTolerance(): void {
		self::assertTrue($this->calculator->isWithinTolerance(differenceCents: 100, toleranceCents: 100));
		self::assertTrue($this->calculator->isWithinTolerance(differenceCents: -100, toleranceCents: 100));
		self::assertFalse($this->calculator->isWithinTolerance(differenceCents: 101, toleranceCents: 100));
	}//end testIsWithinTolerance()

	/**
	 * diffBuckets() emits one row per union key, with null on the side
	 * missing a bucket and a correctly-signed deltaAmount per relationship.
	 *
	 * @return void
	 */
	public function testDiffBucketsUnionsKeysAndComputesDelta(): void {
		$rows = $this->calculator->diffBuckets(
			bucketsA: ['collected:21.00' => 4600.0, 'paid:9.00' => 100.0],
			bucketsB: ['collected:21.00' => 4450.0],
			relationship: 'equal'
		);

		self::assertCount(2, $rows);

		$byKey = [];
		foreach ($rows as $row) {
			$byKey[$row['bucketKey']] = $row;
		}

		self::assertSame(4600.0, $byKey['collected:21.00']['sourceAAmount']);
		self::assertSame(4450.0, $byKey['collected:21.00']['sourceBAmount']);
		self::assertSame(150.0, $byKey['collected:21.00']['deltaAmount']);

		self::assertSame(100.0, $byKey['paid:9.00']['sourceAAmount']);
		self::assertNull($byKey['paid:9.00']['sourceBAmount']);
		self::assertSame(100.0, $byKey['paid:9.00']['deltaAmount']);
	}//end testDiffBucketsUnionsKeysAndComputesDelta()

	/**
	 * diffBuckets() with an empty bucketsA (subledger-gl-control item rows,
	 * where source A never decomposes per item) reports null sourceAAmount
	 * and deltaAmount equal to -sourceBAmount under 'equal'.
	 *
	 * @return void
	 */
	public function testDiffBucketsWithEmptySourceA(): void {
		$rows = $this->calculator->diffBuckets(bucketsA: [], bucketsB: ['aptx-1' => 150.0], relationship: 'equal');

		self::assertCount(1, $rows);
		self::assertNull($rows[0]['sourceAAmount']);
		self::assertSame(150.0, $rows[0]['sourceBAmount']);
		self::assertSame(-150.0, $rows[0]['deltaAmount']);
	}//end testDiffBucketsWithEmptySourceA()
}//end class
