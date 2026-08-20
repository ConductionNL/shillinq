<?php

/**
 * Tax Payment Controller
 *
 * Tier-2 Vpb payment-reconciliation API (REQ-VPB-008). Exposes a single POST
 * endpoint that reconciles a TaxPaymentTracking record against the general
 * ledger and reports the variance. CRUD for payment records themselves is served
 * by OpenRegister's generic object API (the shillinq frontend object store
 * already targets /apps/openregister/api/objects); only the bespoke
 * reconciliation lives here. The endpoint is available to any authenticated user
 * (#[NoAdminRequired]); the administration scope is validated AND authorised here
 * against the caller's memberships (AdministrationContextService::canAccess(),
 * ADR-005 / REQ-MA-001).
 *
 * ⚠️ This paragraph used to end "reads are delegated to OpenRegister's
 * ObjectService, which enforces multitenancy / RBAC". It does not — no
 * administration term is passed in, and a schema with no `authorization` block
 * grants every action to every authenticated user. This endpoint also WRITES.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-35
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TaxPaymentReconciliationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * POST /api/tax-payments/{id}/reconcile — reconcile a payment against the GL.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-35
 */
class TaxPaymentController extends Controller {
	/**
	 * Constructor for the TaxPaymentController.
	 *
	 * @param IRequest $request The request object.
	 * @param TaxPaymentReconciliationService $reconciliation The reconciliation service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TaxPaymentReconciliationService $reconciliation,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Reconcile a payment record against the GL (REQ-VPB-008).
	 *
	 * Path parameter:
	 *  - id (string) slug or id of the TaxPaymentTracking record.
	 *
	 * Query/body parameter:
	 *  - administration_id (required) administration scope (REQ-VPB-003).
	 *
	 * Returns HTTP 200 with { matched, paymentAmount, glAmount, variance,
	 * glLineCount }; HTTP 400 on an invalid parameter; HTTP 500 (no stack trace)
	 * on a GL fetch failure.
	 *
	 * @param string $id The payment record identifier.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-35
	 */
	#[NoAdminRequired]
	public function reconcile(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$paymentId = trim($id);

		if ($administrationId === '' || preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(
				['error' => 'administration_id must be a valid administration identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($paymentId === '' || preg_match('/^[A-Za-z0-9_.\\-]{1,128}$/', $paymentId) !== 1) {
			return new JSONResponse(
				['error' => 'id must be a valid payment identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// ⚠️ Both checks above are character-class tests. The reconciliation
		// WRITES against the administration named on the wire, so the membership
		// check has to happen before it (ADR-005 / REQ-MA-001).
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->reconciliation->reconcile(
				administrationId: $administrationId,
				paymentId: $paymentId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'TaxPaymentController: failed to reconcile payment',
				[
					'administrationId' => $administrationId,
					'paymentId' => $paymentId,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to reconcile payment'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end reconcile()
}//end class
