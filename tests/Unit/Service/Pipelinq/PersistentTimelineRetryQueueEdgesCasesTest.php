<?php

/**
 * Edge cases of the PersistentTimelineRetryQueue.
 *
 * Covers the id shapes a save can answer with (array, entity uuid,
 * serialised `@self` id, numeric id, none), the timestamp normalisation to
 * UTC, a failed job schedule and the outer never-raise guard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue;
use OCA\Shillinq\Kernel\Model\TimelineEventDto;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verifies the queue's id extraction, timestamp rendering and failure paths.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
#[CoversClass(PersistentTimelineRetryQueue::class)]
#[UsesClass(TimelineEventDto::class)]
final class PersistentTimelineRetryQueueEdgesCasesTest extends TestCase {

	/**
	 * The payloads saved by the stub object service.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build an object service stub whose saveObject() answers $result.
	 *
	 * @param mixed $result What saveObject() returns.
	 *
	 * @return object
	 */
	private function objectService(mixed $result): object {
		$saved = &$this->saved;
		return new class($result, $saved) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			private array $saved;

			/**
			 * @param mixed $result The save result.
			 * @param array<int, array<string, mixed>> &$saved Capture sink.
			 */
			public function __construct(private mixed $result, array &$saved) {
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * @param string $slug Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $slug): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $object Payload.
			 * @param string|null $register Register.
			 * @param string|null $schema Schema.
			 * @param bool $_rbac RBAC flag.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, bool $_rbac = true): mixed {
				$this->saved[] = $object;
				return $this->result;
			}//end saveObject()
		};

	}//end objectService()

	/**
	 * Build the queue under test.
	 *
	 * @param mixed $saveResult What the object service save answers.
	 * @param IJobList $jobList The job list.
	 * @param LoggerInterface|null $logger The logger.
	 * @param RegisterLocation|null $settings The register location.
	 *
	 * @return PersistentTimelineRetryQueue
	 */
	private function queue(
		mixed $saveResult,
		IJobList $jobList,
		?LoggerInterface $logger = null,
		?RegisterLocation $settings = null,
	): PersistentTimelineRetryQueue {
		if ($settings === null) {
			$settings = $this->createMock(RegisterLocation::class);
			$settings->method('isOpenRegisterAvailable')->willReturn(true);
			$settings->method('getRegisterSlug')->willReturn('shillinq');
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($this->objectService(result: $saveResult));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-07T15:00:00+02:00'));

		return new PersistentTimelineRetryQueue(
			settings: $settings,
			container: $container,
			jobList: $jobList,
			time: $time,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);

	}//end queue()

	/**
	 * Build an event at the given timestamp.
	 *
	 * @param \DateTimeInterface|null $timestamp The event time.
	 *
	 * @return TimelineEventDto
	 */
	private function event(?\DateTimeInterface $timestamp = null): TimelineEventDto {
		return new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CONFIRMED,
			externalId: 'booking-5',
			timestamp: ($timestamp ?? new DateTimeImmutable('2026-06-07T12:00:00Z')),
			contactId: 'contact-5'
		);

	}//end event()

	/**
	 * The id shapes a save can answer with, and the job argument each yields.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function savedIdShapes(): array {
		return [
			'array id' => [['id' => 'arr-1'], 'arr-1'],
			'entity uuid' => [
				new class {
					/**
					 * @return string
					 */
					public function getUuid(): string {
						return 'uuid-1';
					}//end getUuid()
				},
				'uuid-1',
			],
			'serialised id' => [
				new class implements \JsonSerializable {
					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['id' => 'json-1'];
					}//end jsonSerialize()
				},
				'json-1',
			],
			'serialised @self id after an empty uuid' => [
				new class implements \JsonSerializable {
					/**
					 * @return string
					 */
					public function getUuid(): string {
						return '';
					}//end getUuid()

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['@self' => ['id' => 'self-1']];
					}//end jsonSerialize()
				},
				'self-1',
			],
			'numeric id' => [
				new class {
					/**
					 * @return int
					 */
					public function getId(): int {
						return 42;
					}//end getId()
				},
				'42',
			],
		];

	}//end savedIdShapes()

	/**
	 * Each id shape schedules the retry job with that id.
	 *
	 * @param mixed $saveResult The save result.
	 * @param string $expectedId The expected entry id.
	 *
	 * @return void
	 */
	#[DataProvider('savedIdShapes')]
	public function testTheSavedIdIsPassedToTheRetryJob(mixed $saveResult, string $expectedId): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::once())
			->method('add')
			->with(PipelinqTimelineRetryJob::class, ['entryId' => $expectedId]);

		$this->queue(saveResult: $saveResult, jobList: $jobList)->enqueue(event: $this->event());

	}//end testTheSavedIdIsPassedToTheRetryJob()

	/**
	 * A save that yields no id schedules nothing.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function idlessResults(): array {
		return [
			'array without id' => [['id' => '']],
			'scalar' => ['saved'],
			'null' => [null],
			'object without id accessors' => [new \stdClass()],
			'serialised to a non-array' => [
				new class implements \JsonSerializable {
					/**
					 * @return string
					 */
					public function jsonSerialize(): string {
						return 'x';
					}//end jsonSerialize()
				},
			],
		];

	}//end idlessResults()

	/**
	 * Without an id there is nothing for the job to retry.
	 *
	 * @param mixed $saveResult The save result.
	 *
	 * @return void
	 */
	#[DataProvider('idlessResults')]
	public function testAnIdlessSaveSchedulesNoJob(mixed $saveResult): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');

		$this->queue(saveResult: $saveResult, jobList: $jobList)->enqueue(event: $this->event());

		self::assertCount(1, $this->saved);

	}//end testAnIdlessSaveSchedulesNoJob()

	/**
	 * The event time and the retry time are both written in UTC.
	 *
	 * @return void
	 */
	public function testTimesAreWrittenInUtc(): void {
		$jobList = $this->createMock(IJobList::class);
		$mutable = new DateTime('2026-03-01T10:30:00', new DateTimeZone('Europe/Amsterdam'));

		$this->queue(saveResult: ['id' => 'x'], jobList: $jobList)->enqueue(event: $this->event(timestamp: $mutable));

		self::assertSame('2026-03-01T09:30:00Z', $this->saved[0]['timestampIso']);
		self::assertSame('2026-06-07T13:00:00Z', $this->saved[0]['nextRetryAt']);
		self::assertSame('Europe/Amsterdam', $mutable->getTimezone()->getName(), 'The event time must not be mutated');

	}//end testTimesAreWrittenInUtc()

	/**
	 * A failed job schedule is logged and swallowed; no success line follows.
	 *
	 * @return void
	 */
	public function testAFailedJobScheduleIsLoggedAndSwallowed(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willThrowException(new RuntimeException('job table locked'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				'PersistentTimelineRetryQueue: failed to schedule retry job',
				[
					'app' => 'shillinq',
					'entryId' => 'entry-3',
					'exception' => 'job table locked',
				]
			);
		$logger->expects(self::never())->method('info');

		$this->queue(saveResult: ['id' => 'entry-3'], jobList: $jobList, logger: $logger)->enqueue(event: $this->event());

	}//end testAFailedJobScheduleIsLoggedAndSwallowed()

	/**
	 * A successful enqueue logs the queued entry at INFO.
	 *
	 * @return void
	 */
	public function testASuccessfulEnqueueIsLoggedAtInfo(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::once())
			->method('info')
			->with(
				'PersistentTimelineRetryQueue: event queued for async retry',
				[
					'app' => 'shillinq',
					'entryId' => 'entry-4',
					'type' => 'booking.confirmed',
					'externalId' => 'booking-5',
					'contactId' => 'contact-5',
				]
			);

		$this->queue(saveResult: ['id' => 'entry-4'], jobList: $this->createMock(IJobList::class), logger: $logger)
			->enqueue(event: $this->event());

	}//end testASuccessfulEnqueueIsLoggedAtInfo()

	/**
	 * Even a failing availability check never raises out of enqueue().
	 *
	 * @return void
	 */
	public function testEnqueueNeverRaises(): void {
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willThrowException(new RuntimeException('app manager down'));

		$jobList = $this->createMock(IJobList::class);
		$jobList->expects(self::never())->method('add');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				'PersistentTimelineRetryQueue: enqueue failed, falling back to logging',
				[
					'app' => 'shillinq',
					'type' => 'booking.confirmed',
					'externalId' => 'booking-5',
					'exception' => 'app manager down',
				]
			);

		$this->queue(saveResult: ['id' => 'x'], jobList: $jobList, logger: $logger, settings: $settings)
			->enqueue(event: $this->event());

		self::assertSame([], $this->saved);

	}//end testEnqueueNeverRaises()
}//end class
