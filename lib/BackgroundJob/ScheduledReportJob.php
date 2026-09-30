<?php

/**
 * Scheduled Report Job
 *
 * Hourly pass over the report schedules (reporting-data-delivery
 * REQ-RDD-002): ScheduledReportRunner produces and delivers every schedule
 * whose next run has passed.
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
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the due report schedules every hour.
 */
class ScheduledReportJob extends TimedJob {

	private const INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory          $time   Time factory.
	 * @param ScheduledReportRunner $runner Runs the due schedules.
	 * @param LoggerInterface       $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ScheduledReportRunner $runner,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the due schedules.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	protected function run($argument): void {
		try {
			$now    = new DateTimeImmutable('@' . $this->time->getTime());
			$counts = $this->runner->runDue(now: $now);
			$this->logger->info('ScheduledReportJob: pass done', $counts);
		} catch (Throwable $e) {
			$this->logger->error('ScheduledReportJob: pass failed', ['exception' => $e->getMessage()]);
		}

	}//end run()
}//end class
