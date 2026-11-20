<?php

/**
 * Reorder Proposal Job
 *
 * Once a day: every active reorder rule is compared with the stock at its
 * location; stock at or below the reorder point raises a low-stock alert and a
 * draft purchase order for the reorder quantity with the preferred supplier
 * (REQ-IRA-011). The work is ReorderProposalService, which runs as the system
 * and keeps each rule inside its own administration; this class only
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
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Service\ReorderProposalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily reorder pass.
 *
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
 */
class ReorderProposalJob extends TimedJob {
	/**
	 * Daily interval in seconds.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time      Time factory for the TimedJob base and the run's moment.
	 * @param ReorderProposalService $proposals The daily pass.
	 * @param LoggerInterface        $logger    Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ReorderProposalService $proposals,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the daily pass and log what it did.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob contract passes an argument this job does not use.
	 *
	 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
	 */
	protected function run($argument): void {
		try {
			$now    = DateTimeImmutable::createFromInterface($this->time->now());
			$report = $this->proposals->proposeAll(now: $now);
			$this->logger->info('ReorderProposalJob: daily reorder pass done', $report);
		} catch (Throwable $e) {
			$this->logger->error('ReorderProposalJob: daily reorder pass failed', ['exception' => $e->getMessage()]);
		}

	}//end run()
}//end class
