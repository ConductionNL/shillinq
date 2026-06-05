<?php

/**
 * Widget Settings Controller
 *
 * Admin-only management of self-service widget API keys (REQ-WSW-009). Lets an
 * administrator generate, rotate, and revoke the per-business API key that
 * authenticates the public widget endpoints. The plaintext key is returned
 * exactly once on generation/rotation and never re-readable thereafter. All
 * actions are gated by #[AuthorizedAdminSetting] (ADR-005).
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
use OCA\Shillinq\Service\WidgetAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin endpoints to generate, rotate, and revoke widget API keys.
 *
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 */
class WidgetSettingsController extends Controller
{
    /**
     * Construct the controller.
     *
     * @param IRequest          $request     The request object.
     * @param WidgetAuthService $authService API-key lifecycle service.
     */
    public function __construct(
        IRequest $request,
        private readonly WidgetAuthService $authService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate or rotate the API key for a business (REQ-WSW-009).
     *
     * Returns the plaintext key once; only its hash is persisted.
     *
     * @return JSONResponse HTTP 200 with the one-time plaintext key, or 400.
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function rotate(): JSONResponse
    {
        $businessId       = trim((string) $this->request->getParam('businessId', ''));
        $administrationId = trim((string) $this->request->getParam('administrationId', $businessId));

        if ($businessId === '') {
            return new JSONResponse(['success' => false, 'message' => 'businessId is required.'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->authService->rotateApiKey(
            businessId: $businessId,
            administrationId: $administrationId
        );
        $status = Http::STATUS_BAD_REQUEST;
        if ($result['success'] === true) {
            $status = Http::STATUS_OK;
        }

        return new JSONResponse($result, $status);

    }//end rotate()

    /**
     * Revoke the API key for a business immediately (REQ-WSW-009).
     *
     * @return JSONResponse HTTP 200 on success, or 400.
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function revoke(): JSONResponse
    {
        $businessId = trim((string) $this->request->getParam('businessId', ''));
        if ($businessId === '') {
            return new JSONResponse(['success' => false, 'message' => 'businessId is required.'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->authService->revokeApiKey(businessId: $businessId);
        $status = Http::STATUS_BAD_REQUEST;
        if ($result['success'] === true) {
            $status = Http::STATUS_OK;
        }

        return new JSONResponse($result, $status);

    }//end revoke()
}//end class
