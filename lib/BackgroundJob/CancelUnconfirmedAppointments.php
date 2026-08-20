<?php

/**
 * Cancel Unconfirmed Appointments Background Job
 *
 * Daily TimedJob that auto-cancels appointments still in
 * pending_confirmation whose confirmationDeadline has passed, transitioning
 * them to cancelled with reason "Confirmation deadline passed" (REQ-BCF-005).
 * The actual querying and persistence is delegated to
 * AppointmentConfirmationService so the rule has a single, testable home.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\AppointmentConfirmationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily job cancelling pending appointments past their confirmation deadline.
 */
class CancelUnconfirmedAppointments extends TimedJob {
	/**
	 * Interval between runs, in seconds (24 hours).
	 *
	 * @var int
	 */
	private const INTERVAL = (24 * 60 * 60);

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param AppointmentConfirmationService $confirmationService The confirmation service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private AppointmentConfirmationService $confirmationService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);

		// Auto-cancellation is not millisecond-critical; let the scheduler pick the window.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Single instance only — avoid double-cancellation on overlapping cron passes.
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Run the cancellation sweep.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		$cancelled = $this->confirmationService->cancelExpired();
		if ($cancelled > 0) {
			$this->logger->info(
				'Shillinq: CancelUnconfirmedAppointments cancelled ' . $cancelled . ' appointment(s)'
			);
		}
	}//end run()
}//end class
