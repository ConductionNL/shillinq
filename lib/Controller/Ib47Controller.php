<?php

/**
 * Ib47Controller
 *
 * Prepares the annual IB47 batch of an administration.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\Ib47BatchServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Prepare action of the IB47 annual batch page.
 *
 * WHO MAY. The batch reads every payee's BSN and writes the statutory
 * records, so preparing it is the payroll officer's act: an instance
 * administrator or a member of `shillinq_payroll_officer`, the same group
 * the IB47 notifications go to. Anyone else gets 403.
 *
 * WHICH ADMINISTRATION. The caller names it, and must hold a membership of
 * it; one they do not hold answers 404, never 403, so the endpoint does not
 * confirm that an administration exists (REQ-MA-001, the same contract as
 * PeriodCloseController).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */
class Ib47Controller extends Controller {

	/**
	 * The group whose members prepare the IB47 batch.
	 */
	public const PAYROLL_OFFICER_GROUP = 'shillinq_payroll_officer';

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request     The request.
	 * @param Ib47BatchServiceInterface             $batches     The IB47 batch service.
	 * @param AdministrationContextServiceInterface $context     Membership guard (REQ-MA-001).
	 * @param IUserSession                          $userSession The signed-in user.
	 * @param IGroupManager                         $groups      Group membership, for the role check.
	 */
	public function __construct(
		IRequest $request,
		private readonly Ib47BatchServiceInterface $batches,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Prepare the IB47 batch of a tax year for an administration.
	 *
	 * Both are optional, so the batch page's Prepare button needs no form:
	 * `taxYear` defaults to last year, the year an IB47 statement is filed
	 * for in January, and `administration_id` to the caller's active
	 * administration.
	 *
	 * @return JSONResponse 200 with the prepared and incomplete recipients; 400 without a
	 *                      usable administration or year; 401 signed out; 403 for a caller
	 *                      who is not a payroll officer; 404 for an administration the caller
	 *                      holds no membership of.
	 *
	 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md#requirement-the-annual-ib47-batch-is-prepared-from-the-payments-to-third-parties-req-dpa-021
	 */
	#[NoAdminRequired]
	public function prepare(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		if ($this->groups->isAdmin($userId) === false
			&& $this->groups->isInGroup($userId, self::PAYROLL_OFFICER_GROUP) === false
		) {
			return new JSONResponse(['error' => 'Preparing the IB47 batch needs the payroll officer role'], Http::STATUS_FORBIDDEN);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		if ($administrationId === '') {
			$administrationId = (string)($this->context->defaultAdministrationIdForUser(userId: $userId) ?? '');
		}

		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(['error' => 'Name the administration'], Http::STATUS_BAD_REQUEST);
		}

		$year = trim((string)$this->request->getParam('taxYear', ''));
		if ($year === '') {
			$year = (string)((int)date('Y') - 1);
		}

		if (preg_match('/^[0-9]{4}$/', $year) !== 1) {
			return new JSONResponse(['error' => 'Name the tax year as four digits'], Http::STATUS_BAD_REQUEST);
		}

		$taxYear = (int)$year;

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$batch = $this->batches->prepare(administrationId: $administrationId, taxYear: $taxYear);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($batch);
	}//end prepare()
}//end class
