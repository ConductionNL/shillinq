<?php

/**
 * Shillinq Nightly Soft Close Job
 *
 * Tier-2 nightly soft-close cron (REQ-CLS-002). Runs at the configured cadence
 * (default daily, target completion by 07:00 local CET) and invokes
 * SoftCloseExecutor::execute() per active administratie + the current period.
 * The executor itself is the work — this cron is the schedule.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nightly soft-close orchestrator (TimedJob, daily 00:30 UTC ≈ 07:00 local CET).
 *
 * @psalm-api
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 */
class SoftCloseJob extends TimedJob {
	use RunsJobAsSystem;
	/**
	 * Daily interval in seconds.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Construct the cron job.
	 *
	 * @param ITimeFactory $time NC clock.
	 * @param SoftCloseExecutor $executor Soft-close orchestration service.
	 * @param ContainerInterface $container DI container for ObjectService lookup.
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SoftCloseExecutor $executor,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

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
	 * Execute the nightly soft-close for every active administratie (REQ-CLS-002).
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	private function runAsJob($argument): void {
		try {
			$administrations = $this->findActiveAdministrations();
			$periodId = (new DateTimeImmutable())->format('Y-m');
			foreach ($administrations as $admin) {
				$administrationId = (string)($admin['id'] ?? ($admin['administrationId'] ?? ''));
				if ($administrationId !== '') {
					$this->closeOne(administrationId: $administrationId, periodId: $periodId);
				}
			}
		} catch (Throwable $e) {
			$this->logger->error('SoftCloseJob: top-level failure', ['exception' => $e->getMessage()]);
		}
	}//end runAsJob()

	/**
	 * Run the soft-close for one administration and period; a failure is logged and does not stop the run.
	 *
	 * @param string $administrationId The administration.
	 * @param string $periodId         The yyyy-mm period.
	 *
	 * @return void
	 */
	private function closeOne(string $administrationId, string $periodId): void {
		$scope = ['administrationId' => $administrationId, 'periodId' => $periodId];
		try {
			$report = $this->executor->execute(administrationId: $administrationId, periodId: $periodId, asOf: new DateTimeImmutable());
			$this->logger->info(
				'SoftCloseJob: soft-close run completed',
				$scope + ['status' => $report['status'], 'postingCount' => $report['postingCount']]
			);
		} catch (Throwable $e) {
			$this->logger->error('SoftCloseJob: soft-close run failed', $scope + ['exception' => $e->getMessage()]);
		}
	}//end closeOne()

	/**
	 * Find every active Administration via OpenRegister.
	 *
	 * @return array<int,array<string,mixed>> Administration records.
	 */
	private function findActiveAdministrations(): array {
		try {
			$found = $this->activeAdministrationRows();
			if (is_array($found) === false) {
				return [];
			}

			return array_values(array_map(static fn ($r): array => (array)$r, $found));
		} catch (Throwable $e) {
			$this->logger->error('SoftCloseJob: failed to enumerate administrations', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end findActiveAdministrations()

	/**
	 * The active Administration rows, as findAll() answers them.
	 *
	 * @return mixed
	 */
	private function activeAdministrationRows(): mixed {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return $objectService
			->setRegister($this->register())
			->setSchema('Administration')
			->findAll(['filters' => ['lifecycleState' => 'active']]);
	}//end activeAdministrationRows()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
