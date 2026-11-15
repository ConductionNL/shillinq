<?php

/**
 * Golden tests for BookingReminderJob: windows, the booking query, the events and log lines.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\BookingReminderJob;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins one run: the three windows in order, what is queried and fired, and what is logged.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BookingReminderJobGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the job once.
	 *
	 * @param array<int,array<int,mixed>|\Throwable> $answers findAll() answers per window, in order.
	 * @param string                                 $slug    The configured register slug.
	 * @param \Throwable|null                        $notify  What evaluateEventTrigger throws, if anything.
	 *
	 * @return void
	 */
	private function runJob(array $answers, string $slug='reg-e', ?\Throwable $notify=null): void {
		$store       = new class($this, $answers) {
			/**
			 * Construct.
			 *
			 * @param BookingReminderJobGoldenTest           $test    Recorder.
			 * @param array<int,array<int,mixed>|\Throwable> $answers Answers in order.
			 */
			public function __construct(private BookingReminderJobGoldenTest $test, private array $answers) {
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
				$answer = array_shift($this->answers);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ($answer ?? []);
			}//end findAll()
		};
		$appConfig   = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($slug): string {
				$this->calls[] = ['config', $app, $key, $default];
				return $slug;
			}
		);
		$notification = $this->createMock(BookingNotificationService::class);
		$notification->method('evaluateEventTrigger')->willReturnCallback(
			function (string $eventType, array $booking) use ($notify): void {
				$this->calls[] = ['evaluate', $eventType, $booking];
				if ($notify !== null) {
					throw $notify;
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$job    = new BookingReminderJob($this->createMock(ITimeFactory::class), $notification, $appConfig, $logger, new DuckObjectServiceAdapter($store));
		$method = new ReflectionMethod(BookingReminderJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * The calls of the given kinds.
	 *
	 * @param array<int,string> $kinds Call names.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function only(array $kinds): array {
		return array_values(array_filter($this->calls, fn (array $call): bool => in_array($call[0], $kinds, true)));
	}//end only()

	/**
	 * Windows run 24h, 1h, 15m; each queries confirmed bookings around its target and fires one event per booking.
	 *
	 * @return void
	 */
	public function testWindowsQueriesAndEvents(): void {
		$before = time();
		$this->runJob(answers: [[['id' => 'b1', 'hoursUntilEvent' => 'old']], [['id' => 'b2'], ['id' => 'b3']], [['id' => 'b4']]]);
		$after  = time();

		self::assertSame(expected: ['info', 'Shillinq: BookingReminderJob starting', []], actual: $this->calls[0]);
		self::assertSame(expected: ['info', 'Shillinq: BookingReminderJob complete', ['fired' => 4]], actual: end($this->calls));
		self::assertSame(
			expected: [
				['evaluate', 'reminder', ['id' => 'b1', 'hoursUntilEvent' => 24.0]],
				['evaluate', 'reminder', ['id' => 'b2', 'hoursUntilEvent' => 1.0]],
				['evaluate', 'reminder', ['id' => 'b3', 'hoursUntilEvent' => 1.0]],
				['evaluate', 'reminder', ['id' => 'b4', 'hoursUntilEvent' => 0.3]],
			],
			actual: $this->only(kinds: ['evaluate'])
		);
		self::assertSame(expected: ['config', 'shillinq', 'register', 'shillinq'], actual: $this->calls[1]);
		self::assertSame(expected: ['setRegister', 'reg-e'], actual: $this->calls[2]);
		self::assertSame(expected: ['setSchema', 'Booking'], actual: $this->calls[3]);

		$queries = $this->only(kinds: ['findAll']);
		self::assertCount(expectedCount: 3, haystack: $queries);
		// Start / end offsets in minutes. For the 15-minute window the start
		// is "+-15 minutes", which DateTimeImmutable::modify() reads as +15.
		$offsets = [[1410, 1470], [30, 90], [15, 45]];
		foreach ($offsets as $i => [$startOffset, $endOffset]) {
			$params = $queries[$i][1];
			self::assertSame(expected: ['filters', 'limit'], actual: array_keys($params));
			self::assertSame(expected: 500, actual: $params['limit']);
			self::assertSame(expected: ['status', 'startTime[gte]', 'startTime[lte]'], actual: array_keys($params['filters']));
			self::assertSame(expected: 'confirmed', actual: $params['filters']['status']);
			$gte = strtotime($params['filters']['startTime[gte]']);
			$lte = strtotime($params['filters']['startTime[lte]']);
			self::assertSame(expected: (($endOffset - $startOffset) * 60), actual: $lte - $gte);
			self::assertGreaterThanOrEqual(expected: $before + ($startOffset * 60), actual: $gte);
			self::assertLessThanOrEqual(expected: $after + ($startOffset * 60), actual: $gte);
			self::assertStringEndsWith(suffix: '+00:00', string: $params['filters']['startTime[gte]']);
		}
	}//end testWindowsQueriesAndEvents()

	/**
	 * A failing window logs its error, counts zero and does not stop the others; a blank slug reads as shillinq.
	 *
	 * @return void
	 */
	public function testWindowFailureIsolated(): void {
		$this->runJob(answers: [new \RuntimeException('down'), [['id' => 'b2']], [['id' => 'b4']]], slug: '');
		self::assertSame(
			expected: [
				['error', 'Shillinq: BookingReminderJob window failed', ['windowMinutes' => 1440, 'exception' => 'down']],
				['info', 'Shillinq: BookingReminderJob complete', ['fired' => 2]],
			],
			actual: array_slice($this->only(kinds: ['info', 'error']), 1)
		);
		self::assertSame(expected: ['setRegister', 'shillinq'], actual: $this->only(kinds: ['setRegister'])[0]);
	}//end testWindowFailureIsolated()

	/**
	 * A failing event aborts the rest of that window, which then counts zero.
	 *
	 * @return void
	 */
	public function testEventFailureZeroesWindow(): void {
		$this->runJob(answers: [[['id' => 'a'], ['id' => 'b']], [], []], notify: new \RuntimeException('mail down'));
		self::assertCount(expectedCount: 1, haystack: $this->only(kinds: ['evaluate']));
		self::assertSame(
			expected: [
				['error', 'Shillinq: BookingReminderJob window failed', ['windowMinutes' => 1440, 'exception' => 'mail down']],
				['info', 'Shillinq: BookingReminderJob complete', ['fired' => 0]],
			],
			actual: array_slice($this->only(kinds: ['info', 'error']), 1)
		);
	}//end testEventFailureZeroesWindow()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
