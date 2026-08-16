<?php

/**
 * Uren Prognose Service
 *
 * Rolling-12-weeks + seasonality prognose service per REQ-URC-002. Computes the
 * forecast year-end total from a 12-week running average over the YTD
 * UrenDagregistratie feed, with month-specific seasonal corrections (e.g. -25%
 * for August, -15% for December summer/winter dips) and explicit vakantie / planned
 * opdracht overrides. Transparent, deterministic model: no ML, readable for a
 * boekhouder (design D3).
 *
 * Confidence interval is computed from the variance of the weekly samples in the
 * 12-week window — a high variance lowers confidence (REQ-URC-002).
 *
 * Model version is stamped on every prognose record so subsequent upgrades are
 * traceable.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Computes year-end forecast hours and confidence from a 12-week rolling average.
 *
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-12
 */
final class UrenPrognoseService {

	/**
	 * Stamped model version (REQ-URC-002).
	 */
	public const MODEL_VERSION = 'v3.2-12wk-seasonal';

	/**
	 * Rolling window in weeks.
	 */
	private const WINDOW_WEEKS = 12;

	/**
	 * Seasonal factors per ISO month (1..12). 1.0 = neutral, <1.0 = dip.
	 *
	 * August: -25% (summer dip, REQ-URC-002 example).
	 * December: -15% (kerst-vakantie dip).
	 *
	 * @var array<int, float>
	 */
	private const SEASONAL_FACTORS = [
		1 => 1.0,
		2 => 1.0,
		3 => 1.0,
		4 => 1.0,
		5 => 1.0,
		6 => 1.0,
		7 => 0.9,
		8 => 0.75,
		9 => 1.0,
		10 => 1.0,
		11 => 1.0,
		12 => 0.85,
	];

	/**
	 * Construct the service.
	 *
	 * @param LoggerInterface $logger Diagnostics logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute a UrenPrognose record for an onderneming, given the YTD daily
	 * tallies and the start month for the remaining-year forecast.
	 *
	 * Inputs:
	 *  - dailyTallies: array<string, float> — keyed by ISO date (Y-m-d), value is
	 *    the day's counted hours (post-cap). Days with 0 hours MAY be omitted; the
	 *    service infers them from the date range when computing the weekly window.
	 *  - asOf: Y-m-d — current date (the prognose is computed forward from here).
	 *  - kalenderjaar: int — target year.
	 *  - lopendeUren: float — current YTD total (typically the result of UrenTallyService::tallyYearToDate).
	 *  - vakanties: array<int, string> — ISO date ranges ("2026-07-15/2026-08-09") that go to 0.
	 *  - geplandeOpdrachten: array<int, array{maand: string, hours: float}> — overrides per maand (YYYY-MM).
	 *
	 * @param array<string, mixed> $input Input bundle.
	 *
	 * @return array<string, mixed> UrenPrognose record shape.
	 *
	 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-12
	 */
	public function bouwPrognose(array $input): array {
		$asOf = (string)($input['asOf'] ?? gmdate('Y-m-d'));
		$calendarYear = (int)($input['calendarYear'] ?? (int)substr($asOf, 0, 4));
		$lopende = (float)($input['currentHours'] ?? 0.0);
		$dailyTallies = (array)($input['dailyTallies'] ?? []);
		$vakanties = (array)($input['vakanties'] ?? []);
		$assignments = (array)($input['geplandeOpdrachten'] ?? []);

		$weeklyWindow = $this->bouwWeeklyWindow(dailyTallies: $dailyTallies, asOf: $asOf);
		$weekGemiddelde = $this->weekGemiddelde(weeklyWindow: $weeklyWindow);
		$confidence = $this->confidence(weeklyWindow: $weeklyWindow);

		$perMonth = $this->perMonthPrognose(
			asOf: $asOf,
			calendarYear: $calendarYear,
			weekGemiddelde: $weekGemiddelde,
			vakanties: $vakanties,
			plannedAssignments: $assignments
		);

		$resterend = array_sum($perMonth);
		$totalPrognose = ($lopende + $resterend);

		$norm = (int)($input['purposeNorm'] ?? 1225);
		$kansAchievedNorm = $this->kansAchievedNorm(
			totalPrognose: $totalPrognose,
			norm: $norm,
			confidence: $confidence
		);

		$this->logger->info(
			'UrenPrognoseService: prognose computed',
			[
				'asOf' => $asOf,
				'modelVersion' => self::MODEL_VERSION,
				'weekGemiddelde' => $weekGemiddelde,
				'totalForecast' => $totalPrognose,
				'confidence' => $confidence,
			]
		);

		return [
			'modelVersion' => self::MODEL_VERSION,
			'calculatedOn' => gmdate('c'),
			'perMonthPrognose' => $perMonth,
			'vakanties' => array_values(array_map('strval', $vakanties)),
			'totalForecast' => $totalPrognose,
			'kansAchievedNorm' => $kansAchievedNorm,
			'prognoseConfidence' => $confidence,
		];

	}//end bouwPrognose()

	/**
	 * Build the 12-week (or fewer, when YTD is short) rolling window of weekly
	 * totals ending at asOf.
	 *
	 * @param array<string, float|int> $dailyTallies Daily totals keyed by Y-m-d.
	 * @param string $asOf Reference date.
	 *
	 * @return array<int, float> Weekly totals (oldest → newest).
	 */
	private function bouwWeeklyWindow(array $dailyTallies, string $asOf): array {
		$end = strtotime($asOf);
		if ($end === false) {
			return [];
		}

		$weeks = [];
		for ($w = (self::WINDOW_WEEKS - 1); $w >= 0; $w--) {
			$weekTotal = 0.0;
			for ($d = 0; $d < 7; $d++) {
				$offsetDays = (($w * 7) + $d);
				$date = gmdate('Y-m-d', ($end - ($offsetDays * 86400)));
				$weekTotal += (float)($dailyTallies[$date] ?? 0.0);
			}

			$weeks[] = $weekTotal;
		}

		return $weeks;
	}//end bouwWeeklyWindow()

	/**
	 * Mean of a weekly window. Returns 0.0 when empty.
	 *
	 * @param array<int, float> $weeklyWindow Weekly totals.
	 *
	 * @return float Mean weekly hours.
	 */
	private function weekGemiddelde(array $weeklyWindow): float {
		$n = count($weeklyWindow);
		if ($n === 0) {
			return 0.0;
		}

		return (array_sum($weeklyWindow) / $n);
	}//end weekGemiddelde()

	/**
	 * Confidence score (0-1) from the coefficient of variation of the weekly window.
	 *
	 * Low variance → high confidence. High variance → low confidence. Caps at
	 * [0.5 .. 0.99] for the canonical 12-week model — even very stable inputs
	 * carry some forecasting uncertainty.
	 *
	 * @param array<int, float> $weeklyWindow Weekly totals.
	 *
	 * @return float Confidence in [0.5, 0.99].
	 */
	private function confidence(array $weeklyWindow): float {
		$n = count($weeklyWindow);
		if ($n < 2) {
			return 0.5;
		}

		$mean = $this->weekGemiddelde(weeklyWindow: $weeklyWindow);
		if ($mean <= 0.0) {
			return 0.5;
		}

		$variance = 0.0;
		foreach ($weeklyWindow as $week) {
			$variance += (($week - $mean) ** 2);
		}

		$variance /= $n;
		$stdDev = sqrt($variance);
		$cv = ($stdDev / $mean);
		// 1 - CV, clamped to [0.5, 0.99].
		$score = max(0.5, min(0.99, (1.0 - $cv)));
		return round($score, 2);
	}//end confidence()

	/**
	 * Build the per-resterende-maand prognose with seasonality, vakantie, and
	 * geplande-opdracht overrides applied.
	 *
	 * @param string $asOf Y-m-d.
	 * @param int $calendarYear Target year.
	 * @param float $weekGemiddelde Weekly mean hours.
	 * @param array<int, string> $vakanties ISO date ranges.
	 * @param array<int, array{maand: string, hours: float}|array<string, mixed>> $plannedAssignments Per-maand overrides.
	 *
	 * @return array<string, float> Forecast hours keyed by YYYY-MM.
	 */
	private function perMonthPrognose(
		string $asOf,
		int $calendarYear,
		float $weekGemiddelde,
		array $vakanties,
		array $plannedAssignments,
	): array {
		$startTs = strtotime($asOf);
		if ($startTs === false) {
			return [];
		}

		$startMonth = (int)gmdate('n', $startTs);
		$overridesByMonth = [];
		foreach ($plannedAssignments as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$month = (string)($assignment['month'] ?? '');
			$hours = (float)($assignment['hours'] ?? 0);
			if ($month !== '') {
				$overridesByMonth[$month] = $hours;
			}
		}

		$holidayMonths = $this->holidayMonths(vakanties: $vakanties);

		$forecast = [];
		for ($m = $startMonth; $m <= 12; $m++) {
			$key = sprintf('%04d-%02d', $calendarYear, $m);
			if (isset($overridesByMonth[$key]) === true) {
				$forecast[$key] = $overridesByMonth[$key];
				continue;
			}

			if (isset($holidayMonths[$key]) === true && $holidayMonths[$key] === 'full') {
				$forecast[$key] = 0.0;
				continue;
			}

			$seasonal = (self::SEASONAL_FACTORS[$m] ?? 1.0);
			// 4.33 weeks per maand average.
			$monthHours = ($weekGemiddelde * 4.33 * $seasonal);
			if (isset($holidayMonths[$key]) === true && $holidayMonths[$key] === 'partial') {
				$monthHours *= 0.5;
			}

			$forecast[$key] = round($monthHours, 2);
		}//end for

		return $forecast;
	}//end perMaandPrognose()

	/**
	 * Resolve which months (YYYY-MM) are fully or partially blocked by vakantie ranges.
	 *
	 * @param array<int, string> $vakanties ISO date ranges ("2026-07-15/2026-08-09").
	 *
	 * @return array<string, string> Maand → 'full' | 'partial'.
	 */
	private function holidayMonths(array $vakanties): array {
		$months = [];
		foreach ($vakanties as $range) {
			if (is_string($range) === false || str_contains($range, '/') === false) {
				continue;
			}

			[$startStr, $endStr] = explode('/', $range, 2);
			$start = strtotime($startStr);
			$end = strtotime($endStr);
			if ($start === false || $end === false) {
				continue;
			}

			$cursor = $start;
			while ($cursor <= $end) {
				$key = gmdate('Y-m', $cursor);
				if (isset($months[$key]) === false) {
					$months[$key] = 'partial';
				}

				$cursor += 86400;
			}

			// If the entire month is covered, mark full.
			$firstOfStartMonth = strtotime(gmdate('Y-m-01', $start));
			$lastOfStartMonth = strtotime(gmdate('Y-m-t', $start));
			if ($firstOfStartMonth !== false && $lastOfStartMonth !== false
				&& $start <= $firstOfStartMonth && $end >= $lastOfStartMonth
			) {
				$months[gmdate('Y-m', $start)] = 'full';
			}
		}//end foreach

		return $months;
	}//end vakantieMonths()

	/**
	 * Estimate the probability the norm is reached given a forecast and confidence.
	 *
	 * Deterministic, transparent formula:
	 *  - if forecast >= norm → confidence (high probability).
	 *  - if forecast >= 0.8 * norm → confidence * 0.5 (around break-even).
	 *  - else → confidence * 0.1 (low).
	 *
	 * @param float $totalPrognose Forecast year-end total.
	 * @param int $norm Applicable doel-norm.
	 * @param float $confidence Confidence score [0.5..0.99].
	 *
	 * @return float Probability in [0.0, 0.99].
	 */
	private function kansAchievedNorm(float $totalPrognose, int $norm, float $confidence): float {
		if ($norm <= 0) {
			return 0.0;
		}

		if ($totalPrognose >= $norm) {
			return round($confidence, 2);
		}

		if ($totalPrognose >= ($norm * 0.8)) {
			return round(($confidence * 0.5), 2);
		}

		return round(($confidence * 0.1), 2);
	}//end kansBehaaldNorm()
}//end class
