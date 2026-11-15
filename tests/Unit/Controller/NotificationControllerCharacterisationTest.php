<?php

/**
 * Characterisation tests for NotificationController.
 *
 * Pins the booking access check, the per-booking trigger update with every
 * skipped case, the admin monitor tally and the disable-all sweep, with the
 * queries, writes and log lines each makes.
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

use OCA\Shillinq\Controller\NotificationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins NotificationController's behaviour.
 */
final class NotificationControllerCharacterisationTest extends TestCase {

	/**
	 * Log lines as level|message|context.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * The fake object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Whether a user is logged in.
	 *
	 * @var boolean
	 */
	private bool $loggedIn = true;

	/**
	 * Whether OpenRegister is bound.
	 *
	 * @var boolean
	 */
	private bool $bound = true;

	/**
	 * Administrations the caller may access.
	 *
	 * @var array<int,string>
	 */
	private array $accessible = ['adm-1'];

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new class {
			/**
			 * Calls in order.
			 *
			 * @var array<int,string>
			 */
			public array $calls = [];

			/**
			 * findAll() answers per schema (queued), or a Throwable.
			 *
			 * @var array<string,array<int,mixed>>
			 */
			public array $answers = [];

			/**
			 * find() answers per id.
			 *
			 * @var array<string,mixed>
			 */
			public array $found = [];

			/**
			 * Ids whose update fails.
			 *
			 * @var array<int,string>
			 */
			public array $failingUpdates = [];

			/**
			 * Current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Scope to a register.
			 *
			 * @param string $r Register.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				$this->calls[] = 'register:' . $r;
				return $this;
			}

			/**
			 * Scope to a schema.
			 *
			 * @param string $s Schema.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				$this->calls[] = 'schema:' . $s;
				$this->schema = $s;
				return $this;
			}

			/**
			 * Find one object.
			 *
			 * @param string $id Id.
			 *
			 * @return mixed
			 */
			public function find(string $id): mixed {
				$this->calls[] = 'find:' . $id;
				if (array_key_exists($id, $this->found) === false) {
					throw new \RuntimeException('not found');
				}

				if ($this->found[$id] instanceof \Throwable) {
					throw $this->found[$id];
				}

				return $this->found[$id];
			}

			/**
			 * List objects.
			 *
			 * @param array<string,mixed> $query Query.
			 *
			 * @return mixed
			 */
			public function findAll(array $query): mixed {
				$schema = (string)($query['schema'] ?? $this->schema);
				$this->calls[] = 'findAll:' . json_encode($query);
				$queue = ($this->answers[$schema] ?? []);
				$answer = array_shift($queue);
				$this->answers[$schema] = $queue;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ($answer ?? []);
			}

			/**
			 * Update an object.
			 *
			 * @param mixed $id Id.
			 * @param array<string,mixed> $payload Object.
			 *
			 * @return array<string,mixed>
			 */
			public function updateObject(mixed $id, array $payload): array {
				$this->calls[] = 'update:' . $id . ':' . json_encode($payload);
				if (in_array((string)$id, $this->failingUpdates, true) === true) {
					throw new \RuntimeException('write failed');
				}

				return $payload;
			}
		};

	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return NotificationController
	 */
	private function controller(): NotificationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (): bool => $this->bound);
		$container->method('get')->willReturnCallback(fn (): object => $this->objects);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('books');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => ($this->loggedIn === true ? $this->createMock(IUser::class) : null));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(fn (string $id): bool => in_array($id, $this->accessible, true));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logs[] = $message . '|' . json_encode($context);
			}
		);

		return new NotificationController($request, $container, $settings, $session, $context, $logger);

	}//end controller()

	/**
	 * Status and body of a response.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function outcome(\OCP\AppFramework\Http\JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];

	}//end outcome()

	/**
	 * Booking lookups the caller is refused for, with the response.
	 *
	 * @return void
	 */
	public function testBookingAccess(): void {
		$this->objects->found = [
			'b-ok' => ['id' => 'b-ok', 'administrationId' => 'adm-1'],
			'b-foreign' => ['id' => 'b-foreign', 'administrationId' => 'adm-9'],
			'b-orphan' => ['id' => 'b-orphan'],
			'b-other' => ['id' => 'someone-else', 'administrationId' => 'adm-1'],
			'b-boom' => new \Error('db'),
		];
		$this->objects->answers['Booking'] = [[], [['@self' => ['id' => 'b-self'], 'administrationId' => 'adm-1']], new \Error('db')];

		$notFound = [404, ['message' => 'Booking not found']];
		self::assertSame($notFound, self::outcome($this->controller()->listForBooking('b-foreign')));
		self::assertSame($notFound, self::outcome($this->controller()->listForBooking('b-orphan')));
		self::assertSame($notFound, self::outcome($this->controller()->listForBooking('b-other')));
		self::assertSame($notFound, self::outcome($this->controller()->listForBooking('b-missing')));
		self::assertSame(200, $this->controller()->listForBooking('b-self')->getStatus());
		self::assertSame(200, $this->controller()->listForBooking('b-ok')->getStatus());
		self::assertSame($notFound, self::outcome($this->controller()->listForBooking('b-boom')), 'lookup failures are a miss, not an outage');
		$this->bound = false;
		self::assertSame([503, ['message' => 'Booking unavailable']], self::outcome($this->controller()->updateForBooking('b-ok')));
		self::assertSame(
			['shillinq.notification.booking.unavailable|{"error":"OR ObjectService not bound"}'],
			$this->logs
		);

	}//end testBookingAccess()

	/**
	 * The per-booking update over every kind of update entry.
	 *
	 * @return void
	 */
	public function testUpdateForBooking(): void {
		$this->objects->found = ['b-1' => ['id' => 'b-1', 'administrationId' => 'adm-1']];
		$this->objects->answers['BookingNotificationTrigger'] = [
			new \RuntimeException('index'),
			[],
			[['id' => 't-global', 'status' => 'enabled', 'channels' => ['email']]],
			[['id' => 't-foreign', 'appliesToBookingSlug' => 'b-2', 'status' => 'enabled', 'channels' => []]],
			[['id' => 't-1', 'appliesToBookingSlug' => 'b-1', 'status' => 'disabled', 'channels' => ['email']]],
			[['appliesToBookingSlug' => 'b-1', 'status' => 'enabled', 'channels' => ['sms']]],
			[['id' => 't-bad', 'appliesToBookingSlug' => 'b-1', 'status' => 'enabled', 'channels' => ['sms']]],
			['not an array'],
		];
		$this->objects->failingUpdates = ['t-bad'];
		$this->params = ['updates' => [
			'not an array',
			['status' => 'x'],
			['slug' => 'boom'],
			['slug' => 'nothing'],
			['slug' => 'global'],
			['slug' => 'foreign'],
			['slug' => 'scoped', 'status' => 'enabled', 'channels' => 'push'],
			['slug' => 'no-id'],
			['slug' => 'bad'],
			['slug' => 'scalar'],
		]];

		[$status, $data] = self::outcome($this->controller()->updateForBooking('b-1'));

		self::assertSame(200, $status);
		self::assertSame(
			[
				'bookingId' => 'b-1',
				'triggers' => [
					['id' => 't-1', 'appliesToBookingSlug' => 'b-1', 'status' => 'enabled', 'channels' => ['push']],
					['appliesToBookingSlug' => 'b-1', 'status' => 'enabled', 'channels' => ['sms']],
				],
			],
			$data
		);
		$triggerQuery = static fn (string $slug): string => 'findAll:' . json_encode(['register' => 'books', 'schema' => 'BookingNotificationTrigger', 'filters' => ['slug' => $slug], 'limit' => 1]);
		self::assertSame(
			[
				'register:books', 'schema:Booking', 'find:b-1',
				$triggerQuery('boom'), $triggerQuery('nothing'), $triggerQuery('global'), $triggerQuery('foreign'), $triggerQuery('scoped'),
				'update:t-1:{"id":"t-1","appliesToBookingSlug":"b-1","status":"enabled","channels":["push"]}',
				$triggerQuery('no-id'),
				'update:no-id:{"appliesToBookingSlug":"b-1","status":"enabled","channels":["sms"]}',
				$triggerQuery('bad'),
				'update:t-bad:{"id":"t-bad","appliesToBookingSlug":"b-1","status":"enabled","channels":["sms"]}',
				$triggerQuery('scalar'),
			],
			$this->objects->calls
		);
		self::assertSame(
			[
				'shillinq.notification.update.find|{"slug":"boom","error":"index"}',
				'shillinq.notification.update.persist|{"slug":"bad","error":"write failed"}',
			],
			$this->logs
		);

	}//end testUpdateForBooking()

	/**
	 * Refusals of the update before any trigger is read.
	 *
	 * @return void
	 */
	public function testUpdateRefusals(): void {
		$this->objects->found = ['b-1' => ['id' => 'b-1', 'administrationId' => 'adm-1']];
		$this->params = ['updates' => []];
		self::assertSame([400, ['message' => 'updates array required']], self::outcome($this->controller()->updateForBooking('b-1')));
		$this->params = ['updates' => 'x'];
		self::assertSame([200, ['bookingId' => 'b-1', 'triggers' => []]], self::outcome($this->controller()->updateForBooking('b-1')), 'a scalar becomes a one-element list whose entry is skipped');
		$this->loggedIn = false;
		self::assertSame([401, ['message' => 'Not logged in']], self::outcome($this->controller()->updateForBooking('b-1')));

	}//end testUpdateRefusals()

	/**
	 * The admin monitor tallies delivery statuses and keeps 25 failures.
	 *
	 * @return void
	 */
	public function testAdminMonitor(): void {
		$deliveries = [['status' => 'sent'], ['status' => 'queued'], ['status' => 'skipped'], ['status' => 'other'], []];
		for ($i = 0; $i < 27; $i++) {
			$deliveries[] = ['status' => 'failed', 'n' => $i];
		}

		$this->objects->answers['BookingNotificationTrigger'] = [[['id' => 't']]];
		$this->objects->answers['NotificationDelivery'] = [$deliveries];

		[$status, $data] = self::outcome($this->controller()->adminMonitor());

		self::assertSame(200, $status);
		self::assertSame(['sent' => 1, 'failed' => 27, 'queued' => 1, 'skipped' => 1], $data['summary']);
		self::assertSame([['id' => 't']], $data['triggers']);
		self::assertCount(25, $data['recentFailures']);
		self::assertSame(24, $data['recentFailures'][24]['n']);
		self::assertSame(
			[
				'findAll:{"register":"books","schema":"BookingNotificationTrigger"}',
				'findAll:{"register":"books","schema":"NotificationDelivery","order":{"sentAt":"desc"},"limit":200}',
			],
			$this->objects->calls
		);

	}//end testAdminMonitor()

	/**
	 * Failing reads degrade to empty lists, logged.
	 *
	 * @return void
	 */
	public function testFailingReadsAreEmpty(): void {
		$this->objects->answers['BookingNotificationTrigger'] = [new \RuntimeException('t'), 'not a list'];
		$this->objects->answers['NotificationDelivery'] = [new \RuntimeException('d'), 'not a list'];

		$first = $this->controller()->adminMonitor()->getData();
		$second = $this->controller()->adminMonitor()->getData();
		$this->bound = false;
		$third = $this->controller()->adminMonitor()->getData();

		$empty = ['summary' => ['sent' => 0, 'failed' => 0, 'queued' => 0, 'skipped' => 0], 'triggers' => [], 'recentFailures' => []];
		self::assertSame([$empty, $empty, $empty], [$first, $second, $third]);
		self::assertSame(['shillinq.notification.fetch-triggers|{"error":"t"}', 'shillinq.notification.fetch-deliveries|{"error":"d"}'], $this->logs);

	}//end testFailingReadsAreEmpty()

	/**
	 * Disable-all disables enabled triggers only, by id or slug.
	 *
	 * @return void
	 */
	public function testAdminDisableAll(): void {
		$this->objects->answers['BookingNotificationTrigger'] = [[
			['id' => 't-1', 'slug' => 's-1', 'status' => 'enabled'],
			['slug' => 's-2', 'status' => 'enabled'],
			['status' => 'enabled'],
			['id' => 't-4', 'status' => 'disabled'],
			['id' => 't-5'],
			['id' => 't-bad', 'slug' => 's-bad', 'status' => 'enabled'],
		]];
		$this->objects->failingUpdates = ['t-bad'];

		self::assertSame([200, ['disabled' => 3]], self::outcome($this->controller()->adminDisableAll()));
		self::assertSame(
			[
				'findAll:{"register":"books","schema":"BookingNotificationTrigger"}',
				'update:t-1:{"id":"t-1","slug":"s-1","status":"disabled"}',
				'update:s-2:{"slug":"s-2","status":"disabled"}',
				'update::{"status":"disabled"}',
				'update:t-bad:{"id":"t-bad","slug":"s-bad","status":"disabled"}',
			],
			$this->objects->calls
		);
		self::assertSame(['shillinq.notification.disable-all.persist|{"slug":"s-bad","error":"write failed"}'], $this->logs);

		$this->bound = false;
		self::assertSame([503, ['message' => 'OR ObjectService unavailable']], self::outcome($this->controller()->adminDisableAll()));

	}//end testAdminDisableAll()
}//end class
