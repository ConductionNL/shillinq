<?php

/**
 * Recognition Controller
 *
 * Thin read-only endpoint for the order-revenue-recognition chain. Exposes
 * GET /api/recognition/recurring-revenue which returns recognized RECURRING
 * revenue for one administration over a runtime period [from, to], the annualized
 * ARR run-rate, the currency and the count of folded recurring lines. The recurring
 * figure is IFRS 15 over-time recognition (whole-month proration); the one-off
 * figure is computed separately by the service and is intentionally NOT a top-level
 * field of this recurring endpoint.
 *
 * The endpoint is available to any authenticated user (#[NoAdminRequired]); it
 * rejects unauthenticated callers (401), validates administrationId + from/to before
 * the data layer (400), and authorises the administration against the caller's
 * memberships here (AdministrationContextService::canAccess(), ADR-005 Rule 3 /
 * no-admin-idor, REQ-MA-001).
 *
 * ⚠️ This paragraph used to end "delegates reads to OpenRegister's ObjectService,
 * which enforces per-administration multitenancy — so an authenticated user cannot
 * read another administration's orders". That was false: no administration term was
 * passed into OpenRegister, and a schema with no `authorization` block grants every
 * action to every authenticated user. Any authenticated user COULD read another
 * administration's orders, and the sentence is why nobody looked.
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
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\RevenueRecognitionService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET /api/recognition/recurring-revenue — recognized recurring revenue for a period.
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
 */
class RecognitionController extends Controller {
	/**
	 * Constructor for the RecognitionController.
	 *
	 * @param IRequest $request The request object.
	 * @param RevenueRecognitionService $recognitionService The recognition computation service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly RevenueRecognitionService $recognitionService,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Return recognized recurring revenue for an administration + period.
	 *
	 * Query parameters:
	 *  - administrationId (required) administration scope (ADR-005 IDOR-safety).
	 *  - from             (required) ISO period start (YYYY-MM-DD).
	 *  - to               (required) ISO period end (YYYY-MM-DD); from <= to.
	 *
	 * Returns HTTP 200 with { recognized, arr, currency, lineCount } on success;
	 * HTTP 401 when unauthenticated; HTTP 400 on a missing or malformed parameter;
	 * HTTP 500 (without a stack trace) on an unexpected failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function recurringRevenue(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		$from = trim((string)$this->request->getParam('from', ''));
		$to = trim((string)$this->request->getParam('to', ''));

		if ($administrationId === '') {
			return new JSONResponse(
				['error' => 'administrationId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Reject obviously malformed identifiers before touching the data layer
		// (ADR-005 input validation) — administration ids are short slugs.
		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(
				['error' => 'administrationId must be a valid administration identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// ⚠️ The regex above is INPUT VALIDATION, not authorisation — it was the
		// only thing standing between any authenticated user and another
		// tenant's recurring-revenue book. The membership check is what the
		// docblock's "ADR-005 IDOR-safety" always claimed (REQ-MA-001).
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from) !== 1) {
			return new JSONResponse(
				['error' => 'from must be an ISO date (YYYY-MM-DD)'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $to) !== 1) {
			return new JSONResponse(
				['error' => 'to must be an ISO date (YYYY-MM-DD)'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($from > $to) {
			return new JSONResponse(
				['error' => 'from must be on or before to'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->recognitionService->computeRecurring(
				administrationId: $administrationId,
				from: $from,
				to: $to
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RecognitionController: failed to compute recurring revenue',
				[
					'administrationId' => $administrationId,
					'from' => $from,
					'to' => $to,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to compute recurring revenue'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse(
			[
				'recognized' => $result['recognized'],
				'arr' => $result['arr'],
				'currency' => $result['currency'],
				'lineCount' => $result['lineCount'],
			],
			Http::STATUS_OK
		);

	}//end recurringRevenue()
}//end class
