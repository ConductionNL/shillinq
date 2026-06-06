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

}//end class
