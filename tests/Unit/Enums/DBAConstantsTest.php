<?php

/**
 * Unit tests for DBAConstants.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Enums
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Enums;

use OCA\Shillinq\Enums\DBAConstants;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Risk bands and the per-administration DBA settings.
 */
#[CoversClass(DBAConstants::class)]
class DBAConstantsTest extends TestCase {

	/**
	 * Scores on and around every band boundary.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function bandProvider(): array {
		return [
			'zero' => [0, 'LOW'],
			'top of low' => [24, 'LOW'],
			'bottom of low-midden' => [25, 'LOW_MIDDEN'],
			'top of low-midden' => [49, 'LOW_MIDDEN'],
			'bottom of midden-high' => [50, 'MIDDEN_HIGH'],
			'top of midden-high' => [74, 'MIDDEN_HIGH'],
			'bottom of high' => [75, 'HIGH'],
			'maximum' => [100, 'HIGH'],
			'negative' => [-1, 'LOW'],
		];
	}//end bandProvider()

	/**
	 * bandFromScore maps each score onto its band, boundaries inclusive at the top.
	 *
	 * @param int    $score The risk score.
	 * @param string $band  The expected band.
	 *
	 * @return void
	 */
	#[DataProvider('bandProvider')]
	public function testBandFromScore(int $score, string $band): void {
		$this->assertSame($band, DBAConstants::bandFromScore($score));
	}//end testBandFromScore()

	/**
	 * vbarGrensCents reads the global key with the peil-2024 default.
	 *
	 * @return void
	 */
	public function testVbarGrensReadsTheGlobalKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueInt')
			->with('shillinq', 'dba.vbar_grens_cents', 3300)
			->willReturn(3500);

		$this->assertSame(3500, DBAConstants::vbarGrensCents($config, 'shillinq'));
	}//end testVbarGrensReadsTheGlobalKey()

	/**
	 * vbarGrensCents reads the per-administration key when an administration is given.
	 *
	 * @return void
	 */
	public function testVbarGrensReadsThePerAdministrationKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueInt')
			->with('shillinq', 'dba.vbar_grens_cents.adm-7', DBAConstants::VBAR_GRENS_EUR_CENTS)
			->willReturn(4000);

		$this->assertSame(4000, DBAConstants::vbarGrensCents($config, 'shillinq', 'adm-7'));
	}//end testVbarGrensReadsThePerAdministrationKey()

	/**
	 * A zero or negative configured threshold falls back to the constant.
	 *
	 * @return void
	 */
	public function testVbarGrensFallsBackOnANonPositiveValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnOnConsecutiveCalls(0, -100);

		$this->assertSame(3300, DBAConstants::vbarGrensCents($config, 'shillinq'));
		$this->assertSame(3300, DBAConstants::vbarGrensCents($config, 'shillinq', 'adm-1'));
	}//end testVbarGrensFallsBackOnANonPositiveValue()

	/**
	 * Stored compliance modes and what each one resolves to.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function modeProvider(): array {
		return [
			'soft' => ['soft', 'soft'],
			'hard' => ['hard', 'hard'],
			'intermediair' => ['intermediair', 'intermediair'],
			'unknown value' => ['strict', 'soft'],
			'wrong case' => ['HARD', 'soft'],
			'empty' => ['', 'soft'],
		];
	}//end modeProvider()

	/**
	 * complianceMode accepts only the three known modes and falls back to soft.
	 *
	 * @param string $stored   The stored value.
	 * @param string $expected The resolved mode.
	 *
	 * @return void
	 */
	#[DataProvider('modeProvider')]
	public function testComplianceModeResolvesStoredValue(string $stored, string $expected): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')
			->with('shillinq', 'dba.compliance_mode', 'soft')
			->willReturn($stored);

		$this->assertSame($expected, DBAConstants::complianceMode($config, 'shillinq'));
	}//end testComplianceModeResolvesStoredValue()

	/**
	 * complianceMode reads the per-administration key when an administration is given.
	 *
	 * @return void
	 */
	public function testComplianceModeReadsThePerAdministrationKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')
			->with('shillinq', 'dba.compliance_mode.adm-2', DBAConstants::COMPLIANCE_MODE_SOFT)
			->willReturn(DBAConstants::COMPLIANCE_MODE_HARD);

		$this->assertSame('hard', DBAConstants::complianceMode($config, 'shillinq', 'adm-2'));
	}//end testComplianceModeReadsThePerAdministrationKey()
}//end class
