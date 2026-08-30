<?php

/**
 * Shillinq Crisis Mode Refresh Job
 *
 * Daily 00:30 UTC job. When any active CashflowForecastHorizon has weeks 1-4
 * with predicted negative saldo, this job re-triggers the lifecycle roll so
 * the operator sees an up-to-date forecast every morning. Deactivates crisis
 * mode automatically when all four leading weeks recover to positive saldo.
 *
 * Per ADR-031 / REQ-CF-010 the actual saldo computation lives in the
 * x-openregister-aggregations on CashflowWeek; this job orchestrates only.
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
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
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
 * Daily crisis-mode refresh orchestrator.
 *
 * @psalm-api
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
 */
class CrisisModeRefreshJob extends TimedJob {

	/**
	 * Daily interval in seconds.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SettingsService $settingsService Register-slug resolver.
	 * @param ContainerInterface $container DI container.
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
	 * Re-run aggregations on each horizon flagged as crisis.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-21
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	protected function run($argument): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->logger->info('CrisisModeRefreshJob: OR unavailable, skipping.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();

			$horizons = $objectService
				->setRegister($registerSlug)
				->setSchema('CashflowForecastHorizon')
				->findAll(
					[
						'filters' => ['lifecycleState' => 'active'],
					]
				);

			$refreshed = 0;
			foreach ($horizons as $horizon) {
				$horizonId = $this->extractField(entity: $horizon, field: 'horizonId');
				if ($horizonId === null) {
					continue;
				}

				// Find weeks 1-4 with negative eindSaldo.
				$crisisWeeks = $objectService
					->setRegister($registerSlug)
					->setSchema('CashflowWeek')
					->findAll(
						[
							'filters' => ['horizonId' => $horizonId],
							'limit' => 4,
							'order' => ['weekNumber' => 'ASC'],
						]
					);

				$hasCrisis = false;
				foreach ($crisisWeeks as $week) {
					$balance = $this->extractField(entity: $week, field: 'closingBalance');
					if ($balance !== null && (float)$balance < 0) {
						$hasCrisis = true;
						break;
					}
				}

				if ($hasCrisis === true) {
					// Bump rolledOp so the dashboard reflects the recompute.
					$objectService
						->setRegister($registerSlug)
						->setSchema('CashflowForecastHorizon')
						->saveObject(
							object: [
								'horizonId' => $horizonId,
								'rolledOn' => date('c'),
							]
						);

					$refreshed++;
				}
			}//end foreach

			$this->logger->info('CrisisModeRefreshJob: refreshed ' . $refreshed . ' horizon(s).');
		} catch (Throwable $e) {
			$this->logger->error(
				'CrisisModeRefreshJob failed: ' . $e->getMessage(),
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
