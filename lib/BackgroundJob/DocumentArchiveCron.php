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

use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
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
	use RunsJobAsSystem;

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
	private function runAsJob($argument): void {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->info('DocumentArchiveCron: OpenRegister not available, skipping.');
			return;
		}

		try {
			$filed = $this->filedDocuments();
			$this->logTally(tally: $this->archiveAll(filed: $filed), sampled: count($filed));
		} catch (Throwable $e) {
			$this->logger->error(
				'DocumentArchiveCron failed: ' . $e->getMessage(),
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end runAsJob()

	/**
	 * Log the run's counts.
	 *
	 * @param ArchiveTally $tally   The run's counts.
	 * @param int          $sampled How many filed documents were read.
	 *
	 * @return void
	 */
	private function logTally(ArchiveTally $tally, int $sampled): void {
		$this->logger->info(
			'DocumentArchiveCron: archived=' . $tally->archived . ' skipped=' . $tally->skipped . ' failed=' . $tally->failed
			. ' (sampled ' . $sampled . ' filed documents).'
		);
	}//end logTally()

	/**
	 * The filed Documents (up to 5000).
	 *
	 * @return array<int, mixed>
	 */
	private function filedDocuments(): array {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$registerSlug = $this->settings->getRegisterSlug();

		return $objectService
			->setRegister($registerSlug)
			->setSchema('Document')
			->findAll(
				[
					'filters' => ['status' => 'filed'],
					'limit' => 5000,
				]
			);
	}//end filedDocuments()

	/**
	 * Archive every filed document whose retention has elapsed, counting the outcomes.
	 *
	 * @param array<int, mixed> $filed The filed Document rows.
	 *
	 * @return ArchiveTally
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
	 */
	private function archiveAll(array $filed): ArchiveTally {
		$tally = new ArchiveTally();
		foreach ($filed as $row) {
			$this->archiveOne(tally: $tally, document: new ArchivableDocument(row: $row));
		}

		return $tally;
	}//end archiveAll()

	/**
	 * Skip an unidentified document or one still within the retention
	 * boundary; archive any other.
	 *
	 * @param ArchiveTally       $tally    The run's counts.
	 * @param ArchivableDocument $document The filed document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
	 */
	private function archiveOne(ArchiveTally $tally, ArchivableDocument $document): void {
		if ($document->isIdentified() === false || $this->documents->isRetentionElapsed(filedAt: $document->filedAt) === false) {
			$tally->skipped++;
			return;
		}

		$this->tryArchive(tally: $tally, document: $document);
	}//end archiveOne()

	/**
	 * Archive one document; a failure is counted and logged, never fatal.
	 *
	 * @param ArchiveTally       $tally    The run's counts.
	 * @param ArchivableDocument $document The document past its retention boundary.
	 *
	 * @return void
	 */
	private function tryArchive(ArchiveTally $tally, ArchivableDocument $document): void {
		try {
			$this->documents->archiveDocument(
				administrationId: $document->administrationId,
				documentId: $document->documentId,
				reason: self::SYSTEM_REASON,
				allowEarly: false,
			);
			$tally->archived++;
		} catch (Throwable $e) {
			$tally->failed++;
			$this->logArchiveFailure(document: $document, e: $e);
		}
	}//end tryArchive()

	/**
	 * Log a document that could not be archived.
	 *
	 * @param ArchivableDocument $document The document.
	 * @param Throwable          $e        The failure.
	 *
	 * @return void
	 */
	private function logArchiveFailure(ArchivableDocument $document, Throwable $e): void {
		$this->logger->warning(
			'DocumentArchiveCron: failed to archive document ' . $document->documentId,
			[
				'administrationId' => $document->administrationId,
				'exception' => $e->getMessage(),
			]
		);
	}//end logArchiveFailure()
}//end class
