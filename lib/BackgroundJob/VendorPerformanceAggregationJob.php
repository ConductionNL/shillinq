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
 * @SuppressWarnings(PHPMD.ElseExpression)         Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 */
class VendorPerformanceAggregationJob extends TimedJob {

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
	protected function run($argument): void {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->info('VendorPerformanceAggregationJob: OR unavailable, skipping.');
			return;
		}

		try {
			$now = $this->timeFactory->getDateTime();
			$period = $this->previousMonthPeriod(now: $now);
		} catch (Throwable $e) {
			$this->logger->error(
				'VendorPerformanceAggregationJob: failed to compute period',
				['exception' => $e->getMessage()]
			);
			return;
		}

		try {
			$administrations = $this->discoverAdministrationsForPeriod(period: $period);
		} catch (Throwable $e) {
			$this->logger->error(
				'VendorPerformanceAggregationJob: failed to discover administrations',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$totalScorecards = 0;
		$failedAdmins = 0;
		foreach ($administrations as $administrationId) {
			try {
				$scorecards = $this->aggregation->aggregateAdministrationForPeriod(
					administrationId: $administrationId,
					period:           $period
				);
				$totalScorecards += count($scorecards);
			} catch (Throwable $e) {
				$failedAdmins++;
				$this->logger->error(
					'VendorPerformanceAggregationJob: aggregation failed for administration',
					[
						'administrationId' => $administrationId,
						'period' => $period,
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		$this->logger->info(
			sprintf(
				'VendorPerformanceAggregationJob: period=%s scorecards=%d administrations=%d failed=%d',
				$period,
				$totalScorecards,
				count($administrations),
				$failedAdmins
			)
		);

	}//end run()

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

		$from = $period . '-01';
		$to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$rows = $objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema(self::SCHEMA_SUPPLIER_INVOICE)
			->findAll(['filters' => []]);

		$administrations = [];
		foreach ($rows as $row) {
			$data = $row;
			if (is_array($row) === false) {
				if (method_exists($row, 'jsonSerialize') === true) {
					$serialized = $row->jsonSerialize();
					if (is_array($serialized) === true) {
						$data = $serialized;
					} else {
						continue;
					}
				} else {
					continue;
				}
			}

			$admin = trim((string)($data['administrationId'] ?? ''));
			if ($admin === '') {
				continue;
			}

			$invoiceDate = $this->dateOnly(iso: (string)($data['invoiceDate'] ?? ''));
			if ($invoiceDate === '' || $invoiceDate < $from || $invoiceDate > $to) {
				continue;
			}

			$administrations[$admin] = true;
		}//end foreach

		return array_keys($administrations);
	}//end discoverAdministrationsForPeriod()

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
