<?php

/**
 * Shillinq Calibration Period
 *
 * The period a calibration run reports on and the moment it generates its reports.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-22
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

/**
 * Last month as calibration period, and the moment of the run.
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-22
 */
final class CalibrationPeriod {
	/**
	 * The period, as YYYY-MM.
	 *
	 * @var string
	 */
	public readonly string $period;

	/**
	 * The moment the reports are generated, ISO 8601.
	 *
	 * @var string
	 */
	public readonly string $generatedAt;

	/**
	 * Take last month as period and now as generation moment.
	 */
	public function __construct() {
		$this->period = date('Y-m', strtotime('-1 month'));
		$this->generatedAt = date('c');

	}//end __construct()

	/**
	 * The report envelope for a horizon; null when the horizon has no id or
	 * no administration.
	 *
	 * The MAPE values are computed by OR aggregations (declared on
	 * CashflowCalibrationReport when the report record is created). The job
	 * creates the envelope; OR fills the metrics.
	 *
	 * @param mixed $horizonId The horizon.
	 * @param mixed $administrationId Its administration.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-22
	 */
	public function report(mixed $horizonId, mixed $administrationId): ?array {
		if ($horizonId === null || $administrationId === null) {
			return null;
		}

		return $this->envelope(horizonId: $horizonId, administrationId: $administrationId);

	}//end report()

	/**
	 * The report envelope, with the metrics at zero.
	 *
	 * @param mixed $horizonId The horizon.
	 * @param mixed $administrationId Its administration.
	 *
	 * @return array<string,mixed>
	 */
	private function envelope(mixed $horizonId, mixed $administrationId): array {
		return [
			'reportId' => 'calib-' . $horizonId . '-' . $this->period,
			'horizonId' => $horizonId,
			'calibrationPeriod' => $this->period,
			'generatedAt' => $this->generatedAt,
			'ar_mape' => 0.0,
			'ap_mape' => 0.0,
			'recurring_mape' => 0.0,
			'tax_mape' => 0.0,
			'administrationId' => $administrationId,
		];

	}//end envelope()
}//end class
