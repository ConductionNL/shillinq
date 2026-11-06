<?php

/**
 * Forged request parameters cannot change who CalendarController acts for.
 *
 * Mallory may use administration adm-1 only. Calendar cal-1 belongs to adm-2;
 * calendar cal-x belongs to adm-1. Alice is a Nextcloud admin. A request from
 * Mallory for cal-1 that carries userId=alice, administrationId=adm-1 and
 * calendar=cal-x in its body must still be refused: the acting user comes
 * from the session, the administration from the stored calendar, and the
 * calendar from the route.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Forged-parameter cases for the calendar booking endpoints.
 *
 * @covers \OCA\Shillinq\Controller\CalendarController
 * @uses   \OCA\Shillinq\Service\Booking\CalendarRows
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CalendarForgedParametersTest extends TestCase {

	private const FORGED = [
		'userId' => 'alice',
		'administrationId' => 'adm-1',
		'calendar' => 'cal-x',
		'calendarId' => 'cal-x',
		'title' => 'Intake',
		'startTime' => '2026-06-01T09:00:00Z',
		'endTime' => '2026-06-01T09:30:00Z',
		'attendee' => 'jan@example.org',
		'start' => '2026-06-01T00:00:00Z',
		'end' => '2026-06-02T00:00:00Z',
	];

	/**
	 * The object service double of the current case.
	 *
	 * @var ForgedCalendarObjects|null
	 */
	private ?ForgedCalendarObjects $objects = null;

	/**
	 * Mallory cannot book another administration's calendar by naming alice,
	 * her own administration or her own calendar in the body.
	 *
	 * @return void
	 */
	public function testForgedBodyDoesNotLetMalloryBookAnotherAdministrationsCalendar(): void {
		$response = $this->controller()->createBooking('cal-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([], $this->objects?->saved);

	}//end testForgedBodyDoesNotLetMalloryBookAnotherAdministrationsCalendar()

	/**
	 * Mallory cannot list another administration's calendar by naming alice,
	 * her own administration or her own calendar in the query.
	 *
	 * @return void
	 */
	public function testForgedQueryDoesNotLetMalloryListAnotherAdministrationsCalendar(): void {
		$response = $this->controller()->listBookings('cal-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testForgedQueryDoesNotLetMalloryListAnotherAdministrationsCalendar()

	/**
	 * The controller for Mallory with the forged parameters.
	 *
	 * @return CalendarController
	 */
	private function controller(): CalendarController {
		$this->objects = new ForgedCalendarObjects();

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (self::FORGED[$key] ?? $default)
		);

		$context = $this->createStub(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('mallory');
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-1');

		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'alice');

		$settings = $this->createStub(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('books');

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		return new CalendarController(
			request: $request,
			container: $container,
			settings: $settings,
			conflicts: $this->createStub(ConflictDetectionService::class),
			context: $context,
			guard: $this->createStub(TransactionalGuard::class),
			logger: new NullLogger(),
			l10n: $this->createStub(IL10N::class),
			groupManager: $groups,
			transitions: $this->createStub(ObjectTransitionRunner::class),
		);

	}//end controller()
}//end class

/**
 * Object service double: calendars by calendarId, no bookings, recorded saves.
 */
final class ForgedCalendarObjects {

	/**
	 * The current schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Every saved object.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Fluent register setter.
	 *
	 * @param string $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema name.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Calendars by their calendarId filter; no bookings.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		if ($this->schema !== 'Calendar') {
			return [];
		}

		$calendars = [
			'cal-1' => ['calendarId' => 'cal-1', 'resource' => 'room-2', 'administrationId' => 'adm-2'],
			'cal-x' => ['calendarId' => 'cal-x', 'resource' => 'room-1', 'administrationId' => 'adm-1'],
		];
		$calendarId = (string)($config['filters']['calendarId'] ?? '');

		return array_values(array_filter([$calendars[$calendarId] ?? null]));
	}//end findAll()

	/**
	 * Record a save.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		$this->saved[] = $object;
		return $object;
	}//end saveObject()
}//end class
