<?php

/**
 * Unit tests for TreasuryRateResult.
 *
 * The result is the value every treasury rate adapter returns: it keeps each
 * member exactly as given, and has no extras unless they are passed.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\TreasuryRate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\TreasuryRate;

use Error;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TreasuryRateResult.
 *
 * @covers \OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateResult
 */
class TreasuryRateResultTest extends TestCase {

	/**
	 * Every member is kept as given, the rate value as a decimal string.
	 *
	 * @return void
	 */
	public function testItKeepsEveryMember(): void {
		$result = new TreasuryRateResult(
			status: 'SNAPSHOT_TAKEN',
			rateId: 'tr_fx_1',
			rateCode: 'EUR/USD',
			value: '1.08250000',
			asOf: '2026-11-01',
			source: 'ECB',
			dormant: false,
			extras: ['provider' => 'sdmx'],
		);

		$this->assertSame('SNAPSHOT_TAKEN', $result->status);
		$this->assertSame('tr_fx_1', $result->rateId);
		$this->assertSame('EUR/USD', $result->rateCode);
		$this->assertSame('1.08250000', $result->value);
		$this->assertSame('2026-11-01', $result->asOf);
		$this->assertSame('ECB', $result->source);
		$this->assertFalse($result->dormant);
		$this->assertSame(['provider' => 'sdmx'], $result->extras);
	}//end testItKeepsEveryMember()

	/**
	 * Without extras the result carries an empty list of them.
	 *
	 * @return void
	 */
	public function testExtrasDefaultToEmpty(): void {
		$result = new TreasuryRateResult('SNAPSHOT_DEFERRED', 'id', 'EURIBOR-3M', '0', '2026-11-01', 'LOG_DEFERRED', true);

		$this->assertSame([], $result->extras);
		$this->assertTrue($result->dormant);
	}//end testExtrasDefaultToEmpty()

	/**
	 * A result cannot be changed once made.
	 *
	 * @return void
	 */
	public function testItIsReadOnly(): void {
		$result = new TreasuryRateResult('SNAPSHOT_DEFERRED', 'id', 'EUR/USD', '0', '2026-11-01', 'LOG_DEFERRED', true);

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');
		$result->value = '1';
	}//end testItIsReadOnly()
}//end class
