<?php

/**
 * Unit tests for TimelineDeadLetterController.
 *
 * Slice 09 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Verifies the admin dashboard contract:
 *
 *   - index() lists dead-letter rows.
 *   - retry() writes a fresh TimelinePublishRetryEntry (retryCount 0),
 *     adds a PipelinqTimelineRetryJob tick, and stamps dispatchedAt on
 *     the source dead-letter row.
 *   - retry() returns 404 when the id is unknown.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTimeZone;
use OCA\Shillinq\Controller\TimelineDeadLetterController;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Verifies the dead-letter dashboard endpoints.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
final class TimelineDeadLetterControllerTest extends TestCase {

	/**
	 * Build a fixed-store ObjectService stub mirroring the retry-job test
	 * helper but kept local so each test file is self-contained.
	 *
	 * @param array<string, array<string, array<string, mixed>>> &$store In-memory store.
	 * @param array<int, array<string, mixed>> &$saves saveObject capture.
	 *
	 * @return object
	 */
	private function objectService(array &$store, array &$saves): object {
		return new class($store, $saves) {
			/**
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			private array $store;

			/**
			 * @var array<int, array<string, mixed>>
			 */
			private array $saves;

			/**
			 * @var string|null
			 */
			private ?string $schema = null;

			/**
			 * @param array<string, array<string, array<string, mixed>>> &$store
			 * @param array<int, array<string, mixed>> &$saves
			 */
			public function __construct(array &$store, array &$saves) {
				$this->store = & $store;
				$this->saves = & $saves;
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
			 * @param array<string, mixed> $config Optional find configuration.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$schema = (string)$this->schema;
				return array_values($this->store[$schema] ?? []);
			}//end findAll()

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
		};

	}//end objectService()

	/**
	 * Build a container that resolves the OR ObjectService id.
	 *
	 * @param object $objectService Stub.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('Unexpected container lookup: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * Recording job-list stub.
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
	 * Settings stub with OR available.
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
	 * @param string $iso ISO instant (UTC).
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
	 * Build a dead-letter row.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function dead(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'dl-1',
				'type' => 'booking.created',
				'externalId' => 'booking-abc-123',
				'contactId' => 'pl-contact-42',
				'timestampIso' => '2026-06-07T12:34:56Z',
				'metadata' => ['bookingNumber' => 'booking-abc-123'],
				'retryCount' => 3,
				'lastError' => 'transport',
				'failedAt' => '2026-06-07T12:36:56Z',
				'dispatchedAt' => null,
			],
			$overrides
		);

	}//end dead()

	/**
	 * Build the controller under test.
	 *
	 * @param object $objectService Stub.
	 * @param IJobList $jobList Stub.
	 *
	 * @return TimelineDeadLetterController
	 */
	private function controller(object $objectService, IJobList $jobList): TimelineDeadLetterController {
		return new TimelineDeadLetterController(
			request: $this->createMock(IRequest::class),
			settings: $this->settings(),
			container: $this->container($objectService),
			jobList: $jobList,
			time: $this->timeAt('2026-06-07T14:00:00Z'),
			logger: new NullLogger(),
		);

	}//end controller()

	/**
	 * index() returns the dead-letter rows currently in the store.
	 *
	 * @return void
	 */
	public function testIndexListsDeadLetterRows(): void {
		$store = [
			'TimelineDeadLetter' => [
				'dl-1' => $this->dead(),
				'dl-2' => $this->dead(['id' => 'dl-2', 'externalId' => 'booking-other']),
			],
		];
		$saves = [];
		$added = [];

		$controller = $this->controller(
			objectService: $this->objectService($store, $saves),
			jobList: $this->jobList($added)
		);

		$response = $controller->index();
		self::assertInstanceOf(JsonResponseShape::class, JsonResponseShape::wrap($response));

		$data = JsonResponseShape::data($response);
		self::assertSame(2, $data['total']);
		self::assertCount(2, $data['results']);

	}//end testIndexListsDeadLetterRows()

	/**
	 * retry() writes a fresh retry entry, schedules a job tick, and
	 * stamps dispatchedAt on the source dead-letter row.
	 *
	 * @return void
	 */
	public function testRetryRequeuesAndStampsDispatchedAt(): void {
		$store = [
			'TimelineDeadLetter' => [
				'dl-1' => $this->dead(),
			],
			'TimelinePublishRetryEntry' => [],
		];
		$saves = [];
		$added = [];

		$controller = $this->controller(
			objectService: $this->objectService($store, $saves),
			jobList: $this->jobList($added)
		);

		$response = $controller->retry(id: 'dl-1');
		$data = JsonResponseShape::data($response);

		self::assertTrue($data['success']);
		self::assertSame('dl-1', $data['deadLetter']);
		self::assertNotEmpty($data['entryId']);

		// Two saves: the new retry entry + the dispatched-stamp on the
		// dead-letter row.
		$schemas = array_map(static fn (array $s): string => (string)$s['schema'], $saves);
		self::assertContains('TimelinePublishRetryEntry', $schemas);
		self::assertContains('TimelineDeadLetter', $schemas);

		// The retry entry MUST start with retryCount=0.
		foreach ($saves as $save) {
			if ($save['schema'] === 'TimelinePublishRetryEntry') {
				self::assertSame(0, $save['object']['retryCount']);
				self::assertSame(PipelinqTimelineRetryJob::DEFAULT_MAX_RETRIES, $save['object']['maxRetries']);
				self::assertSame('booking-abc-123', $save['object']['externalId']);
				self::assertNull($save['object']['lastError']);
			}

			if ($save['schema'] === 'TimelineDeadLetter') {
				self::assertSame('2026-06-07T14:00:00Z', $save['object']['dispatchedAt']);
			}
		}

		self::assertCount(1, $added);
		self::assertSame(PipelinqTimelineRetryJob::class, $added[0]['job']);

	}//end testRetryRequeuesAndStampsDispatchedAt()

	/**
	 * retry() with an unknown id returns 404.
	 *
	 * @return void
	 */
	public function testRetryUnknownIdReturns404(): void {
		$store = [
			'TimelineDeadLetter' => [],
		];
		$saves = [];
		$added = [];

		$controller = $this->controller(
			objectService: $this->objectService($store, $saves),
			jobList: $this->jobList($added)
		);

		$response = $controller->retry(id: 'dl-missing');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRetryUnknownIdReturns404()

}//end class

/**
 * Tiny adapter so the tests can assert on the JSONResponse shape without
 * hard-coding internal getData() / getStatus() calls everywhere.
 */
final class JsonResponseShape {

	/**
	 * Wrap the response (no-op marker for assertion clarity).
	 *
	 * @param mixed $response JSONResponse.
	 *
	 * @return self
	 */
	public static function wrap(mixed $response): self {
		return new self();
	}//end wrap()

	/**
	 * Read the JSON payload out of a JSONResponse.
	 *
	 * @param mixed $response JSONResponse.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(mixed $response): array {
		if (method_exists($response, 'getData') === false) {
			return [];
		}

		$payload = $response->getData();
		if (is_array($payload) === true) {
			return $payload;
		}

		return [];
	}//end data()

}//end class
