<?php

/**
 * Widget API Controller
 *
 * Public, API-key-authenticated endpoints for the embeddable self-service
 * booking widget (REQ-WSW-001). Routes are #[PublicPage] (no Nextcloud login)
 * but every request MUST present a valid `Authorization: Bearer {api_key}`
 * header for the claimed `businessId`; unauthenticated requests are rejected
 * with HTTP 401 and rate-limited requests with HTTP 429 (ADR-005). Only
 * safe-public data is returned — customer PII is never exposed (design D6).
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
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetAuthService;
use OCA\Shillinq\Service\WidgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the public widget API: services, slots, and appointment creation.
 *
 * Every action calls authorize() first, which enforces API-key validity and
 * per-business rate-limiting before any data is read or written.
 *
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 */
class WidgetApiController extends Controller
{
    /**
     * Construct the controller.
     *
     * @param IRequest          $request       The request object.
     * @param WidgetAuthService $authService   API-key validation + rate-limiting.
     * @param WidgetService     $widgetService Service catalogue + appointment creation.
     * @param SlotService       $slotService   Slot availability computation.
     */
    public function __construct(
        IRequest $request,
        private readonly WidgetAuthService $authService,
        private readonly WidgetService $widgetService,
        private readonly SlotService $slotService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Extract the bearer token from the Authorization header.
     *
     * @return string The plaintext API key, or '' when absent/malformed.
     */
    private function bearerToken(): string
    {
        $header = (string) $this->request->getHeader('Authorization');
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return '';

    }//end bearerToken()

    /**
     * Authorise the request: validate API key and enforce rate-limiting.
     *
     * @param string $businessId The business the caller claims to be.
     *
     * @return JSONResponse|null A 401/429 error response, or null when authorised.
     */
    private function authorize(string $businessId): ?JSONResponse
    {
        $apiKey = $this->bearerToken();
        if ($businessId === '' || $this->authService->validateApiKey($businessId, $apiKey) === false) {
            return new JSONResponse(
                ['error' => 'Invalid or missing API key'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $limit = $this->authService->getRateLimit($businessId);
        if ($this->authService->registerRequest($businessId, $limit) === false) {
            $response = new JSONResponse(
                ['error' => 'Rate limit exceeded'],
                Http::STATUS_TOO_MANY_REQUESTS
            );
            $response->addHeader('Retry-After', '60');
            return $response;
        }

        return null;

    }//end authorize()

    /**
     * List public services for a business (REQ-WSW-001).
     *
     * @param string $businessId The business identifier (== administrationId).
     *
     * @return JSONResponse HTTP 200 with the public service list, or 401/429.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function services(string $businessId=''): JSONResponse
    {
        $error = $this->authorize(businessId: $businessId);
        if ($error !== null) {
            return $error;
        }

        return new JSONResponse(
            ['services' => $this->widgetService->listPublicServices(administrationId: $businessId)],
            Http::STATUS_OK
        );

    }//end services()

    /**
     * List available slots for a service on a date (REQ-WSW-002, REQ-WSW-007).
     *
     * Emits an ETag and honours If-None-Match with HTTP 304 to support caching.
     *
     * @param string $businessId  The business identifier.
     * @param string $serviceSlug The service to book.
     * @param string $date        The ISO date (YYYY-MM-DD).
     *
     * @return JSONResponse HTTP 200 with slots (or 304/401/404/429).
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function slots(string $businessId='', string $serviceSlug='', string $date=''): JSONResponse
    {
        $error = $this->authorize(businessId: $businessId);
        if ($error !== null) {
            return $error;
        }

        if ($serviceSlug === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new JSONResponse(['error' => 'invalid_parameters'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->slotService->getAvailableSlots(
            serviceSlug: $serviceSlug,
            date: $date,
            administrationId: $businessId
        );
        if (isset($result['error']) === true) {
            $status = Http::STATUS_BAD_REQUEST;
            if ($result['error'] === 'service_not_found') {
                $status = Http::STATUS_NOT_FOUND;
            }

            return new JSONResponse(['error' => $result['error']], $status);
        }

        $etag        = '"'.($result['etag'] ?? '').'"';
        $ifNoneMatch = trim((string) $this->request->getHeader('If-None-Match'));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            $notModified = new JSONResponse([], Http::STATUS_NOT_MODIFIED);
            $notModified->addHeader('ETag', $etag);
            return $notModified;
        }

        $response = new JSONResponse(
            [
                'slots'      => ($result['slots'] ?? []),
                'resourceId' => ($result['resourceId'] ?? ''),
            ],
            Http::STATUS_OK
        );
        $response->addHeader('ETag', $etag);
        $response->addHeader('Cache-Control', 'private, max-age=300');

        return $response;

    }//end slots()

    /**
     * Create an appointment from the widget (REQ-WSW-003, REQ-WSW-008).
     *
     * @param string $businessId The business identifier.
     *
     * @return JSONResponse HTTP 201 on success; 400/401/404/409/429/500 otherwise.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function createAppointment(string $businessId=''): JSONResponse
    {
        $error = $this->authorize(businessId: $businessId);
        if ($error !== null) {
            return $error;
        }

        $payload = [
            'serviceSlug'   => $this->request->getParam('serviceSlug', ''),
            'startTime'     => $this->request->getParam('startTime', ''),
            'customerName'  => $this->request->getParam('customerName', ''),
            'customerEmail' => $this->request->getParam('customerEmail', ''),
            'customerPhone' => $this->request->getParam('customerPhone', ''),
            'notes'         => $this->request->getParam('notes', ''),
        ];

        $result = $this->widgetService->createAppointment(administrationId: $businessId, payload: $payload);
        $code   = (int) ($result['code'] ?? Http::STATUS_INTERNAL_SERVER_ERROR);
        unset($result['code']);

        return new JSONResponse($result, $code);

    }//end createAppointment()
}//end class
