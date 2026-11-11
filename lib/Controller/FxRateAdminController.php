<?php

/**
 * Shillinq FX Rate Admin Controller
 *
 * Read-only admin endpoints over the FxRate register import status. Surfaces
 * the last successful run of `FxRateImportJob` (NC IJobList last-run
 * timestamp + the adapter's dormant flag) so the FX Rates Vue admin tab can
 * render an honest "Import status" header strip alongside the declarative
 * FxRate index grid.
 *
 * Per ADR-005 / ADR-004 the endpoint is admin-gated via
 * `#[AuthorizedAdminSetting(AdminSettings::class)]` — only admins authorised
 * for the shillinq settings section can read the cron metadata (the rate
 * grid itself follows the standard OR register read RBAC).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\BackgroundJob\FxRateImportJob;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only admin endpoint for the FxRate import status.
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-14
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class FxRateAdminController extends Controller {
	/**
	 * Construct the FX Rate admin controller.
	 *
	 * @param IRequest $request Nextcloud request.
	 * @param IJobList $jobList Background-job list (last-run lookup).
	 * @param TreasuryRateAdapterInterface $adapter Dormancy lookup for the ECB adapter.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IJobList $jobList,
		private readonly TreasuryRateAdapterInterface $adapter,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the FxRate import-status envelope.
	 *
	 * Shape:
	 *  - lastRunAt: ISO-8601 UTC timestamp of the last successful job tick, or NULL.
	 *  - lastRunEpoch: integer epoch seconds, or NULL.
	 *  - jobClass: FQCN of the cron job.
	 *  - adapterDormant: TRUE when the TreasuryRateAdapter is a log-only stub.
	 *  - interval: integer seconds between scheduled runs.
	 *  - status: 'ok' | 'dormant' | 'never-ran' summary for the UI badge.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-14
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): JSONResponse {
		$jobClass     = FxRateImportJob::class;
		$lastRunEpoch = $this->resolveLastRunEpoch(jobClass: $jobClass);
		$dormant      = $this->isAdapterDormant();

		return new JSONResponse(
			[
				'jobClass' => $jobClass,
				'lastRunAt' => self::isoTime(epoch: $lastRunEpoch),
				'lastRunEpoch' => $lastRunEpoch,
				'adapterDormant' => $dormant,
				'interval' => 86400,
				'status' => self::statusKey(lastRunEpoch: $lastRunEpoch, dormant: $dormant),
			]
		);
	}//end status()

	/**
	 * The epoch as an ISO-8601 UTC timestamp, or null.
	 *
	 * @param int|null $epoch Epoch seconds.
	 *
	 * @return string|null
	 */
	private static function isoTime(int|null $epoch): string|null {
		if ($epoch === null) {
			return null;
		}

		return (new DateTimeImmutable('@' . $epoch))
			->setTimezone(new DateTimeZone('UTC'))
			->format(DateTimeInterface::ATOM);
	}//end isoTime()

	/**
	 * The UI badge: dormant overrides last-run because no real rates flow even if the tick fires.
	 *
	 * @param int|null $lastRunEpoch The last run, null when it never ran.
	 * @param bool     $dormant      Whether the adapter is a log-only stub.
	 *
	 * @return string
	 */
	private static function statusKey(int|null $lastRunEpoch, bool $dormant): string {
		if ($dormant === true) {
			return 'dormant';
		}

		if ($lastRunEpoch === null) {
			return 'never-ran';
		}

		return 'ok';
	}//end statusKey()

	/**
	 * Look up the last-run epoch from the NC IJobList by exact job class.
	 *
	 * Returns NULL when the job has never run (or has not yet been
	 * registered by `repair --include-expensive`).
	 *
	 * @param class-string $jobClass The job FQCN.
	 *
	 * @return int|null Epoch seconds of the last successful tick.
	 */
	private function resolveLastRunEpoch(string $jobClass): int|null {
		try {
			$iterator = $this->jobList->getJobsIterator($jobClass, null, 0);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq FxRateAdminController: IJobList lookup threw',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return self::latestRun(jobs: $iterator);
	}//end resolveLastRunEpoch()

	/**
	 * The latest positive last run of the listed jobs, or null when none ran.
	 *
	 * @param iterable<mixed> $jobs The listed jobs.
	 *
	 * @return int|null
	 */
	private static function latestRun(iterable $jobs): int|null {
		$best = 0;
		foreach ($jobs as $job) {
			if ($job instanceof IJob) {
				$best = max($best, (int)$job->getLastRun());
			}
		}

		if ($best > 0) {
			return $best;
		}

		return null;
	}//end latestRun()

	/**
	 * Whether the TreasuryRate adapter is a log-only stub.
	 *
	 * @return bool
	 */
	private function isAdapterDormant(): bool {
		try {
			return $this->adapter->isDormant();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq FxRateAdminController: TreasuryRateAdapter.isDormant() threw',
				['exception' => $e->getMessage()]
			);
			return false;
		}
	}//end isAdapterDormant()
}//end class
