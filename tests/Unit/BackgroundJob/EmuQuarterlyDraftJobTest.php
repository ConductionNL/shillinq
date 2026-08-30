<?php

/**
 * Unit tests for EmuQuarterlyDraftJob.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\EmuQuarterlyDraftJob;
use OCA\Shillinq\Service\EmuReportingService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the quarter-end scheduling window (REQ-EMU-001 / Tasks 8 + 9).
 */
class EmuQuarterlyDraftJobTest extends TestCase {
	/**
	 * The job under test.
	 *
	 * @var EmuQuarterlyDraftJob
	 */
	private EmuQuarterlyDraftJob $job;

	/**
	 * Set up the job with mocked dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$time = $this->createMock(ITimeFactory::class);
		$logger = $this->createMock(LoggerInterface::class);
		$svc = new EmuReportingService(logger: $logger);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->job = new EmuQuarterlyDraftJob(
			time: $time,
			emuReportingService: $svc,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * REQ-EMU-001: day-5 after Q1-end is within the window (Q1 just closed).
	 *
	 * @return void
	 */
	public function testDay5AfterQ1EndYieldsQ1(): void {
		$today = new \DateTimeImmutable('2026-04-05');
		$due = $this->job->isDueForQuarter(today: $today);
		self::assertSame([2026, 1], $due);
	}//end testDay5AfterQ1EndYieldsQ1()

	/**
	 * Task 9 fallback: day-7 still triggers (boundary inclusive).
	 *
	 * @return void
	 */
	public function testDay7FallbackStillTriggers(): void {
		$today = new \DateTimeImmutable('2026-04-07');
		$due = $this->job->isDueForQuarter(today: $today);
		self::assertSame([2026, 1], $due);
	}//end testDay7FallbackStillTriggers()

	/**
	 * Outside the window (day 8+) the job is silent.
	 *
	 * @return void
	 */
	public function testDay8AfterQuarterEndNotDue(): void {
		$today = new \DateTimeImmutable('2026-04-08');
		self::assertNull($this->job->isDueForQuarter(today: $today));
	}//end testDay8AfterQuarterEndNotDue()

	/**
	 * Outside the window (too early — day 4) the job is silent.
	 *
	 * @return void
	 */
	public function testDay4AfterQuarterEndNotDue(): void {
		$today = new \DateTimeImmutable('2026-04-04');
		self::assertNull($this->job->isDueForQuarter(today: $today));
	}//end testDay4AfterQuarterEndNotDue()

	/**
	 * REQ-EMU-001: January day 5 maps to Q4 of the previous year.
	 *
	 * @return void
	 */
	public function testJanuaryWindowMapsToQ4PreviousYear(): void {
		$today = new \DateTimeImmutable('2026-01-05');
		$due = $this->job->isDueForQuarter(today: $today);
		self::assertSame([2025, 4], $due);
	}//end testJanuaryWindowMapsToQ4PreviousYear()

	/**
	 * Q3-end window (October day 5 → Q3 closed).
	 *
	 * @return void
	 */
	public function testOctoberWindowMapsToQ3(): void {
		$today = new \DateTimeImmutable('2026-10-05');
		$due = $this->job->isDueForQuarter(today: $today);
		self::assertSame([2026, 3], $due);
	}//end testOctoberWindowMapsToQ3()
}//end class
