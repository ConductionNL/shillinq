<?php

/**
 * Shillinq Calibration Batch Job
 *
 * Monthly post-month-end calibration: compare actual vs forecast by category
 * (AR / AP / recurring / tax), compute MAPE, update customer-specific
 * betalingsgedrag offsets and pipelinq deal conversion probabilities, then
 * persist a CashflowCalibrationReport. Per ADR-031 / REQ-CF-013 this is pure
 * statistical aggregation — no service class authors the math, only the report
 * envelope is created here while the aggregations run on the OR engine.
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

use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Monthly calibration orchestrator.
 *
 * @psalm-api
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-22
 */
class CalibrationBatchJob extends TimedJob {
	use RunsJobAsSystem;

	/**
	 * Monthly interval in seconds (30 days).
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 2592000;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SettingsService $settingsService Register-slug resolver.
	 * @param ContainerInterface $container DI container for OR ObjectService.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the job inside OpenRegister's system context.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
	 */
	protected function run($argument): void {
		$this->asSystem(work: function () use ($argument): void {
			$this->runAsJob(argument: $argument);
		});
	}//end run()

	/**
	 * Generate one calibration report per active horizon.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-22
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	private function runAsJob($argument): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->logger->info('CalibrationBatchJob: OR unavailable, skipping.');
			return;
		}

		try {
			$this->calibrateLastMonth();
		} catch (Throwable $e) {
			$this->logger->error(
				'CalibrationBatchJob failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end runAsJob()

	/**
	 * Create last month's calibration report of every active horizon and log
	 * how many were created.
	 *
	 * @return void
	 */
	private function calibrateLastMonth(): void {
		$run = new CashflowHorizonRun(
			objectService: $this->container->get('OCA\OpenRegister\Service\ObjectService'),
			register: $this->settingsService->getRegisterSlug(),
		);
		$period = new CalibrationPeriod();
		$this->calibrate(run: $run, period: $period);

		$this->logger->info(
			'CalibrationBatchJob: created ' . $run->count . ' calibration report(s) for period ' . $period->period . '.'
		);

	}//end calibrateLastMonth()

	/**
	 * Create the period's report of every active horizon with an id and an
	 * administration, unless it already exists.
	 *
	 * @param CashflowHorizonRun $run The run.
	 * @param CalibrationPeriod $period The period.
	 *
	 * @return void
	 */
	private function calibrate(CashflowHorizonRun $run, CalibrationPeriod $period): void {
		foreach ($run->activeHorizons() as $horizon) {
			$report = $period->report(
				horizonId: $run->field(entity: $horizon, field: 'horizonId'),
				administrationId: $run->field(entity: $horizon, field: 'administrationId'),
			);
			if ($report !== null && $this->isNewReport(run: $run, reportId: $report['reportId']) === true) {
				$run->scoped(schema: 'CashflowCalibrationReport')->saveObject(object: $report);
				$run->count++;
			}
		}

	}//end calibrate()

	/**
	 * Idempotency: whether no report exists yet for this horizon and period.
	 *
	 * @param CashflowHorizonRun $run The run.
	 * @param string $reportId The report id.
	 *
	 * @return bool
	 */
	private function isNewReport(CashflowHorizonRun $run, string $reportId): bool {
		$existing = $run->scoped(schema: 'CashflowCalibrationReport')
			->findAll(
				[
					'filters' => [
						'reportId' => $reportId,
					],
					'limit' => 1,
				]
			);

		return empty($existing);

	}//end isNewReport()

}//end class
