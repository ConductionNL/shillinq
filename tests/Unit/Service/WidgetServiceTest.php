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
class WidgetServiceTest extends TestCase
{

    /**
     * Mock container.
     *
     * @var ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $container;

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $appConfig       = $this->createMock(IAppConfig::class);
        $slotService     = $this->createMock(SlotService::class);
        $logger          = $this->createMock(LoggerInterface::class);
        $appConfig->method('getValueString')->willReturn('shillinq');

        $this->service = new WidgetService(
            container: $this->container,
            appConfig: $appConfig,
            slotService: $slotService,
            logger: $logger,
        );

    }//end setUp()

    /**
     * Email validation accepts RFC-valid and rejects malformed addresses.
     *
     * @return void
     */
    public function testEmailValidation(): void
    {
        self::assertTrue($this->service->validateEmail('alice@example.com'));
        self::assertFalse($this->service->validateEmail('alice@invalid'));
        self::assertFalse($this->service->validateEmail('not-an-email'));

    }//end testEmailValidation()

    /**
     * Phone is optional; non-empty must be E.164-ish.
     *
     * @return void
     */
    public function testPhoneValidation(): void
    {
        self::assertTrue($this->service->validatePhone(''), 'empty phone is allowed');
        self::assertTrue($this->service->validatePhone('+31612345678'));
        self::assertFalse($this->service->validatePhone('06-12 invalid'));

    }//end testPhoneValidation()

    /**
     * Name must be 1-255 chars of letters/space/hyphen.
     *
     * @return void
     */
    public function testNameValidation(): void
    {
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
    public function testCreateAppointmentRejectsInvalidEmail(): void
    {
        $result = $this->service->createAppointment(
            'salon-demo',
            [
                'serviceSlug'   => 'haircut',
                'startTime'     => '2026-05-22T08:00:00Z',
                'customerName'  => 'Alice',
                'customerEmail' => 'bad-email',
            ]
        );

        self::assertSame(400, $result['code']);
        self::assertSame('invalid_email', $result['error']);

    }//end testCreateAppointmentRejectsInvalidEmail()

    /**
     * createAppointment returns 404 when the service does not exist / is private.
     *
     * @return void
     */
    public function testCreateAppointmentServiceNotFound(): void
    {
        $objectService = $this->buildObjectService(services: [], appointments: [], saved: null);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->createAppointment(
            'salon-demo',
            [
                'serviceSlug'   => 'haircut',
                'startTime'     => '2026-05-22T08:00:00Z',
                'customerName'  => 'Alice Smith',
                'customerEmail' => 'alice@example.com',
            ]
        );

        self::assertSame(404, $result['code']);

    }//end testCreateAppointmentServiceNotFound()

    /**
     * createAppointment returns 409 when the slot already has a confirmed booking.
     *
     * @return void
     */
    public function testCreateAppointmentConflict(): void
    {
        $service  = [
            '@self'      => ['slug' => 'haircut'],
            'serviceId'  => 'svc-001',
            'isPublic'   => true,
            'duration'   => 45,
            'resourceId' => 'res-001',
        ];
        $existing = [
            '@self'      => ['slug' => 'apt-1'],
            'resourceId' => 'res-001',
            'status'     => 'confirmed',
            'startTime'  => '2026-05-22T08:00:00Z',
            'endTime'    => '2026-05-22T08:45:00Z',
        ];

        $objectService = $this->buildObjectService(
            services: [$service],
            appointments: [$existing],
            saved: null,
        );
        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->createAppointment(
            'salon-demo',
            [
                'serviceSlug'   => 'haircut',
                'startTime'     => '2026-05-22T08:00:00Z',
                'customerName'  => 'Alice Smith',
                'customerEmail' => 'alice@example.com',
            ]
        );

        self::assertSame(409, $result['code']);
        self::assertSame('slot_unavailable', $result['error']);

    }//end testCreateAppointmentConflict()

    /**
     * Happy path: 201 and the response NEVER echoes customer PII (design D6).
     *
     * @return void
     */
    public function testCreateAppointmentSucceedsWithoutLeakingPii(): void
    {
        $service = [
            '@self'      => ['slug' => 'haircut'],
            'serviceId'  => 'svc-001',
            'isPublic'   => true,
            'duration'   => 45,
            'resourceId' => 'res-001',
        ];

        $objectService = $this->buildObjectService(
            services: [$service],
            appointments: [],
            saved: ['@self' => ['slug' => 'apt-new']],
        );
        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->createAppointment(
            'salon-demo',
            [
                'serviceSlug'   => 'haircut',
                'startTime'     => '2026-05-22T08:00:00Z',
                'customerName'  => 'Alice Smith',
                'customerEmail' => 'alice@example.com',
                'customerPhone' => '+31612345678',
            ]
        );

        self::assertSame(201, $result['code']);
        self::assertSame('confirmed', $result['status']);
        // PII must not appear anywhere in the public response.
        $encoded = json_encode($result);
        self::assertStringNotContainsString('alice@example.com', (string) $encoded);
        self::assertStringNotContainsString('Alice Smith', (string) $encoded);
        self::assertStringNotContainsString('+31612345678', (string) $encoded);

    }//end testCreateAppointmentSucceedsWithoutLeakingPii()

    /**
     * listPublicServices exposes only the safe-public subset (no admin fields/PII).
     *
     * @return void
     */
    public function testListPublicServicesReturnsSafeSubset(): void
    {
        $service       = [
            '@self'            => ['slug' => 'haircut'],
            'name'             => 'Haircut',
            'description'      => 'Wash and cut',
            'duration'         => 45,
            'price'            => 35.0,
            'currency'         => 'EUR',
            'priceVisible'     => true,
            'isPublic'         => true,
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
     * Build a fluent ObjectService stub returning services / appointments by schema.
     *
     * @param array<int,array<string,mixed>> $services     Service records.
     * @param array<int,array<string,mixed>> $appointments Appointment records.
     * @param array<string,mixed>|null       $saved        Return value of saveObject().
     *
     * @return object
     */
    private function buildObjectService(array $services, array $appointments, ?array $saved): object
    {
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

            private string $schema = '';

            /**
             * @param array<int,array<string,mixed>> $services
             * @param array<int,array<string,mixed>> $appointments
             * @param array<string,mixed>|null       $saved
             */
            public function __construct(array $services, array $appointments, ?array $saved)
            {
                $this->services     = $services;
                $this->appointments = $appointments;
                $this->saved        = $saved;
            }//end __construct()

            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(string $schema): static
            {
                $this->schema = $schema;
                return $this;
            }//end setSchema()

            /**
             * @param  array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                if ($this->schema === 'Service') {
                    return $this->services;
                }

                if ($this->schema === 'Appointment') {
                    return $this->appointments;
                }

                return [];
            }//end findAll()

            /**
             * @param  array<string,mixed> $object
             * @return array<string,mixed>|null
             */
            public function saveObject(array $object, string $register='', string $schema=''): ?array
            {
                return $this->saved;
            }//end saveObject()
        };

    }//end buildObjectService()
}//end class
