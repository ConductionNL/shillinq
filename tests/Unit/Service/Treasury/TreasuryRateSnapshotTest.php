<?php

/**
 * Unit tests for TreasuryRateSnapshot.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-treasury-ihb/tasks.md#external-adapter
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\Shillinq\Service\Treasury\TreasuryRateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Pins the live/dormant predicate contract on the snapshot VO so callers
 * never apply the synthetic '0' value to FX postings without checking
 * `isLive()` first.
 *
 * @spec openspec/changes/bookkeeping-treasury-ihb/tasks.md#external-adapter
 */
final class TreasuryRateSnapshotTest extends TestCase {

	/**
	 * Live snapshot: isLive() true, isDormant() false, asFloat() preserves
	 * the decimal-string value as a float.
	 *
	 * @return void
	 */
	public function testLiveSnapshotIsLive(): void {
		$s = new TreasuryRateSnapshot('1.1058', 'ECB', '2026-04-01', 'EURUSD', false, 'r-1');

		self::assertTrue($s->isLive());
		self::assertFalse($s->isDormant());
		self::assertSame(1.1058, $s->asFloat());

	}//end testLiveSnapshotIsLive()

	/**
	 * Dormant snapshot: isDormant() true, asFloat() returns 0.0.
	 *
	 * @return void
	 */
	public function testDormantSnapshotIsDormantAndZero(): void {
		$s = new TreasuryRateSnapshot('0', 'LOG_DEFERRED', '2026-04-01', 'EURUSD', true, 'log-1');

		self::assertTrue($s->isDormant());
		self::assertFalse($s->isLive());
		self::assertSame(0.0, $s->asFloat());

	}//end testDormantSnapshotIsDormantAndZero()

	/**
	 * Public readonly properties carry the construction values verbatim
	 * (audit trail).
	 *
	 * @return void
	 */
	public function testPublicPropertiesAreCarriedThrough(): void {
		$s = new TreasuryRateSnapshot('0.85', 'BLOOMBERG', '2026-04-01', 'GBPEUR', false, 'bb-99');

		self::assertSame('0.85', $s->value);
		self::assertSame('BLOOMBERG', $s->source);
		self::assertSame('2026-04-01', $s->asOf);
		self::assertSame('GBPEUR', $s->rateCode);
		self::assertSame('bb-99', $s->rateId);

	}//end testPublicPropertiesAreCarriedThrough()

}//end class
