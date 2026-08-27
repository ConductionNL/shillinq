<?php

/**
 * Shillinq BackfillGlLineFiscalYear Repair Step
 *
 * Backfills `GLLine.fiscalYearId` from the parent `GLTransaction`, so the
 * segment-P&L roll-ups can group by year.
 *
 * ## Why
 *
 * `GLLine` declared no fiscal-year property at all. Three segment-P&L
 * aggregations grouped by `GLLine.fiscalYearId` regardless, so every row landed
 * in ONE null bucket — a plausible total rather than an error, which is why it
 * survived. The property now exists; without this step every historical row
 * carries null and the roll-ups would still be wrong, just for a new reason.
 *
 * `periodId` was deliberately not reused: a period is a finer grain than a
 * year, so grouping by it would silently change what the roll-ups mean.
 *
 * ## Reporting rather than gating
 *
 * {@see BackfillGlLineAdministration} closes a config gate and refuses to open
 * it unless a re-read proves the backfill complete. That is right for
 * `administrationId`, which is a tenant SCOPE: a half-scoped ledger makes a
 * filter return a silent zero, and a wrong number in a bookkeeping total is
 * worse than refusing to answer.
 *
 * `fiscalYearId` is a GROUPING key. A line that cannot resolve one is not a
 * leak and zeroes nothing — it shows up as a null bucket in the result, which
 * is visible. So this step does not gate anything; it stamps what resolves and
 * REPORTS what did not, as a count over the whole set taken by re-reading the
 * store afterwards. A backfill that quietly leaves rows behind is the same
 * fake-completeness problem in a softer form, so the count is always emitted —
 * including when it is zero.
 *
 * Idempotent and non-destructive: a line that already carries a fiscal year is
 * left alone even when its parent now disagrees. That disagreement is reported
 * rather than resolved, because re-pointing a posted line to a different year
 * is a bigger decision than a backfill gets to make.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Stamps `GLLine.fiscalYearId` from each line's parent `GLTransaction`.
 */
class BackfillGlLineFiscalYear implements IRepairStep {
	use ReadsSourceRowsInBatches;
	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param SettingsService                    $settingsService Resolves the register slug.
	 * @param GlLineFiscalYearBackfillMigrator   $migrator        The pure migration core.
	 * @param LoggerInterface                    $logger          For failures.
	 * @param ObjectServiceInterface             $objectService   Store access.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly GlLineFiscalYearBackfillMigrator $migrator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Human-readable step name shown by `occ upgrade`.
	 *
	 * @return string The step name.
	 */
	public function getName(): string {
		return 'Shillinq: backfill GLLine.fiscalYearId from the parent GLTransaction';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * @param IOutput $output Migration output channel.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		// Under a system identity: an upgrade has no session, and OpenRegister
		// would otherwise scope the read to nobody.
		$this->withSystemIdentity(
			objectService: $this->objectService,
			work: function () use ($output): void {
				$this->runInner(output: $output);
			}
		);
	}//end run()

	/**
	 * The body of the step, already under a system identity.
	 *
	 * @param IOutput $output Migration output channel.
	 *
	 * @return void
	 */
	private function runInner(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			$lines = $this->readPayloads(
				registerSlug: $registerSlug,
				schema: GlLineFiscalYearBackfillMigrator::SCHEMA_GL_LINE
			);
			$transactions = $this->readPayloads(
				registerSlug: $registerSlug,
				schema: GlLineFiscalYearBackfillMigrator::SCHEMA_GL_TRANSACTION
			);

			try {
				$report = $this->migrator->backfillBatch(
					glLines: $lines,
					glTransactions: $transactions
				);
			} catch (\Throwable $e) {
				$output->warning('Shillinq: GLLine fiscal-year backfill aborted: ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: GLLine fiscal-year backfill aborted',
					['exception' => $e->getMessage()]
				);
				return;
			}

			$written = $this->writeBackfilledRows(
				registerSlug: $registerSlug,
				rows: $report['lines'],
				output: $output
			);

			$output->info(
				'Shillinq: GLLine fiscal-year backfill — ' . $report['seen'] . ' seen, '
				. $report['stamped'] . ' resolved (' . $written . ' written), '
				. $report['alreadyStamped'] . ' already stamped, '
				. $report['unresolvable'] . ' unresolvable.'
			);

			foreach ($report['disagreements'] as $disagreement) {
				$output->warning(
					'Shillinq: GLLine fiscal-year disagrees with its parent — ' . $disagreement
					. '. Left as-is; re-pointing a posted line is not a backfill decision.'
				);
			}

			$this->reportRemaining(registerSlug: $registerSlug, output: $output);
		} catch (\Throwable $e) {
			$output->warning('Shillinq: GLLine fiscal-year backfill failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: GLLine fiscal-year backfill failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end runInner()

	/**
	 * Re-read the store and say how many lines still carry no fiscal year.
	 *
	 * A measurement taken AFTER the writes, over the whole set — the migrator's
	 * own report describes what it intended, which is not the same thing. The
	 * line is emitted even when the count is zero, so "nothing left behind" is
	 * something the operator read rather than something they assumed.
	 *
	 * @param string  $registerSlug The register to read.
	 * @param IOutput $output       Migration output channel.
	 *
	 * @return void
	 */
	private function reportRemaining(string $registerSlug, IOutput $output): void {
		$reread = $this->readPayloads(
			registerSlug: $registerSlug,
			schema: GlLineFiscalYearBackfillMigrator::SCHEMA_GL_LINE
		);
		$missing = $this->migrator->countMissingFiscalYearId(glLines: $reread);

		if ($missing === 0) {
			$output->info(
				'Shillinq: every one of ' . count($reread) . ' GLLine row(s) now carries a fiscalYearId.'
			);
			return;
		}

		$output->warning(
			'Shillinq: ' . $missing . ' of ' . count($reread) . ' GLLine row(s) still carry no '
			. 'fiscalYearId — their parent GLTransaction has none either, or the line references a '
			. 'transaction that no longer exists. Those rows will group under a NULL year bucket in '
			. 'the segment-P&L roll-ups rather than being silently dropped.'
		);
	}//end reportRemaining()

	/**
	 * Persist the stamped rows.
	 *
	 * @param string                           $registerSlug The register to write to.
	 * @param array<int, array<string, mixed>> $rows         The changed rows.
	 * @param IOutput                          $output       Migration output channel.
	 *
	 * @return int How many rows were written.
	 */
	private function writeBackfilledRows(string $registerSlug, array $rows, IOutput $output): int {
		$written = 0;

		foreach ($rows as $row) {
			$objectId = trim((string)($row['id'] ?? ''));
			if ($objectId === '') {
				$output->warning('Shillinq: GLLine fiscal-year backfill skipped a row with no object id.');
				continue;
			}

			try {
				// Runs in the installer/repair context where no web user is
				// authenticated. Bypass RBAC + multi-tenancy so the backfill
				// sees and writes every tenant's rows.
				$this->objectService->saveObject(
					object: $row,
					register: $registerSlug,
					schema: GlLineFiscalYearBackfillMigrator::SCHEMA_GL_LINE,
					uuid: $objectId,
					_rbac: false,
					_multitenancy: false,
				);
				$written++;
			} catch (\Throwable $e) {
				$output->warning(
					'Shillinq: GLLine fiscal-year backfill failed for object ' . $objectId . ': ' . $e->getMessage()
				);
			}
		}//end foreach

		return $written;
	}//end writeBackfilledRows()

	/**
	 * Read every row of one schema as a plain payload array.
	 *
	 * @param string $registerSlug The register to read.
	 * @param string $schema       The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The payloads.
	 */
	private function readPayloads(string $registerSlug, string $schema): array {
		$rows = $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $registerSlug,
			schema: $schema
		);

		$payloads = [];
		foreach ($rows as $row) {
			$payloads[] = $this->rowPayload(row: $row);
		}

		return $payloads;
	}//end readPayloads()
}//end class
