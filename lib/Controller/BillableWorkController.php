<?php

/**
 * BillableWorkController
 *
 * `GET /api/v1/invoices/billable-work`: the unbilled hours and pass-through
 * expenses the Generate invoice page lets a user tick.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BillableWorkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the work a customer can still be invoiced for (design D1).
 *
 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/tasks.md#task-2.2
 */
class BillableWorkController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request The request.
	 * @param AdministrationContextServiceInterface $context The caller's administrations.
	 * @param BillableWorkServiceInterface          $work    The unbilled work.
	 * @param LoggerInterface                       $logger  Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextServiceInterface $context,
		private readonly BillableWorkServiceInterface $work,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The customer's unbilled hours on a project and unbilled pass-through expenses.
	 *
	 * The administration is checked first: the caller's active one, or the one
	 * the request names when the caller may access it. Another administration
	 * answers a masked 404, never its work.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/specs/invoice-from-time-and-expense/spec.md#requirement-the-generate-page-lists-unbilled-hours-to-tick-req-steb-001
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$administrationId = $this->administration();
			if ($administrationId === '') {
				return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(
				$this->work->billableWork(
					administrationId: $administrationId,
					customerId: $this->param(name: 'customerId'),
					projectId: $this->param(name: 'projectId'),
					from: $this->param(name: 'from'),
					to: $this->param(name: 'to'),
					rateCardId: $this->param(name: 'rateCardId')
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('BillableWorkController: failed to list billable work', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to list billable work'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * The administration to read: the one named, if the caller may access it,
	 * else the caller's active one; '' when there is none.
	 *
	 * @return string
	 */
	private function administration(): string {
		$named = $this->param(name: 'administrationId');
		if ($named !== '') {
			if ($this->context->canAccess(administrationId: $named) === true) {
				return $named;
			}

			return '';
		}

		return (string)($this->context->buildContext()['activeAdministrationId'] ?? '');
	}//end administration()

	/**
	 * A trimmed string request parameter.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function param(string $name): string {
		return trim((string)$this->request->getParam($name, ''));
	}//end param()
}//end class
