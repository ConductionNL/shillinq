<?php

/**
 * Unit tests for UrenPrognoseService.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\UrenPrognoseService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-002: 12-week rolling + seasonal + vakantie + planning prognose.
 */
final class UrenPrognoseServiceTest extends TestCase {

	/**
	 * Build a service.
	 *
	 * @return UrenPrognoseService
	 */
	private function build(): UrenPrognoseService {
		$logger = $this->createMock(LoggerInterface::class);
		return new UrenPrognoseService(logger: $logger);
	}//end build()

	/**
	 * Build a daily-tallies map of constant 5 hours/day for `n` days ending at end.
	 *
	 * @param string $end Y-m-d.
	 * @param int $n Days.
	 *
	 * @return array<string, float>
	 */
	private function steadyTallies(string $end, int $n): array {
		$tallies = [];
		$endTs = strtotime($end);
		for ($i = 0; $i < $n; $i++) {
			$tallies[gmdate('Y-m-d', $endTs - ($i * 86400))] = 5.0;
		}

		return $tallies;
	}//end steadyTallies()

	/**
	 * Steady 12-week input yields a positive prognose with the canonical model version.
	 *
	 * @return void
	 */
	public function testSteadyInputYieldsPositivePrognose(): void {
		$result = $this->build()->bouwPrognose(
			input: [
				'asOf' => '2026-06-30',
				'calendarYear' => 2026,
				'currentHours' => 700.0,
				'dailyTallies' => $this->steadyTallies('2026-06-30', 84),
				'purposeNorm' => 1225,
			]
		);

		self::assertSame(UrenPrognoseService::MODEL_VERSION, $result['modelVersion']);
		self::assertGreaterThan(700.0, $result['totalForecast']);
		self::assertIsArray($result['perMonthPrognose']);
		self::assertNotEmpty($result['perMonthPrognose']);
		self::assertArrayHasKey('2026-07', $result['perMonthPrognose']);
		self::assertArrayHasKey('2026-12', $result['perMonthPrognose']);
		// Confidence should be high for steady input.
		self::assertGreaterThanOrEqual(0.5, $result['prognoseConfidence']);

	}//end testSteadyInputYieldsPositivePrognose()

	/**
	 * August has a -25% seasonal factor, December -15%.
	 *
	 * @return void
	 */
	public function testSeasonalFactorsLowerAugustAndDecember(): void {
		$result = $this->build()->bouwPrognose(
			input: [
				'asOf' => '2026-06-30',
				'calendarYear' => 2026,
				'currentHours' => 0.0,
				'dailyTallies' => $this->steadyTallies('2026-06-30', 84),
				'purposeNorm' => 1225,
			]
		);

		$july = $result['perMonthPrognose']['2026-07'];
		$augustus = $result['perMonthPrognose']['2026-08'];
		$sept = $result['perMonthPrognose']['2026-09'];
		$december = $result['perMonthPrognose']['2026-12'];

		// August must be lower than September (-25% vs neutral).
		self::assertLessThan($sept, $augustus);
		// July is -10% so also below September.
		self::assertLessThan($sept, $july);
		// December is -15% so below September.
		self::assertLessThan($sept, $december);

	}//end testSeasonalFactorsLowerAugustAndDecember()

	/**
	 * A full-month vakantie zeroes that month's prognose.
	 *
	 * @return void
	 */
	public function testFullMonthVakantieZeroes(): void {
		$result = $this->build()->bouwPrognose(
			input: [
				'asOf' => '2026-06-30',
				'calendarYear' => 2026,
				'currentHours' => 0.0,
				'dailyTallies' => $this->steadyTallies('2026-06-30', 84),
				'vakanties' => ['2026-08-01/2026-08-31'],
				'purposeNorm' => 1225,
			]
		);

		self::assertSame(0.0, $result['perMonthPrognose']['2026-08']);

	}//end testFullMonthVakantieZeroes()

	/**
	 * A geplande opdracht overrides the seasonal projection for that maand.
	 *
	 * @return void
	 */
	public function testGeplandeOpdrachtOverridesMaand(): void {
		$result = $this->build()->bouwPrognose(
			input: [
				'asOf' => '2026-06-30',
				'calendarYear' => 2026,
				'currentHours' => 0.0,
				'dailyTallies' => $this->steadyTallies('2026-06-30', 84),
				'geplandeOpdrachten' => [
					['month' => '2026-09', 'hours' => 200.0],
				],
				'purposeNorm' => 1225,
			]
		);

		self::assertSame(200.0, $result['perMonthPrognose']['2026-09']);

	}//end testGeplandeOpdrachtOverridesMaand()

	/**
	 * Confidence is lower for noisy input vs steady input.
	 *
	 * @return void
	 */
	public function testNoisyInputLowersConfidence(): void {
		$end = '2026-06-30';
		$endTs = strtotime($end);
		// 12 weeks of alternating 0 / 60 hours per week (very noisy).
		$noisy = [];
		for ($i = 0; $i < 84; $i++) {
			$week = (int)floor($i / 7);
			$noisy[gmdate('Y-m-d', $endTs - ($i * 86400))] = (($week % 2 === 0) ? 0.0 : 8.57);
		}

		$steady = $this->build()->bouwPrognose(
			input: [
				'asOf' => $end,
				'calendarYear' => 2026,
				'currentHours' => 0.0,
				'dailyTallies' => $this->steadyTallies($end, 84),
				'purposeNorm' => 1225,
			]
		);

		$noisyResult = $this->build()->bouwPrognose(
			input: [
				'asOf' => $end,
				'calendarYear' => 2026,
				'currentHours' => 0.0,
				'dailyTallies' => $noisy,
				'purposeNorm' => 1225,
			]
		);

		self::assertLessThan($steady['prognoseConfidence'], $noisyResult['prognoseConfidence']);

	}//end testNoisyInputLowersConfidence()

	/**
	 * kansBehaaldNorm rises when the prognose meets the norm.
	 *
	 * @return void
	 */
	public function testKansBehaaldNormReflectsForecastVsNorm(): void {
		// 12 weeks (84 days) of steady 7h/day = 49h/week mean → forecasts ~50 weeks
		// remaining at that pace → well above 1225 norm.
		$tallies = [];
		$endTs = strtotime('2026-02-28');
		for ($i = 0; $i < 84; $i++) {
			$tallies[gmdate('Y-m-d', $endTs - ($i * 86400))] = 7.0;
		}

		$result = $this->build()->bouwPrognose(
			input: [
				'asOf' => '2026-02-28',
				'calendarYear' => 2026,
				'currentHours' => 300.0,
				'dailyTallies' => $tallies,
				'purposeNorm' => 1225,
			]
		);

		// Forecast should clear the norm comfortably → high kansBehaaldNorm.
		self::assertGreaterThanOrEqual(0.5, $result['kansAchievedNorm']);

	}//end testKansBehaaldNormReflectsForecastVsNorm()

	/**
	 * Empty daily-tallies input still returns a structurally valid prognose.
	 *
	 * @return void
	 */
	public function testEmptyTalliesReturnsZeroPrognose(): void {
		$result = $this->build()->bouwPrognose(
			input: [
				'asOf' => '2026-06-30',
				'calendarYear' => 2026,
				'currentHours' => 0.0,
				'dailyTallies' => [],
				'purposeNorm' => 1225,
			]
		);

		self::assertSame(0.0, $result['totalForecast']);
		self::assertSame(UrenPrognoseService::MODEL_VERSION, $result['modelVersion']);

	}//end testEmptyTalliesReturnsZeroPrognose()

}//end class
