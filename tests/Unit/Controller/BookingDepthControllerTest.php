<?php

/**
 * Unit tests for BookingDepthController.
 *
 * Exercises the two reachable bookings-depth endpoints end-to-end through the
 * REAL service stack (NoShowFeeCaptureService + LogDepositPaymentAdapter;
 * RecurringSeriesService + SlotService): auth guards (401/403), the no-show
 * capture happy path (fee charged, appointment persisted), and recurring-series
 * generation (individual appointments persisted).
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
 * @spec openspec/changes/bookings-depth/specs/bookings-recurring-series/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BookingDepthController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\External\DepositPayment\LogDepositPaymentAdapter;
use OCA\Shillinq\Service\NoShowFeeCaptureService;
use OCA\Shillinq\Service\RecurringSeriesService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SlotService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the no-show capture + recurring-series operator endpoints.
 *
 * @spec openspec/changes/bookings-depth/specs/bookings-recurring-series/spec.md
 */
final class BookingDepthControllerTest extends TestCase {

	/**
	 * DI container mock (resolves the fake ObjectService).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Settings mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Auth/context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Set up shared mocks + real services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the controller with the real service stack.
	 *
	 * @return BookingDepthController
	 */
	private function makeController(): BookingDepthController {
		$logger = $this->createMock(LoggerInterface::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(strtotime('2029-01-01T00:00:00Z'));

		$slotService = new SlotService(
			container: $this->createMock(ContainerInterface::class),
			settings: $this->createMock(SettingsService::class),
			cacheFactory: $cacheFactory,
			time: $time,
			logger: $logger,
		);

		return new BookingDepthController(
			request: $this->request,
			container: $this->container,
			settings: $this->settings,
			context: $this->context,
			noShowFee: new NoShowFeeCaptureService(
				adapter: new LogDepositPaymentAdapter($logger),
				logger: $logger,
			),
			series: new RecurringSeriesService(slotService: $slotService, logger: $logger),
			logger: $logger,
			l10n: $this->makeL10n(),
		);

	}//end makeController()

	/**
	 * Build a translation-service stub that echoes its input back, so
	 * assertions can match on the source string.
	 *
	 * @return IL10N&MockObject
	 */
	private function makeL10n(): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text
		);
		return $l10n;

	}//end makeL10n()

	/**
	 * A schema-aware fake ObjectService: findAll returns the canned records for
	 * the last setSchema(), saveObject echoes the payload back.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Records per schema.
	 *
	 * @return object
	 */
	private function makeObjectService(array $bySchema): object {
		return new class($bySchema) {
			/**
			 * Canned records keyed by schema.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $bySchema;

			/**
			 * Current schema selected by setSchema().
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * Persisted objects, for assertions.
			 *
			 * @var array<int, array{schema: string, object: array<string, mixed>}>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $bySchema Records per schema.
			 */
			public function __construct(array $bySchema) {
				$this->bySchema = $bySchema;
			}//end __construct()

			/**
			 * @param string $_register Ignored.
			 *
			 * @return self
			 */
			public function setRegister(string $_register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Selected schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $_filters Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $_filters = []): array {
				return ($this->bySchema[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param array<string, mixed> $object Object payload.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end makeObjectService()

	/**
	 * captureNoShow rejects an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testCaptureNoShowRejectsAnonymous(): void {
		$this->context->method('currentUserId')->willReturn(null);
		$response = $this->makeController()->captureNoShow('apt-1');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCaptureNoShowRejectsAnonymous()

	/**
	 * captureNoShow rejects a caller without administration access with 403.
	 *
	 * @return void
	 */
	public function testCaptureNoShowRejectsForbidden(): void {
		$this->context->method('currentUserId')->willReturn('user-1');
		$this->context->method('canAccess')->willReturn(false);

		$objectService = $this->makeObjectService(
			['Appointment' => [['appointmentId' => 'apt-1', 'administrationId' => 'admin-x']]]
		);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->makeController()->captureNoShow('apt-1');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCaptureNoShowRejectsForbidden()

	/**
	 * captureNoShow charges the defined fee and persists the appointment.
	 *
	 * @return void
	 */
	public function testCaptureNoShowChargesFeeAndPersists(): void {
		$this->context->method('currentUserId')->willReturn('user-1');
		$this->context->method('canAccess')->willReturn(true);

		$objectService = $this->makeObjectService(
			[
				'Appointment' => [
					[
						'appointmentId' => 'apt-1',
						'administrationId' => 'admin-1',
						'appointmentCost' => 10000,
						'appliedPolicy' => ['noShowFee' => 100],
					],
				],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->makeController()->captureNoShow('apt-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->getData();
		self::assertTrue($body['charged']);
		self::assertSame(10000, $body['feeCents']);
		self::assertSame(NoShowFeeCaptureService::STATUS_CAPTURED, $body['noShowFeeStatus']);

		// The appointment was persisted with the no-show bookkeeping + status.
		self::assertNotEmpty($objectService->saved);
		$saved = $objectService->saved[0]['object'];
		self::assertSame('no_show', $saved['status']);
		self::assertSame(10000, $saved['noShowFeeAmount']);

	}//end testCaptureNoShowChargesFeeAndPersists()

	/**
	 * createSeries generates + persists individual appointments for each valid
	 * occurrence of the recurrence rule.
	 *
	 * @return void
	 */
	public function testCreateSeriesGeneratesAppointments(): void {
		$this->context->method('currentUserId')->willReturn('user-1');
		$this->context->method('canAccess')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) {
				$params = [
					'administrationId' => 'admin-1',
					'seriesId' => 'series-1',
					'serviceId' => 'svc-yoga',
					'resourceId' => 'res-a',
					'startTime' => '2030-01-07T09:00:00Z',
					'durationMinutes' => 60,
					'recurrenceRule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
					'customerId' => 'cust-1',
				];
				return ($params[$key] ?? $default);
			}
		);

		$objectService = $this->makeObjectService(
			[
				'Resource' => [
					[
						'resourceId' => 'res-a',
						'administrationId' => 'admin-1',
						'openingTime' => '09:00',
						'closingTime' => '17:00',
					],
				],
				'Appointment' => [],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->makeController()->createSeries();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->getData();
		self::assertSame('series-1', $body['seriesId']);
		self::assertSame(4, $body['generated']);
		self::assertSame(0, $body['skipped']);

		// One AppointmentSeries + four Appointment saves.
		$schemasSaved = array_map(static fn (array $s): string => $s['schema'], $objectService->saved);
		self::assertContains('AppointmentSeries', $schemasSaved);
		self::assertSame(4, count(array_filter($schemasSaved, static fn (string $s): bool => $s === 'Appointment')));

	}//end testCreateSeriesGeneratesAppointments()

	/**
	 * createSeries rejects an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testCreateSeriesRejectsAnonymous(): void {
		$this->context->method('currentUserId')->willReturn(null);
		$response = $this->makeController()->createSeries();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCreateSeriesRejectsAnonymous()

	/**
	 * NEGATIVE CONTROL / security-endpoint-guards REQ-001 hardening: a
	 * caller who has access to their OWN administration (canAccess() ===
	 * true) is still rejected when the named resourceId belongs to a
	 * DIFFERENT administration — the create-time canAccess($administrationId)
	 * check alone does not prove the fetched Resource is in scope. Before
	 * this fix, this exact call would have booked appointments against
	 * another tenant's resource under the caller's own administrationId.
	 *
	 * @return void
	 */
	public function testCreateSeriesRejectsResourceFromAnotherAdministration(): void {
		$this->context->method('currentUserId')->willReturn('user-1');
		$this->context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'admin-1'
		);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) {
				$params = [
					'administrationId' => 'admin-1',
					'seriesId' => 'series-1',
					'serviceId' => 'svc-yoga',
					'resourceId' => 'res-b',
					'startTime' => '2030-01-07T09:00:00Z',
					'durationMinutes' => 60,
					'recurrenceRule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
					'customerId' => 'cust-1',
				];
				return ($params[$key] ?? $default);
			}
		);

		$objectService = $this->makeObjectService(
			[
				// res-b belongs to a DIFFERENT administration than the
				// caller-supplied/authorized administrationId (admin-1).
				'Resource' => [
					[
						'resourceId' => 'res-b',
						'administrationId' => 'admin-2',
						'openingTime' => '09:00',
						'closingTime' => '17:00',
					],
				],
				'Appointment' => [],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->makeController()->createSeries();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([], $objectService->saved, 'No AppointmentSeries/Appointment must be persisted');

	}//end testCreateSeriesRejectsResourceFromAnotherAdministration()

	/**
	 * A validation failure from RecurringSeriesService::planSeries()
	 * (security-endpoint-guards REQ-003) returns a stable slug and a
	 * localized message — never the raw exception text.
	 *
	 * @return void
	 */
	public function testCreateSeriesValidationFailureDoesNotLeakExceptionText(): void {
		$this->context->method('currentUserId')->willReturn('user-1');
		$this->context->method('canAccess')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) {
				$params = [
					'administrationId' => 'admin-1',
					'seriesId' => 'series-1',
					'serviceId' => 'svc-yoga',
					'resourceId' => 'res-a',
					'startTime' => '2030-01-07T09:00:00Z',
					'durationMinutes' => 60,
					// Unsupported FREQ -> RecurringSeriesService throws
					// InvalidArgumentException.
					'recurrenceRule' => 'FREQ=YEARLY',
					'customerId' => 'cust-1',
				];
				return ($params[$key] ?? $default);
			}
		);

		$objectService = $this->makeObjectService(
			[
				'Resource' => [
					[
						'resourceId' => 'res-a',
						'administrationId' => 'admin-1',
						'openingTime' => '09:00',
						'closingTime' => '17:00',
					],
				],
				'Appointment' => [],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->makeController()->createSeries();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		self::assertSame('appointment-series-create-failed', $data['error']);
		self::assertArrayHasKey('message', $data);
		self::assertStringNotContainsString('FREQ', $data['message']);
		self::assertStringNotContainsString('RRULE', json_encode($data));

	}//end testCreateSeriesValidationFailureDoesNotLeakExceptionText()
}//end class
