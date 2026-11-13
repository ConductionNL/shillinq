<?php

/**
 * Subject Cost Controller
 *
 * Read-only API putting the subject-cost-aggregation capability within reach
 * of a caller: GET /api/subject-cost returns the employer cost of the hours
 * booked against one domain object.
 *
 * Per hydra ADR-081 the domain app classifies and displays while Shillinq
 * aggregates, because Shillinq owns the ledger. Before this controller the
 * Shillinq half of that split had no door: SubjectCostAggregator and
 * HrmqCostRateAdapter were implemented, spec-tagged and unit-tested, and no
 * route, listener or service reached either of them.
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
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\SubjectCostService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET /api/subject-cost.
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
 */
class SubjectCostController extends Controller {
	/**
	 * Constructor for the SubjectCostController.
	 *
	 * @param IRequest $request The request object.
	 * @param SubjectCostService $costs Hours-to-cost composition for one subject.
	 * @param AdministrationContextServiceInterface $context Authenticated-user context (ADR-005).
	 * @param IUserSession $userSession Session, for the Nextcloud-admin bypass.
	 * @param IGroupManager $groupManager Nextcloud admin bypass for the administration guard.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SubjectCostService $costs,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The employer cost of the hours booked against one domain object.
	 *
	 * Query parameters:
	 *  - subjectApp (required) the owning app, e.g. `dossiq`.
	 *  - subjectId  (required) the domain object id.
	 *  - administrationId (optional) narrow to one administration the caller
	 *    can reach; omitted, every administration the caller can reach counts.
	 *  - period (optional) costing period `YYYY-MM`.
	 *
	 * Returns HTTP 200 with { subjectApp, subjectId, hours, costCents,
	 * complete, currency, perPerson, unpricedPersonIds, unscopedRowsExcluded };
	 * 400 on a missing subject or a malformed period; 401 unauthenticated;
	 * 403 when the caller belongs to no administration; 404 when it names an
	 * administration it cannot reach; 500 without a stack trace on an
	 * unexpected failure.
	 *
	 * A Nextcloud admin sees every administration, matching
	 * `CBSSubmissionController::requireAdministrationAccess()` and
	 * `BookingNotificationController::authorizeBookingAccess()`. Back-office
	 * admins hold no `AdministrationMembership` of their own, so without the
	 * bypass the admin surface answers 403 to its own operator.
	 *
	 * A 200 does NOT promise a number. `complete: false` with `costCents: null`
	 * is the documented answer when any person's rate could not be resolved,
	 * and `unpricedPersonIds` names them. A caller renders "hours known, cost
	 * unavailable" rather than a total that is always lower than the truth.
	 *
	 * @return JSONResponse The subject cost payload.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(
				['error' => 'Not authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$query = new SubjectCostQuery(request: $this->request);
		$refusal = $this->queryRefusal(query: $query);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->scopedCost(query: $query);
	}//end index()

	/**
	 * The 400 for a missing subject or a malformed period.
	 *
	 * @param SubjectCostQuery $query The query.
	 *
	 * @return JSONResponse|null The refusal, or null when the query is well-formed.
	 */
	private function queryRefusal(SubjectCostQuery $query): ?JSONResponse {
		if ($query->hasSubject() === false) {
			return new JSONResponse(
				['error' => 'subjectApp and subjectId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($query->periodWellFormed() === false) {
			return new JSONResponse(
				['error' => 'period must be YYYY-MM'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end queryRefusal()

	/**
	 * Check the named administration, resolve the readable scope and cost the subject in it.
	 *
	 * @param SubjectCostQuery $query The well-formed query.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	private function scopedCost(SubjectCostQuery $query): JSONResponse {
		$isAdmin = $this->isNextcloudAdmin();

		$refusal = $this->administrationRefusal(query: $query, isAdmin: $isAdmin);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->costWithin(query: $query, scope: $this->scope(named: $query->administrationId, isAdmin: $isAdmin));
	}//end scopedCost()

	/**
	 * The masked 404 when a non-admin names an administration they cannot reach.
	 *
	 * @param SubjectCostQuery $query   The query.
	 * @param bool             $isAdmin Whether the caller is a Nextcloud admin.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	private function administrationRefusal(SubjectCostQuery $query, bool $isAdmin): ?JSONResponse {
		$named = $query->administrationId;
		if ($named !== '' && $isAdmin === false && $this->context->canAccess($named) === false) {
			// Masked as 404, not 403. AdministrationContextService::canAccess()
			// states the house IDOR rule (REQ-MA-001): a resource the caller
			// may not reach is indistinguishable from one that is not there.
			return new JSONResponse(
				['error' => 'Not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The 403 when the caller may read no administration, else the cost.
	 *
	 * @param SubjectCostQuery       $query The query.
	 * @param array<int,string>|null $scope The readable administrations (null for unrestricted).
	 *
	 * @return JSONResponse
	 */
	private function costWithin(SubjectCostQuery $query, ?array $scope): JSONResponse {
		if ($scope === []) {
			return new JSONResponse(
				['error' => 'No accessible administration'],
				Http::STATUS_FORBIDDEN
			);
		}

		return $this->computedCost(query: $query, scope: $scope);
	}//end costWithin()

	/**
	 * Cost the subject within the readable administrations.
	 *
	 * @param SubjectCostQuery       $query The query.
	 * @param array<int,string>|null $scope The readable administrations (null for unrestricted).
	 *
	 * @return JSONResponse 200 with the cost payload, or a logged 500.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	private function computedCost(SubjectCostQuery $query, ?array $scope): JSONResponse {
		try {
			$result = $this->costs->costFor(
				subjectApp: $query->subjectApp,
				subjectId: $query->subjectId,
				administrationIds: $scope,
				period: $query->period
			);
		} catch (\Throwable $e) {
			return $this->costFailed(query: $query, e: $e);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end computedCost()

	/**
	 * Log a failed cost computation and answer 500 without a stack trace.
	 *
	 * @param SubjectCostQuery $query The query.
	 * @param \Throwable       $e     The failure.
	 *
	 * @return JSONResponse
	 */
	private function costFailed(SubjectCostQuery $query, \Throwable $e): JSONResponse {
		$this->logger->error(
			'SubjectCostController: failed to compute a subject cost',
			[
				'subjectApp' => $query->subjectApp,
				'subjectId' => $query->subjectId,
				'exception' => $e->getMessage(),
			]
		);

		return new JSONResponse(
			['error' => 'Failed to compute the subject cost'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end costFailed()

	/**
	 * The administrations this request may read.
	 *
	 * A named administration narrows the scope to itself rather than widening
	 * to everything the caller can reach: the two answers differ, and the
	 * caller asked for one of them. Reachability is checked before this runs.
	 *
	 * @param string $named The requested administration, or '' for all reachable.
	 * @param bool $isAdmin Whether the caller is a Nextcloud admin.
	 *
	 * @return array<int, string>|null The scope, null for unrestricted, empty
	 *     when the caller may read none.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	private function scope(string $named, bool $isAdmin): ?array {
		if ($named !== '') {
			return [$named];
		}

		if ($isAdmin === true) {
			return null;
		}

		return array_values($this->context->accessibleAdministrationIds());
	}//end scope()

	/**
	 * Whether the caller is a Nextcloud admin.
	 *
	 * @return bool True for an admin.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	private function isNextcloudAdmin(): bool {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return false;
		}

		return $this->groupManager->isAdmin($uid);
	}//end isNextcloudAdmin()
}//end class
