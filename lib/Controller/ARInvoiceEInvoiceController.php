<?php

/**
 * AR Invoice E-Invoice Controller
 *
 * Single-action server-authoritative API driving the Send e-invoice flow
 * (REQ-EINV-005/006/007). Every guarantee (approval-equivalent precondition:
 * `lifecycleState === issued`, KvK/BTW/Peppol validation, event emission) is
 * enforced inside {@see \OCA\Shillinq\Service\EInvoice\EInvoiceService} — the
 * controller only translates HTTP <-> service and maps exceptions to status
 * codes (mirrors {@see PurchaseOrderController::transmitPeppol()}).
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
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EInvoiceServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ARInvoice e-invoicing REST endpoint (send-einvoice).
 *
 * The endpoint is authenticated (#[NoAdminRequired]) AND authorised per
 * administration in this controller, via
 * AdministrationContextService::canAccess() (ADR-005, REQ-MA-001).
 *
 * ⚠️ This endpoint has an EXTERNAL side effect: it transmits an invoice over
 * the Peppol network under the instance's own access-point credentials. An
 * unauthorised call is therefore not merely a data read — it makes this
 * installation send another administration's invoice to a real recipient, and
 * that cannot be taken back. `scopeParam()` below is a FORMAT check only, and
 * this app declares no `authorization` block on its schemas, so OpenRegister
 * (which treats an absent block as open to every authenticated user — see the
 * same note on VATReturnController) refuses nothing downstream either.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
class ARInvoiceEInvoiceController extends Controller {
	/**
	 * Short-slug identifier pattern shared by every scope parameter (path/query).
	 * Invoice numbers use the `YYYY-NNNN` convention (see seed data) so `.` is
	 * intentionally allowed alongside the standard slug charset.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param EInvoiceServiceInterface $eInvoiceService Orchestrator (server-authoritative).
	 * @param IUserSession $userSession User session guard.
	 * @param AdministrationContextServiceInterface $context Membership guard (REQ-MA-001).
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly EInvoiceServiceInterface $eInvoiceService,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Send an issued ARInvoice as a Peppol e-invoice.
	 *
	 * POST /api/ar-invoices/{invoiceNumber}/send-einvoice
	 * Body: { administrationId }
	 *
	 * @param string $invoiceNumber The ARInvoice.invoiceNumber (path parameter).
	 *
	 * @return JSONResponse 200 with {deliveryStatus, transmissionId, payloadFileUri,
	 *                      fallback, warnings}; 400 on validation failure; 401
	 *                      anonymous; 404 on cross-tenant / missing invoice; 500
	 *                      without stack trace.
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	#[NoAdminRequired]
	public function send(string $invoiceNumber): JSONResponse {
		$refusal = $this->refuseSendRequest(invoiceNumber: $invoiceNumber);
		if ($refusal !== null) {
			return $refusal;
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		$refusal          = $this->refuseAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->eInvoiceService->sendEInvoice(
				administrationId: $administrationId,
				invoiceNumber: $invoiceNumber
			);
		} catch (\RuntimeException $e) {
			return self::refusedSend(e: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'ARInvoiceEInvoiceController: send-einvoice failed',
				['invoiceNumber' => $invoiceNumber, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not send e-invoice'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end send()

	/**
	 * Refuse a send without a logged-in user or with a malformed invoice number.
	 *
	 * @param string $invoiceNumber The invoice number.
	 *
	 * @return JSONResponse|null The 401/400 refusal, or null when the send may proceed.
	 */
	private function refuseSendRequest(string $invoiceNumber): JSONResponse|null {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $invoiceNumber) !== 1) {
			return new JSONResponse(['error' => 'Invalid invoice number'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end refuseSendRequest()

	/**
	 * Refuse a missing administration, or one the caller is not a member of.
	 *
	 * @param string $administrationId The administration id from the request.
	 *
	 * @return JSONResponse|null The 400/404 refusal, or null when the caller may send in it.
	 */
	private function refuseAdministration(string $administrationId): JSONResponse|null {
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			// 404, never 403 — canAccess()'s own documented contract. A 403 here
			// would confirm the administration exists and turn this endpoint
			// into an enumeration oracle for the instance's tenant list.
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end refuseAdministration()

	/**
	 * Answer a refused send: 404 when the invoice is not found, else 400, with the service's message.
	 *
	 * @param \RuntimeException $e The refusal.
	 *
	 * @return JSONResponse
	 */
	private static function refusedSend(\RuntimeException $e): JSONResponse {
		$message = $e->getMessage();
		if (str_contains($message, 'not found') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end refusedSend()

	/**
	 * Resolve + validate a scope parameter from the request body/query.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string The validated value, or '' when absent/invalid.
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()
}//end class
