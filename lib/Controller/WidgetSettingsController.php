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
 * @spec openspec/specs/bookings-self-service-widget/spec.md
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
use OCP\IUserSession;

/**
 * Admin endpoints to generate, rotate, and revoke widget API keys.
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 */
class WidgetSettingsController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param WidgetAuthService $authService API-key lifecycle service.
	 * @param IUserSession $userSession Session for the acting admin user id (audit-trail actor).
	 */
	public function __construct(
		IRequest $request,
		private readonly WidgetAuthService $authService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the acting admin's UID for audit-trail.
	 *
	 * @return string The acting admin's UID, or `'unknown'` when no session.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'unknown';
		}

		return $user->getUID();
	}//end actor()

	/**
	 * Mint the FIRST API key for a business (REQ-WSW-009 §1, "Generate API keys").
	 *
	 * This is the missing half of the key lifecycle. {@see rotate()} REPLACES an
	 * existing key and returns `No active key found for businessId.` when there
	 * is none, so before this endpoint existed a business could never be issued
	 * its first key through the app — the admin view's "Generate key" button hit
	 * `rotate` and always failed on a fresh businessId, leaving the public widget
	 * unbootstrappable.
	 *
	 * Unlike rotate(), this needs `administrationId`: the tenant boundary is read
	 * off the predecessor record when rotating, and there is no predecessor here.
	 *
	 * Returns the plaintext key once; only its bcrypt hash is persisted.
	 *
	 * @return JSONResponse HTTP 200 with the one-time plaintext key, or 400.
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		$businessId = trim((string)$this->request->getParam('businessId', ''));
		$administrationId = trim((string)$this->request->getParam('administrationId', ''));

		if ($businessId === '' || $administrationId === '') {
			return new JSONResponse(
				['success' => false, 'message' => 'businessId and administrationId are required.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->authService->createApiKey(
			administrationId: $administrationId,
			businessId: $businessId,
			actor: $this->actor()
		);
		$status = Http::STATUS_BAD_REQUEST;
		if ($result['success'] === true) {
			$status = Http::STATUS_OK;
		}

		return new JSONResponse($result, $status);
	}//end create()

	/**
	 * Rotate the API key for a business (REQ-WSW-009 §3).
	 *
	 * Replaces the active key and puts the predecessor into the 7-day grace
	 * window. Refuses when the business has no active key — minting the first
	 * one is {@see create()}, not this.
	 *
	 * Returns the plaintext key once; only its hash is persisted.
	 *
	 * @return JSONResponse HTTP 200 with the one-time plaintext key, or 400.
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function rotate(): JSONResponse {
		$businessId = trim((string)$this->request->getParam('businessId', ''));

		if ($businessId === '') {
			return new JSONResponse(['success' => false, 'message' => 'businessId is required.'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->authService->rotateApiKey(
			businessId: $businessId,
			actor: $this->actor()
		);
		$status = Http::STATUS_BAD_REQUEST;
		if ($result['success'] === true) {
			$status = Http::STATUS_OK;
		}

		return new JSONResponse($result, $status);
	}//end rotate()

	/**
	 * Revoke the API key for a business immediately (REQ-WSW-009 §5).
	 *
	 * @return JSONResponse HTTP 200 on success, or 400.
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function revoke(): JSONResponse {
		$businessId = trim((string)$this->request->getParam('businessId', ''));
		if ($businessId === '') {
			return new JSONResponse(['success' => false, 'message' => 'businessId is required.'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->authService->revokeApiKey(businessId: $businessId, actor: $this->actor());
		$status = Http::STATUS_BAD_REQUEST;
		if ($result['success'] === true) {
			$status = Http::STATUS_OK;
		}

		return new JSONResponse($result, $status);
	}//end revoke()
}//end class
