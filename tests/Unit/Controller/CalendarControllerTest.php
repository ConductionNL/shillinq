<?php

/**
 * Unit tests for CalendarController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CalendarController;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the calendar/booking REST endpoints (REQ-005).
 *
 * @covers \OCA\Shillinq\Controller\CalendarController
 *
 * @spec openspec/changes/bookings-resource-calendar/specs/bookings/spec.md#req-005-calendar-api-endpoints-for-reading-calendars-and-bookings
 */
class CalendarControllerTest extends TestCase
{

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock settings service.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settings;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request   = $this->createMock(IRequest::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->settings  = $this->createMock(SettingsService::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->settings->method('getRegisterSlug')->willReturn('shillinq');
    }//end setUp()

    /**
     * Build a controller wired to a stub ObjectService.
     *
     * @param object $objectService     The stub ObjectService.
     * @param bool   $openRegisterReady Whether OR is reported available.
     *
     * @return CalendarController
     */
    private function controller(object $objectService, bool $openRegisterReady=true): CalendarController
    {
        $this->settings->method('isOpenRegisterAvailable')->willReturn($openRegisterReady);
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $conflicts = new ConflictDetectionService(
            container: $this->container,
            settings: $this->settings,
            logger: $this->logger
        );

        return new CalendarController(
            request: $this->request,
            container: $this->container,
            settings: $this->settings,
            conflicts: $conflicts,
            logger: $this->logger
        );

    }//end controller()

    /**
     * Create a configurable in-memory ObjectService stub.
     *
     * @param array<string,array<string,mixed>> $byId        Single-find lookup by id.
     * @param array<int,array<string,mixed>>    $listResults findAll result set.
     *
     * @return object
     */
    private function stubObjectService(array $byId=[], array $listResults=[]): object
    {
        return new class($byId, $listResults) {

            /**
             * @var array<int,array<string,mixed>>
             */
            public array $saved = [];

            /**
             * @param array<string,array<string,mixed>> $byId        Find-by-id map.
             * @param array<int,array<string,mixed>>    $listResults findAll results.
             */
            public function __construct(private array $byId, private array $listResults)
            {
            }//end __construct()

            public function setRegister(mixed $r): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(mixed $s): static
            {
                return $this;
            }//end setSchema()

            /**
             * @param array<string,mixed> $config Query config.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $config): array
            {
                return $this->listResults;
            }//end findAll()

            /**
             * @return array<string,mixed>|null
             */
            public function find(
                int | string $id,
                ?array $_extend=[],
                bool $files=false,
                mixed $register=null,
                mixed $schema=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): ?array {
                return ($this->byId[(string) $id] ?? null);
            }//end find()

            /**
             * @param array<string,mixed> $object Object to save.
             *
             * @return array<string,mixed>
             */
            public function saveObject(
                array | object $object,
                ?array $extend=[],
                mixed $register=null,
                mixed $schema=null,
                ?string $uuid=null
            ): array {
                $saved         = (array) $object;
                $saved['id']   = 'bk-new';
                $this->saved[] = $saved;
                return $saved;
            }//end saveObject()
        };

    }//end stubObjectService()

    /**
     * REQ-005: GET /calendars returns 200 with a JSON array of calendars.
     *
     * @return void
     */
    public function testIndexReturnsCalendars(): void
    {
        $list       = [
            ['id' => 'cal-001', 'resource' => 'res-001', 'timeZone' => 'Europe/Amsterdam', 'status' => 'active'],
        ];
        $controller = $this->controller($this->stubObjectService([], $list));
        $response   = $controller->index();
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        self::assertCount(1, $data);
        self::assertSame('cal-001', $data[0]['id']);

    }//end testIndexReturnsCalendars()

    /**
     * REQ-005: GET /calendars?resource=... filters by resource.
     *
     * @return void
     */
    public function testIndexFiltersByResource(): void
    {
        $controller = $this->controller($this->stubObjectService([], []));
        $response   = $controller->index(resource: 'res-001');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame([], $response->getData());

    }//end testIndexFiltersByResource()

    /**
     * REQ-005: GET /calendars/{id} returns the single calendar.
     *
     * @return void
     */
    public function testShowReturnsCalendar(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001', 'status' => 'active']];
        $controller = $this->controller($this->stubObjectService($byId));
        $response   = $controller->show('cal-001');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('cal-001', $response->getData()['id']);

    }//end testShowReturnsCalendar()

    /**
     * REQ-005: GET /calendars/{id} for a missing calendar returns 404.
     *
     * @return void
     */
    public function testShowReturns404WhenMissing(): void
    {
        $controller = $this->controller($this->stubObjectService([]));
        $response   = $controller->show('cal-missing');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowReturns404WhenMissing()

    /**
     * REQ-005: GET bookings filters to the date range and sorts by startTime.
     *
     * @return void
     */
    public function testBookingsReturnsSortedRange(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001']];
        $bookings   = [
            ['id' => 'bk-002', 'resource' => 'res-001', 'startTime' => '2026-05-21T11:00:00Z', 'endTime' => '2026-05-21T11:45:00Z', 'status' => 'confirmed'],
            ['id' => 'bk-001', 'resource' => 'res-001', 'startTime' => '2026-05-21T10:00:00Z', 'endTime' => '2026-05-21T10:30:00Z', 'status' => 'confirmed'],
            ['id' => 'bk-out', 'resource' => 'res-001', 'startTime' => '2027-01-01T10:00:00Z', 'endTime' => '2027-01-01T10:30:00Z', 'status' => 'confirmed'],
        ];
        $controller = $this->controller($this->stubObjectService($byId, $bookings));
        $response   = $controller->bookings(calendarId: 'cal-001', start: '2026-05-21', end: '2026-05-31');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        self::assertCount(2, $data);
        self::assertSame('bk-001', $data[0]['id']);
        self::assertSame('bk-002', $data[1]['id']);

    }//end testBookingsReturnsSortedRange()

    /**
     * REQ-005: POST a non-conflicting booking returns 201.
     *
     * @return void
     */
    public function testCreateBookingSuccess(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001']];
        $stub       = $this->stubObjectService($byId, []);
        $controller = $this->controller($stub);
        $response   = $controller->createBooking(
            calendarId: 'cal-001',
            title: 'Klant: Bob Jansen',
            startTime: '2026-05-21T14:00:00Z',
            endTime: '2026-05-21T14:30:00Z',
            attendee: 'Bob Jansen',
            status: 'confirmed'
        );
        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
        self::assertCount(1, $stub->saved);

    }//end testCreateBookingSuccess()

    /**
     * REQ-005 / REQ-004: POST an overlapping booking returns 409 with conflicts.
     *
     * @return void
     */
    public function testCreateBookingConflictReturns409(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001']];
        $existing   = [
            ['id' => 'bk-002', 'resource' => 'res-001', 'startTime' => '2026-05-21T11:00:00Z', 'endTime' => '2026-05-21T11:45:00Z', 'status' => 'confirmed'],
        ];
        $controller = $this->controller($this->stubObjectService($byId, $existing));
        $response   = $controller->createBooking(
            calendarId: 'cal-001',
            title: 'Klant: Sophia Vermeulen',
            startTime: '2026-05-21T11:15:00Z',
            endTime: '2026-05-21T12:00:00Z',
            attendee: 'Sophia Vermeulen',
            status: 'pending'
        );
        self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $data = $response->getData();
        self::assertArrayHasKey('conflicts', $data);
        self::assertSame('bk-002', $data['conflicts'][0]['id']);

    }//end testCreateBookingConflictReturns409()

    /**
     * REQ-007: POST with ?force overrides a conflict and creates the booking.
     *
     * @return void
     */
    public function testCreateBookingForceOverridesConflict(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001']];
        $existing   = [
            ['id' => 'bk-002', 'resource' => 'res-001', 'startTime' => '2026-05-21T11:00:00Z', 'endTime' => '2026-05-21T11:45:00Z', 'status' => 'confirmed'],
        ];
        $stub       = $this->stubObjectService($byId, $existing);
        $controller = $this->controller($stub);
        $response   = $controller->createBooking(
            calendarId: 'cal-001',
            title: 'Klant: Sophia Vermeulen',
            startTime: '2026-05-21T11:15:00Z',
            endTime: '2026-05-21T12:00:00Z',
            attendee: 'Sophia Vermeulen',
            status: 'pending',
            force: true
        );
        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
        self::assertCount(1, $stub->saved);

    }//end testCreateBookingForceOverridesConflict()

    /**
     * REQ-007: a sub-15-minute booking is rejected with 400.
     *
     * @return void
     */
    public function testCreateBookingShortDurationRejected(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001']];
        $controller = $this->controller($this->stubObjectService($byId, []));
        $response   = $controller->createBooking(
            calendarId: 'cal-001',
            title: 'Too short',
            startTime: '2026-05-21T10:00:00Z',
            endTime: '2026-05-21T10:10:00Z',
            attendee: 'X',
            status: 'pending'
        );
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testCreateBookingShortDurationRejected()

    /**
     * REQ-007: endTime not after startTime is rejected with 400.
     *
     * @return void
     */
    public function testCreateBookingEndBeforeStartRejected(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001']];
        $controller = $this->controller($this->stubObjectService($byId, []));
        $response   = $controller->createBooking(
            calendarId: 'cal-001',
            title: 'Backwards',
            startTime: '2026-05-21T11:00:00Z',
            endTime: '2026-05-21T10:00:00Z',
            attendee: 'X',
            status: 'pending'
        );
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testCreateBookingEndBeforeStartRejected()

    /**
     * REQ-003: an invalid status value is rejected with 400.
     *
     * @return void
     */
    public function testCreateBookingInvalidStatusRejected(): void
    {
        $byId       = ['cal-001' => ['id' => 'cal-001', 'resource' => 'res-001']];
        $controller = $this->controller($this->stubObjectService($byId, []));
        $response   = $controller->createBooking(
            calendarId: 'cal-001',
            title: 'Bad status',
            startTime: '2026-05-21T10:00:00Z',
            endTime: '2026-05-21T10:30:00Z',
            attendee: 'X',
            status: 'bogus'
        );
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testCreateBookingInvalidStatusRejected()

    /**
     * REQ-005: endpoints return 503 when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testIndexReturns503WhenOpenRegisterUnavailable(): void
    {
        $controller = $this->controller($this->stubObjectService([]), openRegisterReady: false);
        $response   = $controller->index();
        self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

    }//end testIndexReturns503WhenOpenRegisterUnavailable()
}//end class
