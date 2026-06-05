<?php

/**
 * Unit tests for IcpController.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\IcpController;
use OCA\Shillinq\Service\IcpService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the read-only ICP API controller.
 *
 * Covers REQ-ICP-003 (ledger contract), REQ-ICP-004 (reconcile contract),
 * REQ-ICP-002 (periodicity contract), parameter validation, and the 500 fail
 * path that leaks no stack trace (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IcpControllerTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock IcpService.
     *
     * @var IcpService&MockObject
     */
    private IcpService&MockObject $service;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The controller under test.
     *
     * @var IcpController
     */
    private IcpController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request    = $this->createMock(IRequest::class);
        $this->service    = $this->createMock(IcpService::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->controller = new IcpController(
            request: $this->request,
            icpService: $this->service,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Configure request params from a key => value map.
     *
     * @param array<string,string> $map Param map.
     *
     * @return void
     */
    private function withParams(array $map): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null) use ($map): mixed {
                return ($map[$key] ?? $default);
            }
        );

    }//end withParams()

    /**
     * A missing period_id yields HTTP 400 on the ledger endpoint (REQ-ICP-003).
     *
     * @return void
     */
    public function testLedgerMissingPeriodReturns400(): void
    {
        $this->withParams(['administration_id' => 'adm-1']);
        $response = $this->controller->ledger();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testLedgerMissingPeriodReturns400()

    /**
     * A path-traversal period_id is rejected with HTTP 400 (IDOR-safe input).
     *
     * @return void
     */
    public function testLedgerMalformedPeriodReturns400(): void
    {
        $this->withParams(['period_id' => '../../etc', 'administration_id' => 'adm-1']);
        $response = $this->controller->ledger();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testLedgerMalformedPeriodReturns400()

    /**
     * A valid ledger request returns HTTP 200 with the service result (REQ-ICP-003).
     *
     * @return void
     */
    public function testLedgerValidReturns200(): void
    {
        $this->withParams(['period_id' => '2026-Q2', 'administration_id' => 'adm-1']);
        $payload = [
            'period'             => '2026-Q2',
            'lines'              => [['buyerVatId' => 'BE0123456789', 'supplyType' => 'L', 'amountExclVat' => 25000.0]],
            'total'              => 25000.0,
            'totalGoods'         => 25000.0,
            'totalServices'      => 0.0,
            'totalTriangulation' => 0.0,
            'supplyCount'        => 1,
        ];
        $this->service->expects($this->once())
            ->method('ledger')
            ->with('adm-1', '2026-Q2')
            ->willReturn($payload);

        $response = $this->controller->ledger();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame($payload, $response->getData());

    }//end testLedgerValidReturns200()

    /**
     * A valid reconcile request returns HTTP 200 with the outcome (REQ-ICP-004).
     *
     * @return void
     */
    public function testReconcileValidReturns200(): void
    {
        $this->withParams(['period_id' => '2026-Q2', 'administration_id' => 'adm-1']);
        $payload = [
            'period'     => '2026-Q2',
            'icpTotal'   => 25000.0,
            'rubriek3b'  => 25000.0,
            'matches'    => true,
            'missing'    => false,
            'difference' => 0.0,
        ];
        $this->service->expects($this->once())
            ->method('reconcile')
            ->with('adm-1', '2026-Q2')
            ->willReturn($payload);

        $response = $this->controller->reconcile();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame($payload, $response->getData());

    }//end testReconcileValidReturns200()

    /**
     * A valid periodicity request returns HTTP 200 with the decision (REQ-ICP-002).
     *
     * @return void
     */
    public function testPeriodicityValidReturns200(): void
    {
        $this->withParams(['quarter' => '2026-Q1', 'administration_id' => 'adm-1']);
        $payload = ['quarter' => '2026-Q1', 'breached' => true, 'goodsCumulative' => 50100.0];
        $this->service->expects($this->once())
            ->method('periodicityCheck')
            ->with('adm-1', '2026-Q1')
            ->willReturn($payload);

        $response = $this->controller->periodicity();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame($payload, $response->getData());

    }//end testPeriodicityValidReturns200()

    /**
     * A missing quarter yields HTTP 400 on the periodicity endpoint (REQ-ICP-002).
     *
     * @return void
     */
    public function testPeriodicityMissingQuarterReturns400(): void
    {
        $this->withParams(['administration_id' => 'adm-1']);
        $response = $this->controller->periodicity();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testPeriodicityMissingQuarterReturns400()

    /**
     * A service exception yields HTTP 500 with no stack trace leaked (ADR-005).
     *
     * @return void
     */
    public function testServiceFailureReturns500WithoutStackTrace(): void
    {
        $this->withParams(['period_id' => '2026-Q2', 'administration_id' => 'adm-1']);
        $this->service->method('ledger')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->ledger();

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        self::assertArrayHasKey('error', $response->getData());
        self::assertStringNotContainsStringIgnoringCase('boom', (string) json_encode($response->getData()));

    }//end testServiceFailureReturns500WithoutStackTrace()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
