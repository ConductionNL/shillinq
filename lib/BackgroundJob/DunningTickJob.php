<?php

/**
 * Dunning Tick Job
 *
 * Once a day: for every administration with automatic reminders switched on,
 * issued invoices past their due date move to overdue and every overdue
 * invoice gets the ladder stage that is due (receivables-automatic-dunning
 * design D1, D2, REQ-RAD-001). The work is DunningTickRunner; this class only
 * schedules it.
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
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Service\Dunning\DunningTickRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily dunning pass.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
 */
class DunningTickJob extends TimedJob {
	/**
	 * Daily interval in seconds.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time   Time factory for the TimedJob base and the run's date.
	 * @param DunningTickRunner $runner The daily pass.
	 * @param LoggerInterface   $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DunningTickRunner $runner,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the daily pass.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob contract passes an argument this job does not use.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
	 */
	protected function run($argument): void {
		try {
			$now = DateTimeImmutable::createFromInterface($this->time->now());
			$this->runner->runAll(now: $now);
		} catch (Throwable $e) {
			$this->logger->error('DunningTickJob: daily pass failed', ['exception' => $e->getMessage()]);
		}

	}//end run()
}//end class
