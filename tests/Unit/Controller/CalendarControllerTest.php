<?php

/**
 * Unit tests for CalendarController.
 *
 * Covers the four endpoints of REQ-005 (bookings-resource-calendar):
 *   - GET /api/v2/calendars (list all + filter by resource)
 *   - GET /api/v2/calendars/{id}
 *   - GET /api/v2/calendars/{id}/bookings (date range)
 *   - POST /api/v2/calendars/{id}/bookings (201 + 409 conflict)
 *
 * The OR ObjectService is faked via an in-memory PSR container so the
 * tests run without a live OpenRegister instance.
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
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CalendarController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CalendarController.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-8
 */
class CalendarControllerTest extends TestCase {

	/**
	 * IRequest stub for parameter reads.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Settings service stub.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Administration context stub.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Transactional guard stub.
	 *
	 * @var TransactionalGuard&MockObject
	 */
	private TransactionalGuard&MockObject $guard;

	/**
	 * Conflict detection stub.
	 *
	 * @var ConflictDetectionService&MockObject
	 */
	private ConflictDetectionService&MockObject $conflicts;

	/**
	 * Fake ObjectService — captures saveObject calls and returns canned reads.
	 *
	 * @var object
	 */
	private object $fakeObjectService;

	/**
	 * PSR container that resolves the fake ObjectService.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Transition runner.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * Per-request query-param overrides.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Build the fake stack for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->guard = $this->createMock(TransactionalGuard::class);
		$this->conflicts = $this->createMock(ConflictDetectionService::class);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);

		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('buildContext')->willReturn([
			'userId' => 'alice',
			'administrations' => [['administrationId' => 'adm-1']],
			'activeAdministrationId' => 'adm-1',
		]);
		// Default: alice is a member of adm-1 (the seeded calendars'
		// administration) — the positive-direction case for the
		// per-object booking guard (security-endpoint-guards REQ-001).
		$this->context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);

		$this->request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				return $this->params[$key] ?? $default;
			}
		);

		$this->fakeObjectService = $this->buildFakeObjectService();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->fakeObjectService);

		// Transactional guard: a real transaction state machine, since the
		// controller checks inTransaction() after rollBack().
		$tx = false;
		$this->guard->method('beginTransaction')->willReturnCallback(function () use (&$tx): void {
			$tx = true;
		});
		$this->guard->method('commit')->willReturnCallback(function () use (&$tx): void {
			$tx = false;
		});
		$this->guard->method('rollBack')->willReturnCallback(function () use (&$tx): void {
			$tx = false;
		});
		$this->guard->method('inTransaction')->willReturnCallback(function () use (&$tx): bool {
			return $tx;
		});
		$this->guard->method('lockResourceRow')->willReturn(true);

	}//end setUp()

	/**
	 * Create the controller under test.
	 *
	 * @return CalendarController
	 */
	private function buildController(): CalendarController {
		return new CalendarController(
			$this->request,
			$this->container,
			$this->settings,
			$this->conflicts,
			$this->context,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			$this->buildL10n(),
			$this->buildGroupManager(false),
			$this->transitions,
		);
	}//end buildController()

	/**
	 * Build an IL10N stub that echoes its input (no real translation catalog in unit tests).
	 *
	 * @return IL10N&MockObject
	 */
	private function buildL10n(): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => $text);
		return $l10n;
	}//end buildL10n()

	/**
	 * Build an IGroupManager stub whose isAdmin() is fixed to $isAdmin.
	 *
	 * @param bool $isAdmin Whether every uid should be treated as an admin.
	 *
	 * @return IGroupManager&MockObject
	 */
	private function buildGroupManager(bool $isAdmin): IGroupManager&MockObject {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		return $groupManager;
	}//end buildGroupManager()

	/**
	 * Fake ObjectService that mimics the OR query-builder fluent API.
	 *
	 * @return object
	 */
	private function buildFakeObjectService(): object {
		return new class() {
			/**
			 * Canonical seed records, keyed by schema name.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $records = [
				'Calendar' => [
					[
						'calendarId' => 'cal-001',
						'resource' => 'res-001',
						'administrationId' => 'adm-1',
						'timeZone' => 'Europe/Amsterdam',
						'workingHours' => ['monday' => '09:00-17:00'],
						'status' => 'active',
						'createdAt' => '2026-05-20T10:00:00Z',
						'updatedAt' => '2026-05-20T10:00:00Z',
					],
					[
						'calendarId' => 'cal-002',
						'resource' => 'res-003',
						'administrationId' => 'adm-1',
						'timeZone' => 'Europe/Amsterdam',
						'workingHours' => null,
						'status' => 'active',
						'createdAt' => '2026-05-20T10:00:00Z',
						'updatedAt' => '2026-05-20T10:00:00Z',
					],
				],
				'Booking' => [
					[
						'bookingId' => 'bk-001',
						'calendar' => 'cal-001',
						'resource' => 'res-001',
						'administrationId' => 'adm-1',
						'title' => 'Klant: Anna de Wit',
						'startTime' => '2026-05-21T10:00:00Z',
						'endTime' => '2026-05-21T10:30:00Z',
						'attendee' => 'Anna de Wit',
						'status' => 'confirmed',
					],
					[
						'bookingId' => 'bk-002',
						'calendar' => 'cal-001',
						'resource' => 'res-001',
						'administrationId' => 'adm-1',
						'title' => 'Klant: Kees Bakker',
						'startTime' => '2026-05-21T11:00:00Z',
						'endTime' => '2026-05-21T11:45:00Z',
						'attendee' => 'Kees Bakker',
						'status' => 'confirmed',
					],
					[
						'bookingId' => 'bk-008',
						'calendar' => 'cal-001',
						'resource' => 'res-001',
						'administrationId' => 'adm-1',
						'title' => 'Out of range',
						'startTime' => '2027-01-01T10:00:00Z',
						'endTime' => '2027-01-01T10:30:00Z',
						'attendee' => 'Cliente Z',
						'status' => 'confirmed',
					],
				],
			];

			public array $savedObjects = [];

			private string $currentSchema = '';

			public function setRegister(string $_): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}

			public function findAll(array $opts = []): array {
				$rows = ($this->records[$this->currentSchema] ?? []);
				$filters = ($opts['filters'] ?? []);
				$matched = [];
				foreach ($rows as $row) {
					$ok = true;
					foreach ($filters as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							$ok = false;
							break;
						}
					}

					if ($ok === true) {
						$matched[] = $row;
					}
				}

				return $matched;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->savedObjects[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				// Echo back the object so the controller can shape the response.
				return array_merge($object, ['@self' => ['id' => 'uuid-saved']]);
			}
		};
	}//end buildFakeObjectService()

	/**
	 * GET /api/v2/calendars lists all calendars for the active administration.
	 *
	 * @return void
	 */
	public function testIndexListsAllCalendars(): void {
		$controller = $this->buildController();
		$response = $controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(2, $data['calendars']);
		$this->assertSame('cal-001', $data['calendars'][0]['id']);
		$this->assertSame('cal-002', $data['calendars'][1]['id']);
	}//end testIndexListsAllCalendars()

	/**
	 * GET /api/v2/calendars?resource=res-001 filters by resource.
	 *
	 * @return void
	 */
	public function testIndexFiltersByResource(): void {
		$this->params['resource'] = 'res-001';
		$controller = $this->buildController();
		$response = $controller->index();
		$data = $response->getData();
		$this->assertCount(1, $data['calendars']);
		$this->assertSame('cal-001', $data['calendars'][0]['id']);
	}//end testIndexFiltersByResource()

	/**
	 * GET /api/v2/calendars/{id} returns the matching calendar.
	 *
	 * @return void
	 */
	public function testShowReturnsCalendarById(): void {
		$controller = $this->buildController();
		$response = $controller->show('cal-001');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('cal-001', $response->getData()['id']);
	}//end testShowReturnsCalendarById()

	/**
	 * GET /api/v2/calendars/{id} returns 404 when missing.
	 *
	 * @return void
	 */
	public function testShowReturns404WhenMissing(): void {
		$controller = $this->buildController();
		$response = $controller->show('cal-missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowReturns404WhenMissing()

	/**
	 * A user outside the calendar's administration cannot read it: the
	 * answer is the same 404 as for a calendar that does not exist.
	 *
	 * @return void
	 */
	public function testShowRefusesCallerOutsideTheAdministration(): void {
		$response = $this->buildControllerFor(uid: 'mallory', memberOf: 'adm-2', isAdmin: false)->show('cal-001');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('id', $response->getData());
	}//end testShowRefusesCallerOutsideTheAdministration()

	/**
	 * A user outside the calendar's administration cannot list its bookings
	 * (and so its attendees).
	 *
	 * @return void
	 */
	public function testListBookingsRefusesCallerOutsideTheAdministration(): void {
		$this->params['start'] = '2026-05-21';
		$this->params['end'] = '2026-05-22';
		$response = $this->buildControllerFor(uid: 'mallory', memberOf: 'adm-2', isAdmin: false)->listBookings('cal-001');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('bookings', $response->getData());
	}//end testListBookingsRefusesCallerOutsideTheAdministration()

	/**
	 * Asking the index for another administration's calendars is refused.
	 *
	 * @return void
	 */
	public function testIndexRefusesAnAdministrationTheCallerIsNotIn(): void {
		$this->params['organization'] = 'adm-1';
		$response = $this->buildControllerFor(uid: 'mallory', memberOf: 'adm-2', isAdmin: false)->index();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('calendars', $response->getData());
	}//end testIndexRefusesAnAdministrationTheCallerIsNotIn()

	/**
	 * A caller with no administration lists no calendars, not even one that
	 * has no administration either.
	 *
	 * @return void
	 */
	public function testIndexWithoutAnAdministrationListsNothing(): void {
		$this->fakeObjectService->records['Calendar'][] = ['id' => 'cal-orphan', 'name' => 'Orphan'];
		$response = $this->buildControllerFor(uid: 'newcomer', memberOf: '', isAdmin: false)->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['calendars']);
	}//end testIndexWithoutAnAdministrationListsNothing()

	/**
	 * A Nextcloud admin reads any calendar, as for booking.
	 *
	 * @return void
	 */
	public function testShowByAdminBypassesMembershipCheck(): void {
		$response = $this->buildControllerFor(uid: 'admin', memberOf: '', isAdmin: true)->show('cal-001');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('cal-001', $response->getData()['id']);
	}//end testShowByAdminBypassesMembershipCheck()

	/**
	 * Build a controller for another caller with one membership.
	 *
	 * @param string $uid      The caller's user id.
	 * @param string $memberOf The one administration the caller is a member of ('' for none).
	 * @param bool   $isAdmin  Whether the caller is a Nextcloud admin.
	 *
	 * @return CalendarController
	 */
	private function buildControllerFor(string $uid, string $memberOf, bool $isAdmin): CalendarController {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($uid);
		$context->method('buildContext')->willReturn([
			'userId' => $uid,
			'administrations' => $memberOf === '' ? [] : [['administrationId' => $memberOf]],
			'activeAdministrationId' => $memberOf === '' ? null : $memberOf,
		]);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $memberOf !== '' && $administrationId === $memberOf
		);

		return new CalendarController(
			$this->request,
			$this->container,
			$this->settings,
			$this->conflicts,
			$context,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			$this->buildL10n(),
			$this->buildGroupManager($isAdmin),
			$this->transitions,
		);
	}//end buildControllerFor()

	/**
	 * GET /bookings returns only bookings inside the date range and sorts ascending.
	 *
	 * @return void
	 */
	public function testListBookingsAppliesDateRange(): void {
		$this->params['start'] = '2026-05-21';
		$this->params['end'] = '2026-05-22';
		$controller = $this->buildController();
		$response = $controller->listBookings('cal-001');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$bookings = $response->getData()['bookings'];
		$this->assertCount(2, $bookings, 'Out-of-range booking should be filtered out');
		$this->assertSame('bk-001', $bookings[0]['id']);
		$this->assertSame('bk-002', $bookings[1]['id']);
	}//end testListBookingsAppliesDateRange()

	/**
	 * GET /bookings returns 404 when the calendar is missing.
	 *
	 * @return void
	 */
	public function testListBookingsReturns404WhenCalendarMissing(): void {
		$controller = $this->buildController();
		$response = $controller->listBookings('cal-missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testListBookingsReturns404WhenCalendarMissing()

	/**
	 * GET /bookings returns 400 when the range is inverted.
	 *
	 * @return void
	 */
	public function testListBookingsRejectsInvertedRange(): void {
		$this->params['start'] = '2026-05-22';
		$this->params['end'] = '2026-05-21';
		$controller = $this->buildController();
		$response = $controller->listBookings('cal-001');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testListBookingsRejectsInvertedRange()

	/**
	 * POST creates a booking and returns 201 when there's no conflict.
	 *
	 * @return void
	 */
	public function testCreateBookingReturns201OnSuccess(): void {
		$this->params = [
			'title' => 'Klant: Bob Jansen',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:30:00Z',
			'attendee' => 'Bob Jansen',
			'status' => 'pending',
		];
		$this->conflicts->method('checkConflicts')->willReturn([]);
		$this->conflicts->method('lockResource')->willReturn(true);

		$controller = $this->buildController();
		$response = $controller->createBooking('cal-001');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('cal-001', $data['calendar']);
		$this->assertSame('res-001', $data['resource']);
		$this->assertSame('Klant: Bob Jansen', $data['title']);
		$this->assertSame('pending', $data['status']);
		$this->assertCount(1, $this->fakeObjectService->savedObjects);
		$saved = $this->fakeObjectService->savedObjects[0];
		$this->assertSame('Booking', $saved['schema']);
		$this->assertSame('res-001', $saved['object']['resource']);
	}//end testCreateBookingReturns201OnSuccess()

	/**
	 * POST returns 409 when the conflict service reports an overlap.
	 *
	 * @return void
	 */
	public function testCreateBookingReturns409OnConflict(): void {
		$this->params = [
			'title' => 'Klant: Sophia Vermeulen',
			'startTime' => '2026-05-21T11:15:00Z',
			'endTime' => '2026-05-21T12:00:00Z',
			'attendee' => 'Sophia Vermeulen',
			'status' => 'confirmed',
		];
		$this->conflicts->method('checkConflicts')->willReturn([
			['bookingId' => 'bk-002', 'title' => 'Klant: Kees Bakker', 'startTime' => '2026-05-21T11:00:00Z', 'endTime' => '2026-05-21T11:45:00Z'],
		]);
		$this->conflicts->method('lockResource')->willReturn(true);

		$controller = $this->buildController();
		$response = $controller->createBooking('cal-001');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('conflict', $data['error']);
		$this->assertCount(1, $data['conflicts']);
		$this->assertSame('bk-002', $data['conflicts'][0]['bookingId']);
		$this->assertCount(0, $this->fakeObjectService->savedObjects, 'No booking is saved on conflict');
	}//end testCreateBookingReturns409OnConflict()

	/**
	 * The browser cannot choose the status: a posted `status: confirmed`
	 * is ignored and the booking starts in the lifecycle's initial state.
	 *
	 * @return void
	 */
	public function testCreateBookingIgnoresAClientChosenStatus(): void {
		$this->params = [
			'title' => 'Klant: Bob Jansen',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:30:00Z',
			'attendee' => 'Bob Jansen',
			'status' => 'confirmed',
		];
		$this->conflicts->method('checkConflicts')->willReturn([]);
		$this->transitions->expects($this->never())->method('run');

		$response = $this->buildController()->createBooking('cal-001');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('pending', $this->fakeObjectService->savedObjects[0]['object']['status']);
		$this->assertSame('pending', $response->getData()['status']);
	}//end testCreateBookingIgnoresAClientChosenStatus()

	/**
	 * Asking to confirm at once saves the booking pending and then runs the
	 * declared `confirm` transition on the server.
	 *
	 * @return void
	 */
	public function testCreateBookingConfirmsThroughTheTransition(): void {
		$this->params = [
			'title' => 'Klant: Bob Jansen',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:30:00Z',
			'attendee' => 'Bob Jansen',
			'confirm' => true,
		];
		$this->conflicts->method('checkConflicts')->willReturn([]);
		$this->transitions->expects($this->once())->method('run')->with('uuid-saved', 'confirm');

		$response = $this->buildController()->createBooking('cal-001');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('pending', $this->fakeObjectService->savedObjects[0]['object']['status']);
		$this->assertSame('confirmed', $response->getData()['status']);
	}//end testCreateBookingConfirmsThroughTheTransition()

	/**
	 * A refused confirm transition rolls the booking back.
	 *
	 * @return void
	 */
	public function testCreateBookingRollsBackWhenTheConfirmIsRefused(): void {
		$this->params = [
			'title' => 'Klant: Bob Jansen',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:30:00Z',
			'attendee' => 'Bob Jansen',
			'confirm' => true,
		];
		$this->conflicts->method('checkConflicts')->willReturn([]);
		$this->transitions->method('run')->willThrowException(new \RuntimeException('refused'));
		$this->guard->expects($this->never())->method('commit');

		$response = $this->buildController()->createBooking('cal-001');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}//end testCreateBookingRollsBackWhenTheConfirmIsRefused()

	/**
	 * The override flag the form sends (`overrideConflict`) is the one the
	 * server reads: an overlapping booking is created when it is set.
	 *
	 * @return void
	 */
	public function testCreateBookingWithOverrideConflictCreatesDespiteOverlap(): void {
		$this->params = [
			'title' => 'Klant: Sophia Vermeulen',
			'startTime' => '2026-05-21T11:15:00Z',
			'endTime' => '2026-05-21T12:00:00Z',
			'attendee' => 'Sophia Vermeulen',
			'overrideConflict' => true,
		];
		$this->conflicts->method('checkConflicts')->willReturn([
			['bookingId' => 'bk-002', 'title' => 'Klant: Kees Bakker', 'startTime' => '2026-05-21T11:00:00Z', 'endTime' => '2026-05-21T11:45:00Z'],
		]);

		$response = $this->buildController()->createBooking('cal-001');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $this->fakeObjectService->savedObjects);
	}//end testCreateBookingWithOverrideConflictCreatesDespiteOverlap()

	/**
	 * POST rejects sub-15-minute durations with 400 per REQ-007.
	 *
	 * @return void
	 */
	public function testCreateBookingRejectsShortDuration(): void {
		$this->params = [
			'title' => 'Klant: short',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:10:00Z',
			'attendee' => 'Short',
			'status' => 'pending',
		];

		$controller = $this->buildController();
		$response = $controller->createBooking('cal-001');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('15 minutes', $response->getData()['error']);
	}//end testCreateBookingRejectsShortDuration()

	/**
	 * POST returns 400 when required fields are missing.
	 *
	 * @return void
	 */
	public function testCreateBookingRejectsMissingFields(): void {
		$this->params = [
			'title' => '',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:30:00Z',
			'attendee' => '',
			'status' => 'pending',
		];

		$controller = $this->buildController();
		$response = $controller->createBooking('cal-001');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateBookingRejectsMissingFields()

	/**
	 * Unauthenticated calls return 401 across the surface.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallsReturn401(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn(null);
		$context->method('buildContext')->willReturn([
			'userId' => null,
			'administrations' => [],
			'activeAdministrationId' => null,
		]);

		$controller = new CalendarController(
			$this->request,
			$this->container,
			$this->settings,
			$this->conflicts,
			$context,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			$this->buildL10n(),
			$this->buildGroupManager(false),
			$this->transitions,
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('cal-001')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->listBookings('cal-001')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->createBooking('cal-001')->getStatus());
	}//end testUnauthenticatedCallsReturn401()

	/**
	 * POST /bookings rejects a caller with no membership in the calendar's
	 * administration — the confirmed missing-guard finding for
	 * `createBooking()` (security-endpoint-guards REQ-001, negative
	 * direction). Before this change's guard was added, this exact call
	 * returned 201 and created the booking; see
	 * testCreateBookingReturns201OnSuccess() for the positive-direction
	 * counterpart proving the guard does not just deny everyone.
	 *
	 * @return void
	 */
	public function testCreateBookingRejectsNonMemberCaller(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('mallory');
		$context->method('buildContext')->willReturn([
			'userId' => 'mallory',
			'administrations' => [['administrationId' => 'adm-2']],
			'activeAdministrationId' => 'adm-2',
		]);
		// Mallory is only a member of adm-2; cal-001 belongs to adm-1.
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-2'
		);

		$this->params = [
			'title' => 'Klant: Mallory',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:30:00Z',
			'attendee' => 'Mallory',
			'status' => 'pending',
		];

		$controller = new CalendarController(
			$this->request,
			$this->container,
			$this->settings,
			$this->conflicts,
			$context,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			$this->buildL10n(),
			$this->buildGroupManager(false),
			$this->transitions,
		);

		$response = $controller->createBooking('cal-001');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('calendar-booking-forbidden', $response->getData()['error']);
		$this->assertCount(0, $this->fakeObjectService->savedObjects, 'No booking is created for a non-member caller');
	}//end testCreateBookingRejectsNonMemberCaller()

	/**
	 * A Nextcloud admin bypasses the per-administration booking guard —
	 * matching `BookingNotificationController::authorizeBookingAccess()`'s
	 * established pattern. Without this, the Nextcloud admin account (which
	 * carries no `AdministrationMembership` of its own by default — see
	 * tests/e2e/ci-seed.sh) would be locked out of booking on every
	 * calendar.
	 *
	 * @return void
	 */
	public function testCreateBookingByAdminBypassesMembershipCheck(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('admin');
		$context->method('buildContext')->willReturn([
			'userId' => 'admin',
			'administrations' => [],
			'activeAdministrationId' => null,
		]);
		// No memberships at all — canAccess() would deny every administration.
		$context->method('canAccess')->willReturn(false);

		$this->params = [
			'title' => 'Klant: Admin',
			'startTime' => '2026-05-21T14:00:00Z',
			'endTime' => '2026-05-21T14:30:00Z',
			'attendee' => 'Admin',
			'status' => 'pending',
		];
		$this->conflicts->method('checkConflicts')->willReturn([]);
		$this->conflicts->method('lockResource')->willReturn(true);

		$controller = new CalendarController(
			$this->request,
			$this->container,
			$this->settings,
			$this->conflicts,
			$context,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			$this->buildL10n(),
			$this->buildGroupManager(true),
			$this->transitions,
		);

		$response = $controller->createBooking('cal-001');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateBookingByAdminBypassesMembershipCheck()

	/**
	 * GET /bookings with an invalid (inverted) range returns a static
	 * slug/message, not the raw exception text (security-endpoint-guards
	 * REQ-003).
	 *
	 * @return void
	 */
	public function testListBookingsInvalidRangeDoesNotLeakExceptionText(): void {
		$this->params['start'] = '2026-05-22';
		$this->params['end'] = '2026-05-21';
		$controller = $this->buildController();
		$response = $controller->listBookings('cal-001');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('calendar-invalid-range', $data['error']);
		$this->assertArrayHasKey('message', $data);
		$this->assertStringNotContainsString('end must be after start', (string)($data['error'] ?? '') . (string)($data['message'] ?? ''));
	}//end testListBookingsInvalidRangeDoesNotLeakExceptionText()

}//end class
