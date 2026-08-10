<?php

/**
 * Unit tests for WidgetApiController.
 *
 * Covers the auth guard happy path and the 401/429 negative paths.
 * Endpoint-level integration coverage (200 services list, 201 appointment,
 * 409 double-book, 304 ETag) is exercised in tests/integration via Newman
 * against the live OR-backed register per ADR-008.
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

use OCA\Shillinq\Controller\WidgetApiController;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WidgetApiController.
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-15
 */
class WidgetApiControllerTest extends TestCase
{

    /**
     * IRequest stub.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Auth service stub.
     *
     * @var WidgetAuthService&MockObject
     */
    private WidgetAuthService&MockObject $auth;

    /**
     * Slot service stub.
     *
     * @var SlotService&MockObject
     */
    private SlotService&MockObject $slots;

    /**
     * Settings stub.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settings;

    /**
     * Container stub.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Time factory stub.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory&MockObject $time;

    /**
     * Logger stub.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request   = $this->createMock(IRequest::class);
        $this->auth      = $this->createMock(WidgetAuthService::class);
        $this->slots     = $this->createMock(SlotService::class);
        $this->settings  = $this->createMock(SettingsService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->time      = $this->createMock(ITimeFactory::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->time->method('getTime')->willReturn(1717000000);

    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return WidgetApiController
     */
    private function makeController(): WidgetApiController
    {
        return new WidgetApiController(
            request: $this->request,
            auth: $this->auth,
            slots: $this->slots,
            settings: $this->settings,
            container: $this->container,
            time: $this->time,
            logger: $this->logger,
        );

    }//end makeController()

    /**
     * Requests without an Authorization header are 401 Unauthorized.
     *
     * @return void
     */
    public function testServicesReturnsUnauthorisedWithoutHeader(): void
    {
        $this->request->method('getParam')->willReturn('');
        $this->request->method('getHeader')->willReturn('');

        $response = $this->makeController()->services();
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testServicesReturnsUnauthorisedWithoutHeader()

    /**
     * Requests with an invalid bearer token are 401 Unauthorized.
     *
     * @return void
     */
    public function testServicesReturnsUnauthorisedWithInvalidBearer(): void
    {
        $this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
        $this->request->method('getHeader')->willReturnMap(
            [
                ['Authorization', 'Bearer bk_live_wrong'],
                ['If-None-Match', ''],
            ]
        );
        $this->auth->method('validateApiKey')->willReturn(['valid' => false, 'error' => 'Invalid or missing API key']);

        $response = $this->makeController()->services();
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testServicesReturnsUnauthorisedWithInvalidBearer()

    /**
     * Authenticated requests that exceed the rate limit are 429.
     *
     * @return void
     */
    public function testServicesReturns429WhenRateLimited(): void
    {
        $this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
        $this->request->method('getHeader')->willReturnMap(
            [
                ['Authorization', 'Bearer bk_live_valid'],
                ['If-None-Match', ''],
            ]
        );

        $this->auth->method('validateApiKey')->willReturn(
            [
                'valid' => true,
                'key'   => ['rateLimit' => 100],
            ]
        );
        $this->auth->method('consumeRateLimit')->willReturn(
            [
                'allowed'    => false,
                'remaining'  => 0,
                'retryAfter' => 60,
            ]
        );

        $response = $this->makeController()->services();
        self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
        self::assertSame('60', $response->getHeaders()['Retry-After']);

    }//end testServicesReturns429WhenRateLimited()

    /**
     * The /slots endpoint validates date format before authenticating call paths.
     *
     * @return void
     */
    public function testSlotsRejectsInvalidDateFormat(): void
    {
        $this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
        $this->request->method('getHeader')->willReturnMap(
            [
                ['Authorization', 'Bearer bk_live_valid'],
                ['If-None-Match', ''],
            ]
        );

        $this->auth->method('validateApiKey')->willReturn(['valid' => true, 'key' => ['rateLimit' => 100]]);
        $this->auth->method('consumeRateLimit')->willReturn(
            ['allowed' => true, 'remaining' => 99, 'retryAfter' => 60]
        );

        $response = $this->makeController()->slots(
            serviceId: 'svc-001',
            resourceId: 'res-001',
            date: '22-05-2026'
        );
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testSlotsRejectsInvalidDateFormat()

    /**
     * POST /appointments rejects malformed ISO timestamps with 400.
     *
     * @return void
     */
    public function testAppointmentsRejectsInvalidTimestamp(): void
    {
        $this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
        $this->request->method('getHeader')->willReturnMap(
            [
                ['Authorization', 'Bearer bk_live_valid'],
                ['If-None-Match', ''],
            ]
        );

        $this->auth->method('validateApiKey')->willReturn(['valid' => true, 'key' => ['rateLimit' => 100]]);
        $this->auth->method('consumeRateLimit')->willReturn(
            ['allowed' => true, 'remaining' => 99, 'retryAfter' => 60]
        );

        $response = $this->makeController()->appointments(
            serviceId: 'svc-001',
            resourceId: 'res-001',
            startTime: '2026/05/22 09:00',
            endTime: '2026/05/22 09:30',
            customerName: 'Alice',
            email: 'alice@example.com',
        );
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testAppointmentsRejectsInvalidTimestamp()

    /**
     * Authenticate a widget caller so the isPublic tests reach the catalogue.
     *
     * @return void
     */
    private function authoriseWidgetCaller(): void
    {
        $this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
        $this->request->method('getHeader')->willReturnMap(
            [
                ['Authorization', 'Bearer bk_live_valid'],
                ['If-None-Match', ''],
            ]
        );
        $this->auth->method('validateApiKey')->willReturn(
            [
                'valid' => true,
                'key'   => ['rateLimit' => 100],
            ]
        );
        $this->auth->method('consumeRateLimit')->willReturn(
            [
                'allowed'   => true,
                'remaining' => 99,
            ]
        );
        $this->settings->method('isOpenRegisterAvailable')->willReturn(true);
        $this->settings->method('getRegisterSlug')->willReturn('shillinq');

    }//end authoriseWidgetCaller()

    /**
     * Build an ObjectService stub returning a fixed Service catalogue.
     *
     * @param array<int,array<string,mixed>> $rows Service rows to return from findAll().
     *
     * @return object
     */
    private function catalogueStub(array $rows): object
    {
        // phpcs:disable
        return new class($rows) {
            private array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function setRegister(string $r): static { return $this; }
            public function setSchema(string $s): static { return $this; }
            public function findAll(array $c=[]): array
            {
                $sid = (string) ($c['filters']['serviceId'] ?? '');
                if ($sid === '') {
                    return $this->rows;
                }
                return array_values(array_filter(
                    $this->rows,
                    static fn (array $r): bool => ((string) ($r['serviceId'] ?? '')) === $sid
                ));
            }
        };
        // phpcs:enable
    }//end catalogueStub()

    /**
     * A service that is active but NOT flagged isPublic must never be listed
     * by the public widget catalogue.
     *
     * @return void
     */
    public function testServicesOmitsServicesThatAreNotPublic(): void
    {
        $this->authoriseWidgetCaller();
        $this->container->method('get')->willReturn(
            $this->catalogueStub(
                [
                    [
                        'serviceId' => 'svc-public',
                        'name'      => 'Haircut',
                        'status'    => 'active',
                        'isPublic'  => true,
                    ],
                    [
                        'serviceId' => 'svc-internal',
                        'name'      => 'Internal staff slot',
                        'status'    => 'active',
                        'isPublic'  => false,
                    ],
                    [
                        'serviceId' => 'svc-unflagged',
                        'name'      => 'Legacy service with no isPublic key',
                        'status'    => 'active',
                    ],
                ]
            )
        );

        $ids = array_column($this->makeController()->services()->getData()['services'], 'serviceId');

        self::assertSame(['svc-public'], $ids, 'only isPublic services may be listed');

    }//end testServicesOmitsServicesThatAreNotPublic()

    /**
     * priceVisible is a per-service choice; a service that hides its price
     * must not have one published.
     *
     * @return void
     */
    public function testServicesOmitsPriceWhenPriceVisibleIsFalse(): void
    {
        $this->authoriseWidgetCaller();
        $this->container->method('get')->willReturn(
            $this->catalogueStub(
                [
                    [
                        'serviceId'    => 'svc-hidden-price',
                        'name'         => 'Consult',
                        'status'       => 'active',
                        'isPublic'     => true,
                        'basePrice'    => 150,
                        'priceVisible' => false,
                    ],
                ]
            )
        );

        $service = $this->makeController()->services()->getData()['services'][0];

        self::assertArrayNotHasKey('price', $service);

    }//end testServicesOmitsPriceWhenPriceVisibleIsFalse()

    /**
     * The booking endpoint must refuse a serviceId that is not public, even
     * though the caller holds a valid widget API key. Without this the widget
     * key — which ships in the embedding page — was enough to book any
     * service whose id could be guessed or read from another surface.
     *
     * @return void
     */
    public function testAppointmentsRefusesAServiceThatIsNotPublic(): void
    {
        $this->authoriseWidgetCaller();
        $this->container->method('get')->willReturn(
            $this->catalogueStub(
                [
                    [
                        'serviceId' => 'svc-internal',
                        'name'      => 'Internal staff slot',
                        'status'    => 'active',
                        'isPublic'  => false,
                    ],
                ]
            )
        );

        $response = $this->makeController()->appointments(
            serviceId: 'svc-internal',
            resourceId: 'res-001',
            startTime: '2026-05-21T10:00:00Z',
            endTime: '2026-05-21T10:30:00Z',
            customerName: 'Anna de Wit',
            email: 'anna@example.com',
        );

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        self::assertSame('service-not-found', $response->getData()['error']);

    }//end testAppointmentsRefusesAServiceThatIsNotPublic()

    /**
     * An unknown serviceId is refused for the same reason — resolvePublicService()
     * must fail closed rather than fall through to the slot check.
     *
     * @return void
     */
    public function testAppointmentsRefusesAnUnknownService(): void
    {
        $this->authoriseWidgetCaller();
        $this->container->method('get')->willReturn($this->catalogueStub([]));

        $response = $this->makeController()->appointments(
            serviceId: 'svc-does-not-exist',
            resourceId: 'res-001',
            startTime: '2026-05-21T10:00:00Z',
            endTime: '2026-05-21T10:30:00Z',
            customerName: 'Anna de Wit',
            email: 'anna@example.com',
        );

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testAppointmentsRefusesAnUnknownService()
}//end class
