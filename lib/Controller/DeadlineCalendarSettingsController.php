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

use OCA\Shillinq\AppInfo\AppIdentity;
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
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
		return new JSONResponse(data: ['categories' => $this->currentSettings(userId: $userId)]);
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
		return $this->updateFor(userId: $user->getUID());

	}//end update()

	/**
	 * Save the submitted categories for the user, re-publish and answer the saved settings.
	 *
	 * @param string $userId The session user.
	 *
	 * @return JSONResponse 200 with the saved settings and the publication status; 400 on a malformed payload.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	private function updateFor(string $userId): JSONResponse {
		$categories = $this->request->getParam('categories');
		if (is_array($categories) === false) {
			return new JSONResponse(
				data: ['message' => 'categories must be an object'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$this->saveCategories(userId: $userId, categories: $categories);

		return $this->savedResponse(userId: $userId, publication: $this->publishAfterSave(userId: $userId));
	}//end updateFor()

	/**
	 * Write the known, well-formed categories of the payload, in order.
	 * Unknown categories and malformed entries are ignored.
	 *
	 * @param string                    $userId     The session user.
	 * @param array<int|string, mixed>  $categories The submitted categories map.
	 *
	 * @return void
	 */
	private function saveCategories(string $userId, array $categories): void {
		foreach ($categories as $category => $settings) {
			$change = DeadlineCategoryChange::fromEntry(category: $category, settings: $settings);
			if ($change !== null) {
				$this->saveCategory(userId: $userId, change: $change);
			}
		}
	}//end saveCategories()

	/**
	 * Write one category's submitted toggle, then its lead time.
	 *
	 * @param string                 $userId The session user.
	 * @param DeadlineCategoryChange $change The category's submitted settings.
	 *
	 * @return void
	 */
	private function saveCategory(string $userId, DeadlineCategoryChange $change): void {
		$this->saveToggle(userId: $userId, change: $change);
		$this->saveLeadTime(userId: $userId, change: $change);
	}//end saveCategory()

	/**
	 * Write the category's toggle when one was submitted.
	 *
	 * @param string                 $userId The session user.
	 * @param DeadlineCategoryChange $change The category's submitted settings.
	 *
	 * @return void
	 */
	private function saveToggle(string $userId, DeadlineCategoryChange $change): void {
		if ($change->enabled !== null) {
			$this->calendarService->setCategoryEnabled(
				userId: $userId,
				category: $change->category,
				enabled: $change->enabled
			);
		}
	}//end saveToggle()

	/**
	 * Write the category's lead time when one was submitted.
	 *
	 * @param string                 $userId The session user.
	 * @param DeadlineCategoryChange $change The category's submitted settings.
	 *
	 * @return void
	 */
	private function saveLeadTime(string $userId, DeadlineCategoryChange $change): void {
		if ($change->leadDays !== null) {
			$this->calendarService->setLeadTimeDays(
				userId: $userId,
				category: $change->category,
				days: $change->leadDays
			);
		}
	}//end saveLeadTime()

	/**
	 * Apply immediately: removes VEVENTs of freshly-disabled categories
	 * (REQ-CDC-006). Fail-soft — a calendar-less instance still saves the
	 * toggles; a degraded publication is logged.
	 *
	 * @param string $userId The session user.
	 *
	 * @return string The publication status.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	private function publishAfterSave(string $userId): string {
		$publication = $this->calendarService->publishForUser(userId: $userId);
		if ($publication['status'] !== 'ok') {
			$this->logger->info(
				'DeadlineCalendarSettingsController: post-save publication degraded',
				['userId' => $userId]
			);
		}

		return $publication['status'];
	}//end publishAfterSave()

	/**
	 * The saved settings with the publication status.
	 *
	 * @param string $userId      The session user.
	 * @param string $publication The publication status.
	 *
	 * @return JSONResponse
	 */
	private function savedResponse(string $userId, string $publication): JSONResponse {
		return new JSONResponse(
			data: [
				'categories' => $this->currentSettings(userId: $userId),
				'publication' => $publication,
			]
		);
	}//end savedResponse()

	/**
	 * The user's settings per category: enabled and leadDays.
	 *
	 * @param string $userId The session user.
	 *
	 * @return array<string, array{enabled: bool, leadDays: int}>
	 */
	private function currentSettings(string $userId): array {
		$categories = [];
		foreach (ComplianceDeadlineCalendarService::CATEGORIES as $category) {
			$categories[$category] = [
				'enabled' => $this->calendarService->isCategoryEnabled(userId: $userId, category: $category),
				'leadDays' => $this->calendarService->leadTimeDays(userId: $userId, category: $category),
			];
		}

		return $categories;
	}//end currentSettings()
}//end class
