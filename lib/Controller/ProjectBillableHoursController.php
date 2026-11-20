<?php

/**
 * Project Billable Hours Controller
 *
 * GET /api/v1/projects/{id}/billable-hours?month=YYYY-MM: one month of a
 * project's hours with their rate, tier, value and billed state, and the
 * unbilled totals, for the Billable hours card on the project page
 * (sales-billable-rate-per-hour REQ-BRPH-003).
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
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Billing\ProjectBillableHours;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves a project's rated hours of one month.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
 */
class ProjectBillableHoursController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request               The request.
	 * @param ProjectBillableHours                  $billableHours         Rates the project's hours.
	 * @param IUserSession                          $session               The user session.
	 * @param AdministrationContextServiceInterface $administrationContext Server-resolved administration scope.
	 * @param ITimeFactory                          $timeFactory           The clock, for the default month.
	 * @param LoggerInterface                       $logger                Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ProjectBillableHours $billableHours,
		private readonly IUserSession $session,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One month of a project's rated hours (GET /api/v1/projects/{id}/billable-hours).
	 *
	 * Authorization: the project must belong to the caller's active
	 * administration, resolved on the server from the session; that check runs
	 * before any hour is read, and a project of another administration answers
	 * 404 like an unknown one.
	 *
	 * @param string $id The project.
	 *
	 * @return JSONResponse 200 with `available`, `reason`, `month`, `from`, `to`, `rows` and `totals`;
	 *                      401 anonymous, 403 without an administration, 404 unknown project, 422 bad month.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->month(projectId: $id);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'error' => 'billable-hours-invalid-month'], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('ProjectBillableHoursController.index failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * The month's rated hours, after the administration check on the project.
	 *
	 * @param string $projectId The project.
	 *
	 * @return JSONResponse
	 *
	 * @throws InvalidArgumentException When the month is not `YYYY-MM`.
	 */
	private function month(string $projectId): JSONResponse {
		$administrationId = (string)($this->administrationContext->buildContext()['activeAdministrationId'] ?? '');
		if ($administrationId === '') {
			return new JSONResponse(
				['message' => 'You are not a member of any administration.', 'error' => 'billable-hours-no-administration'],
				Http::STATUS_FORBIDDEN
			);
		}

		$project = $this->billableHours->project(projectId: $projectId, administrationId: $administrationId);
		if ($project === null) {
			return new JSONResponse(['message' => 'Project not found.', 'error' => 'billable-hours-project-not-found'], Http::STATUS_NOT_FOUND);
		}

		$month = trim((string)$this->request->getParam(key: 'month', default: ''));
		if ($month === '') {
			$month = $this->timeFactory->getDateTime()->format('Y-m');
		}

		return new JSONResponse($this->billableHours->forMonth(project: $project, month: $month), Http::STATUS_OK);
	}//end month()
}//end class
