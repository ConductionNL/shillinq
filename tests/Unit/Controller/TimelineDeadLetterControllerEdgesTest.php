<?php

/**
 * Unit tests for TimelineDeadLetterController's edge paths.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTime;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Controller\TimelineDeadLetterController;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The dead-letter endpoints when OpenRegister is absent, when a read or a
 * save fails, and when OpenRegister answers entities rather than arrays: the
 * rows are flattened, and the re-queued entry's id is read from whichever
 * accessor the saved entity has.
 *
 * @covers \OCA\Shillinq\Controller\TimelineDeadLetterController
 * @uses \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
final class TimelineDeadLetterControllerEdgesTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job list double.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobs;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobs   = $this->createMock(IJobList::class);
	}//end setUp()

	/**
	 * An object service answering fixed rows, a fixed find, and a save callback.
	 *
	 * @param array<int, mixed> $rows  What findAll() answers.
	 * @param mixed             $found What find() answers, or a Throwable to raise.
	 * @param callable|null     $save  Answers saveObject(object, schema).
	 *
	 * @return object The double.
	 */
	private function objectService(array $rows = [], mixed $found = null, ?callable $save = null): object {
		return new class ($rows, $found, $save) {

			/**
			 * @var array<int, array{schema: string, object: array<string, mixed>}>
			 */
			public array $saves = [];

			/**
			 * @param array<int, mixed> $rows  The rows.
			 * @param mixed             $found The find answer.
			 * @param callable|null     $save  The save answer.
			 */
			public function __construct(private readonly array $rows, private readonly mixed $found, private $save) {
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
			 * @param array<string, mixed> $config The read configuration.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config = []): array {
				return $this->rows;
			}

			/**
			 * @param string $id The id.
			 *
			 * @return mixed The answer.
			 */
			public function find(string $id): mixed {
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}

			/**
			 * @param array<string, mixed> $object   The payload.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 *
			 * @return mixed What the callback answers.
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): mixed {
				$this->saves[] = ['schema' => (string)$schema, 'object' => $object];

				return ($this->save)($object, (string)$schema);
			}
		};
	}//end objectService()

	/**
	 * The controller over a container that answers the given object service.
	 *
	 * @param object|null $objectService The service, or null for a container that throws.
	 * @param boolean     $available     Whether OpenRegister is available.
	 *
	 * @return TimelineDeadLetterController The controller.
	 */
	private function controller(?object $objectService, bool $available = true): TimelineDeadLetterController {
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is broken'));
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objectService);
		}

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-07T16:00:00+02:00'));

		return new TimelineDeadLetterController(
			request: $this->createMock(IRequest::class),
			settings: $settings,
			container: $container,
			jobList: $this->jobs,
			time: $time,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Without OpenRegister the list is empty and the retry is unavailable.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsListedOrRetried(): void {
		$controller = $this->controller(null, false);

		$index = $controller->index();
		$retry = $controller->retry('dl-1');

		$this->assertSame(Http::STATUS_OK, $index->getStatus());
		$this->assertSame(['results' => [], 'total' => 0], $index->getData());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $retry->getStatus());
		$this->assertSame(['error' => 'OpenRegister is not available'], $retry->getData());
	}//end testWithoutOpenRegisterNothingIsListedOrRetried()

	/**
	 * Entity rows are flattened through jsonSerialize, getObject or a cast; null rows are skipped.
	 *
	 * @return void
	 */
	public function testEntityRowsAreFlattened(): void {
		$serialisable = new class implements \JsonSerializable {

			/**
			 * @return array<string, string> The row.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'dl-1', 'type' => 'booking.created'];
			}
		};
		$entity = new class {

			/**
			 * @return array<string, string> The object.
			 */
			public function getObject(): array {
				return ['type' => 'booking.cancelled'];
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'dl-2';
			}
		};
		$plain       = new \stdClass();
		$plain->id   = 'dl-3';
		$plain->type = 'booking.moved';

		$response = $this->controller($this->objectService([$serialisable, null, $entity, $plain]))->index();

		$this->assertSame(
			[
				'results' => [
					['id' => 'dl-1', 'type' => 'booking.created'],
					['type' => 'booking.cancelled', 'id' => 'dl-2'],
					['id' => 'dl-3', 'type' => 'booking.moved'],
				],
				'total' => 3,
			],
			$response->getData()
		);
	}//end testEntityRowsAreFlattened()

	/**
	 * A failed read is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailedListIsALoggedServerError(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with('TimelineDeadLetterController: index failed', ['app' => 'shillinq', 'exception' => 'OpenRegister is broken']);

		$response = $this->controller(null)->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to list dead-letter entries'], $response->getData());
	}//end testAFailedListIsALoggedServerError()

	/**
	 * A blank id is a bad request; a lookup that throws reads as not found.
	 *
	 * @return void
	 */
	public function testABlankOrUnreadableIdIsRefused(): void {
		$blank = $this->controller($this->objectService())->retry('  ');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $blank->getStatus());
		$this->assertSame(['error' => 'Missing dead-letter id'], $blank->getData());

		$throws = $this->controller($this->objectService([], new RuntimeException('no such object')))->retry('dl-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $throws->getStatus());
		$this->assertSame(['error' => 'Dead-letter entry not found'], $throws->getData());
	}//end testABlankOrUnreadableIdIsRefused()

	/**
	 * A failure past the lookup is a logged 500 naming the id.
	 *
	 * @return void
	 */
	public function testAFailedRetryIsALoggedServerError(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with('TimelineDeadLetterController: retry failed', ['app' => 'shillinq', 'id' => 'dl-1', 'exception' => 'OpenRegister is broken']);

		$response = $this->controller(null)->retry('dl-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to re-queue dead-letter entry'], $response->getData());
	}//end testAFailedRetryIsALoggedServerError()

	/**
	 * A save that yields no id is a 500 and nothing is queued or stamped.
	 *
	 * @return void
	 */
	public function testARetryEntryWithoutAnIdIsNotQueued(): void {
		$this->jobs->expects($this->never())->method('add');
		$service = $this->objectService([], ['id' => 'dl-1', 'type' => 'booking.created'], static fn (array $object): array => []);

		$response = $this->controller($service)->retry('dl-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to persist re-queued entry'], $response->getData());
		$this->assertCount(1, $service->saves, 'the dead letter is not stamped');
	}//end testARetryEntryWithoutAnIdIsNotQueued()

	/**
	 * The entry id is read from the saved entity's uuid, its serialised @self, or its id.
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

		foreach (['entry-uuid' => $byUuid, 'entry-self' => $bySelf, '42' => $byId] as $expected => $saved) {
			$service = $this->objectService(
				[],
				['type' => 'booking.created', 'externalId' => 'b-1'],
				static fn (array $object, string $schema): mixed => $schema === 'TimelinePublishRetryEntry' ? $saved : $object
			);

			$response = $this->controller($service)->retry('dl-7');

			$this->assertSame(['success' => true, 'entryId' => (string)$expected, 'deadLetter' => 'dl-7'], $response->getData());
		}
	}//end testTheEntryIdIsReadFromTheSavedEntity()

	/**
	 * A dead letter read without an id is stamped under the requested id, in UTC.
	 *
	 * @return void
	 */
	public function testADeadLetterWithoutAnIdIsStampedUnderTheRequestedId(): void {
		$this->jobs->expects($this->once())
			->method('add')
			->with(PipelinqTimelineRetryJob::class, ['entryId' => 'entry-1']);
		$service = $this->objectService(
			[],
			['type' => 'booking.created'],
			static fn (array $object, string $schema): array => $schema === 'TimelinePublishRetryEntry' ? ['id' => 'entry-1'] : $object
		);

		$this->controller($service)->retry('dl-9');

		$this->assertSame('2026-06-07T14:00:00Z', $service->saves[0]['object']['nextRetryAt']);
		$this->assertSame('TimelineDeadLetter', $service->saves[1]['schema']);
		$this->assertSame('dl-9', $service->saves[1]['object']['id']);
		$this->assertSame('2026-06-07T14:00:00Z', $service->saves[1]['object']['dispatchedAt']);
	}//end testADeadLetterWithoutAnIdIsStampedUnderTheRequestedId()
}//end class
