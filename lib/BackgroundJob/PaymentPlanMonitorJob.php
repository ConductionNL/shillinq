<?php

/**
 * Payment Plan Monitor Job
 *
 * Once a day: instalments of active payment plans fall due, an instalment
 * unpaid past its grace period is missed and breaks its plan (dunning resumes,
 * the customer is mailed, the credit controllers are notified by the schema's
 * `onBroken` notification), and a plan with every instalment paid completes
 * (receivables-payment-plans design.md D5, REQ-RPPL-004, REQ-RPPL-005). The
 * work is PaymentPlanService::monitor(); this class only schedules it.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily payment plan pass.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class PaymentPlanMonitorJob extends TimedJob {
	/**
	 * Daily interval in seconds.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time   Time factory for the TimedJob base.
	 * @param PaymentPlanService $plans  The plan flow.
	 * @param LoggerInterface    $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PaymentPlanService $plans,
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
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-3.1
	 */
	protected function run($argument): void {
		try {
			$counts = $this->plans->monitor();
			$this->logger->info('PaymentPlanMonitorJob: daily pass done', $counts);
		} catch (Throwable $e) {
			$this->logger->error('PaymentPlanMonitorJob: daily pass failed', ['exception' => $e->getMessage()]);
		}

	}//end run()
}//end class
