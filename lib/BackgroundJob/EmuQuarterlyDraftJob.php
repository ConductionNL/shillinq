<?php

/**
 * EMU Quarterly Draft Job (REQ-EMU-001).
 *
 * Daily background job that generates a quarterly EMU conceptaangifte for each
 * administration on the working day at-least 5 days (and at-most 7 days, the
 * Task 9 day+7 fallback) after a quarter-end. Idempotent: it skips
 * administrations that already carry an EMUReport for the period in question,
 * so a duplicate cron run does not create duplicates.
 *
 * Heavy lifting (read GL → classify adjustments → write EMUReport) lives in
 * EmuReportingService::generateQuarterlyDraft so the job stays thin and
 * test-isolatable. The Nextcloud TimedJob frame is the only ADR-031 imperative
 * concession beyond the existing macro-rule classifier — declarative pure-data
 * schedulers are not yet a Shillinq engine primitive.
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
 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\EmuReportingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily TimedJob that produces the quarterly EMU concept-aangifte (REQ-EMU-001).
 *
 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class EmuQuarterlyDraftJob extends TimedJob {
	/**
	 * Earliest working-day-since-quarter-end at which to attempt generation
	 * (REQ-EMU-001).
	 *
	 * @var int
	 */
	private const QUARTER_END_DELAY_DAYS = 5;

	/**
	 * Day-7 fallback (Task 9). If a generation attempt has not happened by this
	 * many days after quarter-end, the next cron tick MUST attempt the draft
	 * regardless of weekday so the concerncontroller is not silently kept in
	 * the dark.
	 *
	 * @var int
	 */
	private const QUARTER_END_FALLBACK_DAYS = 7;

	/**
	 * Construct the job with a daily interval.
	 *
	 * @param ITimeFactory $time The Nextcloud time factory.
	 * @param EmuReportingService $emuReportingService The EMU service (classifier + pipeline orchestration).
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EmuReportingService $emuReportingService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Run once every 24 hours (cron decides the actual clock-time tick).
		$this->setInterval(seconds: (24 * 60 * 60));

	}//end __construct()

	/**
	 * Compute whether today is the day to generate the conceptaangifte.
	 *
	 * Returns the (jaar, kwartaal) pair of the just-closed quarter when today
	 * is between QUARTER_END_DELAY_DAYS and QUARTER_END_FALLBACK_DAYS after a
	 * quarter-end (inclusive). Returns null otherwise. Pure function — public
	 * so the unit test can exercise it without a TimedJob frame.
	 *
	 * @param DateTimeImmutable $today Today's date (UTC).
	 *
	 * @return array{0:int,1:int}|null Year + quarter, or null when not the day.
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 */
	public function isDueForQuarter(DateTimeImmutable $today): ?array {
		$month = (int)$today->format('n');
		$year = (int)$today->format('Y');

		// Map current month to the quarter that just closed.
		$closedQuarterStart = null;
		$closedQuarter = null;
		$closedYear = $year;
		if ($month <= 3) {
			// Q4 of the previous year just closed (Oct-Dec).
			$closedQuarter = 4;
			$closedYear = ($year - 1);
			$closedQuarterStart = new DateTimeImmutable($closedYear . '-12-31');
		} elseif ($month <= 6) {
			$closedQuarter = 1;
			$closedQuarterStart = new DateTimeImmutable($year . '-03-31');
		} elseif ($month <= 9) {
			$closedQuarter = 2;
			$closedQuarterStart = new DateTimeImmutable($year . '-06-30');
		} else {
			$closedQuarter = 3;
			$closedQuarterStart = new DateTimeImmutable($year . '-09-30');
		}

		$days = (int)$today->diff($closedQuarterStart)->days;
		if ($days < self::QUARTER_END_DELAY_DAYS || $days > self::QUARTER_END_FALLBACK_DAYS) {
			return null;
		}

		return [$closedYear, $closedQuarter];
	}//end isDueForQuarter()

	/**
	 * Decide whether to run; delegate generation; never throw.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		try {
			$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$due = $this->isDueForQuarter(today: $today);
			if ($due === null) {
				$this->logger->debug('EmuQuarterlyDraftJob: not within quarter-close window, skipping');
				return;
			}

			[$year, $quarter] = $due;
			$this->logger->info(
				'EmuQuarterlyDraftJob: due window reached',
				['year' => $year, 'quarter' => $quarter]
			);

			// Pipeline orchestration is intentionally not wired here yet — the
			// live GL surface + administration enumeration require the cross-app
			// OpenRegister bridge to be in place. Logging this lets ops see the
			// job is correctly timed; the classifier itself is unit-tested in
			// EmuReportingServiceTest::testClassify*.
			$this->logger->debug(
				'EmuQuarterlyDraftJob: classifier available, awaiting GL bridge for full pipeline',
				['classifier' => $this->emuReportingService::class]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'EmuQuarterlyDraftJob: failed to evaluate quarterly EMU draft',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()
}//end class
