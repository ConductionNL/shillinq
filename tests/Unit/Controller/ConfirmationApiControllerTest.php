<?php

/**
 * Unit tests for ConfirmationApiController.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ConfirmationApiController;
use OCA\Shillinq\Service\AppointmentConfirmationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies HTTP status mapping, input validation and appointment masking.
 */
final class ConfirmationApiControllerTest extends TestCase
{
    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock service.
     *
     * @var AppointmentConfirmationService&MockObject
     */
    private AppointmentConfirmationService&MockObject $service;

    /**
     * The controller under test.
     *
     * @var ConfirmationApiController
     */
    private ConfirmationApiController $controller;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request    = $this->createMock(IRequest::class);
        $this->service    = $this->createMock(AppointmentConfirmationService::class);
        $this->controller = new ConfirmationApiController($this->request, $this->service);
    }//end setUp()

    /**
     * Missing parameters yield a 400 without touching the service.
     *
     * @return void
     */
    public function testValidateRejectsMissingParams(): void
    {
        $this->service->expects($this->never())->method('validateToken');
        $response = $this->controller->validate('', '');
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testValidateRejectsMissingParams()

    /**
     * A valid token returns 200 with a masked appointment (no tokenString/admin fields).
     *
     * @return void
     */
    public function testValidateReturnsMaskedAppointment(): void
    {
        $this->service->method('validateToken')->willReturn(
            [
                'valid'       => true,
                'reason'      => 'ok',
                'appointment' => [
                    'appointmentNumber' => 'APT-1',
                    'serviceName'       => 'Consult',
                    'startTime'         => '2026-05-22T12:30:00Z',
                    'administrationId'  => 'adm-1',
                    'confirmationTokenId' => 'tok-1',
                ],
                'token'       => ['tokenString' => 'secret-hash'],
            ]
        );

        $response = $this->controller->validate('apt-1', 'rawtoken');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        self::assertTrue($data['valid']);
        self::assertSame('APT-1', $data['appointment']['appointmentNumber']);
        // Internal fields must not leak.
        self::assertArrayNotHasKey('administrationId', $data['appointment']);
        self::assertArrayNotHasKey('confirmationTokenId', $data['appointment']);
    }//end testValidateReturnsMaskedAppointment()

    /**
     * An expired token maps to HTTP 403.
     *
     * @return void
     */
    public function testValidateExpiredMapsTo403(): void
    {
        $this->service->method('validateToken')->willReturn(
            ['valid' => false, 'reason' => 'expired', 'appointment' => null, 'token' => null]
        );
        $response = $this->controller->validate('apt-1', 'rawtoken');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testValidateExpiredMapsTo403()

    /**
     * An invalid token maps to HTTP 401.
     *
     * @return void
     */
    public function testValidateInvalidMapsTo401(): void
    {
        $this->service->method('validateToken')->willReturn(
            ['valid' => false, 'reason' => 'invalid', 'appointment' => null, 'token' => null]
        );
        $response = $this->controller->validate('apt-1', 'rawtoken');
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testValidateInvalidMapsTo401()

    /**
     * A successful confirm returns 200 with success true.
     *
     * @return void
     */
    public function testConfirmSuccess(): void
    {
        $this->service->method('confirm')->willReturn(
            [
                'success'     => true,
                'reason'      => 'ok',
                'appointment' => ['appointmentNumber' => 'APT-1', 'status' => 'confirmed'],
            ]
        );
        $response = $this->controller->confirm('apt-1', 'rawtoken');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertTrue($response->getData()['success']);
    }//end testConfirmSuccess()

    /**
     * Confirming with an already-redeemed token maps to HTTP 403.
     *
     * @return void
     */
    public function testConfirmRedeemedMapsTo403(): void
    {
        $this->service->method('confirm')->willReturn(
            ['success' => false, 'reason' => 'redeemed', 'appointment' => null]
        );
        $response = $this->controller->confirm('apt-1', 'rawtoken');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testConfirmRedeemedMapsTo403()

    /**
     * Resend success returns a 200 with a message.
     *
     * @return void
     */
    public function testResendSuccess(): void
    {
        $this->service->method('resend')->willReturn(['success' => true, 'reason' => 'ok']);
        $response = $this->controller->resend('apt-1');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertTrue($response->getData()['success']);
    }//end testResendSuccess()

    /**
     * Resend for a missing appointment maps to HTTP 404.
     *
     * @return void
     */
    public function testResendNotFoundMapsTo404(): void
    {
        $this->service->method('resend')->willReturn(['success' => false, 'reason' => 'not_found']);
        $response = $this->controller->resend('apt-x');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testResendNotFoundMapsTo404()
}//end class
