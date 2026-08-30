<?php

/**
 * Unit tests for the PersistentTimelineRetryQueue.
 *
 * Slice 09 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Verifies the queue's contract:
 *
 *   - enqueue() writes a TimelinePublishRetryEntry record via the OR
 *     ObjectService and adds a PipelinqTimelineRetryJob tick.
 *   - enqueue() NEVER raises — the booking commit already happened
 *     by the time we're called, so a queueing error must downgrade to
 *     a WARNING log line and silently degrade.
 *   - When OpenRegister is unavailable, enqueue() short-circuits to a
 *     WARNING log without touching IJobList.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Verifies the queue persists + schedules + degrades gracefully.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
final class PersistentTimelineRetryQueueTest extends TestCase {

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
	 * Build a stub ObjectService that records saveObject() calls and
	 * returns a stable id from a hand-coded sequence.
	 *
	 * @param array<int, array<string, mixed>> &$saved Capture of save calls.
	 * @param string $idToReturn Id to return.
	 *
	 * @return object
	 */
	private function objectService(array &$saved, string $idToReturn = 'retry-entry-1'): object {
		return new class($saved, $idToReturn) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			private array $saved;

			/**
			 * @var string
			 */
			private string $idToReturn;

			/**
			 * @var string|null
			 */
			private ?string $register = null;

			/**
			 * @var string|null
			 */
			private ?string $schema = null;

			/**
			 * @param array<int, array<string, mixed>> &$saved Capture sink.
			 * @param string $idToReturn Id returned by saveObject.
			 */
			public function __construct(array &$saved, string $idToReturn) {
				$this->saved = & $saved;
				$this->idToReturn = $idToReturn;
			}//end __construct()

			/**
			 * @param string $slug Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $slug): self {
				$this->register = $slug;
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $object Payload.
			 * @param string|null $register Register slug (named).
			 * @param string|null $schema Schema name (named).
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				$this->saved[] = [
					'register' => ($register ?? $this->register),
					'schema' => ($schema ?? $this->schema),
					'object' => $object,
				];

				return array_merge(['id' => $this->idToReturn], $object);
			}//end saveObject()
		};

	}//end objectService()

	/**
	 * Build a stub ObjectService whose saveObject() raises.
	 *
	 * @return object
	 */
	private function failingObjectService(): object {
		return new class {
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
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $object Payload.
			 * @param string|null $register Register (named).
			 * @param string|null $schema Schema (named).
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				throw new \RuntimeException('OR write failed');
			}//end saveObject()
		};

	}//end failingObjectService()

	/**
	 * Build a container that returns the supplied ObjectService for the OR id.
	 *
	 * @param object $objectService Stub ObjectService.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('Unexpected container lookup: ' . $id);
			});

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
	 * Build an ITimeFactory pinned to the supplied UTC timestamp.
	 *
	 * @param string $iso ISO-8601 instant (UTC).
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
	 * Build the SettingsService mock with the supplied availability + slug.
	 *
	 * @param bool $available isOpenRegisterAvailable() result.
	 * @param string $slug getRegisterSlug() result.
	 *
	 * @return SettingsService
	 */
	private function settings(bool $available, string $slug = 'shillinq'): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getRegisterSlug')->willReturn($slug);

		return $settings;
	}//end settings()

	/**
	 * Build a TimelineEventDto with sensible defaults.
	 *
	 * @return TimelineEventDto
	 */
	private function event(): TimelineEventDto {
		return new TimelineEventDto(
			type: TimelineEventDto::TYPE_BOOKING_CREATED,
			externalId: 'booking-abc-123',
			timestamp: new DateTimeImmutable('2026-06-07T12:34:56Z', new DateTimeZone('UTC')),
			contactId: 'pl-contact-42',
			metadata: [
				'bookingNumber' => 'booking-abc-123',
				'service' => 'haircut',
			]
		);

	}//end event()

	/**
	 * Happy path: writes the retry entry, schedules a job tick.
	 *
	 * @return void
	 */
	public function testEnqueuePersistsEntryAndSchedulesJob(): void {
		$saved = [];
		$added = [];
		$stub = $this->objectService($saved, idToReturn: 'retry-entry-77');
		$logger = $this->recordingLogger();

		$queue = new PersistentTimelineRetryQueue(
			settings: $this->settings(available: true),
			container: $this->container($stub),
			jobList: $this->jobList($added),
			time: $this->timeAt('2026-06-07T13:00:00Z'),
			logger: $logger,
		);

		$queue->enqueue(event: $this->event());

		self::assertCount(1, $saved);
		self::assertSame('TimelinePublishRetryEntry', $saved[0]['schema']);

		$payload = $saved[0]['object'];
		self::assertSame('booking.created', $payload['type']);
		self::assertSame('booking-abc-123', $payload['externalId']);
		self::assertSame('pl-contact-42', $payload['contactId']);
		self::assertSame('2026-06-07T12:34:56Z', $payload['timestampIso']);
		self::assertSame(0, $payload['retryCount']);
		self::assertSame(PipelinqTimelineRetryJob::DEFAULT_MAX_RETRIES, $payload['maxRetries']);
		self::assertSame('2026-06-07T13:00:00Z', $payload['nextRetryAt']);
		self::assertNull($payload['lastError']);
		self::assertNull($payload['lastAttemptAt']);

		self::assertCount(1, $added);
		self::assertSame(PipelinqTimelineRetryJob::class, $added[0]['job']);
		self::assertSame(['entryId' => 'retry-entry-77'], $added[0]['argument']);

	}//end testEnqueuePersistsEntryAndSchedulesJob()

	/**
	 * When OR is not available we short-circuit to a WARNING and do not
	 * touch the job list.
	 *
	 * @return void
	 */
	public function testEnqueueWithoutOpenRegisterIsNoop(): void {
		$added = [];
		$logger = $this->recordingLogger();

		$queue = new PersistentTimelineRetryQueue(
			settings: $this->settings(available: false),
			container: $this->createMock(ContainerInterface::class),
			jobList: $this->jobList($added),
			time: $this->timeAt('2026-06-07T13:00:00Z'),
			logger: $logger,
		);

		$queue->enqueue(event: $this->event());

		self::assertCount(0, $added);
		self::assertNotEmpty($logger->records);
		self::assertSame('warning', $logger->records[0]['level']);

	}//end testEnqueueWithoutOpenRegisterIsNoop()

	/**
	 * A failing OR write degrades to a WARNING without raising.
	 *
	 * @return void
	 */
	public function testEnqueueSwallowsOrWriteFailure(): void {
		$added = [];
		$logger = $this->recordingLogger();

		$queue = new PersistentTimelineRetryQueue(
			settings: $this->settings(available: true),
			container: $this->container($this->failingObjectService()),
			jobList: $this->jobList($added),
			time: $this->timeAt('2026-06-07T13:00:00Z'),
			logger: $logger,
		);

		// MUST NOT raise.
		$queue->enqueue(event: $this->event());

		self::assertCount(0, $added);
		self::assertNotEmpty($logger->records);
		self::assertSame('warning', $logger->records[0]['level']);

	}//end testEnqueueSwallowsOrWriteFailure()

}//end class
