<?php

/**
 * Shillinq Chart Template Sync Job
 *
 * Brings the administrations that follow a template in step with its new
 * version, queued when the template's version rises.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\ChartTemplate\ChartTemplateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One template's follower sync, argument `{templateId: string}`.
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
 */
class ChartTemplateSyncJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time      The job clock.
	 * @param ChartTemplateService $templates The sync.
	 * @param LoggerInterface      $logger    Where a failed run is logged.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ChartTemplateService $templates,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Sync the template's followers.
	 *
	 * @param mixed $argument `{templateId: string}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
	 */
	protected function run($argument): void {
		$templateId = '';
		if (is_array($argument) === true) {
			$templateId = trim((string)($argument['templateId'] ?? ''));
		}

		if ($templateId === '') {
			return;
		}

		try {
			$this->templates->syncFollowers(templateId: $templateId);
		} catch (Throwable $e) {
			$this->logger->error('ChartTemplateSyncJob: template sync failed', ['templateId' => $templateId, 'exception' => $e->getMessage()]);
		}
	}//end run()
}//end class
