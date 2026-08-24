<?php

/**
 * Shillinq BackfillGlLineAdministration Repair Step
 *
 * Runs the `GLLine.administrationId` backfill (REQ-GLS-002) and — only if it
 * can then PROVE the result complete — opens the gate that lets
 * {@see \OCA\Shillinq\Service\SpendAnalyticsService} scope its category /
 * cost-centre / period aggregations (REQ-GLS-003).
 *
 * ## Why a gate at all
 *
 * `GLLine` used to declare no administration property, so those three views
 * aggregated every administration in the register. The fix denormalises
 * `administrationId` onto `GLLine` and filters on it — but a filter on a
 * property that some rows lack matches NOTHING for those rows, so switching it
 * on over a half-backfilled ledger silently returns ZERO in a bookkeeping
 * total. A wrong number that looks like a real one is worse than the exposure
 * it replaces. The filter therefore may not simply be "on"; it must be
 * conditional on evidence that the backfill finished.
 *
 * ## What counts as evidence
 *
 * Not "the batch reported success". This step RE-READS every `GLLine` row from
 * the store after writing, and counts — as a total, not a sample — how many
 * still carry no scope. Only a count of exactly zero writes
 * {@see GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY}. The gate is a
 * measurement of the store, taken after the fact; the migrator's own return
 * value is a report, not proof.
 *
 * ## Fail-closed ordering
 *
 * The gate is CLEARED FIRST, before a single row is read or written, and is
 * only ever re-opened at the very end by that re-read. So every intermediate
 * state — a crash mid-write, an aborted batch, an OpenRegister outage, an
 * upgrade that never reached this step — leaves the gate shut, which makes
 * SpendAnalytics refuse the GL-backed views outright. Refusing is the correct
 * third option: it leaks nothing (unlike serving unscoped totals) and it lies
 * about nothing (unlike serving silent zeros).
 *
 * Idempotent: a second run finds every row already scoped, writes nothing, and
 * re-affirms the same gate value.
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
 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator;
use OCA\Shillinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that backfills `GLLine.administrationId` from the parent
 * `GLTransaction` and opens the SpendAnalytics scoping gate on proof.
 *
 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
 */
class BackfillGlLineAdministration implements IRepairStep {
	use ReadsSourceRowsInBatches;
	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (register slug).
	 * @param GlLineAdministrationBackfillMigrator $migrator The pure migration core.
	 * @param IAppConfig $appConfig App config — holds the completeness gate.
	 * @param LoggerInterface $logger The logger interface.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly GlLineAdministrationBackfillMigrator $migrator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function getName(): string {
		return 'Shillinq: backfill GLLine.administrationId from the parent GLTransaction';
	}//end getName()

	/**
	 * Run the backfill, then gate on a re-read proof of completeness.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function run(IOutput $output): void {
		// FAIL-CLOSED FIRST. Every early return below — and every crash — now
		// leaves the gate shut, so SpendAnalytics refuses the GL-backed views
		// rather than serving unscoped totals or silent zeros.
		$this->closeGate();

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses the write for 'Anonymous'. This step carries `_rbac: false`,
		// which is NOT sufficient on its own — measured in InitializeSettings,
		// a step flagging every one of its own writes still failed eight times,
		// because the refusals arrive from writes further down the call chain.
		//
		// The gate is closed BEFORE the scope so it stays shut even if
		// establishing the identity throws.
		$this->withSystemIdentity(
			objectService: $this->objectService,
			work: function () use ($output): void {
				$this->runInner(output: $output);
			}
		);
	}//end run()

	/**
	 * The backfill itself.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 */
	private function runInner(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			$lines = $this->readPayloads(registerSlug: $registerSlug, schema: GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE);
			$transactions = $this->readPayloads(registerSlug: $registerSlug, schema: GlLineAdministrationBackfillMigrator::SCHEMA_GL_TRANSACTION);

			try {
				$report = $this->migrator->backfillBatch(glLines: $lines, glTransactions: $transactions);
			} catch (\Throwable $e) {
				// The assertCountsMatch() call threw: at least one line's parent could
				// not answer for it. NOTHING is written — a partially scoped
				// ledger is the one state worse than an unscoped one — and the
				// gate stays shut.
				$output->warning('Shillinq: GLLine administration backfill aborted: ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: GLLine administration backfill aborted',
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
				'Shillinq: GLLine administration backfill — ' . $report['total'] . ' seen, '
				. $report['backfilled'] . ' resolved (' . $written . ' written), '
				. $report['unchanged'] . ' already scoped, '
				. $report['conflicting'] . ' disagreeing with their parent (reported, not rewritten).'
			);

			$this->proveAndOpenGate(registerSlug: $registerSlug, output: $output);
		} catch (\Throwable $e) {
			// Best-effort: a failed backfill must NOT block the app upgrade.
			// The gate is already shut, so the exposure stays closed either way.
			$output->warning('Shillinq: GLLine administration backfill failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: GLLine administration backfill failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end runInner()

	/**
	 * Re-read every `GLLine` and open the gate only on a zero-missing total.
	 *
	 * The whole ordering of this change rests on this method. It deliberately
	 * re-reads rather than trusting the in-memory batch, because the batch
	 * reports what this process INTENDED and the gate must assert what the
	 * store actually HOLDS — including rows another writer added while this ran.
	 *
	 * @param string $registerSlug The register slug.
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 */
	private function proveAndOpenGate(string $registerSlug, IOutput $output): void {
		$reread = $this->readPayloads(registerSlug: $registerSlug, schema: GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE);
		$missing = $this->migrator->countMissingAdministrationId(glLines: $reread);

		if ($missing > 0) {
			$output->warning(
				'Shillinq: GLLine administration scope gate stays CLOSED — ' . $missing . ' of '
				. count($reread) . ' line(s) still carry no administrationId. SpendAnalytics will '
				. 'refuse the category / cost-centre / period views rather than return totals that '
				. 'silently exclude those lines.'
			);
			return;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY,
			GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION
		);

		$output->info(
			'Shillinq: GLLine administration scope gate OPEN (' . GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION
			. ') — re-read ' . count($reread) . ' line(s), 0 missing administrationId. '
			. 'SpendAnalytics category / cost-centre / period views are now administration-scoped.'
		);
	}//end proveAndOpenGate()

	/**
	 * Persist the rows the migrator stamped, as in-place updates.
	 *
	 * `rowPayload()` resolves each row through OpenRegister's `getObject()`,
	 * which prepends the object `id` — so saving the payload back resolves as
	 * an UPDATE rather than an insert.
	 *
	 * @param string $registerSlug The register slug.
	 * @param array<int, array<string, mixed>> $rows The changed rows.
	 * @param IOutput $output The repair-step output.
	 *
	 * @return int How many rows were written.
	 */
	private function writeBackfilledRows(string $registerSlug, array $rows, IOutput $output): int {
		$written = 0;

		foreach ($rows as $row) {
			$objectId = trim((string)($row['id'] ?? ''));
			if ($objectId === '') {
				$output->warning('Shillinq: GLLine administration backfill skipped a row with no object id.');
				continue;
			}

			try {
				// Runs in the installer/repair context where no web user is
				// authenticated. Bypass RBAC + multi-tenancy so the backfill
				// sees and writes every tenant's rows.
				$this->objectService->saveObject(
					object: $row,
					register: $registerSlug,
					schema: GlLineAdministrationBackfillMigrator::SCHEMA_GL_LINE,
					uuid: $objectId,
					_rbac: false,
					_multitenancy: false,
				);
				$written++;
			} catch (\Throwable $e) {
				$output->warning(
					'Shillinq: GLLine administration backfill failed for object ' . $objectId . ': ' . $e->getMessage()
				);
			}
		}//end foreach

		return $written;
	}//end writeBackfilledRows()

	/**
	 * Read every row of a schema as its payload array.
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The payload rows.
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

	/**
	 * Shut the completeness gate.
	 *
	 * @return void
	 */
	private function closeGate(): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY,
			''
		);
	}//end closeGate()
}//end class
