<?php

/**
 * Soft Close Controller
 *
 * Tier-2 continuous-close API (REQ-CLS-002, REQ-CLS-005, REQ-CLS-007). Exposes
 * the on-demand soft-close trigger, the on-demand flux-analysis trigger, and
 * the flux-narrative export endpoint. Authentication is enforced via
 * #[NoAdminRequired]; per-administration scope is resolved server-side from
 * the request and writes are role-gated inside the underlying services /
 * lifecycle guards. Errors return a static client-safe message + the right
 * HTTP status — no stack trace ever reaches the client (ADR-005).
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\FluxService;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST surface for soft-close execution + flux analysis (REQ-CLS-002, REQ-CLS-007).
 *
 * The endpoints are authenticated (#[NoAdminRequired]) AND authorised per
 * administration in this controller, via
 * AdministrationContextService::canAccess() (ADR-005, REQ-MA-001).
 *
 * ⚠️ These two endpoints WRITE: a soft-close run posts accruals, FX, revenue
 * and lease entries into the administration's ledger. The administration id
 * arrives on the request and SoftCloseExecutor/FluxService treat it purely as a
 * scope term — they act on whichever administration they are handed. There is
 * no downstream backstop either: this app declares no `authorization` block on
 * its schemas, and OpenRegister treats an absent block as open to every
 * authenticated user (see the same note on VATReturnController). The membership
 * check below is the only thing that keeps one tenant's bookkeeping out of
 * another tenant's ledger.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class SoftCloseController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request object.
	 * @param SoftCloseExecutor $softCloseExecutor Orchestration service.
	 * @param FluxService $fluxService Flux analysis service.
	 * @param AdministrationContextServiceInterface $context Membership guard (REQ-MA-001).
	 * @param IUserSession $userSession Session for the acting user id.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 */
	public function __construct(
		IRequest $request,
		private readonly SoftCloseExecutor $softCloseExecutor,
		private readonly FluxService $fluxService,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * POST /api/v2/soft-close/{administrationId}/execute-now (REQ-CLS-002).
	 *
	 * On-demand soft-close trigger for the administratie. The bulk of work is
	 * delegated to SoftCloseExecutor; controller-level auth-guard rejects
	 * unauthenticated callers and validates the administration id slug.
	 *
	 * Body params:
	 *  - periodId (string, optional): yyyy-mm. Defaults to current month.
	 *
	 * @param string $administrationId Administration scope (path).
	 *
	 * @return JSONResponse 200 with the run report; 400 on bad input; 401 unauth.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
	 */
	#[NoAdminRequired]
	public function executeNow(string $administrationId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim($administrationId);
		$refusal = $this->administrationRefusal(administrationId: $administrationId, invalidMessage: 'administration_id must be a valid identifier');
		if ($refusal !== null) {
			return $refusal;
		}

		$periodId = $this->requestedPeriod();
		if (self::isPeriod(periodId: $periodId) === false) {
			return $this->periodRefusal();
		}

		return $this->softCloseRun(administrationId: $administrationId, periodId: $periodId);
	}//end executeNow()

	/**
	 * The requested periodId, trimmed; the current month when absent.
	 *
	 * @return string
	 */
	private function requestedPeriod(): string {
		$periodId = trim((string)$this->request->getParam('periodId', ''));
		if ($periodId === '') {
			return (new DateTimeImmutable())->format('Y-m');
		}

		return $periodId;
	}//end requestedPeriod()

	/**
	 * Run the soft close; a failed run answers 500 with its report.
	 *
	 * @param string $administrationId The authorised administration.
	 * @param string $periodId         The yyyy-mm period.
	 *
	 * @return JSONResponse
	 */
	private function softCloseRun(string $administrationId, string $periodId): JSONResponse {
		try {
			$report = $this->softCloseExecutor->execute(administrationId: $administrationId, periodId: $periodId, asOf: new DateTimeImmutable());
			$status = Http::STATUS_OK;
			if ($report['status'] === 'failed') {
				$status = Http::STATUS_INTERNAL_SERVER_ERROR;
			}

			return new JSONResponse(['data' => $report], $status);
		} catch (\Throwable $e) {
			return $this->serverError(action: 'executeNow', e: $e);
		}
	}//end softCloseRun()

	/**
	 * POST /api/v2/flux-runs/execute (REQ-CLS-005).
	 *
	 * On-demand flux analysis. The request body carries the run inputs
	 * (administrationId, periodId, scope, comparisonBasis, accounts array,
	 * materialityPolicy snapshot).
	 *
	 * @return JSONResponse 200 with the run summary; 400 on bad input; 401 unauth.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
	 */
	#[NoAdminRequired]
	public function executeFlux(): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		$refusal = $this->administrationRefusal(administrationId: $administrationId, invalidMessage: 'administration_id is required');
		if ($refusal !== null) {
			return $refusal;
		}

		$periodId = trim((string)$this->request->getParam('periodId', ''));
		if (self::isPeriod(periodId: $periodId) === false) {
			return $this->periodRefusal();
		}

		return $this->fluxRun(inputs: $this->fluxInputs(administrationId: $administrationId, periodId: $periodId));
	}//end executeFlux()

	/**
	 * The flux run inputs from the request, in the order FluxService::run() reads them.
	 *
	 * @param string $administrationId The authorised administration.
	 * @param string $periodId         The yyyy-mm period.
	 *
	 * @return array<string,mixed>
	 */
	private function fluxInputs(string $administrationId, string $periodId): array {
		$accounts = (array)$this->request->getParam('accounts', []);
		$policy = (array)$this->request->getParam('materialityPolicy', []);
		$scope = (string)$this->request->getParam('scope', 'administration');
		$basis = (string)$this->request->getParam('comparisonBasis', 'budget');

		return [
			'administrationId' => $administrationId,
			'periodId' => $periodId,
			'scope' => $scope,
			'comparisonBasis' => $basis,
			'accounts' => $accounts,
			'materialityPolicy' => $policy,
		];
	}//end fluxInputs()

	/**
	 * Run the flux analysis, stamped now.
	 *
	 * @param array<string,mixed> $inputs The run inputs.
	 *
	 * @return JSONResponse
	 */
	private function fluxRun(array $inputs): JSONResponse {
		try {
			$summary = $this->fluxService->run($inputs + ['runTimestamp' => new DateTimeImmutable()]);

			return new JSONResponse(['data' => $summary], Http::STATUS_OK);
		} catch (\Throwable $e) {
			return $this->serverError(action: 'executeFlux', e: $e);
		}
	}//end fluxRun()

	/**
	 * GET /api/v2/flux-runs/{fluxRunId}/narrative?format=pdf|markdown|json (REQ-CLS-007).
	 *
	 * For a known FluxRun, build the narrative + render to the requested format.
	 * The accounts payload for the narrative is gathered from the request body
	 * when present (the actual GL aggregation lives elsewhere); without items
	 * we render an empty narrative shell.
	 *
	 * @param string $fluxRunId The run id (path).
	 *
	 * @return DataResponse|JSONResponse Format-specific response.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-25
	 *
	 * @no-admin-idor-exempt Stateless renderer — the endpoint reads no storage, so the
	 *     $fluxRunId it is handed reaches no lookup that could be steered. The response is
	 *     built entirely from the caller's own request: buildNarrative($items, $periodId)
	 *     filters/sorts/sums the items[] array the caller posted and returns it, with no
	 *     ObjectService, mapper or app-config read in its body or in the three
	 *     renderNarrative* formatters. $fluxRunId is validated as a slug and then never
	 *     used, so substituting another tenant's run id changes nothing in the output.
	 *     ⚠️ THIS EXEMPTION IS CONDITIONAL ON THAT AND EXPIRES THE MOMENT THE RUN IS
	 *     ACTUALLY LOADED. Whoever wires the persisted FluxRun in must add the guard the
	 *     sibling methods already use — requireAccessibleAdministration() against the
	 *     run's administrationId (FluxRun declares one), 404 not 403 — before reading it.
	 *     Note also that no such lookup is possible today: FluxService::persistRun() saves
	 *     the FluxRun WITHOUT its generated run id and FluxRun declares no id property, so
	 *     the {fluxRunId} in the route addresses nothing that exists. That defect is
	 *     reported separately; it is why this endpoint is stateless rather than a design
	 *     choice to be relied on.
	 */
	#[NoAdminRequired]
	public function narrative(string $fluxRunId): DataResponse|JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$fluxRunId = trim($fluxRunId);
		if ($this->validId(value: $fluxRunId) === false) {
			return $this->error(message: 'flux_run_id must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
		}

		$format = strtolower((string)$this->request->getParam('format', 'json'));

		try {
			return $this->renderedNarrative(format: $format, narrative: $this->builtNarrative());
		} catch (\Throwable $e) {
			return $this->serverError(action: 'narrative', e: $e);
		}
	}//end narrative()

	/**
	 * The narrative built from the posted items and periodId (the request is the only input).
	 *
	 * @return array<string,mixed>
	 */
	private function builtNarrative(): array {
		$items = (array)$this->request->getParam('items', []);
		$periodId = trim((string)$this->request->getParam('periodId', ''));

		return $this->fluxService->buildNarrative(items: $items, periodId: $periodId);
	}//end builtNarrative()

	/**
	 * The narrative in the requested format: json, markdown or pdf; any other format is a 400.
	 *
	 * @param string              $format    The lower-cased format.
	 * @param array<string,mixed> $narrative The built narrative.
	 *
	 * @return DataResponse|JSONResponse
	 */
	private function renderedNarrative(string $format, array $narrative): DataResponse|JSONResponse {
		return match ($format) {
			'json' => new JSONResponse(['data' => $narrative], Http::STATUS_OK),
			'markdown' => self::document(body: $this->fluxService->renderNarrativeMarkdown(narrative: $narrative), contentType: 'text/markdown; charset=utf-8'),
			'pdf' => self::document(body: $this->fluxService->renderNarrativePdfBody(narrative: $narrative), contentType: 'application/pdf'),
			default => $this->error(message: 'format must be json, markdown or pdf', status: Http::STATUS_BAD_REQUEST),
		};
	}//end renderedNarrative()

	/**
	 * A 200 document response of the given content type.
	 *
	 * @param string $body        The rendered document.
	 * @param string $contentType Its Content-Type.
	 *
	 * @return DataResponse
	 */
	private static function document(string $body, string $contentType): DataResponse {
		return new DataResponse($body, Http::STATUS_OK, ['Content-Type' => $contentType]);
	}//end document()

	/**
	 * Authorization body-guard: the in-body counterpart to #[NoAdminRequired].
	 *
	 * @return bool True when authenticated, false otherwise.
	 */
	private function requireUser(): bool {
		return $this->userSession->getUser() !== null;
	}//end requireUser()

	/**
	 * Validate a short slug identifier.
	 *
	 * @param string $value The value to validate.
	 *
	 * @return bool True when the value is a safe short identifier.
	 */
	private function validId(string $value): bool {
		return $value !== '' && preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $value) === 1;
	}//end validId()

	/**
	 * Whether a period is yyyy-mm.
	 *
	 * @param string $periodId The period.
	 *
	 * @return bool
	 */
	private static function isPeriod(string $periodId): bool {
		return preg_match('/^\d{4}-\d{2}$/', $periodId) === 1;
	}//end isPeriod()

	/**
	 * The 400 for a period that is not yyyy-mm.
	 *
	 * @return JSONResponse
	 */
	private function periodRefusal(): JSONResponse {
		return $this->error(message: 'period_id must match yyyy-mm', status: Http::STATUS_BAD_REQUEST);
	}//end periodRefusal()

	/**
	 * The 400 for a malformed administration id, then the membership answer.
	 *
	 * @param string $administrationId The trimmed administration id.
	 * @param string $invalidMessage   The 400 message for this action.
	 *
	 * @return JSONResponse|null The refusal, or null for a member.
	 */
	private function administrationRefusal(string $administrationId, string $invalidMessage): ?JSONResponse {
		if ($this->validId(value: $administrationId) === false) {
			return $this->error(message: $invalidMessage, status: Http::STATUS_BAD_REQUEST);
		}

		return $this->requireAccessibleAdministration(administrationId: $administrationId);
	}//end administrationRefusal()

	/**
	 * Refuse the request unless the caller holds a membership for the
	 * administration it named (ADR-005 / REQ-MA-001).
	 *
	 * 404, never 403 — AdministrationContextService::canAccess()'s own
	 * documented contract. A 403 would confirm the administration exists and
	 * turn these endpoints into an enumeration oracle for the tenant list.
	 *
	 * @param string $administrationId The format-checked administration id.
	 *
	 * @return JSONResponse|null A refusal to return to the client, or null when authorised.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function requireAccessibleAdministration(string $administrationId): ?JSONResponse {
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->error(message: 'Administration not found', status: Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end requireAccessibleAdministration()

	/**
	 * Build a client-safe error response.
	 *
	 * @param string $message The static, client-safe message.
	 * @param int $status The HTTP status code.
	 *
	 * @return JSONResponse The error response.
	 */
	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}//end error()

	/**
	 * Log an unexpected error server-side and return a generic 500 (no stack trace).
	 *
	 * @param string $action The controller action for the log entry.
	 * @param \Throwable $e The caught throwable.
	 *
	 * @return JSONResponse A generic 500 response.
	 */
	private function serverError(string $action, \Throwable $e): JSONResponse {
		$this->logger->error(
			'SoftCloseController: ' . $action . ' failed',
			['exception' => $e->getMessage()]
		);

		return new JSONResponse(['error' => 'An unexpected error occurred'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end serverError()
}//end class
