<?php

/**
 * Unit tests for PipelinqTimelineRetryJob.
 *
 * Slice 09 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Verifies the retry-tick contract:
 *
 *   - successful publish drains the entry and never re-queues,
 *   - failed publish bumps retryCount, advances nextRetryAt by the
 *     exponential backoff (1m / 5m / 30m) and re-queues the tick,
 *   - max retries exhausted moves the entry into TimelineDeadLetter,
 *     deletes the retry row, and logs an ERROR,
 *   - the BACKOFF_SECONDS constant pins exactly the documented schedule.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeZone;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Verifies the per-tick branch table.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
final class PipelinqTimelineRetryJobTest extends TestCase {

	/**
	 * Build a recording logger.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

	}//end recordingLogger()

	/**
	 * Build a fixed-store ObjectService stub keyed by (schema, id).
	 *
	 * @param array<string, array<string, array<string, mixed>>> &$store In-memory store.
	 * @param array<int, array<string, mixed>> &$saves saveObject capture.
	 * @param array<int, array<string, mixed>> &$deletes deleteObject capture.
	 *
	 * @return object
	 */
	private function objectService(array &$store, array &$saves, array &$deletes): object {
		return new class($store, $saves, $deletes) {
			/**
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			private array $store;

			/**
			 * @var array<int, array<string, mixed>>
			 */
			private array $saves;

			/**
			 * @var array<int, array<string, mixed>>
			 */
			private array $deletes;

			/**
			 * @var string|null
			 */
			private ?string $schema = null;

			/**
			 * @param array<string, array<string, array<string, mixed>>> &$store
			 * @param array<int, array<string, mixed>> &$saves
			 * @param array<int, array<string, mixed>> &$deletes
			 */
			public function __construct(array &$store, array &$saves, array &$deletes) {
				$this->store = & $store;
				$this->saves = & $saves;
				$this->deletes = & $deletes;
			}//end __construct()

			/**
			 * @param string $slug Slug.
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
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param string $id Id.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id): ?array {
				$schema = (string)$this->schema;
				return ($this->store[$schema][$id] ?? null);
			}//end find()

			/**
			 * @param array<string, mixed> $object Payload.
			 * @param string|null $register Register (named).
			 * @param string|null $schema Schema (named).
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				$effectiveSchema = ($schema ?? (string)$this->schema);
				$id = (string)($object['id'] ?? ('row-' . count($this->store[$effectiveSchema] ?? [])));
				$object['id'] = $id;
				$this->store[$effectiveSchema][$id] = $object;
				$this->saves[] = [
					'schema' => $effectiveSchema,
					'object' => $object,
				];

				return $object;
			}//end saveObject()

			/**
			 * @param string $id Id.
			 *
			 * @return void
			 */
			public function deleteObject(string $id): void {
				$schema = (string)$this->schema;
				unset($this->store[$schema][$id]);
				$this->deletes[] = ['schema' => $schema, 'id' => $id];
			}//end deleteObject()
		};

	}//end objectService()

	/**
	 * Build a stub PipelinqContactAdapter that returns a configurable result.
	 *
	 * @param bool $publishOk Result to return.
	 * @param array<int, TimelineEventDto> &$publishCalls Capture sink.
	 *
	 * @return PipelinqContactAdapter
	 */
	private function adapter(bool $publishOk, array &$publishCalls): PipelinqContactAdapter {
		return new class($publishOk, $publishCalls) extends PipelinqContactAdapter {
			/**
			 * @var bool
			 */
			private bool $publishOk;

			/**
			 * @var array<int, TimelineEventDto>
			 */
			private array $publishCalls;

			/**
			 * @param bool $publishOk Result.
			 * @param array<int, TimelineEventDto> &$publishCalls Capture.
			 */
			public function __construct(bool $publishOk, array &$publishCalls) {
				$this->publishOk = $publishOk;
				$this->publishCalls = & $publishCalls;
			}//end __construct()

			/**
			 * @param TimelineEventDto $event Event.
			 *
			 * @return bool
			 */
			public function publishTimelineEvent(TimelineEventDto $event): bool {
				$this->publishCalls[] = $event;
				return $this->publishOk;
			}//end publishTimelineEvent()
		};

	}//end adapter()

	/**
	 * Build a container resolving ObjectService + PipelinqContactAdapter.
	 *
	 * @param object $objectService ObjectService stub.
	 * @param PipelinqContactAdapter $adapter Adapter stub.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $objectService, PipelinqContactAdapter $adapter): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $adapter) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === PipelinqContactAdapter::class) {
					return $adapter;
				}

				throw new \RuntimeException('Unexpected container lookup: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * Build a recording IJobList stub.
	 *
	 * @param array<int, array{job:string, argument:mixed}> &$added Sink.
	 *
	 * @return IJobList
	 */
	private function jobList(array &$added): IJobList {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function ($job, $argument = null) use (&$added) {
				$added[] = ['job' => (string)$job, 'argument' => $argument];
			}
		);

		return $jobList;
	}//end jobList()

	/**
	 * Build a settings stub.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return $settings;
	}//end settings()

	/**
	 * Pinned time factory.
	 *
	 * @param string $iso ISO instant.
	 *
	 * @return ITimeFactory
	 */
	private function timeAt(string $iso): ITimeFactory {
		$factory = $this->createMock(ITimeFactory::class);
		$factory->method('getDateTime')
			->willReturn(new \DateTime($iso, new DateTimeZone('UTC')));

		return $factory;
	}//end timeAt()

	/**
	 * Build a retry entry row.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(array $overrides = []): array {
		$defaults = [
			'id' => 'retry-entry-1',
			'type' => TimelineEventDto::TYPE_BOOKING_CREATED,
			'externalId' => 'booking-abc-123',
			'contactId' => 'pl-contact-42',
			'timestampIso' => '2026-06-07T12:34:56Z',
			'metadata' => ['bookingNumber' => 'booking-abc-123'],
			'retryCount' => 0,
			'maxRetries' => PipelinqTimelineRetryJob::DEFAULT_MAX_RETRIES,
			'nextRetryAt' => '2026-06-07T13:00:00Z',
			'lastError' => null,
			'lastAttemptAt' => null,
		];

		return array_merge($defaults, $overrides);
	}//end entry()

	/**
	 * Invoke the protected run() method on a freshly-built job.
	 *
	 * @param PipelinqContactAdapter $adapter Adapter.
	 * @param array<int, array{job:string, argument:mixed}> &$added Sink.
	 * @param object $objectService Object service stub.
	 * @param string $entryId Entry id arg.
	 * @param string $nowIso Pinned now.
	 * @param AbstractLogger $logger Recording logger.
	 *
	 * @return void
	 */
	private function runTick(
		PipelinqContactAdapter $adapter,
		array &$added,
		object $objectService,
		string $entryId,
		string $nowIso,
		AbstractLogger $logger,
	): void {
		$job = new PipelinqTimelineRetryJob(
			time: $this->timeAt($nowIso),
			settings: $this->settings(),
			container: $this->container($objectService, $adapter),
			jobList: $this->jobList($added),
			logger: $logger,
		);

		$reflection = new \ReflectionClass($job);
		$run = $reflection->getMethod('run');
		$run->setAccessible(true);
		$run->invoke($job, ['entryId' => $entryId]);

	}//end runTick()

	/**
	 * Backoff schedule is exactly 1m / 5m / 30m.
	 *
	 * @return void
	 */
	public function testBackoffScheduleIsPinned(): void {
		self::assertSame(60, PipelinqTimelineRetryJob::BACKOFF_SECONDS[0]);
		self::assertSame(300, PipelinqTimelineRetryJob::BACKOFF_SECONDS[1]);
		self::assertSame(1800, PipelinqTimelineRetryJob::BACKOFF_SECONDS[2]);
		self::assertSame(3, PipelinqTimelineRetryJob::DEFAULT_MAX_RETRIES);

	}//end testBackoffScheduleIsPinned()

	/**
	 * Successful retry drains the entry and never re-queues.
	 *
	 * @return void
	 */
	public function testSuccessfulRetryDrainsEntry(): void {
		$store = [
			'TimelinePublishRetryEntry' => ['retry-entry-1' => $this->entry()],
		];
		$saves = [];
		$deletes = [];
		$added = [];
		$publishCalls = [];

		$stub = $this->objectService($store, $saves, $deletes);
		$adapter = $this->adapter(publishOk: true, publishCalls: $publishCalls);

		$this->runTick(
			adapter: $adapter,
			added: $added,
			objectService: $stub,
			entryId: 'retry-entry-1',
			nowIso: '2026-06-07T13:00:00Z',
			logger: $this->recordingLogger()
		);

		self::assertCount(1, $publishCalls);
		self::assertCount(0, $added);

		self::assertCount(1, $deletes);
		self::assertSame('TimelinePublishRetryEntry', $deletes[0]['schema']);
		self::assertSame('retry-entry-1', $deletes[0]['id']);

	}//end testSuccessfulRetryDrainsEntry()

	/**
	 * Failed retry with retryCount 0 bumps the count, advances nextRetryAt
	 * by 60s, and re-queues the tick.
	 *
	 * @return void
	 */
	public function testFailedRetryAdvancesBackoffAndRequeues(): void {
		$store = [
			'TimelinePublishRetryEntry' => ['retry-entry-1' => $this->entry()],
		];
		$saves = [];
		$deletes = [];
		$added = [];
		$publishCalls = [];

		$stub = $this->objectService($store, $saves, $deletes);
		$adapter = $this->adapter(publishOk: false, publishCalls: $publishCalls);

		$this->runTick(
			adapter: $adapter,
			added: $added,
			objectService: $stub,
			entryId: 'retry-entry-1',
			nowIso: '2026-06-07T13:00:00Z',
			logger: $this->recordingLogger()
		);

		self::assertCount(1, $publishCalls);
		self::assertCount(0, $deletes);

		// One save: the bumped entry.
		self::assertCount(1, $saves);
		self::assertSame('TimelinePublishRetryEntry', $saves[0]['schema']);

		$persisted = $saves[0]['object'];
		self::assertSame(1, $persisted['retryCount']);
		self::assertSame('2026-06-07T13:01:00Z', $persisted['nextRetryAt']);
		self::assertSame('2026-06-07T13:00:00Z', $persisted['lastAttemptAt']);
		self::assertSame('publish_failed', $persisted['lastError']);

		self::assertCount(1, $added);
		self::assertSame(PipelinqTimelineRetryJob::class, $added[0]['job']);
		self::assertSame(['entryId' => 'retry-entry-1'], $added[0]['argument']);

	}//end testFailedRetryAdvancesBackoffAndRequeues()

	/**
	 * Second-tick failure (retryCount=1) advances nextRetryAt by 5m.
	 *
	 * @return void
	 */
	public function testSecondFailureAdvancesByFiveMinutes(): void {
		$store = [
			'TimelinePublishRetryEntry' => [
				'retry-entry-1' => $this->entry(['retryCount' => 1]),
			],
		];
		$saves = [];
		$deletes = [];
		$added = [];
		$publishCalls = [];

		$stub = $this->objectService($store, $saves, $deletes);
		$adapter = $this->adapter(publishOk: false, publishCalls: $publishCalls);

		$this->runTick(
			adapter: $adapter,
			added: $added,
			objectService: $stub,
			entryId: 'retry-entry-1',
			nowIso: '2026-06-07T13:00:00Z',
			logger: $this->recordingLogger()
		);

		$persisted = $saves[0]['object'];
		self::assertSame(2, $persisted['retryCount']);
		self::assertSame('2026-06-07T13:05:00Z', $persisted['nextRetryAt']);

	}//end testSecondFailureAdvancesByFiveMinutes()

	/**
	 * Third-tick failure (retryCount=2) advances nextRetryAt by 30m.
	 *
	 * @return void
	 */
	public function testThirdFailureAdvancesByThirtyMinutes(): void {
		$store = [
			'TimelinePublishRetryEntry' => [
				'retry-entry-1' => $this->entry(['retryCount' => 2]),
			],
		];
		$saves = [];
		$deletes = [];
		$added = [];
		$publishCalls = [];

		$stub = $this->objectService($store, $saves, $deletes);
		$adapter = $this->adapter(publishOk: false, publishCalls: $publishCalls);

		$this->runTick(
			adapter: $adapter,
			added: $added,
			objectService: $stub,
			entryId: 'retry-entry-1',
			nowIso: '2026-06-07T13:00:00Z',
			logger: $this->recordingLogger()
		);

		$persisted = $saves[0]['object'];
		self::assertSame(3, $persisted['retryCount']);
		self::assertSame('2026-06-07T13:30:00Z', $persisted['nextRetryAt']);

	}//end testThirdFailureAdvancesByThirtyMinutes()

	/**
	 * Failed retry that would exceed max_retries moves the entry into
	 * TimelineDeadLetter, deletes the retry row and logs ERROR.
	 *
	 * @return void
	 */
	public function testMaxRetriesExhaustedMovesToDeadLetter(): void {
		$store = [
			'TimelinePublishRetryEntry' => [
				'retry-entry-1' => $this->entry([
					'retryCount' => PipelinqTimelineRetryJob::DEFAULT_MAX_RETRIES,
					'lastError' => 'transport',
				]),
			],
		];
		$saves = [];
		$deletes = [];
		$added = [];
		$publishCalls = [];

		$stub = $this->objectService($store, $saves, $deletes);
		$adapter = $this->adapter(publishOk: false, publishCalls: $publishCalls);
		$logger = $this->recordingLogger();

		$this->runTick(
			adapter: $adapter,
			added: $added,
			objectService: $stub,
			entryId: 'retry-entry-1',
			nowIso: '2026-06-07T13:00:00Z',
			logger: $logger
		);

		self::assertCount(1, $publishCalls);

		// One save (the dead-letter row), one delete (the retry entry).
		self::assertCount(1, $saves);
		self::assertSame('TimelineDeadLetter', $saves[0]['schema']);
		self::assertCount(1, $deletes);
		self::assertSame('TimelinePublishRetryEntry', $deletes[0]['schema']);

		$dead = $saves[0]['object'];
		self::assertSame('booking.created', $dead['type']);
		self::assertSame('booking-abc-123', $dead['externalId']);
		self::assertSame('pl-contact-42', $dead['contactId']);
		self::assertSame('transport', $dead['lastError']);
		self::assertSame('2026-06-07T13:00:00Z', $dead['failedAt']);
		self::assertNull($dead['dispatchedAt']);

		// Re-queue MUST NOT happen on exhaustion.
		self::assertCount(0, $added);

		// ERROR logged at least once.
		$hasError = false;
		foreach ($logger->records as $record) {
			if ($record['level'] === 'error') {
				$hasError = true;
				break;
			}
		}

		self::assertTrue($hasError, 'ERROR log expected on dead-letter');

	}//end testMaxRetriesExhaustedMovesToDeadLetter()

	/**
	 * When the entry's nextRetryAt is in the future the tick re-queues
	 * without publishing.
	 *
	 * @return void
	 */
	public function testNotYetDueRequeuesWithoutPublish(): void {
		$store = [
			'TimelinePublishRetryEntry' => [
				'retry-entry-1' => $this->entry(['nextRetryAt' => '2026-06-07T14:00:00Z']),
			],
		];
		$saves = [];
		$deletes = [];
		$added = [];
		$publishCalls = [];

		$stub = $this->objectService($store, $saves, $deletes);
		$adapter = $this->adapter(publishOk: true, publishCalls: $publishCalls);

		$this->runTick(
			adapter: $adapter,
			added: $added,
			objectService: $stub,
			entryId: 'retry-entry-1',
			nowIso: '2026-06-07T13:00:00Z',
			logger: $this->recordingLogger()
		);

		self::assertCount(0, $publishCalls);
		self::assertCount(0, $saves);
		self::assertCount(0, $deletes);
		self::assertCount(1, $added);

	}//end testNotYetDueRequeuesWithoutPublish()

	/**
	 * Missing entry (already drained) is a no-op.
	 *
	 * @return void
	 */
	public function testMissingEntryIsNoop(): void {
		$store = ['TimelinePublishRetryEntry' => []];
		$saves = [];
		$deletes = [];
		$added = [];
		$publishCalls = [];

		$stub = $this->objectService($store, $saves, $deletes);
		$adapter = $this->adapter(publishOk: true, publishCalls: $publishCalls);

		$this->runTick(
			adapter: $adapter,
			added: $added,
			objectService: $stub,
			entryId: 'retry-entry-not-here',
			nowIso: '2026-06-07T13:00:00Z',
			logger: $this->recordingLogger()
		);

		self::assertCount(0, $publishCalls);
		self::assertCount(0, $saves);
		self::assertCount(0, $deletes);
		self::assertCount(0, $added);

	}//end testMissingEntryIsNoop()

}//end class
