<?php

/**
 * Appointment Confirmation API Controller
 *
 * HTTP surface for the bookings-confirm-flow capability: a dry-run token
 * validation endpoint for the web portal, the confirm endpoint that redeems a
 * token and transitions the appointment to confirmed, a resend endpoint that
 * issues a fresh token, and the public confirmation portal page. The validate
 * and confirm endpoints are token-authenticated public pages (the customer is
 * not necessarily a logged-in Nextcloud user) and are therefore guarded solely
 * by the unguessable bcrypt-hashed confirmation token (ADR-005); resend is
 * restricted to authenticated users. No stack traces are returned to the client
 * and the raw token is never echoed back (REQ-BCF-004, REQ-BCF-006, REQ-BCF-007).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AppointmentConfirmationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller exposing the appointment confirmation endpoints.
 */
class ConfirmationApiController extends Controller
{
    /**
     * Map a validation reason to an HTTP status code per the spec.
     *
     * @var array<string,int>
     */
    private const REASON_STATUS = [
        'expired'   => Http::STATUS_FORBIDDEN,
        'redeemed'  => Http::STATUS_FORBIDDEN,
        'revoked'   => Http::STATUS_FORBIDDEN,
        'invalid'   => Http::STATUS_UNAUTHORIZED,
        'not_found' => Http::STATUS_NOT_FOUND,
    ];

    /**
     * Constructor.
     *
     * @param IRequest                       $request             The request.
     * @param AppointmentConfirmationService $confirmationService The confirmation service.
     */
    public function __construct(
        IRequest $request,
        private AppointmentConfirmationService $confirmationService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Mask the appointment record for client consumption (no internal fields).
     *
     * @param array<string,mixed>|null $appointment The appointment.
     *
     * @return array<string,mixed>|null
     */
    private function maskAppointment(?array $appointment): ?array
    {
        if ($appointment === null) {
            return null;
        }

        return [
            'appointmentNumber' => ($appointment['appointmentNumber'] ?? null),
            'serviceName'       => ($appointment['serviceName'] ?? null),
            'providerName'      => ($appointment['providerName'] ?? null),
            'location'          => ($appointment['location'] ?? null),
            'notes'             => ($appointment['notes'] ?? null),
            'startTime'         => ($appointment['startTime'] ?? null),
            'endTime'           => ($appointment['endTime'] ?? null),
            'status'            => ($appointment['status'] ?? null),
            'customerTimezone'  => ($appointment['customerTimezone'] ?? null),
        ];
    }//end maskAppointment()

    /**
     * Dry-run token validation for the portal load (REQ-BCF-007). No side effects.
     *
     * @param string $appointmentId The appointment id.
     * @param string $token         The raw confirmation token.
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function validate(string $appointmentId='', string $token=''): JSONResponse
    {
        if ($appointmentId === '' || $token === '') {
            return new JSONResponse(['valid' => false, 'error' => 'Missing token'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->confirmationService->validateToken(appointmentId: $appointmentId, rawToken: $token);
        if ($result['valid'] === true) {
            return new JSONResponse(
                ['valid' => true, 'appointment' => $this->maskAppointment(appointment: $result['appointment'])]
            );
        }

        $status = (self::REASON_STATUS[$result['reason']] ?? Http::STATUS_FORBIDDEN);
        return new JSONResponse(
            ['valid' => false, 'reason' => $result['reason']],
            $status
        );
    }//end validate()

    /**
     * Redeem a token and confirm the appointment (REQ-BCF-004).
     *
     * @param string $appointmentId The appointment id.
     * @param string $token         The raw confirmation token.
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function confirm(string $appointmentId='', string $token=''): JSONResponse
    {
        if ($appointmentId === '' || $token === '') {
            return new JSONResponse(['success' => false, 'error' => 'Missing token'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->confirmationService->confirm(appointmentId: $appointmentId, rawToken: $token);
        if ($result['success'] === true) {
            return new JSONResponse(
                ['success' => true, 'appointment' => $this->maskAppointment(appointment: $result['appointment'])]
            );
        }

        $status = (self::REASON_STATUS[$result['reason']] ?? Http::STATUS_FORBIDDEN);
        return new JSONResponse(
            ['success' => false, 'reason' => $result['reason']],
            $status
        );
    }//end confirm()

    /**
     * Resend a confirmation email with a fresh token (REQ-BCF-006). Restricted
     * to authenticated users (operator/admin re-send on the customer's behalf).
     *
     * @param string $appointmentId The appointment id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function resend(string $appointmentId=''): JSONResponse
    {
        if ($appointmentId === '') {
            return new JSONResponse(['success' => false, 'error' => 'Missing appointment'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->confirmationService->resend(appointmentId: $appointmentId);
        if ($result['success'] === true) {
            return new JSONResponse(['success' => true, 'message' => 'Confirmation email resent']);
        }

        $status = Http::STATUS_BAD_REQUEST;
        if ($result['reason'] === 'not_found') {
            $status = Http::STATUS_NOT_FOUND;
        }

        return new JSONResponse(['success' => false, 'reason' => $result['reason']], $status);
    }//end resend()

    /**
     * Public confirmation portal page (REQ-BCF-007). Renders the SPA shell; the
     * portal reads the token from the query string and calls validate()/confirm().
     *
     * @return TemplateResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function portal(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index', [], TemplateResponse::RENDER_AS_PUBLIC);
    }//end portal()
}//end class
