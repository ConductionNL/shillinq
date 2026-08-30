<?php

/**
 * Purchase Order Controller
 *
 * Server-authoritative API for the 3-way-match Purchase Order sub-ledger.
 * Implements member 02 of the bookkeeping-purchase-order-3way chain: it exposes
 * the PO create, read, send-transition and approval-chain-preview endpoints over
 * the PurchaseOrderService. Every endpoint is #[NoAdminRequired] (admin posture
 * is the NC SecurityMiddleware default — controllers without the attribute are
 * admin-only, see [[nc-security-defaults]]); a manual user-session guard rejects
 * anonymous callers and the administration scope is validated via
 * AdministrationContextService so cross-tenant access is masked as 404
 * (ADR-005 IDOR-safe). No stack traces are returned to the client.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Purchase-order REST endpoints (create / preview / send-transition).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 */
class PurchaseOrderController extends Controller {
	/**
	 * Short-slug identifier pattern shared by every scope parameter (path/query).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param PurchaseOrderService $purchaseOrderService The PO service (server-authoritative).
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param IL10N $l10n Translation service for error-response messages (ADR-050).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PurchaseOrderService $purchaseOrderService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Create a purchase order with a materialised approval chain.
	 *
	 * POST /api/purchase-orders
	 * Body: administrationId, supplierId, costCenter, projectCode (optional),
	 *       currency (optional, default EUR), lines: [{ productCode, quantity,
	 *       unitPrice, vatRate, glAccount, lineNumber? }, ...], notes (optional).
	 *
	 * @return JSONResponse 201 with the persisted PurchaseOrder; 400 on validation;
	 *                      401 anonymous; 404 on cross-tenant; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		// IDOR check — mask cross-tenant as 404 (ADR-005).
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'supplierId' => trim((string)$this->request->getParam('supplierId', '')),
			'costCenter' => trim((string)$this->request->getParam('costCenter', '')),
			'projectCode' => trim((string)$this->request->getParam('projectCode', '')),
			'currency' => trim((string)$this->request->getParam('currency', 'EUR')),
			'lines' => (array)$this->request->getParam('lines', []),
			'notes' => (string)$this->request->getParam('notes', ''),
		];

		try {
			$po = $this->purchaseOrderService->createPurchaseOrder(
				administrationId: $administrationId,
				payload: $payload
			);
		} catch (\RuntimeException $e) {
			$this->logger->error(
				'PurchaseOrderController: purchase order creation rejected',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to create purchase order — check the required fields'),
					'error' => 'purchase-order-create-invalid',
				],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PurchaseOrderController: failed to create purchase order',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to create purchase order'),
					'error' => 'purchase-order-create-failed',
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($po, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Preview the approval-chain that would be assigned for a given amount.
	 *
	 * GET /api/purchase-orders/approval-chain?amount=18500
	 * Useful for the PO form: the Vue layer renders the chip set as the user
	 * types. The amount is server-validated; non-numeric or non-positive values
	 * return an empty chain.
	 *
	 * @return JSONResponse 200 with {chain: [...]} ; 401 anonymous.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
	 *
	 * @no-admin-idor-exempt Stateless policy calculator — reads no storage and takes no
	 *     object reference. The only input is a numeric amount; it goes to
	 *     PurchaseOrderService::determineApprovalChain(), whose body compares the amount
	 *     in cents against two class constants and returns role NAMES
	 *     (teamleider / facility_manager / procurement_manager) with an order index. No
	 *     ObjectService call, no mapper, no PurchaseOrder is loaded, and no administration
	 *     appears in the call at all — the neighbouring create() and send() methods, which
	 *     DO reach storage, carry the canAccess() 404-masking guard. The response reveals
	 *     only the app-global approval thresholds, which are the same for every tenant and
	 *     are documented in the spec; substituting any amount reaches no one else's data.
	 *     Verify by reading lib/Service/PurchaseOrderService.php::determineApprovalChain().
	 */
	#[NoAdminRequired]
	public function previewApprovalChain(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$amount = (float)$this->request->getParam('amount', 0);
		if ($amount < 0.0) {
			$amount = 0.0;
		}

		$chain = $this->purchaseOrderService->determineApprovalChain(amount: $amount);

		return new JSONResponse(['chain' => $chain], Http::STATUS_OK);
	}//end previewApprovalChain()

	/**
	 * Attempt to advance a purchase order's lifecycle to "sent".
	 *
	 * POST /api/purchase-orders/{id}/send
	 * Body: administrationId.
	 * Server refuses the transition until every required approver has signed
	 * (REQ-PO3W-001 send-block). The Vue layer never grants the transition.
	 *
	 * @param string $id The PO id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated PO; 400 on validation; 401
	 *                      anonymous; 404 on cross-tenant / missing PO; 409 when
	 *                      approval-chain incomplete; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
	 */
	#[NoAdminRequired]
	public function send(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid purchase order id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Purchase order not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$po = $this->purchaseOrderService->blockSendUntilApproved(
				administrationId: $administrationId,
				purchaseOrderId: $id
			);
		} catch (\RuntimeException $e) {
			// Distinguish missing PO (404) from incomplete chain (409).
			$message = $e->getMessage();
			if (str_contains($message, 'not found') === true) {
				return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PurchaseOrderController: failed to advance purchase order',
				['purchaseOrderId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not advance purchase order'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($po, Http::STATUS_OK);
	}//end send()

	/**
	 * Transmit an approved PO to its supplier via Peppol BIS Ordering 3.0.
	 *
	 * POST /api/purchase-orders/{id}/transmit/peppol
	 * Body: administrationId.
	 * Server enforces the approval-complete precondition (REQ-PO3W-001 send-block)
	 * and routes the document through the openconnector Peppol Access Point. On
	 * success the response carries the updated PO with `peppolMessageId` +
	 * `peppolSentAt`; when the supplier is not Peppol-registered the server
	 * automatically falls back to PDF + email and records
	 * `peppolFallbackReason` (REQ-PO3W-002 D2 — graceful, never silent).
	 *
	 * @param string $id The PO id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated PO; 400 on validation; 401
	 *                      anonymous; 404 on cross-tenant / missing PO; 409 when
	 *                      approval-chain incomplete; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
	 */
	#[NoAdminRequired]
	public function transmitPeppol(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid purchase order id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Purchase order not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$po = $this->purchaseOrderService->sendToPeppol(
				administrationId: $administrationId,
				purchaseOrderId: $id
			);
		} catch (\RuntimeException $e) {
			$message = $e->getMessage();
			if (str_contains($message, 'not found') === true) {
				return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PurchaseOrderController: Peppol transmission failed',
				['purchaseOrderId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not transmit purchase order'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($po, Http::STATUS_OK);
	}//end transmitPeppol()

	/**
	 * Transmit an approved PO to its supplier via PDF + email fallback.
	 *
	 * POST /api/purchase-orders/{id}/transmit/email
	 * Body: administrationId, fallbackReason (optional).
	 * The fallbackReason audits why the operator chose the manual path; an empty
	 * value defaults to `manual_pdf_email_fallback`. Server-side guarantees
	 * are identical to the Peppol path (approval-complete precondition + IDOR).
	 *
	 * @param string $id The PO id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated PO; 400 on validation; 401
	 *                      anonymous; 404 on cross-tenant / missing PO; 409 when
	 *                      approval-chain incomplete; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
	 */
	#[NoAdminRequired]
	public function transmitEmail(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid purchase order id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Purchase order not found'], Http::STATUS_NOT_FOUND);
		}

		$fallbackReason = trim((string)$this->request->getParam('fallbackReason', ''));

		try {
			$po = $this->purchaseOrderService->sendToPDFEmail(
				administrationId: $administrationId,
				purchaseOrderId: $id,
				fallbackReason: $fallbackReason
			);
		} catch (\RuntimeException $e) {
			$message = $e->getMessage();
			if (str_contains($message, 'not found') === true) {
				return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PurchaseOrderController: PDF+email transmission failed',
				['purchaseOrderId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not transmit purchase order'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($po, Http::STATUS_OK);
	}//end transmitEmail()

	/**
	 * Read and validate a scope parameter, returning '' when blank/malformed.
	 *
	 * @param string $name Parameter name (body for POST / query for GET).
	 *
	 * @return string The validated value or '' (blank/malformed).
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()
}//end class
