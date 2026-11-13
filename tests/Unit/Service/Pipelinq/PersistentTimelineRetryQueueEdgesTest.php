<?php

/**
 * Unit tests for PersistentTimelineRetryQueue's edge paths.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use DateTime;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * enqueue() runs after the booking commit and so must never raise: an entry
 * id is read from whichever accessor the saved entity has, a save with no id
 * schedules nothing, a scheduler that refuses is logged, and anything else
 * that throws falls back to a warning. A mutable timestamp is stored in UTC.
 *
 * @covers \OCA\Shillinq\Service\Pipelinq\PersistentTimelineRetryQueue
 * @uses \OCA\Shillinq\Service\Pipelinq\TimelineEventDto
 */
final class PersistentTimelineRetryQueueEdgesTest extends TestCase {

	/**
	 * Warning and info lines, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Jobs scheduled.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $jobs = [];

	/**
	 * Payloads saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The job list double.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * Set up the job list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument = null): void {
				$this->jobs[] = [$job, $argument];
			}
		);
	}//end setUp()

	/**
	 * The queue over an object service whose save answers the given value.
	 *
	 * @param mixed                 $saveAnswer What saveObject returns.
	 * @param RegisterLocation|null $settings   The settings, or an available default.
	 *
	 * @return PersistentTimelineRetryQueue The queue.
	 */
	private function queue(mixed $saveAnswer, ?RegisterLocation $settings = null): PersistentTimelineRetryQueue {
		$objectService = new class ($saveAnswer, $this->saved) {

			/**
			 * @param mixed                            $answer The save answer.
			 * @param array<int, array<string, mixed>> $saved  The sink.
			 */
			public function __construct(private readonly mixed $answer, private array &$saved) {
			}

			/**
			 * @param string $slug The register.
			 *
			 * @return self
			 */
			public function setRegister(string $slug): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $object   The payload.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param boolean              $_rbac    RBAC.
			 *
			 * @return mixed The answer.
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac): mixed {
				$this->saved[] = $object;

				return $this->answer;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		if ($settings === null) {
			$settings = $this->createMock(RegisterLocation::class);
			$settings->method('isOpenRegisterAvailable')->willReturn(true);
			$settings->method('getRegisterSlug')->willReturn('shillinq');
		}

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-01T12:00:00Z'));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []): void {
					$this->logged[] = [$message, $context];
				}
			);
		}

		return new PersistentTimelineRetryQueue($settings, $container, $this->jobList, $time, $logger);
	}//end queue()

	/**
	 * An event stamped with a mutable, non-UTC time.
	 *
	 * @return TimelineEventDto The event.
	 */
	private static function event(): TimelineEventDto {
		return new TimelineEventDto(
			'booking.created',
			'b-1',
			new DateTime('2026-11-01T11:30:00', new DateTimeZone('Europe/Amsterdam')),
			'contact-1',
			['bookingNumber' => 'b-1']
		);
	}//end event()

	/**
	 * The entry id is read from the saved entity's uuid, serialised @self id, or id.
	 *
	 * @return void
	 */
	public function testTheEntryIdIsReadFromTheSavedEntity(): void {
		$byUuid = new class {

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'entry-uuid';
			}
		};
		$bySelf = new class implements \JsonSerializable {

			/**
			 * @return array<string, mixed> The payload.
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'entry-self']];
			}
		};
		$byId = new class {

			/**
			 * @return integer The id.
			 */
			public function getId(): int {
				return 42;
			}
		};

		foreach ([$byUuid, $bySelf, $byId] as $saved) {
			$this->queue($saved)->enqueue(self::event());
		}

		$this->assertSame(
			[
				[PipelinqTimelineRetryJob::class, ['entryId' => 'entry-uuid']],
				[PipelinqTimelineRetryJob::class, ['entryId' => 'entry-self']],
				[PipelinqTimelineRetryJob::class, ['entryId' => '42']],
			],
			$this->jobs
		);
	}//end testTheEntryIdIsReadFromTheSavedEntity()

	/**
	 * The stored timestamp is the event's instant in UTC.
	 *
	 * @return void
	 */
	public function testAMutableTimestampIsStoredInUtc(): void {
		$this->queue(['id' => 'e-1'])->enqueue(self::event());

		$this->assertSame('2026-11-01T10:30:00Z', $this->saved[0]['timestampIso']);
		$this->assertSame('2026-11-01T12:00:00Z', $this->saved[0]['nextRetryAt']);
		$this->assertSame(0, $this->saved[0]['retryCount']);
	}//end testAMutableTimestampIsStoredInUtc()

	/**
	 * A save that yields no id schedules nothing.
	 *
	 * @return void
	 */
	public function testASaveWithoutAnIdSchedulesNothing(): void {
		foreach ([['id' => ''], new \stdClass(), 'saved'] as $answer) {
			$this->queue($answer)->enqueue(self::event());
		}

		$this->assertSame([], $this->jobs);
		$this->assertCount(3, $this->saved);
	}//end testASaveWithoutAnIdSchedulesNothing()

	/**
	 * A scheduler that refuses is logged and the call still returns.
	 *
	 * @return void
	 */
	public function testARefusedScheduleIsLogged(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willThrowException(new RuntimeException('cron table locked'));
		$this->jobList = $jobList;

		$this->queue(['id' => 'e-1'])->enqueue(self::event());

		$this->assertSame(
			[['PersistentTimelineRetryQueue: failed to schedule retry job', ['app' => 'shillinq', 'entryId' => 'e-1', 'exception' => 'cron table locked']]],
			$this->logged
		);
	}//end testARefusedScheduleIsLogged()

	/**
	 * Anything else that throws falls back to a warning instead of raising.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureNeverRaises(): void {
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willThrowException(new RuntimeException('config unreadable'));

		$this->queue(['id' => 'e-1'], $settings)->enqueue(self::event());

		$this->assertSame(
			[['PersistentTimelineRetryQueue: enqueue failed, falling back to logging', ['app' => 'shillinq', 'type' => 'booking.created', 'externalId' => 'b-1', 'exception' => 'config unreadable']]],
			$this->logged
		);
		$this->assertSame([], $this->saved);
	}//end testAnUnexpectedFailureNeverRaises()
}//end class
