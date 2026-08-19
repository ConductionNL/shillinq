<?php

/**
 * Unit tests for NotificationController.
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
 * @spec openspec/specs/bookings-notification-triggers/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\NotificationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fluent stand-in for OCA\OpenRegister\Service\ObjectService covering the
 * surface NotificationController uses: setRegister / setSchema / find (throws
 * on a miss, like the real find()) / findAll (schema + filters) and a
 * recording updateObject.
 */
final class FakeNotificationObjectService {

	/**
	 * Seeded rows per schema.
	 *
	 * @var array<string, list<array<string,mixed>>>
	 */
	private array $rows;

	/**
	 * Recorded updateObject() calls.
	 *
	 * @var array<int, array{id:string, payload:array<string,mixed>}>
	 */
	public array $updates = [];

	/**
	 * Currently selected schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, list<array<string,mixed>>> $rows Seed rows per schema.
	 */
	public function __construct(array $rows) {
		$this->rows = $rows;

	}//end __construct()

	/**
	 * Fluent register setter (single-register fixture — value ignored).
	 *
	 * @param string $register Register slug.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;

	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * Single-object lookup — throws on a miss exactly like OR's find().
	 *
	 * @param string $id The object uuid.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When no row carries the id.
	 */
	public function find(string $id): array {
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			if ((string)($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		throw new \RuntimeException('object not found');

	}//end find()

	/**
	 * Filter the seeded rows by explicit schema plus an equality filters map.
	 *
	 * @param array<string,mixed> $query Query carrying `schema` and/or `filters`.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function findAll(array $query): array {
		$schema = (string)($query['schema'] ?? $this->schema);
		$filters = (array)($query['filters'] ?? []);
		$out = [];
		foreach (($this->rows[$schema] ?? []) as $row) {
			$matches = true;
			foreach ($filters as $key => $value) {
				if ((string)($row[$key] ?? '') !== (string)$value) {
					$matches = false;
					break;
				}
			}

			if ($matches === true) {
				$out[] = $row;
			}
		}

		return $out;

	}//end findAll()

	/**
	 * Record a write and hand the payload back.
	 *
	 * @param mixed                $id      The object identifier.
	 * @param array<string,mixed>  $payload The updated object.
	 *
	 * @return array<string,mixed>
	 */
	public function updateObject(mixed $id, array $payload): array {
		$this->updates[] = ['id' => (string)$id, 'payload' => $payload];
		return $payload;

	}//end updateObject()
}//end class

/**
 * Covers the two per-booking notification endpoints (REQ-BNT-007): the
 * trigger listing and the bulk status/channel update.
 *
 * Asserts the anonymous 401 guard, the ADR-005 booking-scope 404 mask, the
 * global-trigger write refusal (a global config must never be mutated from a
 * per-booking modal) and the 503 fail-closed path when OR is unavailable.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class NotificationControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The OR fake handed back by the container.
	 *
	 * @var FakeNotificationObjectService
	 */
	private FakeNotificationObjectService $objectService;

	/**
	 * Whether the container binds the OR ObjectService at all.
	 *
	 * @var boolean
	 */
	private bool $orBound = true;

	/**
	 * Set up shared fixtures — an authenticated session that may access the
	 * seeded booking's administration is the default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->context->method('canAccess')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->objectService = new FakeNotificationObjectService($this->seed());

	}//end setUp()

	/**
	 * The default OR fixture: one booking plus a global, a booking-scoped and
	 * a foreign-booking trigger.
	 *
	 * @return array<string, list<array<string,mixed>>>
	 */
	private function seed(): array {
		return [
			'Booking' => [
				['id' => 'book-1', 'bookingId' => 'book-1', 'administrationId' => 'adm-1'],
			],
			'BookingNotificationTrigger' => [
				[
					'id' => 't-global',
					'slug' => 'global-reminder',
					'name' => 'Global reminder',
					'triggerType' => 'booking.created',
					'channels' => ['email'],
					'status' => 'enabled',
				],
				[
					'id' => 't-scoped',
					'slug' => 'scoped-reminder',
					'name' => 'Scoped reminder',
					'triggerType' => 'booking.confirmed',
					'channels' => ['email'],
					'status' => 'disabled',
					'appliesToBookingSlug' => 'book-1',
				],
				[
					'id' => 't-foreign',
					'slug' => 'foreign-reminder',
					'name' => 'Other booking reminder',
					'triggerType' => 'booking.confirmed',
					'channels' => ['push'],
					'status' => 'enabled',
					'appliesToBookingSlug' => 'book-2',
				],
			],
		];

	}//end seed()

	/**
	 * Build the controller over the current mocks.
	 *
	 * @return NotificationController
	 */
	private function controller(): NotificationController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			function (string $id): bool {
				return $this->orBound;
			}
		);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				return $this->objectService;
			}
		);

		return new NotificationController(
			$this->request,
			$container,
			$this->settings,
			$this->userSession,
			$this->context,
			$this->logger,
		);

	}//end controller()

	/**
	 * listForBooking() returns global triggers plus the ones scoped to this
	 * booking, and excludes triggers bound to another booking (REQ-BNT-007).
	 *
	 * @return void
	 */
	public function testListForBookingReturnsGlobalAndScopedTriggersOnly(): void {
		$response = $this->controller()->listForBooking('book-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('book-1', $data['bookingId']);

		$slugs = array_column($data['triggers'], 'slug');
		self::assertSame(['global-reminder', 'scoped-reminder'], $slugs);
		self::assertNotContains('foreign-reminder', $slugs);

	}//end testListForBookingReturnsGlobalAndScopedTriggersOnly()

	/**
	 * listForBooking() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testListForBookingAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller()->listForBooking('book-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testListForBookingAnonymousReturns401()

	/**
	 * A booking that does not exist answers HTTP 404 — the instance-wide
	 * trigger configuration is never returned for an unknown id.
	 *
	 * @return void
	 */
	public function testListForBookingUnknownBookingReturns404(): void {
		$response = $this->controller()->listForBooking('book-absent');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Booking not found', $response->getData()['message']);

	}//end testListForBookingUnknownBookingReturns404()

	/**
	 * A booking in another administration is masked as HTTP 404, never
	 * confirmed (REQ-MA-001).
	 *
	 * @return void
	 */
	public function testListForBookingCrossTenantIsMaskedAs404(): void {
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->context->method('canAccess')->willReturn(false);

		$response = $this->controller()->listForBooking('book-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Booking not found', $response->getData()['message']);

	}//end testListForBookingCrossTenantIsMaskedAs404()

	/**
	 * When OR is not bound the endpoint fails closed with HTTP 503 rather
	 * than returning an unscoped trigger list.
	 *
	 * @return void
	 */
	public function testListForBookingWithoutOrReturns503(): void {
		$this->orBound = false;

		$response = $this->controller()->listForBooking('book-1');

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testListForBookingWithoutOrReturns503()

	/**
	 * updateForBooking() persists status + channels on the booking-scoped
	 * trigger and silently leaves the global trigger untouched.
	 *
	 * @return void
	 */
	public function testUpdateForBookingPersistsScopedTriggerAndSkipsGlobal(): void {
		$this->request->method('getParams')->willReturn(
			[
				'updates' => [
					['slug' => 'scoped-reminder', 'status' => 'enabled', 'channels' => ['email', 'push']],
					['slug' => 'global-reminder', 'status' => 'disabled'],
					['slug' => 'foreign-reminder', 'status' => 'disabled'],
				],
			]
		);

		$response = $this->controller()->updateForBooking('book-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('book-1', $data['bookingId']);
		self::assertCount(1, $data['triggers']);
		self::assertSame('scoped-reminder', $data['triggers'][0]['slug']);
		self::assertSame('enabled', $data['triggers'][0]['status']);
		self::assertSame(['email', 'push'], $data['triggers'][0]['channels']);

		self::assertCount(1, $this->objectService->updates);
		self::assertSame('t-scoped', $this->objectService->updates[0]['id']);

	}//end testUpdateForBookingPersistsScopedTriggerAndSkipsGlobal()

	/**
	 * updateForBooking() requires a non-empty updates array (HTTP 400).
	 *
	 * @return void
	 */
	public function testUpdateForBookingRequiresUpdatesArray(): void {
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller()->updateForBooking('book-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('updates array required', $response->getData()['message']);
		self::assertSame([], $this->objectService->updates);

	}//end testUpdateForBookingRequiresUpdatesArray()

	/**
	 * updateForBooking() rejects an anonymous caller with HTTP 401 and writes
	 * nothing.
	 *
	 * @return void
	 */
	public function testUpdateForBookingAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParams')->willReturn(
			['updates' => [['slug' => 'scoped-reminder', 'status' => 'enabled']]]
		);

		$response = $this->controller()->updateForBooking('book-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame([], $this->objectService->updates);

	}//end testUpdateForBookingAnonymousReturns401()

	/**
	 * updateForBooking() refuses a booking the caller cannot access with HTTP
	 * 404 and writes nothing (ADR-005).
	 *
	 * @return void
	 */
	public function testUpdateForBookingCrossTenantIsMaskedAs404(): void {
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->context->method('canAccess')->willReturn(false);
		$this->request->method('getParams')->willReturn(
			['updates' => [['slug' => 'scoped-reminder', 'status' => 'enabled']]]
		);

		$response = $this->controller()->updateForBooking('book-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame([], $this->objectService->updates);

	}//end testUpdateForBookingCrossTenantIsMaskedAs404()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
