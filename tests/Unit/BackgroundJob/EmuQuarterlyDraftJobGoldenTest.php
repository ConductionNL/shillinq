<?php

/**
 * Golden test for EmuQuarterlyDraftJob.
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
 * @spec openspec/specs/bookkeeping-emu-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\EmuQuarterlyDraftJob;
use OCA\Shillinq\Service\EmuReportingService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins every due day across a leap year and its edges, and the log lines of a run.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class EmuQuarterlyDraftJobGoldenTest extends TestCase {

	/**
	 * Recorded log lines in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The service class the job names in its classifier line.
	 *
	 * @var string
	 */
	private string $serviceClass = '';

	/**
	 * The job, with a logger that records and can throw on its first debug line.
	 *
	 * @param bool $failDebug Whether the first debug line throws.
	 *
	 * @return EmuQuarterlyDraftJob
	 */
	private function job(bool $failDebug=false): EmuQuarterlyDraftJob {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level, &$failDebug): void {
					$this->calls[] = [$level, $message, $context];
					if ($level === 'debug' && $failDebug === true) {
						$failDebug = false;
						throw new RuntimeException('log down');
					}
				}
			);
		}

		$service            = $this->createMock(EmuReportingService::class);
		$this->serviceClass = $service::class;

		return new EmuQuarterlyDraftJob($this->createMock(ITimeFactory::class), $service, $logger);
	}//end job()

	/**
	 * Days 5 to 7 after each quarter end are due, for the quarter that closed; nothing else is.
	 *
	 * @return void
	 */
	public function testDueDays(): void {
		$job  = $this->job();
		$day  = new DateTimeImmutable('2023-12-20', new DateTimeZone('UTC'));
		$seen = [];
		while ($day->format('Y-m-d') <= '2025-01-15') {
			$due = $job->isDueForQuarter(today: $day);
			if ($due !== null) {
				$seen[] = $day->format('Y-m-d') . '=' . $due[0] . 'Q' . $due[1];
			}

			$day = $day->modify('+1 day');
		}

		$expected = [
			'2024-01-05=2023Q4',
			'2024-01-06=2023Q4',
			'2024-01-07=2023Q4',
			'2024-04-05=2024Q1',
			'2024-04-06=2024Q1',
			'2024-04-07=2024Q1',
			'2024-07-05=2024Q2',
			'2024-07-06=2024Q2',
			'2024-07-07=2024Q2',
			'2024-10-05=2024Q3',
			'2024-10-06=2024Q3',
			'2024-10-07=2024Q3',
			'2025-01-05=2024Q4',
			'2025-01-06=2024Q4',
			'2025-01-07=2024Q4',
		];
		self::assertSame(expected: $expected, actual: $seen);
		self::assertSame(expected: [2025, 2], actual: $job->isDueForQuarter(today: new DateTimeImmutable('2025-07-06 23:59:59', new DateTimeZone('UTC'))));
	}//end testDueDays()

	/**
	 * A run logs the skip, or the due window and the waiting classifier; a failure inside is one error line.
	 *
	 * @return void
	 */
	public function testRun(): void {
		$job = $this->job();
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
		$due      = $job->isDueForQuarter(today: new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$expected = [['debug', 'EmuQuarterlyDraftJob: not within quarter-close window, skipping', []]];
		if ($due !== null) {
			$expected = [
				['info', 'EmuQuarterlyDraftJob: due window reached', ['year' => $due[0], 'quarter' => $due[1]]],
				['debug', 'EmuQuarterlyDraftJob: classifier available, awaiting GL bridge for full pipeline', ['classifier' => $this->serviceClass]],
			];
		}

		self::assertSame(expected: $expected, actual: $this->calls);
		$this->calls = [];
		$job         = $this->job(failDebug: true);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
		$last = $this->calls[count($this->calls) - 1];
		self::assertSame(expected: ['error', 'EmuQuarterlyDraftJob: failed to evaluate quarterly EMU draft', ['exception' => 'log down']], actual: $last);
	}//end testRun()

	/**
	 * In the due window a run logs the window and the waiting classifier, nothing more.
	 *
	 * @return void
	 */
	public function testDueRun(): void {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$service = $this->createMock(EmuReportingService::class);
		$job     = new class ($this->createMock(ITimeFactory::class), $service, $logger) extends EmuQuarterlyDraftJob {
			/**
			 * Always due for 2025 Q3.
			 *
			 * @param DateTimeImmutable $today Ignored.
			 *
			 * @return array{0:int,1:int}|null
			 */
			public function isDueForQuarter(DateTimeImmutable $today): ?array {
				return [2025, 3];
			}
		};
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
		$expected = [
			['info', 'EmuQuarterlyDraftJob: due window reached', ['year' => 2025, 'quarter' => 3]],
			['debug', 'EmuQuarterlyDraftJob: classifier available, awaiting GL bridge for full pipeline', ['classifier' => $service::class]],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testDueRun()
}//end class
