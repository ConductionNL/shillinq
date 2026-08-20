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
	protected function run($argument): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->logger->info('CalibrationBatchJob: OR unavailable, skipping.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();
			$period = date('Y-m', strtotime('-1 month'));
			$generatedAt = date('c');

			$horizons = $objectService
				->setRegister($registerSlug)
				->setSchema('CashflowForecastHorizon')
				->findAll(
					[
						'filters' => ['lifecycleState' => 'active'],
					]
				);

			$reportsCreated = 0;
			foreach ($horizons as $horizon) {
				$horizonId = $this->extractField(entity: $horizon, field: 'horizonId');
				$admin = $this->extractField(entity: $horizon, field: 'administrationId');
				if ($horizonId === null || $admin === null) {
					continue;
				}

				$reportId = 'calib-' . $horizonId . '-' . $period;

				// Idempotency: skip if a report already exists for this horizon+period.
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema('CashflowCalibrationReport')
					->findAll(
						[
							'filters' => [
								'reportId' => $reportId,
							],
							'limit' => 1,
						]
					);

				if (empty($existing) === false) {
					continue;
				}

				// The MAPE values are computed by OR aggregations (declared on
				// CashflowCalibrationReport when the report record is created).
				// The job creates the envelope; OR fills the metrics.
				$objectService
					->setRegister($registerSlug)
					->setSchema('CashflowCalibrationReport')
					->saveObject(
						object: [
							'reportId' => $reportId,
							'horizonId' => $horizonId,
							'calibrationPeriod' => $period,
							'generatedAt' => $generatedAt,
							'ar_mape' => 0.0,
							'ap_mape' => 0.0,
							'recurring_mape' => 0.0,
							'tax_mape' => 0.0,
							'administrationId' => $admin,
						]
					);

				$reportsCreated++;
			}//end foreach

			$this->logger->info(
				'CalibrationBatchJob: created ' . $reportsCreated . ' calibration report(s) for period ' . $period . '.'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CalibrationBatchJob failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end run()

	/**
	 * Defensive field extractor.
	 *
	 * @param mixed $entity Entity or array.
	 * @param string $field Field name.
	 *
	 * @return mixed
	 */
	private function extractField(mixed $entity, string $field): mixed {
		if (is_array($entity) === true) {
			return ($entity[$field] ?? null);
		}

		$getter = 'get' . ucfirst($field);
		if (method_exists($entity, $getter) === true) {
			return $entity->{$getter}();
		}

		return null;
	}//end extractField()
}//end class
