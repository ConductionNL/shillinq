<?php

/**
 * Service Receipt Controller
 *
 * Server-authoritative API for the 3-way-match service-receipt
 * (prestatieverklaring) sub-ledger (member 12 of the
 * bookkeeping-purchase-order-3way chain, REQ-PO3W-011). Exposes
 * ServiceReceiptService's four surfaces — create / add line / confirm /
 * accept — mirroring GoodsReceiptNoteController's shape for the service
 * side of the 3-way match.
 *
 * Every endpoint is #[NoAdminRequired] (admin posture is the NC
 * SecurityMiddleware default — controllers without the attribute are
 * admin-only, see [[nc-security-defaults]]); a manual user-session guard
 * rejects anonymous callers and the administration scope is validated via
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
 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ServiceReceiptService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service-receipt (prestatieverklaring) REST endpoints (create / add line /
 * confirm / accept).
 *
 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
 */
class ServiceReceiptController extends Controller {
	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ServiceReceiptService $serviceReceiptService The service-receipt service (server-authoritative).
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ServiceReceiptService $serviceReceiptService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Create a SvcReceipt against one or more purchase orders.
	 *
	 * POST /api/service-receipts
	 * Body: administrationId, poIds[], periodStart, periodEnd (optional),
	 *       notes, costCenter, projectCode.
	 *
	 * @return JSONResponse 201 with the persisted receipt; 400 on
	 *                      validation; 401 anonymous; 404 on cross-tenant;
	 *                      500 without stack trace.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
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

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'poIds' => (array)$this->request->getParam('poIds', []),
			'periodStart' => trim((string)$this->request->getParam('periodStart', '')),
			'periodEnd' => trim((string)$this->request->getParam('periodEnd', '')),
			'notes' => trim((string)$this->request->getParam('notes', '')),
			'costCenter' => trim((string)$this->request->getParam('costCenter', '')),
			'projectCode' => trim((string)$this->request->getParam('projectCode', '')),
		];

		try {
			$receipt = $this->serviceReceiptService->createServiceReceipt(
				administrationId: $administrationId,
				payload: $payload
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ServiceReceiptController: failed to create service receipt',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not create service receipt'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($receipt, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Append a SvcReceiptLine to an existing SvcReceipt.
	 *
	 * POST /api/service-receipts/{id}/lines
	 * Body: administrationId, poLineId, percentageComplete OR
	 *       quantityConfirmed OR amountConfirmedCents, periodStart,
	 *       periodEnd, notes.
	 *
	 * @param string $id The service-receipt id (path parameter).
	 *
	 * @return JSONResponse 201 with the persisted line; 400 on validation;
	 *                      401 anonymous; 404 cross-tenant or missing
	 *                      receipt/PO line.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	#[NoAdminRequired]
	public function addLine(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid service receipt id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Service receipt not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'poLineId' => trim((string)$this->request->getParam('poLineId', '')),
			'percentageComplete' => $this->request->getParam('percentageComplete', null),
			'quantityConfirmed' => $this->request->getParam('quantityConfirmed', null),
			'amountConfirmedCents' => $this->request->getParam('amountConfirmedCents', null),
			'periodStart' => $this->request->getParam('periodStart', null),
			'periodEnd' => $this->request->getParam('periodEnd', null),
			'notes' => $this->request->getParam('notes', null),
		];

		try {
			$line = $this->serviceReceiptService->addServiceReceiptLine(
				administrationId: $administrationId,
				receiptId: $id,
				payload: $payload
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ServiceReceiptController: failed to add service receipt line',
				['receiptId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not add service receipt line'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($line, Http::STATUS_CREATED);
	}//end addLine()

	/**
	 * Confirm the SvcReceipt (draft → confirmed).
	 *
	 * POST /api/service-receipts/{id}/confirm
	 * Body: administrationId.
	 *
	 * @param string $id The service-receipt id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated receipt; 400 on
	 *                      validation; 401 anonymous; 404 cross-tenant or
	 *                      missing receipt; 409 on lifecycle conflict.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	#[NoAdminRequired]
	public function confirm(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid service receipt id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Service receipt not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$receipt = $this->serviceReceiptService->confirmServiceReceipt(
				administrationId: $administrationId,
				receiptId: $id
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ServiceReceiptController: failed confirm transition',
				['receiptId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not confirm service receipt'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($receipt, Http::STATUS_OK);
	}//end confirm()

	/**
	 * Accept the SvcReceipt — updates PO lifecycle (no StockMove; services
	 * never move inventory).
	 *
	 * POST /api/service-receipts/{id}/accept
	 * Body: administrationId.
	 *
	 * @param string $id The service-receipt id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated receipt; 400 on
	 *                      validation; 401 anonymous; 404 cross-tenant or
	 *                      missing receipt; 409 on lifecycle conflict.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid service receipt id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Service receipt not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$receipt = $this->serviceReceiptService->acceptServiceReceipt(
				administrationId: $administrationId,
				receiptId: $id
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ServiceReceiptController: failed to accept service receipt',
				['receiptId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not accept service receipt'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($receipt, Http::STATUS_OK);
	}//end accept()

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

	/**
	 * Map a service-level RuntimeException to a JSONResponse.
	 *
	 * Conventions:
	 *  - "not found"                                     → 404
	 *  - "requires statusCode" / "does not belong to"      → 409
	 *  - anything else                                    → 400 (validation)
	 *
	 * @param \RuntimeException $exception The exception to map.
	 *
	 * @return JSONResponse
	 */
	private function mapRuntimeException(\RuntimeException $exception): JSONResponse {
		$message = $exception->getMessage();
		if (str_contains($message, 'not found') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}

		if (str_contains($message, 'requires statusCode') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end mapRuntimeException()
}//end class
