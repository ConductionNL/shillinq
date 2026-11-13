<?php

/**
 * PipelinqTimelineRetryJob edge tests.
 *
 * The guards before a tick touches the queue, the entry shapes the object
 * service may answer, the timestamps it cannot read, and what it does when
 * a delete, a dead-letter write or a re-queue fails.
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
 * @spec openspec/changes/archive/2026-06-14-bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Kernel\Contracts\PipelinqContactAdapterInterface;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Guards, entry shapes and failing side effects of one retry tick.
 *
 * @covers \OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob
 * @uses   \OCA\Shillinq\Service\Pipelinq\TimelineEventDto
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class PipelinqTimelineRetryJobEdgesTest extends TestCase {

	private const NOW = '2026-06-07T13:00:00Z';

	/**
	 * What the logger received, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Jobs added to the job list.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $added = [];

	/**
	 * Events handed to the adapter.
	 *
	 * @var array<int, TimelineEventDto>
	 */
	private array $published = [];

	/**
	 * A stored retry entry.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'entry-1',
				'type' => TimelineEventDto::TYPE_BOOKING_CREATED,
				'externalId' => 'booking-1',
				'contactId' => 'contact-1',
				'timestampIso' => '2026-06-07T12:00:00Z',
				'metadata' => ['bookingNumber' => 'booking-1'],
				'retryCount' => 0,
				'maxRetries' => 3,
				'nextRetryAt' => '2026-06-07T12:59:00Z',
			],
			$overrides
		);
	}//end entry()

	/**
	 * An object service answering one entry and recording writes.
	 *
	 * @param mixed                     $found    What find() answers, or a Throwable to throw.
	 * @param array<string, \Throwable> $failures Failures by method: saveObject, deleteObject.
	 *
	 * @return object
	 */
	private static function store(mixed $found, array $failures = []): object {
		return new class($found, $failures) {
			/** @var array<int, array{0: string, 1: array<string, mixed>}> */
			public array $saves = [];

			/** @var array<int, string> */
			public array $deletes = [];

			private string $schema = '';

			/**
			 * @param mixed                     $found    The answer.
			 * @param array<string, \Throwable> $failures The failures.
			 */
			public function __construct(private mixed $found, private array $failures) {
			}

			public function setRegister(string $slug): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function find(string $id): mixed {
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}

			/**
			 * @param array<string, mixed> $object The object.
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				if (isset($this->failures['saveObject']) === true) {
					throw $this->failures['saveObject'];
				}

				$this->saves[] = [(string)$schema, $object];
				return $object;
			}

			public function deleteObject(string $id): void {
				if (isset($this->failures['deleteObject']) === true) {
					throw $this->failures['deleteObject'];
				}

				$this->deletes[] = $this->schema . ':' . $id;
			}
		};
	}//end store()

	/**
	 * Run one tick.
	 *
	 * @param mixed       $argument   The job argument.
	 * @param object|null $store      The object service, or null when the container has none.
	 * @param bool        $publishOk  What the adapter answers.
	 * @param bool        $available  Whether OpenRegister is available.
	 * @param bool        $queueFails Whether adding a job fails.
	 *
	 * @return void
	 */
	private function tick(mixed $argument, ?object $store, bool $publishOk = true, bool $available = true, bool $queueFails = false): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime(self::NOW, new DateTimeZone('UTC')));

		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn($available);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$published = &$this->published;
		$adapter = new class($publishOk, $published) extends PipelinqContactAdapter {
			/** @var array<int, TimelineEventDto> */
			private array $calls;

			/**
			 * @param bool                         $ok    The answer.
			 * @param array<int, TimelineEventDto> $calls The record.
			 */
			public function __construct(private bool $ok, array &$calls) {
				$this->calls = &$calls;
			}

			public function publishTimelineEvent(TimelineEventDto $event): bool {
				$this->calls[] = $event;
				return $this->ok;
			}
		};

		/** @var ContainerInterface&MockObject $container */
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($store, $adapter): object {
			if ($id === PipelinqContactAdapter::class || $id === PipelinqContactAdapterInterface::class) {
				return $adapter;
			}

			if ($store === null) {
				throw new RuntimeException('no object service');
			}

			return $store;
		});

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(function ($job, $argument = null) use ($queueFails): void {
			if ($queueFails === true) {
				throw new RuntimeException('queue down');
			}

			$this->added[] = ['job' => (string)$job, 'argument' => $argument];
		});

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
			private array $records;

			/**
			 * @param array<int, array{0: string, 1: string, 2: array<string, mixed>}> $records The record.
			 */
			public function __construct(array &$records) {
				$this->records = &$records;
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message, $context];
			}
		};

		$job = new PipelinqTimelineRetryJob($time, $location, $container, $jobList, $logger);
		(new ReflectionMethod(PipelinqTimelineRetryJob::class, 'run'))->invoke($job, $argument);
	}//end tick()

	/**
	 * The messages logged at a level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string>
	 */
	private function messages(string $level): array {
		return array_values(array_map(
			static fn (array $record): string => $record[1],
			array_filter($this->logged, static fn (array $record): bool => $record[0] === $level)
		));
	}//end messages()

	/**
	 * An argument without an entry id, or with an empty one, is skipped before anything is read.
	 *
	 * @return void
	 */
	public function testAnArgumentWithoutAnEntryIdIsSkipped(): void {
		$store = self::store(self::entry());

		$this->tick('entry-1', $store);
		$this->tick(['other' => 'x'], $store);
		$this->tick(['entryId' => ''], $store);

		self::assertSame(
			[
				'PipelinqTimelineRetryJob: invalid argument, skipping',
				'PipelinqTimelineRetryJob: invalid argument, skipping',
				'PipelinqTimelineRetryJob: empty entryId, skipping',
			],
			$this->messages('warning')
		);
		self::assertSame([], $this->published);
	}//end testAnArgumentWithoutAnEntryIdIsSkipped()

	/**
	 * Without OpenRegister the tick does nothing and says so.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheTickIsSkipped(): void {
		$store = self::store(self::entry());

		$this->tick(['entryId' => 'entry-1'], $store, available: false);

		self::assertSame(['PipelinqTimelineRetryJob: OpenRegister not available, skipping'], $this->messages('info'));
		self::assertSame([], $this->published);
		self::assertSame([], $store->deletes);
	}//end testWithoutOpenRegisterTheTickIsSkipped()

	/**
	 * A lookup that throws, or answers no entry shape, counts as an entry already drained.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsTreatedAsDrained(): void {
		$this->tick(['entryId' => 'entry-1'], self::store(new RuntimeException('gone')));
		$this->tick(['entryId' => 'entry-1'], self::store('not an entry'));

		self::assertSame(
			[
				'PipelinqTimelineRetryJob: entry not found, treating as already drained',
				'PipelinqTimelineRetryJob: entry not found, treating as already drained',
			],
			$this->messages('debug')
		);
		self::assertSame([], $this->published);
		self::assertSame([], $this->added);
	}//end testAFailingLookupIsTreatedAsDrained()

	/**
	 * A missing object service is logged as an unexpected tick failure.
	 *
	 * @return void
	 */
	public function testAMissingObjectServiceIsLoggedAsAFailedTick(): void {
		$this->tick(['entryId' => 'entry-1'], null);

		$errors = array_values(array_filter($this->logged, static fn (array $r): bool => $r[0] === 'error'));
		self::assertCount(1, $errors);
		self::assertSame('PipelinqTimelineRetryJob: tick failed unexpectedly', $errors[0][1]);
		self::assertSame(['entryId' => 'entry-1', 'exception' => 'no object service'], array_diff_key($errors[0][2], ['app' => true]));
	}//end testAMissingObjectServiceIsLoggedAsAFailedTick()

	/**
	 * An entry without a retry time, or with one it cannot read, is due now.
	 *
	 * @return void
	 */
	public function testAnEmptyOrUnreadableRetryTimeIsDue(): void {
		$first = self::store(self::entry(['nextRetryAt' => '']));
		$this->tick(['entryId' => 'entry-1'], $first);
		$second = self::store(self::entry(['nextRetryAt' => 'not a time']));
		$this->tick(['entryId' => 'entry-1'], $second);

		self::assertCount(2, $this->published);
		self::assertSame(['TimelinePublishRetryEntry:entry-1'], $first->deletes);
		self::assertSame(['TimelinePublishRetryEntry:entry-1'], $second->deletes);
	}//end testAnEmptyOrUnreadableRetryTimeIsDue()

	/**
	 * An unreadable event time is published at the tick's time; non-array metadata becomes none.
	 *
	 * @return void
	 */
	public function testAnUnreadableEventTimeIsPublishedAtTheTicksTime(): void {
		$this->tick(['entryId' => 'entry-1'], self::store(self::entry(['timestampIso' => 'yesterday-ish', 'metadata' => 'x'])));

		self::assertCount(1, $this->published);
		self::assertSame('2026-06-07T13:00:00+00:00', $this->published[0]->timestamp()->format(DATE_ATOM));
		self::assertSame([], $this->published[0]->metadata());
	}//end testAnUnreadableEventTimeIsPublishedAtTheTicksTime()

	/**
	 * An entity is read through jsonSerialize, or through getObject with its uuid as id.
	 *
	 * @return void
	 */
	public function testEntitiesAreReadThroughTheirAccessors(): void {
		$serialisable = new class(self::entry()) implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};
		$this->tick(['entryId' => 'entry-1'], self::store($serialisable));
		self::assertSame('booking-1', $this->published[0]->externalId());

		$row = self::entry();
		unset($row['id']);
		$entity = new class($row) {
			/**
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->row;
			}

			public function getUuid(): string {
				return 'uuid-7';
			}
		};
		$failing = self::store($entity);
		$this->tick(['entryId' => 'entry-1'], $failing, publishOk: false);

		self::assertSame('uuid-7', $failing->saves[0][1]['id']);
		self::assertSame(1, $failing->saves[0][1]['retryCount']);
	}//end testEntitiesAreReadThroughTheirAccessors()

	/**
	 * A plain object without accessors is read as its public properties.
	 *
	 * @return void
	 */
	public function testAPlainObjectIsReadAsItsProperties(): void {
		$this->tick(['entryId' => 'entry-1'], self::store((object)self::entry()));

		self::assertCount(1, $this->published);
		self::assertSame('contact-1', $this->published[0]->contactId());
	}//end testAPlainObjectIsReadAsItsProperties()

	/**
	 * A failing delete after a published retry is only a warning.
	 *
	 * @return void
	 */
	public function testAFailingDeleteIsOnlyAWarning(): void {
		$this->tick(['entryId' => 'entry-1'], self::store(self::entry(), ['deleteObject' => new RuntimeException('locked')]));

		self::assertCount(1, $this->published);
		self::assertSame(['PipelinqTimelineRetryJob: failed to delete retry entry'], $this->messages('warning'));
		self::assertSame([], $this->messages('error'));
	}//end testAFailingDeleteIsOnlyAWarning()

	/**
	 * A failing dead-letter write keeps the retry entry and is logged twice: where it failed, and as a failed tick.
	 *
	 * @return void
	 */
	public function testAFailingDeadLetterWriteKeepsTheEntry(): void {
		$store = self::store(self::entry(['retryCount' => 3]), ['saveObject' => new RuntimeException('disk full')]);

		$this->tick(['entryId' => 'entry-1'], $store, publishOk: false);

		self::assertSame([], $store->deletes);
		self::assertSame(
			['PipelinqTimelineRetryJob: failed to write dead-letter entry', 'PipelinqTimelineRetryJob: tick failed unexpectedly'],
			$this->messages('error')
		);
	}//end testAFailingDeadLetterWriteKeepsTheEntry()

	/**
	 * A dead letter without a recorded error says the retries ran out.
	 *
	 * @return void
	 */
	public function testADeadLetterWithoutAnErrorSaysTheRetriesRanOut(): void {
		$store = self::store(self::entry(['retryCount' => 3, 'metadata' => null]));

		$this->tick(['entryId' => 'entry-1'], $store, publishOk: false);

		self::assertSame('TimelineDeadLetter', $store->saves[0][0]);
		self::assertSame('max_retries_exhausted', $store->saves[0][1]['lastError']);
		self::assertSame(3, $store->saves[0][1]['retryCount']);
		self::assertSame('2026-06-07T13:00:00Z', $store->saves[0][1]['failedAt']);
		self::assertSame(['TimelinePublishRetryEntry:entry-1'], $store->deletes);
	}//end testADeadLetterWithoutAnErrorSaysTheRetriesRanOut()

	/**
	 * A failing re-queue after a failed retry is only a warning; the backoff is still saved.
	 *
	 * @return void
	 */
	public function testAFailingRequeueIsOnlyAWarning(): void {
		$store = self::store(self::entry(['retryCount' => 5, 'maxRetries' => 9]));

		$this->tick(['entryId' => 'entry-1'], $store, publishOk: false, queueFails: true);

		self::assertSame(['PipelinqTimelineRetryJob: failed to re-queue retry tick'], $this->messages('warning'));
		self::assertSame(6, $store->saves[0][1]['retryCount']);
		// Past the schedule, the backoff stays at its last step: thirty minutes.
		self::assertSame('2026-06-07T13:30:00Z', $store->saves[0][1]['nextRetryAt']);
	}//end testAFailingRequeueIsOnlyAWarning()
}//end class
