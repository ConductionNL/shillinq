<?php

/**
 * Deadline Calendar Settings Controller
 *
 * Per-user REST surface for the compliance-deadline-calendar category
 * toggles + reminder lead times (REQ-CDC-006). Strictly current-user
 * scoped: the acting user is ALWAYS resolved from the session and no
 * user id is accepted from the request, so cross-user access (IDOR) is
 * structurally impossible (ADR-005).
 *
 * Saving triggers a fail-soft re-publication for the current user so a
 * disabled category's VEVENTs are removed immediately (REQ-CDC-006
 * "disabling a category MUST remove that category's VEVENTs") instead
 * of waiting for the next daily job tick.
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ComplianceDeadlineCalendarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Per-user deadline-calendar category toggles (REQ-CDC-006).
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
class DeadlineCalendarSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param IUserSession $userSession The user session (current-user scoping).
	 * @param ComplianceDeadlineCalendarService $calendarService The toggle store + publisher.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ComplianceDeadlineCalendarService $calendarService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET the current user's deadline-calendar settings.
	 *
	 * Returns per category: enabled (bool) + leadDays (int). Reads ONLY
	 * the session user's preferences — no user id parameter exists.
	 *
	 * @return JSONResponse `{categories: {filing: {enabled, leadDays}, …}}`.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['message' => 'Not logged in'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		// Security-endpoint-guards REQ-001 JUSTIFY: $userId is resolved from
		// the session ONLY (see class docblock) — no user id is ever accepted
		// from the request, so there is no other tenant's object this
		// endpoint could be pointed at. Cross-user access is structurally
		// impossible, not merely checked.
		$userId = $user->getUID();
		$categories = [];
		foreach (ComplianceDeadlineCalendarService::CATEGORIES as $category) {
			$categories[$category] = [
				'enabled' => $this->calendarService->isCategoryEnabled(userId: $userId, category: $category),
				'leadDays' => $this->calendarService->leadTimeDays(userId: $userId, category: $category),
			];
		}

		return new JSONResponse(data: ['categories' => $categories]);
	}//end index()

	/**
	 * POST the current user's deadline-calendar settings.
	 *
	 * Accepts a `categories` map ({category: {enabled, leadDays}}) and
	 * writes ONLY the session user's preferences. Unknown categories are
	 * ignored. After saving, re-publishes the user's calendar fail-soft
	 * so disabled categories' VEVENTs are removed immediately.
	 *
	 * @return JSONResponse The saved settings (same shape as index()).
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['message' => 'Not logged in'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		// Security-endpoint-guards REQ-001 JUSTIFY: writes ONLY the session
		// user's own preferences — no user id parameter exists on this
		// endpoint (see class docblock), so cross-user mutation (IDOR) is
		// structurally impossible rather than merely guarded.
		$userId = $user->getUID();
		$categories = $this->request->getParam('categories');
		if (is_array($categories) === false) {
			return new JSONResponse(
				data: ['message' => 'categories must be an object'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		foreach ($categories as $category => $settings) {
			if (is_string($category) === false || is_array($settings) === false) {
				continue;
			}

			if (in_array($category, ComplianceDeadlineCalendarService::CATEGORIES, true) === false) {
				continue;
			}

			if (array_key_exists('enabled', $settings) === true) {
				$this->calendarService->setCategoryEnabled(
					userId: $userId,
					category: $category,
					enabled: (bool)$settings['enabled']
				);
			}

			if (array_key_exists('leadDays', $settings) === true) {
				$this->calendarService->setLeadTimeDays(
					userId: $userId,
					category: $category,
					days: (int)$settings['leadDays']
				);
			}
		}//end foreach

		// Apply immediately: removes VEVENTs of freshly-disabled
		// categories (REQ-CDC-006). Fail-soft — a calendar-less
		// instance still saves the toggles.
		$publication = $this->calendarService->publishForUser(userId: $userId);
		if ($publication['status'] !== 'ok') {
			$this->logger->info(
				'DeadlineCalendarSettingsController: post-save publication degraded',
				['userId' => $userId]
			);
		}

		$saved = [];
		foreach (ComplianceDeadlineCalendarService::CATEGORIES as $category) {
			$saved[$category] = [
				'enabled' => $this->calendarService->isCategoryEnabled(userId: $userId, category: $category),
				'leadDays' => $this->calendarService->leadTimeDays(userId: $userId, category: $category),
			];
		}

		return new JSONResponse(
			data: [
				'categories' => $saved,
				'publication' => $publication['status'],
			]
		);

	}//end update()
}//end class
