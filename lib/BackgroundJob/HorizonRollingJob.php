<?php

/**
 * Shillinq Cashflow Horizon Rolling Background Job
 *
 * Weekly background job that rolls every active CashflowForecastHorizon forward by
 * one week (REQ-CF-000). Scheduled for Monday 02:00 UTC via a 7-day interval so the
 * roll completes before the operator's CET/CEST workday begins.
 *
 * Per ADR-031 this job is pure orchestration: it shifts horizonStart/horizonEind by
 * seven days, stamps rolledOp, and re-anchors the lifecycle to "active" after the
 * transient "rolling" state. It computes no forecast figures itself — the weekly
 * slot recomputation (week-1 archived, week-13 appended) is performed by the
 * OpenRegister aggregations declared on the schemas. The roll is idempotent: a
 * horizon already rolled to the current ISO Monday is skipped, so re-running the
 * same Monday's job never double-shifts.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Weekly horizon-rolling job for the 13-week rolling cashflow forecast (REQ-CF-000).
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-19
 */
class HorizonRollingJob extends TimedJob {

	/**
	 * Interval between runs: 604800 seconds = 7 days (weekly Monday roll).
	 */
	private const INTERVAL_SECONDS = 604800;

	/**
	 * OpenRegister page size for horizon traversal.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Construct the job.
	 *
	 * @param ITimeFactory $time Nextcloud time factory (injected by TimedJob).
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-19
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

		// Horizon roll is not millisecond-critical; let the scheduler pick the window.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Single instance only — avoid duplicate rolls on overlapping cron passes.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Roll all active horizons forward by one week.
	 *
	 * @param mixed $argument Not used; required by TimedJob interface.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-19
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('Shillinq: HorizonRollingJob started');

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq HorizonRollingJob: OpenRegister not available, skipping.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$register = $this->getRegisterSlug();
		$thisMonday = $this->currentIsoMonday();

		$rolled = 0;
		$skipped = 0;
		$errors = 0;

		foreach ($this->fetchActiveHorizons(objectService: $objectService, register: $register) as $horizon) {
			$uuid = ($horizon['id'] ?? $horizon['uuid'] ?? null);
			if ($uuid === null || $uuid === '') {
				continue;
			}

			// Idempotency: skip a horizon already rolled to this ISO Monday.
			if ($this->alreadyRolled(horizon: $horizon, monday: $thisMonday) === true) {
				$skipped++;
				continue;
			}

			try {
				$rolledHorizon = $this->shiftHorizon(horizon: $horizon, monday: $thisMonday);
				$objectService->setRegister($register);
				$objectService->setSchema('CashflowForecastHorizon');
				$objectService->saveObject($rolledHorizon, $register, 'CashflowForecastHorizon', $uuid);
				$rolled++;
			} catch (\Throwable $e) {
				$errors++;
				$this->logger->error(
					'Shillinq HorizonRollingJob: failed to roll horizon',
					['uuid' => $uuid, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$this->logger->info(
			sprintf(
				'Shillinq HorizonRollingJob: rolled %d horizon(s), skipped %d, %d error(s)',
				$rolled,
				$skipped,
				$errors
			)
		);

	}//end run()

	/**
	 * Return the ISO date (YYYY-MM-DD) of the Monday of the current week, UTC.
	 *
	 * @return string
	 */
	private function currentIsoMonday(): string {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		return $now->modify('monday this week')->format('Y-m-d');
	}//end currentIsoMonday()

	/**
	 * Whether a horizon's window already starts on the given Monday.
	 *
	 * @param array<string,mixed> $horizon The horizon object array.
	 * @param string $monday ISO date of this week's Monday.
	 *
	 * @return bool True when no roll is needed.
	 */
	private function alreadyRolled(array $horizon, string $monday): bool {
		return (($horizon['horizonStart'] ?? null) === $monday);
	}//end alreadyRolled()

	/**
	 * Produce the rolled horizon: window shifted to start on $monday, end +90 days,
	 * rolledOp stamped, lifecycle re-anchored to active.
	 *
	 * @param array<string,mixed> $horizon The horizon object array.
	 * @param string $monday ISO date of this week's Monday.
	 *
	 * @return array<string,mixed> The updated horizon object array.
	 */
	private function shiftHorizon(array $horizon, string $monday): array {
		$start = new DateTimeImmutable($monday, new DateTimeZone('UTC'));
		$end = $start->modify('+90 days');

		$horizon['horizonStart'] = $start->format('Y-m-d');
		$horizon['horizonEnd'] = $end->format('Y-m-d');
		$horizon['rolledOn'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		$horizon['lifecycleState'] = 'active';

		return $horizon;
	}//end shiftHorizon()

	/**
	 * Fetch all active CashflowForecastHorizon records via offset pagination.
	 *
	 * @param object $objectService The ObjectService instance.
	 * @param string $register The register slug.
	 *
	 * @return array<int,array<string,mixed>> Active horizon object arrays.
	 */
	private function fetchActiveHorizons(object $objectService, string $register): array {
		$offset = 0;
		$result = [];

		while (true) {
			try {
				$objectService->setRegister($register);
				$objectService->setSchema('CashflowForecastHorizon');
				$entities = $objectService->findAll(
					[
						'filters' => [
							'register' => $register,
							'schema' => 'CashflowForecastHorizon',
							'lifecycleState' => 'active',
						],
						'limit' => self::PAGE_SIZE,
						'offset' => $offset,
					]
				);

				$batch = [];
				foreach ($entities as $entity) {
					if (method_exists($entity, 'getObject') === true) {
						$batch[] = $entity->getObject();
					} elseif (is_array($entity) === true) {
						$batch[] = $entity;
					}
				}

				$result = array_merge($result, $batch);
				$offset += count($batch);

				if (count($batch) < self::PAGE_SIZE) {
					break;
				}
			} catch (\Throwable $e) {
				$this->logger->error(
					'Shillinq HorizonRollingJob: failed to fetch horizons',
					['offset' => $offset, 'exception' => $e->getMessage()]
				);
				break;
			}//end try
		}//end while

		return $result;
	}//end fetchActiveHorizons()
}//end class
