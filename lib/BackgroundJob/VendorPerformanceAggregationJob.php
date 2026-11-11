<?php

/**
 * Vendor Performance Aggregation Job
 *
 * Slice 10 of the bookkeeping-purchase-order-3way chain (REQ-PO3W-008 /
 * REQ-VP-001 / REQ-VP-005). Monthly cron that aggregates GoodsReceiptNote
 * + ThreeWayMatch + SupplierInvoice activity per supplier into a
 * VendorPerformance scorecard, sets `automatedReviewEligible=TRUE` when
 * the supplier crosses the 96 % threshold (after the 90-day bootstrap
 * window) and optionally relaxes their ToleranceProfile.
 *
 * Aggregation logic lives in {@see VendorPerformanceAggregation}; this
 * job is pure orchestration: discover the administrations with activity
 * in the previous month, then delegate per administration. The scoring
 * window is always the immediately-preceding calendar month (the
 * current month's data is incomplete on the 1st run).
 *
 * Fail-soft per administration: a single bad administration logs and the
 * sweep continues. The schedule is monthly (TimedJob 30-day interval)
 * with TIME_INSENSITIVE + allowParallelRuns=false so a missed run is
 * picked up the next scheduler poll without overlapping.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\VendorPerformanceAggregation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Monthly vendor-performance aggregation sweep.
 *
 * Discovers active administrations by inspecting SupplierInvoice activity
 * in the previous calendar month, then runs
 * {@see VendorPerformanceAggregation::aggregateAdministrationForPeriod()}
 * per administration. The 30-day interval pairs with the Nextcloud
 * scheduler poll: as soon as the first poll after a month-end fires,
 * the sweep runs for the just-closed month.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 */
class VendorPerformanceAggregationJob extends TimedJob {
	use RunsJobAsSystem;

	/**
	 * Monthly interval in seconds (30 days).
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 2592000;

	/**
	 * Schema slug for the SupplierInvoice register (slice 01) — used to
	 * enumerate active administrations.
	 *
	 * @var string
	 */
	private const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';

	/**
	 * The sweep's summary log line: period, scorecards, administrations, failed.
	 *
	 * @var string
	 */
	private const SUMMARY = 'VendorPerformanceAggregationJob: period=%s scorecards=%d administrations=%d failed=%d';

	/**
	 * Time factory captured at construction (the parent stores it
	 * privately so we keep our own reference for run()'s current-time
	 * computation).
	 *
	 * @var ITimeFactory
	 */
	private ITimeFactory $timeFactory;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for scheduling and current-time reads.
	 * @param SettingsService $settings Resolves the active OR register slug.
	 * @param VendorPerformanceAggregation $aggregation Aggregation service (server-authoritative scoring).
	 * @param ContainerInterface $container DI container for OR ObjectService lookup.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settings,
		private readonly VendorPerformanceAggregation $aggregation,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
		$this->timeFactory = $time;

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
	 * Sweep every administration with SupplierInvoice activity in the
	 * previous calendar month and write VendorPerformance scorecards.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	private function runAsJob($argument): void {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->info('VendorPerformanceAggregationJob: OR unavailable, skipping.');
			return;
		}

		$period = $this->currentPeriod();
		if ($period === null) {
			return;
		}

		$administrations = $this->activeAdministrations(period: $period);
		if ($administrations === null) {
			return;
		}

		$this->aggregateAll(period: $period, administrations: $administrations);
	}//end runAsJob()

	/**
	 * The period just closed; a failing clock is logged.
	 *
	 * @return string|null Period code (YYYY-MM), or null when it cannot be computed.
	 */
	private function currentPeriod(): ?string {
		try {
			return $this->previousMonthPeriod(now: $this->timeFactory->getDateTime());
		} catch (Throwable $e) {
			$this->logger->error(
				'VendorPerformanceAggregationJob: failed to compute period',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end currentPeriod()

	/**
	 * The administrations active in the period; a failing lookup is logged.
	 *
	 * @param string $period Period code (YYYY-MM).
	 *
	 * @return array<int,string>|null Administration ids, or null when discovery failed.
	 */
	private function activeAdministrations(string $period): ?array {
		try {
			return $this->discoverAdministrationsForPeriod(period: $period);
		} catch (Throwable $e) {
			$this->logger->error(
				'VendorPerformanceAggregationJob: failed to discover administrations',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end activeAdministrations()

	/**
	 * Aggregate every administration, continuing past failures, then log the summary.
	 *
	 * @param string            $period          Period code (YYYY-MM).
	 * @param array<int,string> $administrations Administration ids.
	 *
	 * @return void
	 */
	private function aggregateAll(string $period, array $administrations): void {
		$totalScorecards = 0;
		$failedAdmins = 0;
		foreach ($administrations as $administrationId) {
			$scorecards = $this->aggregateOne(administrationId: $administrationId, period: $period);
			if ($scorecards === null) {
				$failedAdmins++;
				continue;
			}

			$totalScorecards += $scorecards;
		}

		$this->logger->info(sprintf(self::SUMMARY, $period, $totalScorecards, count($administrations), $failedAdmins));
	}//end aggregateAll()

	/**
	 * Aggregate one administration; a failure is logged.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           Period code (YYYY-MM).
	 *
	 * @return int|null The number of scorecards written, or null when aggregation failed.
	 */
	private function aggregateOne(string $administrationId, string $period): ?int {
		try {
			return count($this->aggregation->aggregateAdministrationForPeriod(administrationId: $administrationId, period: $period));
		} catch (Throwable $e) {
			$this->logger->error(
				'VendorPerformanceAggregationJob: aggregation failed for administration',
				['administrationId' => $administrationId, 'period' => $period, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end aggregateOne()

	/**
	 * Compute the YYYY-MM code of the calendar month before $now.
	 *
	 * @param \DateTime $now Current time.
	 *
	 * @return string Period code (YYYY-MM).
	 */
	private function previousMonthPeriod(\DateTime $now): string {
		$current = DateTimeImmutable::createFromMutable($now)->modify('first day of this month');
		$prior = $current->modify('-1 month');
		return $prior->format('Y-m');
	}//end previousMonthPeriod()

	/**
	 * Enumerate administrations with SupplierInvoice activity in the period.
	 *
	 * Inspects every SupplierInvoice row (the register is normally
	 * bounded — one row per supplier invoice received) and groups by
	 * administrationId for rows whose invoiceDate falls inside the period.
	 *
	 * @param string $period Period code (YYYY-MM).
	 *
	 * @return array<int,string> Unique administration ids.
	 */
	private function discoverAdministrationsForPeriod(string $period): array {
		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period) !== 1) {
			return [];
		}

		$window = self::monthWindow(period: $period);
		$administrations = [];
		foreach ($this->supplierInvoiceRows() as $row) {
			$admin = $this->administrationInWindow(data: self::rowData(row: $row), window: $window);
			if ($admin !== '') {
				$administrations[$admin] = true;
			}
		}

		return array_keys($administrations);
	}//end discoverAdministrationsForPeriod()

	/**
	 * The first and last day (YYYY-MM-DD) of a period.
	 *
	 * @param string $period Period code (YYYY-MM).
	 *
	 * @return array{from: string, to: string}
	 */
	private static function monthWindow(string $period): array {
		$from = $period . '-01';
		$to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');

		return ['from' => $from, 'to' => $to];
	}//end monthWindow()

	/**
	 * Every SupplierInvoice row on the configured register.
	 *
	 * @return mixed What findAll answers.
	 */
	private function supplierInvoiceRows(): mixed {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return $objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema(self::SCHEMA_SUPPLIER_INVOICE)
			->findAll(['filters' => []]);
	}//end supplierInvoiceRows()

	/**
	 * A row as an array: an array as is, an object through jsonSerialize(), else null.
	 *
	 * @param mixed $row A SupplierInvoice row.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function rowData(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (method_exists($row, 'jsonSerialize') === false) {
			return null;
		}

		$serialized = $row->jsonSerialize();
		if (is_array($serialized) === true) {
			return $serialized;
		}

		return null;
	}//end rowData()

	/**
	 * The row's administration when its invoiceDate falls inside the window, else ''.
	 *
	 * @param array<string,mixed>|null       $data   The row data, or null for an unreadable row.
	 * @param array{from: string, to: string} $window The period's first and last day.
	 *
	 * @return string
	 */
	private function administrationInWindow(?array $data, array $window): string {
		if ($data === null) {
			return '';
		}

		$admin = trim((string)($data['administrationId'] ?? ''));
		$invoiceDate = $this->dateOnly(iso: (string)($data['invoiceDate'] ?? ''));
		if ($admin === '' || self::inWindow(date: $invoiceDate, window: $window) === false) {
			return '';
		}

		return $admin;
	}//end administrationInWindow()

	/**
	 * Whether a non-empty YYYY-MM-DD date lies within the window (both ends inclusive).
	 *
	 * @param string                          $date   The date, or ''.
	 * @param array{from: string, to: string} $window The period's first and last day.
	 *
	 * @return bool
	 */
	private static function inWindow(string $date, array $window): bool {
		return $date !== '' && $date >= $window['from'] && $date <= $window['to'];
	}//end inWindow()

	/**
	 * Reduce an ISO timestamp to its date component.
	 *
	 * @param string $iso ISO timestamp.
	 *
	 * @return string Date in YYYY-MM-DD or the trimmed input when shorter.
	 */
	private function dateOnly(string $iso): string {
		$trimmed = trim($iso);
		if (strlen($trimmed) >= 10) {
			return substr($trimmed, 0, 10);
		}

		return $trimmed;
	}//end dateOnly()
}//end class
