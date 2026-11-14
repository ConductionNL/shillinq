<?php

/**
 * Golden tests for RateScheduleOverlapGuard: the sibling query, self-skip, overlap answers and log lines.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\RateScheduleOverlapGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every lookup, answer and log line of the reactivate precondition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class RateScheduleOverlapGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the precondition once.
	 *
	 * @param array<string,mixed>         $schedule The schedule.
	 * @param array<int,mixed>|\Throwable $siblings findAll()'s answer, or its failure.
	 * @param string                      $slug     The configured register slug.
	 *
	 * @return bool
	 */
	private function check(array $schedule, array|\Throwable $siblings=[], string $slug='reg-h'): bool {
		$store     = new class($this, $siblings) {
			/**
			 * Construct.
			 *
			 * @param RateScheduleOverlapGuardGoldenTest $test     Recorder.
			 * @param array<int,mixed>|\Throwable        $siblings Answer.
			 */
			public function __construct(private RateScheduleOverlapGuardGoldenTest $test, private array|\Throwable $siblings) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $params];
				if ($this->siblings instanceof \Throwable) {
					throw $this->siblings;
				}

				return $this->siblings;
			}//end findAll()
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($slug);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return (new RateScheduleOverlapGuard($appConfig, $logger, new DuckObjectServiceAdapter($store)))->requireNonOverlappingWindow($schedule);
	}//end check()

	/**
	 * Without an effective date nothing is looked up.
	 *
	 * @return void
	 */
	public function testNoWindowPasses(): void {
		self::assertTrue(condition: $this->check(schedule: ['effectiveDate' => '  ', 'tier' => 't'], siblings: [['effectiveDate' => '2020-01-01']]));
		self::assertSame(expected: [], actual: $this->calls);
	}//end testNoWindowPasses()

	/**
	 * The query is exact; the schedule itself and non-overlapping siblings pass.
	 *
	 * @return void
	 */
	public function testQueryAndPass(): void {
		$schedule = [
			'id' => 's1',
			'tier' => 5,
			'entityId' => 7,
			'administrationId' => 'adm',
			'effectiveDate' => ' 2026-01-01 ',
			'expiryDate' => '2026-06-30',
		];
		$siblings = [
			['id' => 's1', 'effectiveDate' => '2026-01-01'],
			['id' => 's2', 'effectiveDate' => '2026-07-01'],
			['id' => 's3', 'effectiveDate' => '2025-01-01', 'expiryDate' => '2025-12-31'],
			['id' => 's4'],
		];
		self::assertTrue(condition: $this->check(schedule: $schedule, siblings: $siblings, slug: ''));
		self::assertSame(
			expected: [
				['setRegister', 'shillinq'],
				['setSchema', 'RateSchedule'],
				['findAll', ['filters' => ['tier' => '5', 'entityId' => 7, 'administrationId' => 'adm', 'status' => 'active']]],
			],
			actual: $this->calls
		);
	}//end testQueryAndPass()

	/**
	 * The first overlapping sibling is logged and refuses; a new schedule overlaps an id-less sibling.
	 *
	 * @return void
	 */
	public function testOverlapRefused(): void {
		$siblings = [['id' => 'a', 'effectiveDate' => '2026-06-30'], ['id' => 'b', 'effectiveDate' => '2026-01-01']];
		$result = $this->check(schedule: ['tier' => 't', 'effectiveDate' => '2026-01-01', 'expiryDate' => '2026-06-30'], siblings: $siblings);
		self::assertFalse(condition: $result);
		self::assertSame(
			expected: [[
				'info',
				'RateScheduleOverlapGuard: overlapping active schedule found — denying reactivate',
				['tier' => 't', 'entityId' => null, 'conflictingId' => 'a'],
			]],
			actual: array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'info'))
		);
		$this->calls = [];
		self::assertFalse(condition: $this->check(schedule: ['effectiveDate' => '2026-01-01'], siblings: [['effectiveDate' => '1990-01-01']]));
		self::assertNull(actual: end($this->calls)[2]['conflictingId']);
	}//end testOverlapRefused()

	/**
	 * A failing lookup, or a non-string expiry date, denies with the error line.
	 *
	 * @return void
	 */
	public function testFailuresDeny(): void {
		self::assertFalse(condition: $this->check(schedule: ['effectiveDate' => '2026-01-01'], siblings: new \RuntimeException('db')));
		self::assertSame(
			expected: ['error', 'RateScheduleOverlapGuard: requireNonOverlappingWindow check failed — denying (fail-closed)', ['exception' => 'db']],
			actual: end($this->calls)
		);
		$result = $this->check(schedule: ['effectiveDate' => '2026-01-01', 'expiryDate' => 20261231], siblings: [['effectiveDate' => '2026-01-01']]);
		self::assertFalse(condition: $result);
		self::assertSame(expected: 'error', actual: end($this->calls)[0]);
	}//end testFailuresDeny()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
