<?php

/**
 * Shillinq PeriodCloseBackfill Repair Step
 *
 * Idempotent repair step that backfills `FiscalPeriod` records for every
 * known Administration record, ensuring the current calendar month + the
 * next twelve months exist in the open state (per
 * `bookkeeping-period-close` REQ-PC-009 / Task 12).
 *
 * The `add-shillinq-period-close` slice already shipped a sibling
 * `BackfillFiscalPeriods` step that backfills periods from distinct
 * historical `GLLine.periodId` values — covering the in-flight ledger.
 * This step covers the complementary forward-looking horizon:
 *
 *   1. List every Administration via the OpenRegister ObjectService
 *      `findAll`.
 *   2. For each administration, derive the current calendar month and
 *      the next twelve calendar months (rolling horizon).
 *   3. For each (administrationId, periodId) tuple, check whether a
 *      matching `FiscalPeriod` record already exists (skip → idempotent;
 *      pre-existing closed / audit-locked periods are preserved).
 *   4. When no matching record exists, create a minimal
 *      `FiscalPeriod` record in state `open` with derived
 *      `name` / `startDate` / `endDate` / `fiscalYear`. No closedAt /
 *      auditLockedAt are set.
 *
 * Idempotent — re-runs of `occ maintenance:repair` (or a fresh
 * `occ app:enable shillinq`) never duplicate records and never mutate
 * existing FiscalPeriod state. Failures are non-fatal: logged + warned
 * so an operator can re-run after a fix.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use DateTimeImmutable;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Repair step that backfills forward-looking FiscalPeriod records for
 * every Administration (current calendar month + next twelve months).
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
 */
class PeriodCloseBackfill implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Number of calendar months ahead of the current month to backfill.
	 *
	 * @var int
	 */
	private const HORIZON_MONTHS = 12;

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
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
	 */
	public function getName(): string {
		return 'Shillinq: backfill open FiscalPeriod records for every Administration (current month + next twelve months)';
	}//end getName()

	/**
	 * Run the backfill. Idempotent — never duplicates records and never
	 * mutates existing FiscalPeriod state.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-12
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			// List every Administration record. The repair step is
			// best-effort: when no Administration records exist yet,
			// the forward backfill is a no-op.
			$administrations = $this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: 'Administration');

			if ($administrations === []) {
				$output->info('Shillinq: no Administration records — forward FiscalPeriod backfill skipped.');
				return;
			}

			$horizon = $this->buildHorizon();

			$created = 0;
			$skipped = 0;
			foreach ($administrations as $administration) {
				$arr = $this->rowPayload(row: $administration);
				$administrationId = (string)($arr['administrationId'] ?? ($arr['id'] ?? ($arr['code'] ?? '')));
				if ($administrationId === '') {
					continue;
				}

				foreach ($horizon as $period) {
					if ($this->fiscalPeriodExists(
						objectService: $this->objectService,
						registerSlug: $registerSlug,
						periodId: $period['periodId'],
						administrationId: $administrationId
					) === true
					) {
						$skipped++;
						continue;
					}

					$record = [
						'periodId' => $period['periodId'],
						'name' => $period['name'],
						'administrationId' => $administrationId,
						'startDate' => $period['startDate'],
						'endDate' => $period['endDate'],
						'fiscalYear' => $period['fiscalYear'],
						'state' => 'open',
						'reopenedHistory' => [],
						'taskChecklistItems' => [],
						'aiFlags' => [],
					];

					// Runs in the installer/repair context where no web user is
					// authenticated ('Anonymous'). Bypass RBAC + multi-tenancy so
					// the backfill persists instead of throwing "User 'Anonymous'
					// does not have permission to 'create'".
					$this->objectService->saveObject(
						object: $record,
						register: $registerSlug,
						schema: 'FiscalPeriod',
						_rbac: false,
						_multitenancy: false,
					);
					$created++;
				}//end foreach
			}//end foreach

			$output->info(
				'Shillinq: forward FiscalPeriod backfill complete — ' . $created . ' created, ' . $skipped . ' skipped (already exist).'
			);
		} catch (Throwable $e) {
			// Backfill is best-effort: failing it must NOT block the
			// app upgrade. Log + warn so an operator can re-run.
			$output->warning('Shillinq: forward FiscalPeriod backfill failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: forward FiscalPeriod backfill failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()

	/**
	 * Whether a FiscalPeriod record already exists for the given
	 * (periodId, administrationId) tuple.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $periodId The business periodId.
	 * @param string $administrationId The administration scope.
	 *
	 * @return bool True when a match is found.
	 */
	private function fiscalPeriodExists(
		object $objectService,
		string $registerSlug,
		string $periodId,
		string $administrationId,
	): bool {
		$filters = [
			'periodId' => $periodId,
			'administrationId' => $administrationId,
		];

		$found = $objectService
			->setRegister($registerSlug)
			->setSchema('FiscalPeriod')
			->findAll(['filters' => $filters, 'limit' => 1]);

		return is_array($found) === true && $found !== [];
	}//end fiscalPeriodExists()

	/**
	 * Build the rolling horizon — the current calendar month + the next
	 * HORIZON_MONTHS calendar months — each as a (periodId, name,
	 * startDate, endDate, fiscalYear) tuple suitable for the
	 * FiscalPeriod record.
	 *
	 * @return array<int,array{periodId:string,name:string,startDate:string,endDate:string,fiscalYear:int}> The horizon periods.
	 */
	private function buildHorizon(): array {
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

		$now = new DateTimeImmutable('first day of this month');
		$horizon = [];
		// Current month + next HORIZON_MONTHS months (inclusive).
		for ($i = 0; $i <= self::HORIZON_MONTHS; $i++) {
			$month = $now->modify('+' . $i . ' month');
			$year = (int)$month->format('Y');
			$monthNum = (int)$month->format('n');
			$startDate = $month->format('Y-m-01');
			$endDay = (int)$month->format('t');
			$endDate = $month->format('Y-m-') . sprintf('%02d', $endDay);

			$horizon[] = [
				'periodId' => sprintf('%04d-%02d', $year, $monthNum),
				'name' => sprintf('%s %04d', $names[$monthNum], $year),
				'startDate' => $startDate,
				'endDate' => $endDate,
				'fiscalYear' => $year,
			];
		}

		return $horizon;
	}//end buildHorizon()
}//end class
