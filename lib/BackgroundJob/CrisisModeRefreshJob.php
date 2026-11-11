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

use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use OCA\Shillinq\Service\RegisterLocation;
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
	use RunsJobAsSystem;

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
	 * @param RegisterLocation $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RegisterLocation $registerLocation,
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
	private function runAsJob($argument): void {
		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			$this->logger->info('CrisisModeRefreshJob: OR unavailable, skipping.');
			return;
		}

		try {
			$this->refreshCrisisHorizons();
		} catch (Throwable $e) {
			$this->logger->error(
				'CrisisModeRefreshJob failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end runAsJob()

	/**
	 * Roll every active horizon in crisis and log how many were rolled.
	 *
	 * @return void
	 */
	private function refreshCrisisHorizons(): void {
		$run = new CashflowHorizonRun(
			objectService: $this->container->get('OCA\OpenRegister\Service\ObjectService'),
			register: $this->registerLocation->getRegisterSlug(),
		);

		foreach ($run->activeHorizons() as $horizon) {
			$horizonId = $run->field(entity: $horizon, field: 'horizonId');
			if ($horizonId !== null && $this->isInCrisis(run: $run, horizonId: $horizonId) === true) {
				$this->markRolled(run: $run, horizonId: $horizonId);
			}
		}

		$this->logger->info('CrisisModeRefreshJob: refreshed ' . $run->count . ' horizon(s).');

	}//end refreshCrisisHorizons()

	/**
	 * Whether one of the horizon's first four weeks closes negative.
	 *
	 * @param CashflowHorizonRun $run The run.
	 * @param mixed $horizonId The horizon.
	 *
	 * @return bool
	 */
	private function isInCrisis(CashflowHorizonRun $run, mixed $horizonId): bool {
		foreach ($this->firstWeeks(run: $run, horizonId: $horizonId) as $week) {
			$balance = $run->field(entity: $week, field: 'closingBalance');
			if ($balance !== null && (float)$balance < 0) {
				return true;
			}
		}

		return false;

	}//end isInCrisis()

	/**
	 * The horizon's weeks 1-4, in order.
	 *
	 * @param CashflowHorizonRun $run The run.
	 * @param mixed $horizonId The horizon.
	 *
	 * @return iterable<mixed>
	 */
	private function firstWeeks(CashflowHorizonRun $run, mixed $horizonId): iterable {
		return $run->scoped(schema: 'CashflowWeek')
			->findAll(
				[
					'filters' => ['horizonId' => $horizonId],
					'limit' => 4,
					'order' => ['weekNumber' => 'ASC'],
				]
			);

	}//end firstWeeks()

	/**
	 * Bump rolledOn so the dashboard reflects the recompute.
	 *
	 * @param CashflowHorizonRun $run The run.
	 * @param mixed $horizonId The horizon.
	 *
	 * @return void
	 */
	private function markRolled(CashflowHorizonRun $run, mixed $horizonId): void {
		$run->scoped(schema: 'CashflowForecastHorizon')
			->saveObject(
				object: [
					'horizonId' => $horizonId,
					'rolledOn' => date('c'),
				]
			);
		$run->count++;

	}//end markRolled()

}//end class
