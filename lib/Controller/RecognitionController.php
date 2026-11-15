<?php

/**
 * Recognition Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RevenueRecognitionServiceInterface;
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
	 * @param RevenueRecognitionServiceInterface $recognitionService The recognition computation service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param AdministrationContextServiceInterface $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly RevenueRecognitionServiceInterface $recognitionService,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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

		$window = new RevenueWindow(request: $this->request);
		$refusal = $this->administrationRefusal(window: $window) ?? $this->periodRefusal(window: $window);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->projected(window: $window);

	}//end recurringRevenue()

	/**
	 * The 400 for a missing or malformed administration, or the masked 404
	 * when the caller may not access it.
	 *
	 * Reject obviously malformed identifiers before touching the data layer
	 * (ADR-005 input validation) — administration ids are short slugs.
	 * ⚠️ That check is INPUT VALIDATION, not authorisation — it was the
	 * only thing standing between any authenticated user and another
	 * tenant's recurring-revenue book. The membership check is what the
	 * docblock's "ADR-005 IDOR-safety" always claimed (REQ-MA-001).
	 *
	 * @param RevenueWindow $window The request.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
	 */
	private function administrationRefusal(RevenueWindow $window): ?JSONResponse {
		if ($window->administrationId === '') {
			return $this->badRequest(error: 'administrationId is required');
		}

		if ($window->wellFormedAdministration() === false) {
			return $this->badRequest(error: 'administrationId must be a valid administration identifier');
		}

		if ($this->context->canAccess(administrationId: $window->administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The 400 unless from and to are ISO dates with from on or before to.
	 *
	 * @param RevenueWindow $window The request.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function periodRefusal(RevenueWindow $window): ?JSONResponse {
		if (RevenueWindow::isIsoDate(date: $window->from) === false) {
			return $this->badRequest(error: 'from must be an ISO date (YYYY-MM-DD)');
		}

		if (RevenueWindow::isIsoDate(date: $window->to) === false) {
			return $this->badRequest(error: 'to must be an ISO date (YYYY-MM-DD)');
		}

		if ($window->from > $window->to) {
			return $this->badRequest(error: 'from must be on or before to');
		}

		return null;
	}//end periodRefusal()

	/**
	 * A 400 with an error.
	 *
	 * @param string $error The error.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $error): JSONResponse {
		return new JSONResponse(
			['error' => $error],
			Http::STATUS_BAD_REQUEST
		);
	}//end badRequest()

	/**
	 * Compute the recurring revenue of the window; a failure is a logged 500.
	 *
	 * @param RevenueWindow $window The validated request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-2
	 */
	private function projected(RevenueWindow $window): JSONResponse {
		try {
			$result = $this->recognitionService->computeRecurring(
				administrationId: $window->administrationId,
				from: $window->from,
				to: $window->to
			);
		} catch (\Throwable $e) {
			return $this->projectionFailed(window: $window, e: $e);
		}

		return $this->projectionResponse(result: $result);
	}//end projected()

	/**
	 * The 200 answer with the recognized amount, the ARR, the currency and the line count.
	 *
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return JSONResponse
	 */
	private function projectionResponse(array $result): JSONResponse {
		return new JSONResponse(
			[
				'recognized' => $result['recognized'],
				'arr' => $result['arr'],
				'currency' => $result['currency'],
				'lineCount' => $result['lineCount'],
			],
			Http::STATUS_OK
		);
	}//end projectionResponse()

	/**
	 * Log a failed projection and answer 500 without a stack trace.
	 *
	 * @param RevenueWindow $window The request.
	 * @param \Throwable    $e      The failure.
	 *
	 * @return JSONResponse
	 */
	private function projectionFailed(RevenueWindow $window, \Throwable $e): JSONResponse {
		$this->logger->error(
			'RecognitionController: failed to compute recurring revenue',
			[
				'administrationId' => $window->administrationId,
				'from' => $window->from,
				'to' => $window->to,
				'exception' => $e->getMessage(),
			]
		);

		return new JSONResponse(
			['error' => 'Failed to compute recurring revenue'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end projectionFailed()
}//end class
