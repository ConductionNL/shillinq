<?php

/**
 * Shillinq BackfillFiscalPeriods Repair Step
 *
 * Idempotent repair step that backfills `FiscalPeriod` records for every
 * distinct historical `GLLine.periodId` value (per
 * `add-shillinq-period-close` REQ-PC-001 / Task 11).
 *
 * The promotion of `GLLine.periodId` from a stub string to a real FK
 * resolving against the `FiscalPeriod` register is additive — existing
 * string values resolve by exact match on the `periodId` field of the
 * new register. To make that resolution clean for every historical
 * line, this repair step:
 *
 *   1. Lists every distinct `(administrationId, periodId)` tuple
 *      appearing on `GLLine` records via the OpenRegister ObjectService
 *      `findAll`.
 *   2. For each tuple, checks whether a matching `FiscalPeriod` record
 *      already exists (skip → idempotent).
 *   3. When no matching record exists, creates a minimal
 *      `FiscalPeriod` record in state `open` with a derived `name`
 *      (`Q1 2026` / `January 2026` / `Week 12 2026` based on the
 *      `periodId` shape) and a best-effort `startDate` / `endDate`
 *      derived from the `periodId` slug. `fiscalYear` is parsed from
 *      the same slug (or defaults to the current calendar year).
 *
 * Idempotent — re-runs of `occ maintenance:repair` (or a fresh
 * `occ app:enable shillinq`) never duplicate records and never mutate
 * existing FiscalPeriod state.
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
 * @spec openspec/changes/add-shillinq-period-close/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use DateTimeImmutable;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Repair step that backfills FiscalPeriod records for every distinct
 * historical (administrationId, periodId) tuple appearing on GLLine.
 *
 * @spec openspec/changes/add-shillinq-period-close/tasks.md#task-11
 */
class BackfillFiscalPeriods implements IRepairStep {
	use RunsUnderSystemIdentity;

	use ReadsSourceRowsInBatches;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (register slug).
	 * @param LoggerInterface $logger The logger interface.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/add-shillinq-period-close/tasks.md#task-11
	 */
	public function getName(): string {
		return 'Shillinq: backfill FiscalPeriod records from historical GLLine.periodId values';
	}//end getName()

	/**
	 * Run the backfill. Idempotent — never duplicates records and never
	 * mutates existing FiscalPeriod state.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-period-close/tasks.md#task-11
	 */
	public function run(IOutput $output): void {
		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous' — measured against this register, not
		// assumed. Without it this backfill writes nothing and says so only in a
		// warning, which does not fail the upgrade.
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
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function runInner(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			// Stream every GLLine carrying a non-empty periodId; collect
			// the (administrationId, periodId) tuples we have not yet
			// materialised as FiscalPeriod records.
			$lines = $this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: 'GLLine');

			if ($lines === []) {
				$output->info('Shillinq: no GLLine records — FiscalPeriod backfill skipped.');
				return;
			}

			// Bucket distinct (administrationId, periodId) tuples.
			$tuples = [];
			foreach ($lines as $line) {
				$arr = $this->rowPayload(row: $line);
				$periodId = (string)($arr['periodId'] ?? '');
				if ($periodId === '') {
					continue;
				}

				// GLLine now DOES carry administrationId directly,
				// denormalised from the parent GLTransaction by
				// glline-administration-scope (REQ-GLS-001) and backfilled by
				// BackfillGlLineAdministration, which is registered ahead of
				// this step. The comment that used to sit here said the
				// opposite and told the reader to derive it from the parent —
				// which this line never did, so it silently bucketed every
				// historical tuple under administration ''. Reading the
				// property is now both what the code does and what works.
				$administrationId = (string)($arr['administrationId'] ?? '');
				$key = $administrationId . '|' . $periodId;
				if (isset($tuples[$key]) === true) {
					continue;
				}

				$tuples[$key] = ['administrationId' => $administrationId, 'periodId' => $periodId];
			}

			$created = 0;
			$skipped = 0;
			foreach ($tuples as $tuple) {
				if ($this->fiscalPeriodExists(
					objectService: $this->objectService,
					registerSlug: $registerSlug,
					periodId: $tuple['periodId'],
					administrationId: $tuple['administrationId']
				) === true
				) {
					$skipped++;
					continue;
				}

				$record = $this->buildSeedRecord(
					periodId: $tuple['periodId'],
					administrationId: $tuple['administrationId']
				);

				// Runs in the installer/repair context where no web user is
				// authenticated ('Anonymous'). Bypass RBAC + multi-tenancy so the
				// backfill persists instead of throwing "User 'Anonymous' does not
				// have permission to 'create'".
				$this->objectService->saveObject(
					object: $record,
					register: $registerSlug,
					schema: 'FiscalPeriod',
					_rbac: false,
					_multitenancy: false,
				);
				$created++;
			}//end foreach

			$output->info(
				'Shillinq: FiscalPeriod backfill complete — ' . $created . ' created, ' . $skipped . ' skipped (already exist).'
			);
		} catch (\Throwable $e) {
			// Backfill is best-effort: failing it must NOT block the
			// app upgrade. Log + warn so an operator can re-run.
			$output->warning('Shillinq: FiscalPeriod backfill failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: FiscalPeriod backfill failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end runInner()

	/**
	 * Whether a FiscalPeriod record already exists for the given
	 * (periodId, administrationId) tuple.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $periodId The business periodId.
	 * @param string $administrationId The administration scope ('' for none).
	 *
	 * @return bool True when a match is found.
	 */
	private function fiscalPeriodExists(
		object $objectService,
		string $registerSlug,
		string $periodId,
		string $administrationId,
	): bool {
		$filters = ['periodId' => $periodId];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$found = $objectService
			->setRegister($registerSlug)
			->setSchema('FiscalPeriod')
			->findAll(['filters' => $filters, 'limit' => 1]);

		return is_array($found) === true && $found !== [];
	}//end fiscalPeriodExists()

	/**
	 * Build the seed record for a distinct historical periodId. Derives
	 * a reasonable `name`, `startDate`, `endDate`, and `fiscalYear` from
	 * the periodId slug (e.g. `2026-Q1`, `2026-01`, `2026-W12`,
	 * `FY2026`). Falls back to placeholder values when the slug shape
	 * is unrecognised — the operator can refine the record afterwards.
	 *
	 * @param string $periodId The business periodId.
	 * @param string $administrationId The administration scope ('' allowed).
	 *
	 * @return array<string,mixed> The seed FiscalPeriod record (state: open).
	 */
	private function buildSeedRecord(string $periodId, string $administrationId): array {
		$derived = $this->derivePeriodFields(periodId: $periodId);

		$resolvedAdministrationId = 'unknown';
		if ($administrationId !== '') {
			$resolvedAdministrationId = $administrationId;
		}

		$record = [
			'periodId' => $periodId,
			'name' => $derived['name'],
			'administrationId' => $resolvedAdministrationId,
			'startDate' => $derived['startDate'],
			'endDate' => $derived['endDate'],
			'fiscalYear' => $derived['fiscalYear'],
			'state' => 'open',
			'reopenedHistory' => [],
			'taskChecklistItems' => [],
			'aiFlags' => [],
		];

		return $record;
	}//end buildSeedRecord()

	/**
	 * Parse a periodId slug into a (name, startDate, endDate,
	 * fiscalYear) tuple. Recognises:
	 *
	 *   - YYYY-Qn        → calendar quarter
	 *   - YYYY-Mnn       → calendar month
	 *   - YYYY-nn        → calendar month (alias)
	 *   - YYYY-Wnn       → ISO week
	 *   - FYYYYY         → fiscal year only
	 *
	 * Falls back to YYYY-01-01..YYYY-12-31 when only a year can be
	 * extracted, and to today's year + a placeholder name otherwise.
	 *
	 * @param string $periodId The periodId slug.
	 *
	 * @return array{name:string,startDate:string,endDate:string,fiscalYear:int} The derived fields.
	 */
	private function derivePeriodFields(string $periodId): array {
		$now = new DateTimeImmutable();
		$fallbackYear = (int)$now->format('Y');

		// YYYY-Qn (calendar quarter).
		if (preg_match('/^(\d{4})-Q([1-4])$/i', $periodId, $m) === 1) {
			$year = (int)$m[1];
			$quarter = (int)$m[2];
			$startMonth = (($quarter - 1) * 3) + 1;
			$endMonth = $startMonth + 2;
			$start = sprintf('%04d-%02d-01', $year, $startMonth);
			$endDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))->format('t');
			$end = sprintf('%04d-%02d-%02d', $year, $endMonth, $endDay);

			return [
				'name' => sprintf('Q%d %04d', $quarter, $year),
				'startDate' => $start,
				'endDate' => $end,
				'fiscalYear' => $year,
			];
		}

		// YYYY-Mnn (calendar month, M-prefixed).
		if (preg_match('/^(\d{4})-M(\d{2})$/i', $periodId, $m) === 1
			|| preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodId, $m) === 1
		) {
			$year = (int)$m[1];
			$month = (int)$m[2];
			$start = sprintf('%04d-%02d-01', $year, $month);
			$endDay = (int)(new DateTimeImmutable($start))->format('t');
			$end = sprintf('%04d-%02d-%02d', $year, $month, $endDay);

			$names = [
				1 => 'January',
				2 => 'February',
				3 => 'March',
				4 => 'April',
				5 => 'May',
				6 => 'June',
				7 => 'July',
				8 => 'August',
				9 => 'September',
				10 => 'October',
				11 => 'November',
				12 => 'December',
			];

			return [
				'name' => sprintf('%s %04d', $names[$month], $year),
				'startDate' => $start,
				'endDate' => $end,
				'fiscalYear' => $year,
			];
		}//end if

		// YYYY-Wnn (ISO week — startDate Monday, endDate Sunday).
		if (preg_match('/^(\d{4})-W(\d{2})$/i', $periodId, $m) === 1) {
			$year = (int)$m[1];
			$week = (int)$m[2];
			try {
				$start = (new DateTimeImmutable())->setISODate($year, $week, 1)->format('Y-m-d');
				$end = (new DateTimeImmutable())->setISODate($year, $week, 7)->format('Y-m-d');
			} catch (\Throwable) {
				$start = sprintf('%04d-01-01', $year);
				$end = sprintf('%04d-12-31', $year);
			}

			return [
				'name' => sprintf('Week %d %04d', $week, $year),
				'startDate' => $start,
				'endDate' => $end,
				'fiscalYear' => $year,
			];
		}

		// FYYYYY (fiscal-year only).
		if (preg_match('/^FY(\d{4})$/i', $periodId, $m) === 1) {
			$year = (int)$m[1];

			return [
				'name' => sprintf('Fiscal year %04d', $year),
				'startDate' => sprintf('%04d-01-01', $year),
				'endDate' => sprintf('%04d-12-31', $year),
				'fiscalYear' => $year,
			];
		}

		// YYYY-... fallback — at least the year is parseable.
		if (preg_match('/^(\d{4})/', $periodId, $m) === 1) {
			$year = (int)$m[1];

			return [
				'name' => $periodId,
				'startDate' => sprintf('%04d-01-01', $year),
				'endDate' => sprintf('%04d-12-31', $year),
				'fiscalYear' => $year,
			];
		}

		// Total fallback.
		return [
			'name' => $periodId,
			'startDate' => sprintf('%04d-01-01', $fallbackYear),
			'endDate' => sprintf('%04d-12-31', $fallbackYear),
			'fiscalYear' => $fallbackYear,
		];

	}//end derivePeriodFields()
}//end class
