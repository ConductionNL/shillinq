<?php

/**
 * WBSO Document Archive Cron.
 *
 * Nightly background job that scans `Document` records in `filed` whose
 * `documentDate` (or `filedAt`, whichever is later) has crossed the seven-
 * year Archiefwet 1995 retention boundary, and routes each match through
 * `WbsoDocumentService::archiveDocument()` with the `system` user as the
 * caller of last resort (REQ-WBSO-009). Fail-soft per record: a single bad
 * document logs and the sweep continues.
 *
 * Idempotent: documents already in `archived` are filtered out by the
 * service before any write, so a repeat sweep is a no-op.
 *
 * Per the openspec design D2 / REQ-WBSO-009, the actual approval-workflow
 * binding lives on the schema (`x-openregister-lifecycle.transitions.archive.
 * approvalWorkflow=document-archival`). When OR's approval-workflow refuses
 * the transition the service raises and this job logs and moves on; the
 * matter is then surfaced to the auditor through the workflow inbox.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nightly archival sweep for filed bookkeeping documents (REQ-WBSO-009).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
 */
class DocumentArchiveCron extends TimedJob {

	/**
	 * Daily interval in seconds (24h).
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Default archival reason captured against the job-driven transitions.
	 *
	 * @var string
	 */
	private const SYSTEM_REASON = 'Automatic archival after 7-year Archiefwet retention boundary.';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SettingsService $settings Settings (register slug + OR availability).
	 * @param WbsoDocumentService $documents Document archival service.
	 * @param ContainerInterface $container DI container (OR ObjectService).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settings,
		private readonly WbsoDocumentService $documents,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Sweep filed documents and archive ones past the retention boundary.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	protected function run($argument): void {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->info('DocumentArchiveCron: OpenRegister not available, skipping.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			$filed = $objectService
				->setRegister($registerSlug)
				->setSchema('Document')
				->findAll(
					[
						'filters' => ['status' => 'filed'],
						'limit' => 5000,
					]
				);

			$archived = 0;
			$skipped = 0;
			$failed = 0;
			foreach ($filed as $row) {
				$documentId = (string)($row['id'] ?? ($row['documentNumber'] ?? ''));
				$administrationId = (string)($row['administrationId'] ?? '');
				$filedAt = (string)($row['filedAt'] ?? ($row['documentDate'] ?? ''));

				if ($documentId === '' || $administrationId === '') {
					$skipped++;
					continue;
				}

				if ($this->documents->isRetentionElapsed(filedAt: $filedAt) === false) {
					$skipped++;
					continue;
				}

				try {
					$this->documents->archiveDocument(
						administrationId: $administrationId,
						documentId: $documentId,
						reason: self::SYSTEM_REASON,
						allowEarly: false,
					);
					$archived++;
				} catch (Throwable $e) {
					$failed++;
					$this->logger->warning(
						'DocumentArchiveCron: failed to archive document ' . $documentId,
						[
							'administrationId' => $administrationId,
							'exception' => $e->getMessage(),
						]
					);
				}
			}//end foreach

			$this->logger->info(
				'DocumentArchiveCron: archived=' . $archived . ' skipped=' . $skipped . ' failed=' . $failed
				. ' (sampled ' . count($filed) . ' filed documents).'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'DocumentArchiveCron failed: ' . $e->getMessage(),
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()
}//end class
