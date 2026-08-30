<?php

/**
 * Unit tests for WidgetService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers customer input validation (REQ-WSW-006) and appointment creation with
 * server-authoritative double-booking prevention + PII non-exposure (design D6).
 */
class WidgetServiceTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $container;

	/**
	 * Mock slot service — the availability authority (REQ-WSW-002).
	 *
	 * @var SlotService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $slotService;

	/**
	 * The service under test.
	 *
	 * @var WidgetService
	 */
	private WidgetService $service;

	/**
	 * Build the service with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$this->slotService = $this->createMock(SlotService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->service = new WidgetService(
			container: $this->container,
			appConfig: $appConfig,
			slotService: $this->slotService,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * Make SlotService offer exactly the canonical test interval.
	 *
	 * @return void
	 */
	private function offerCanonicalSlot(): void {
		$this->slotService->method('getAvailableSlots')->willReturn(
			[
				'slots' => [
					[
						'startTime' => '2026-05-22T08:00:00Z',
						'endTime' => '2026-05-22T08:45:00Z',
					],
				],
			]
		);

	}//end offerCanonicalSlot()

	/**
	 * The canonical valid widget payload.
	 *
	 * @param array<string,mixed> $overrides Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function payload(array $overrides = []): array {
		return array_merge(
			[
				'serviceId' => 'svc-001',
				'resourceId' => 'res-001',
				'startTime' => '2026-05-22T08:00:00Z',
				'endTime' => '2026-05-22T08:45:00Z',
				'customerName' => 'Alice Smith',
				'customerEmail' => 'alice@example.com',
				'customerPhone' => '+31612345678',
			],
			$overrides
		);

	}//end payload()

	/**
	 * A bookable public Service record.
	 *
	 * @return array<string,mixed>
	 */
	private function bookableService(): array {
		return [
			'@self' => ['slug' => 'haircut'],
			'serviceId' => 'svc-001',
			'isPublic' => true,
			'status' => 'active',
			'duration' => 45,
			'resourceId' => 'res-001',
		];

	}//end bookableService()

	/**
	 * Email validation accepts RFC-valid and rejects malformed addresses.
	 *
	 * @return void
	 */
	public function testEmailValidation(): void {
		self::assertTrue($this->service->validateEmail('alice@example.com'));
		self::assertFalse($this->service->validateEmail('alice@invalid'));
		self::assertFalse($this->service->validateEmail('not-an-email'));

	}//end testEmailValidation()

	/**
	 * Phone is optional; non-empty must be E.164-ish.
	 *
	 * @return void
	 */
	public function testPhoneValidation(): void {
		self::assertTrue($this->service->validatePhone(''), 'empty phone is allowed');
		self::assertTrue($this->service->validatePhone('+31612345678'));
		self::assertFalse($this->service->validatePhone('06-12 invalid'));

	}//end testPhoneValidation()

	/**
	 * Name must be 1-255 chars of letters/space/hyphen.
	 *
	 * @return void
	 */
	public function testNameValidation(): void {
		self::assertTrue($this->service->validateName('Alice Smith'));
		self::assertTrue($this->service->validateName("Anne-Marie d'Or"));
		self::assertFalse($this->service->validateName(''));
		self::assertFalse($this->service->validateName('Robert<script>'));

	}//end testNameValidation()

	/**
	 * createAppointment rejects an invalid email with HTTP 400.
	 *
	 * @return void
	 */
	public function testCreateAppointmentRejectsInvalidEmail(): void {
		$result = $this->service->createAppointment(
			'salon-demo',
			$this->payload(['customerEmail' => 'bad-email'])
		);

		self::assertSame(400, $result['code']);
		self::assertSame('invalid_email', $result['error']);

	}//end testCreateAppointmentRejectsInvalidEmail()

	/**
	 * createAppointment returns 404 when the service does not exist / is private.
	 *
	 * @return void
	 */
	public function testCreateAppointmentServiceNotFound(): void {
		$objectService = $this->buildObjectService(services: [], appointments: [], saved: null);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->createAppointment('salon-demo', $this->payload());

		self::assertSame(404, $result['code']);

	}//end testCreateAppointmentServiceNotFound()

	/**
	 * A service that exists but is NOT flagged isPublic must not be bookable.
	 *
	 * This is the PR #491 hole. It is pinned here because the check has now
	 * moved into the service: a regression would otherwise be invisible.
	 *
	 * @return void
	 */
	public function testCreateAppointmentRejectsNonPublicService(): void {
		$private = $this->bookableService();
		$private['isPublic'] = false;

		$objectService = $this->buildObjectService(services: [$private], appointments: [], saved: null);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->createAppointment('salon-demo', $this->payload());

		self::assertSame(404, $result['code'], 'a private service must not be bookable via the public widget');

	}//end testCreateAppointmentRejectsNonPublicService()

	/**
	 * A service that is public but not `active` must not be bookable either.
	 *
	 * @return void
	 */
	public function testCreateAppointmentRejectsInactiveService(): void {
		$retired = $this->bookableService();
		$retired['status'] = 'archived';

		$objectService = $this->buildObjectService(services: [$retired], appointments: [], saved: null);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->createAppointment('salon-demo', $this->payload());

		self::assertSame(404, $result['code']);

	}//end testCreateAppointmentRejectsInactiveService()

	/**
	 * createAppointment returns 409 when the slot already has a confirmed booking.
	 *
	 * @return void
	 */
	public function testCreateAppointmentConflict(): void {
		$objectService = $this->buildObjectService(
			services: [$this->bookableService()],
			appointments: [],
			saved: null,
		);
		$this->container->method('get')->willReturn($objectService);

		// SlotService no longer offers the interval — it was just booked.
		$this->slotService->method('getAvailableSlots')->willReturn(['slots' => []]);

		$result = $this->service->createAppointment('salon-demo', $this->payload());

		self::assertSame(409, $result['code']);
		self::assertSame('slot_unavailable', $result['error']);

	}//end testCreateAppointmentConflict()

	/**
	 * A SlotService failure must DENY the booking, never permit it.
	 *
	 * @return void
	 */
	public function testCreateAppointmentFailsClosedWhenAvailabilityCheckThrows(): void {
		$objectService = $this->buildObjectService(
			services: [$this->bookableService()],
			appointments: [],
			saved: null,
		);
		$this->container->method('get')->willReturn($objectService);

		$this->slotService->method('getAvailableSlots')
			->willThrowException(new \RuntimeException('slot backend down'));

		$result = $this->service->createAppointment('salon-demo', $this->payload());

		self::assertSame(409, $result['code'], 'an unavailable slot backend must not permit a booking');

	}//end testCreateAppointmentFailsClosedWhenAvailabilityCheckThrows()

	/**
	 * Happy path: 201 and the response NEVER echoes customer PII (design D6).
	 *
	 * @return void
	 */
	public function testCreateAppointmentSucceedsWithoutLeakingPii(): void {
		$objectService = $this->buildObjectService(
			services: [$this->bookableService()],
			appointments: [],
			saved: ['appointmentId' => 'apt-new', 'status' => 'pending_confirmation'],
		);
		$this->container->method('get')->willReturn($objectService);
		$this->offerCanonicalSlot();

		$result = $this->service->createAppointment('salon-demo', $this->payload());

		self::assertSame(201, $result['code']);
		// PII must not appear anywhere in the public response (design D6).
		$encoded = json_encode($result);
		self::assertStringNotContainsString('alice@example.com', (string)$encoded);
		self::assertStringNotContainsString('Alice Smith', (string)$encoded);
		self::assertStringNotContainsString('+31612345678', (string)$encoded);

	}//end testCreateAppointmentSucceedsWithoutLeakingPii()

	/**
	 * The customer's contact details MUST be persisted on the Appointment.
	 *
	 * REGRESSION PIN. `register.d/30-bookings-self-service-widget.json` declares
	 * customerName / customerEmail / customerPhone on Appointment; REQ-WSW-006's
	 * scenario requires the appointment to be created with the phone number, and
	 * the REQ-BCF-003 confirmation email needs the address. The routed controller
	 * copy of this write path persisted only an anonymised customerId and dropped
	 * all three — a gap that was invisible because nothing asserted the SAVED
	 * payload, only the returned one. Write-only is the point: they are stored
	 * and never echoed back, so a response-shape assertion cannot see them.
	 *
	 * @return void
	 */
	public function testCreateAppointmentPersistsCustomerContactDetails(): void {
		$objectService = $this->buildObjectService(
			services: [$this->bookableService()],
			appointments: [],
			saved: ['appointmentId' => 'apt-new', 'status' => 'pending_confirmation'],
		);
		$this->container->method('get')->willReturn($objectService);
		$this->offerCanonicalSlot();

		$this->service->createAppointment('salon-demo', $this->payload());

		$written = $objectService->lastSaved();

		self::assertNotNull($written, 'the appointment must have been persisted');
		self::assertSame('Alice Smith', $written['customerName'] ?? null);
		self::assertSame('alice@example.com', $written['customerEmail'] ?? null);
		self::assertSame('+31612345678', $written['customerPhone'] ?? null);
		self::assertSame('widget', $written['source'] ?? null);

	}//end testCreateAppointmentPersistsCustomerContactDetails()

	/**
	 * A widget booking starts `pending_confirmation`, never `confirmed`.
	 *
	 * REQ-BCA-005 pathway 2 / REQ-BCF-010: the customer self-service pathway
	 * awaits confirmation. bookings-confirm-flow keys its ConfirmationToken,
	 * its confirmation email and the CancelUnconfirmedAppointments job off this
	 * value, so creating the row already `confirmed` would silently bypass the
	 * entire capability.
	 *
	 * @return void
	 */
	public function testCreateAppointmentStartsPendingConfirmation(): void {
		$objectService = $this->buildObjectService(
			services: [$this->bookableService()],
			appointments: [],
			saved: ['appointmentId' => 'apt-new', 'status' => 'pending_confirmation'],
		);
		$this->container->method('get')->willReturn($objectService);
		$this->offerCanonicalSlot();

		$result = $this->service->createAppointment('salon-demo', $this->payload());

		self::assertSame('pending_confirmation', $objectService->lastSaved()['status'] ?? null);
		self::assertSame('pending_confirmation', $result['status']);

	}//end testCreateAppointmentStartsPendingConfirmation()

	/**
	 * listPublicServices exposes only the safe-public subset (no admin fields/PII).
	 *
	 * @return void
	 */
	public function testListPublicServicesReturnsSafeSubset(): void {
		$service = [
			'@self' => ['slug' => 'haircut'],
			'name' => 'Haircut',
			'description' => 'Wash and cut',
			'duration' => 45,
			'price' => 35.0,
			'currency' => 'EUR',
			'priceVisible' => true,
			'isPublic' => true,
			'administrationId' => 'salon-demo',
		];
		$objectService = $this->buildObjectService(services: [$service], appointments: [], saved: null);
		$this->container->method('get')->willReturn($objectService);

		$list = $this->service->listPublicServices('salon-demo');

		self::assertCount(1, $list);
		self::assertSame('haircut', $list[0]['serviceSlug']);
		self::assertArrayHasKey('price', $list[0]);
		self::assertArrayNotHasKey('administrationId', $list[0], 'tenant id must not leak');
		self::assertArrayNotHasKey('isPublic', $list[0]);

	}//end testListPublicServicesReturnsSafeSubset()

	/**
	 * A service stored under the real schema publishes its basePrice, not 0.00.
	 *
	 * ⚠️ This is the test the suite did not have, and its absence is why the
	 * widget shipped 0.00 for every priced service.
	 *
	 * The Service schema is declared across two fragments:
	 * `30-bookings-self-service-widget.json` declares `priceVisible`, and
	 * `bookings-service-catalog.json` declares `basePrice` — which is in its
	 * `required` list. **No fragment declares `price`**, and OpenRegister's
	 * MagicMapper discards undeclared properties, so no stored Service can
	 * carry one. Measured on a live instance: a Service saved with
	 * `basePrice = 125.50` renders `basePrice => 125.5` and `price => ABSENT`.
	 *
	 * 🔑 The fixture below therefore omits `price` deliberately. The pre-existing
	 * `testListPublicServicesReturnsSafeSubset` supplies `'price' => 35.0` — a
	 * key OpenRegister has never emitted — which is exactly why it stayed green
	 * over a broken production path. It also asserts only `assertArrayHasKey`,
	 * so it could not have caught a 0.00 VALUE even with the invented key.
	 *
	 * @return void
	 */
	public function testPricedServicePublishesBasePriceNotZero(): void {
		$service = [
			'@self' => ['slug' => 'consult'],
			'name' => 'Consultation',
			'duration' => 30,
			'basePrice' => 125.50,
			'currency' => 'EUR',
			'priceVisible' => true,
			'isPublic' => true,
			'administrationId' => 'salon-demo',
		];
		$objectService = $this->buildObjectService(services: [$service], appointments: [], saved: null);
		$this->container->method('get')->willReturn($objectService);

		$list = $this->service->listPublicServices('salon-demo');

		self::assertCount(1, $list);
		self::assertSame(
			125.50,
			$list[0]['price'],
			'the widget must publish the stored basePrice, not 0.00'
		);
		self::assertNotSame(0.0, $list[0]['price']);
		self::assertSame('EUR', $list[0]['currency']);

	}//end testPricedServicePublishesBasePriceNotZero()

	/**
	 * A legacy object still carrying `price` keeps working.
	 *
	 * `basePrice` must win when both are present — the defect was ordering, so
	 * the fallback has to stay second.
	 *
	 * @return void
	 */
	public function testLegacyPriceIsUsedOnlyAsFallback(): void {
		$legacyOnly = [
			'@self' => ['slug' => 'legacy'],
			'name' => 'Legacy service',
			'duration' => 15,
			'price' => 42.0,
			'currency' => 'EUR',
			'priceVisible' => true,
			'isPublic' => true,
		];
		$bothPresent = [
			'@self' => ['slug' => 'both'],
			'name' => 'Both spellings',
			'duration' => 15,
			'basePrice' => 99.0,
			'price' => 42.0,
			'currency' => 'EUR',
			'priceVisible' => true,
			'isPublic' => true,
		];
		$objectService = $this->buildObjectService(
			services: [$legacyOnly, $bothPresent],
			appointments: [],
			saved: null
		);
		$this->container->method('get')->willReturn($objectService);

		$list = $this->service->listPublicServices('salon-demo');

		self::assertCount(2, $list);
		self::assertSame(42.0, $list[0]['price'], 'legacy price is still honoured when it is all there is');
		self::assertSame(99.0, $list[1]['price'], 'basePrice must win over the legacy spelling');

	}//end testLegacyPriceIsUsedOnlyAsFallback()

	/**
	 * Build a fluent ObjectService stub returning services / appointments by schema.
	 *
	 * @param array<int,array<string,mixed>> $services Service records.
	 * @param array<int,array<string,mixed>> $appointments Appointment records.
	 * @param array<string,mixed>|null $saved Return value of saveObject().
	 *
	 * @return object
	 */
	private function buildObjectService(array $services, array $appointments, ?array $saved): object {
		return new class($services, $appointments, $saved) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $services;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $appointments;

			/**
			 * @var array<string,mixed>|null
			 */
			private ?array $saved;

			/**
			 * The payload the service last handed to saveObject().
			 *
			 * @var array<string,mixed>|null
			 */
			private ?array $lastSaved = null;

			private string $schema = '';

			/**
			 * @param array<int,array<string,mixed>> $services
			 * @param array<int,array<string,mixed>> $appointments
			 * @param array<string,mixed>|null $saved
			 */
			public function __construct(array $services, array $appointments, ?array $saved) {
				$this->services = $services;
				$this->appointments = $appointments;
				$this->saved = $saved;
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema === 'Service') {
					return $this->services;
				}

				if ($this->schema === 'Appointment') {
					return $this->appointments;
				}

				return [];
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 * @return array<string,mixed>|null
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): ?array {
				$this->lastSaved = $object;
				return $this->saved;
			}//end saveObject()

			/**
			 * The payload last handed to saveObject(), for write-side assertions.
			 *
			 * @return array<string,mixed>|null
			 */
			public function lastSaved(): ?array {
				return $this->lastSaved;
			}//end lastSaved()
		};

	}//end buildObjectService()
}//end class
