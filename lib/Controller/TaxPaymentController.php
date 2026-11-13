<?php

/**
 * Tax Payment Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
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
	 * @param AdministrationContextServiceInterface $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TaxPaymentReconciliationService $reconciliation,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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

		$refusal = self::administrationRefusal(administrationId: $administrationId)
			?? self::paymentRefusal(paymentId: $paymentId)
			?? $this->accessRefusal(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->reconciled(administrationId: $administrationId, paymentId: $paymentId);
	}//end reconcile()

	/**
	 * The 400 for a blank or malformed administration id.
	 *
	 * @param string $administrationId The trimmed administration_id.
	 *
	 * @return JSONResponse|null The refusal, or null when it is a short slug.
	 */
	private static function administrationRefusal(string $administrationId): ?JSONResponse {
		if ($administrationId === '' || preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(
				['error' => 'administration_id must be a valid administration identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The 400 for a blank or malformed payment id.
	 *
	 * @param string $paymentId The trimmed path id.
	 *
	 * @return JSONResponse|null The refusal, or null when it is a slug of up to 128 characters.
	 */
	private static function paymentRefusal(string $paymentId): ?JSONResponse {
		if ($paymentId === '' || preg_match('/^[A-Za-z0-9_.\\-]{1,128}$/', $paymentId) !== 1) {
			return new JSONResponse(['error' => 'id must be a valid payment identifier'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end paymentRefusal()

	/**
	 * The masked 404 unless the caller is a member of the administration.
	 *
	 * ⚠️ The input checks are character-class tests. The reconciliation WRITES
	 * against the administration named on the wire, so the membership check has
	 * to happen before it (ADR-005 / REQ-MA-001).
	 *
	 * @param string $administrationId The well-formed administration id.
	 *
	 * @return JSONResponse|null The refusal, or null for a member.
	 */
	private function accessRefusal(string $administrationId): ?JSONResponse {
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end accessRefusal()

	/**
	 * Run the reconciliation; a failure is logged and answered 500 without detail.
	 *
	 * @param string $administrationId The authorised administration id.
	 * @param string $paymentId        The well-formed payment id.
	 *
	 * @return JSONResponse
	 */
	private function reconciled(string $administrationId, string $paymentId): JSONResponse {
		try {
			$result = $this->reconciliation->reconcile(administrationId: $administrationId, paymentId: $paymentId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'TaxPaymentController: failed to reconcile payment',
				['administrationId' => $administrationId, 'paymentId' => $paymentId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to reconcile payment'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end reconciled()
}//end class
