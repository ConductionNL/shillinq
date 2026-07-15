<?php

/**
 * Contract tests for SpendAnalyticsController::spend().
 *
 * Pins the wire contract of `GET /api/analytics/spend?dimension=...`: the 200
 * success shape (dimension + label + groups + total), the 400 rejection of an
 * unknown dimension, and the 401 anonymous gate.
 *
 * @contract exercises spendAnalytics#spend (GET /api/analytics/spend)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SpendAnalyticsController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SpendAnalyticsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Controller\SpendAnalyticsController
 */
final class SpendAnalyticsControllerTest extends TestCase
{
    /**
     * Build the controller with mocked dependencies.
     *
     * @param string|null $userId    The resolved user id (null = anonymous).
     * @param string      $dimension The dimension query parameter value.
     * @param array<string,mixed> $servicePayload The service payload to return.
     *
     * @return SpendAnalyticsController
     */
    private function makeController(?string $userId, string $dimension, array $servicePayload): SpendAnalyticsController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($dimension) {
                if ($key === 'dimension') {
                    return $dimension;
                }

                return $default;
            }
        );

        $service = $this->createMock(SpendAnalyticsService::class);
        $service->method('spendBySupplier')->willReturn($servicePayload);
        $service->method('spendByCategory')->willReturn($servicePayload);
        $service->method('spendByCostCentre')->willReturn($servicePayload);
        $service->method('spendByPeriod')->willReturn($servicePayload);

        $context = $this->createMock(AdministrationContextService::class);
        $context->method('currentUserId')->willReturn($userId);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);

        return new SpendAnalyticsController(
            request: $request,
            service: $service,
            context: $context,
            l10n: $l10n,
            logger: $logger
        );

    }//end makeController()

    /**
     * Happy path — 200 with the view payload + translated label.
     */
    public function testSpendReturnsPayloadForValidDimension(): void
    {
        $payload    = [
            'dimension' => 'supplier',
            'groups'    => [['key' => 'V1', 'amount' => 150.0]],
            'total'     => 150.0,
            'backend'   => 'postgres',
        ];
        $controller = $this->makeController(userId: 'alice', dimension: 'supplier', servicePayload: $payload);

        $response = $controller->spend();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('supplier', $data['dimension']);
        $this->assertSame('Spend by supplier', $data['label']);
        $this->assertSame(150.0, $data['total']);
        $this->assertSame('V1', $data['groups'][0]['key']);
    }//end testSpendReturnsPayloadForValidDimension()

    /**
     * Unknown dimension — HTTP 400.
     */
    public function testSpendRejectsUnknownDimension(): void
    {
        $controller = $this->makeController(userId: 'alice', dimension: '__nope', servicePayload: []);

        $response = $controller->spend();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }//end testSpendRejectsUnknownDimension()

    /**
     * Anonymous request — HTTP 401.
     */
    public function testSpendRejectsAnonymous(): void
    {
        $controller = $this->makeController(userId: null, dimension: 'supplier', servicePayload: []);

        $response = $controller->spend();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSpendRejectsAnonymous()
}//end class
