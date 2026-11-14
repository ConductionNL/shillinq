<?php

/**
 * Tests for DBAVbarMonitorService::assess(), the VBAR hourly-rate check.
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
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Enums\DBAConstants;
use OCA\Shillinq\Service\DBAVbarMonitorService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the three outcomes of assess(): not enough data, at or above the
 * threshold, and below it (a warning, or a block in hard compliance mode).
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
#[CoversClass(DBAVbarMonitorService::class)]
#[UsesClass(DBAConstants::class)]
final class DBAVbarMonitorServiceAssessTest extends TestCase {

	/**
	 * The service with the given threshold and compliance mode for administration adm-1.
	 *
	 * @param int    $threshold The configured threshold in cents (0 falls back to the default).
	 * @param string $mode      The configured compliance mode.
	 *
	 * @return DBAVbarMonitorService
	 */
	private function service(int $threshold, string $mode): DBAVbarMonitorService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')
			->with('shillinq', 'dba.vbar_grens_cents.adm-1', DBAConstants::VBAR_GRENS_EUR_CENTS)
			->willReturn($threshold);
		$config->method('getValueString')
			->with('shillinq', 'dba.compliance_mode.adm-1', DBAConstants::COMPLIANCE_MODE_SOFT)
			->willReturn($mode);

		return new DBAVbarMonitorService(
			$this->createMock(ContainerInterface::class),
			$config,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	public function testZeroHoursIsOkWithoutARate(): void {
		$result = $this->service(5000, 'hard')->assess(100000, 0.0, 'adm-1');

		$this->assertSame(
			[
				'result' => DBAVbarMonitorService::RESULT_OK,
				'uurtariefCents' => null,
				'vbarGrensCents' => 5000,
				'message' => 'Onvoldoende data voor VBAR-toets (uren of bedrag = 0).',
			],
			$result
		);
	}//end testZeroHoursIsOkWithoutARate()

	public function testAZeroAmountIsOkWithoutARate(): void {
		$result = $this->service(5000, 'hard')->assess(0, 10.0, 'adm-1');

		$this->assertSame(DBAVbarMonitorService::RESULT_OK, $result['result']);
		$this->assertNull($result['uurtariefCents']);
	}//end testAZeroAmountIsOkWithoutARate()

	public function testARateAtTheThresholdIsOk(): void {
		$result = $this->service(5000, 'hard')->assess(50000, 10.0, 'adm-1');

		$this->assertSame(
			[
				'result' => DBAVbarMonitorService::RESULT_OK,
				'uurtariefCents' => 5000,
				'vbarGrensCents' => 5000,
				'message' => 'Effectief uurtarief EUR 50.00 voldoet aan VBAR-rechtsvermoeden-grens EUR 50.00.',
			],
			$result
		);
	}//end testARateAtTheThresholdIsOk()

	public function testTheRateIsRoundedToWholeCents(): void {
		$result = $this->service(1000, 'soft')->assess(10000, 3.0, 'adm-1');

		$this->assertSame(3333, $result['uurtariefCents']);
	}//end testTheRateIsRoundedToWholeCents()

	public function testARateBelowTheThresholdWarnsInSoftMode(): void {
		$result = $this->service(5000, 'soft')->assess(40000, 10.0, 'adm-1');

		$this->assertSame(
			[
				'result' => DBAVbarMonitorService::RESULT_WARN,
				'uurtariefCents' => 4000,
				'vbarGrensCents' => 5000,
				'message' => 'Effectief uurtarief EUR 40.00 onder VBAR-rechtsvermoeden-grens EUR 50.00.',
			],
			$result
		);
	}//end testARateBelowTheThresholdWarnsInSoftMode()

	public function testARateBelowTheThresholdBlocksInHardMode(): void {
		$result = $this->service(5000, 'hard')->assess(40000, 10.0, 'adm-1');

		$this->assertSame(DBAVbarMonitorService::RESULT_BLOCK, $result['result']);
	}//end testARateBelowTheThresholdBlocksInHardMode()

	public function testTheIntermediaryModeOnlyWarns(): void {
		$result = $this->service(5000, 'intermediair')->assess(40000, 10.0, 'adm-1');

		$this->assertSame(DBAVbarMonitorService::RESULT_WARN, $result['result']);
	}//end testTheIntermediaryModeOnlyWarns()

	public function testWithoutAConfiguredThresholdTheStatutoryDefaultApplies(): void {
		$result = $this->service(0, 'soft')->assess(32000, 10.0, 'adm-1');

		$this->assertSame(DBAConstants::VBAR_GRENS_EUR_CENTS, $result['vbarGrensCents']);
		$this->assertSame(DBAVbarMonitorService::RESULT_WARN, $result['result']);
	}//end testWithoutAConfiguredThresholdTheStatutoryDefaultApplies()
}//end class
