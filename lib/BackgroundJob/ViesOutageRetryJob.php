<?php

/**
 * VIES Outage Retry Job
 *
 * Tier-3 intra-community supplies (ICP) daily revalidation job (REQ-ICP-009).
 * Distinguishes a transient VIES outage (`outage: true`) from a definitive
 * rejection (`valid: false`): for every administration with pending-outage
 * `ViesValidation` evidence it re-queries VIES via ViesService. When a definitive
 * answer arrives the new evidence supersedes the outage record; when an outage has
 * persisted beyond 14 calendar days the bookkeeper is escalated so the buyer can be
 * manually verified or the supply reclassified as NL-BTW liable. This is the single
 * ADR-031 exception-path scheduler the declarative engine cannot express
 * (conditional, time-windowed retry).
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTime;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\ViesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Re-validates pending-outage VAT-IDs daily and escalates after 14 days.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class ViesOutageRetryJob extends TimedJob {
	/**
	 * Interval between runs (24 hours), per REQ-ICP-009 "daily job".
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = (24 * 60 * 60);

	/**
	 * Construct the job and set its daily interval.
	 *
	 * @param ITimeFactory $time Time factory for the TimedJob base.
	 * @param IcpFilingService $filingService ICP filing service (pending-outage scan).
	 * @param ViesService $viesService VIES re-validation service.
	 * @param INotificationManager $notifications Notification manager for escalation.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IcpFilingService $filingService,
		private readonly ViesService $viesService,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Re-validate pending-outage VAT-IDs for the administration in the argument.
	 *
	 * The job is enqueued per administration (the orchestration that creates outage
	 * evidence schedules this job with `['administrationId' => ...]`). For each
	 * pending outage it re-queries VIES; a definitive answer is persisted as fresh
	 * evidence, and an outage older than 14 days raises an escalation notification.
	 *
	 * @param mixed $argument The job argument; expects ['administrationId' => string].
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	protected function run($argument): void {
		$administrationId = '';
		if (is_array($argument) === true) {
			$administrationId = (string)($argument['administrationId'] ?? '');
		}

		if ($administrationId === '') {
			return;
		}

		$pending = $this->filingService->pendingOutages(administrationId: $administrationId);
		foreach ($pending as $outage) {
			$vatId = $outage['vatId'];
			if ($vatId === '') {
				continue;
			}

			if ($outage['escalate'] === true) {
				$this->escalate(administrationId: $administrationId, vatId: $vatId, ageDays: $outage['ageDays']);
				continue;
			}

			$result = $this->viesService->validate(administrationId: $administrationId, vatId: $vatId);
			if ($result['outage'] === false) {
				$this->logger->info(
					'ViesOutageRetryJob: VIES returned a definitive answer for a pending VAT-ID',
					['administrationId' => $administrationId, 'valid' => $result['valid']]
				);
			}
		}//end foreach

	}//end run()

	/**
	 * Raise a bookkeeper escalation for an outage that persisted beyond 14 days.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $vatId The unresolved VAT-ID.
	 * @param int $ageDays Days the outage has persisted.
	 *
	 * @return void
	 */
	private function escalate(string $administrationId, string $vatId, int $ageDays): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp('shillinq')
				->setUser($administrationId)
				->setDateTime(new DateTime())
				->setObject('icp_vies_outage', $vatId)
				->setSubject('icp.vies.outage_escalated', ['vatId' => $vatId, 'ageDays' => $ageDays]);
			$this->notifications->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ViesOutageRetryJob: failed to deliver outage escalation',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
		}

	}//end escalate()
}//end class
