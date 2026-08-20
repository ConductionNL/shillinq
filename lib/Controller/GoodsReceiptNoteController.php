<?php

/**
 * Goods Receipt Note Controller
 *
 * Server-authoritative API for the 3-way-match GRN sub-ledger (member 04 of
 * the bookkeeping-purchase-order-3way chain). Exposes the
 * GoodsReceiptNoteService's five surfaces — create / add line /
 * quality-check / accept / upload photos — and one read endpoint used by the
 * mobile receiver UI.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GoodsReceiptNoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Goods-receipt-note REST endpoints (create / add line / quality-check /
 * accept / upload photos).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
 */
class GoodsReceiptNoteController extends Controller {
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
	 * @param GoodsReceiptNoteService $grnService The GRN service (server-authoritative).
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly GoodsReceiptNoteService $grnService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Create a GoodsReceiptNote against one or more purchase orders.
	 *
	 * POST /api/goods-receipt-notes
	 * Body: administrationId, poIds[], receivedAt (optional), carrier,
	 *       deliveryNoteReference, costCenter, projectCode,
	 *       lotNumbers[], serialNumbers[], temperatureLog[], photos[].
	 *
	 * @return JSONResponse 201 with the persisted GRN; 400 on validation;
	 *                      401 anonymous; 404 on cross-tenant; 500 without
	 *                      stack trace.
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive (the scan only recognises
	 * guard calls named `authorize*`/`require*`/`ensure*` and does not match
	 * `canAccess(`). `GoodsReceiptNoteService::createGRN()` also re-checks
	 * access. No guard change needed.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
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

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'poIds' => (array)$this->request->getParam('poIds', []),
			'receivedAt' => trim((string)$this->request->getParam('receivedAt', '')),
			'carrier' => trim((string)$this->request->getParam('carrier', '')),
			'deliveryNoteReference' => trim((string)$this->request->getParam('deliveryNoteReference', '')),
			'costCenter' => trim((string)$this->request->getParam('costCenter', '')),
			'projectCode' => trim((string)$this->request->getParam('projectCode', '')),
			'lotNumbers' => (array)$this->request->getParam('lotNumbers', []),
			'serialNumbers' => (array)$this->request->getParam('serialNumbers', []),
			'temperatureLog' => (array)$this->request->getParam('temperatureLog', []),
			'photos' => (array)$this->request->getParam('photos', []),
		];

		try {
			$grn = $this->grnService->createGRN(
				administrationId: $administrationId,
				payload: $payload
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'GoodsReceiptNoteController: failed to create GRN',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not create goods receipt note'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($grn, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Append a GoodsReceiptLine to an existing GRN.
	 *
	 * POST /api/goods-receipt-notes/{id}/lines
	 * Body: administrationId, poLineId, quantityReceived, quantityAccepted,
	 *       quantityRejected, rejectionReason, batchReference.
	 *
	 * @param string $id The GRN id (path parameter).
	 *
	 * @return JSONResponse 201 with the persisted line; 400 on validation;
	 *                      401 anonymous; 404 cross-tenant or missing GRN/PO
	 *                      line.
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive. `GoodsReceiptNoteService::
	 * addGRNLine()` additionally scopes the GRN lookup by `id` AND
	 * `administrationId` together, so a caller cannot reach another
	 * administration's GRN even by supplying an id they guessed. No guard
	 * change needed.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function addLine(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid GRN id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Goods receipt note not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'poLineId' => trim((string)$this->request->getParam('poLineId', '')),
			'quantityReceived' => $this->request->getParam('quantityReceived', 0),
			'quantityAccepted' => $this->request->getParam('quantityAccepted', null),
			'quantityRejected' => $this->request->getParam('quantityRejected', 0),
			'rejectionReason' => trim((string)$this->request->getParam('rejectionReason', '')),
			'batchReference' => trim((string)$this->request->getParam('batchReference', '')),
		];

		try {
			$line = $this->grnService->addGRNLine(
				administrationId: $administrationId,
				grnId: $id,
				payload: $payload
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'GoodsReceiptNoteController: failed to add GRN line',
				['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not add goods receipt line'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($line, Http::STATUS_CREATED);
	}//end addLine()

	/**
	 * Pass the quality check (received → quality_checked).
	 *
	 * POST /api/goods-receipt-notes/{id}/quality-check
	 * Body: administrationId.
	 *
	 * @param string $id The GRN id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated GRN; 400 on validation; 401
	 *                      anonymous; 404 cross-tenant or missing GRN; 409 on
	 *                      lifecycle conflict.
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive.
	 * `GoodsReceiptNoteService::qualityCheckPass()` additionally scopes the
	 * GRN lookup by `id` AND `administrationId` together. No guard change
	 * needed.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function qualityCheck(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid GRN id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Goods receipt note not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$grn = $this->grnService->qualityCheckPass(
				administrationId: $administrationId,
				grnId: $id
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'GoodsReceiptNoteController: failed quality-check transition',
				['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not transition GRN'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($grn, Http::STATUS_OK);
	}//end qualityCheck()

	/**
	 * Accept the GRN — posts StockMove credits and updates PO lifecycle.
	 *
	 * POST /api/goods-receipt-notes/{id}/accept
	 * Body: administrationId.
	 *
	 * @param string $id The GRN id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated GRN; 400 on validation; 401
	 *                      anonymous; 404 cross-tenant or missing GRN; 409 on
	 *                      terminal-state conflict.
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive. `GoodsReceiptNoteService::
	 * acceptGRN()` additionally scopes the GRN lookup by `id` AND
	 * `administrationId` together. No guard change needed.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid GRN id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Goods receipt note not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$grn = $this->grnService->acceptGRN(
				administrationId: $administrationId,
				grnId: $id
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'GoodsReceiptNoteController: failed to accept GRN',
				['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not accept GRN'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($grn, Http::STATUS_OK);
	}//end accept()

	/**
	 * Attach photos to the GRN (docudesk file ids).
	 *
	 * POST /api/goods-receipt-notes/{id}/photos
	 * Body: administrationId, photos[] (file ids).
	 *
	 * @param string $id The GRN id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated GRN; 400 on validation; 401
	 *                      anonymous; 404 cross-tenant or missing GRN.
	 *
	 * Re-verified for security-endpoint-guards (REQ-001): the
	 * `AdministrationContextService::canAccess()` masked-404 guard below was
	 * already present and enforcing before this change — a mechanical
	 * `hydra-gate-no-admin-idor` false positive. `GoodsReceiptNoteService::
	 * uploadPhotos()` additionally scopes the GRN lookup by `id` AND
	 * `administrationId` together. No guard change needed (REQ-004 test
	 * coverage for both directions was previously missing and is added by
	 * this change).
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function uploadPhotos(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid GRN id'], Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Goods receipt note not found'], Http::STATUS_NOT_FOUND);
		}

		$photos = (array)$this->request->getParam('photos', []);

		try {
			$grn = $this->grnService->uploadPhotos(
				administrationId: $administrationId,
				grnId: $id,
				photoFileIds: $photos
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'GoodsReceiptNoteController: failed to attach GRN photos',
				['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Could not attach photos'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($grn, Http::STATUS_OK);
	}//end uploadPhotos()

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
	 *  - "not found"            → 404
	 *  - "terminal state" / "requires statusCode" / "may not exceed" → 409
	 *  - anything else          → 400 (validation)
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

		if (str_contains($message, 'terminal state') === true
			|| str_contains($message, 'requires statusCode') === true
			|| str_contains($message, 'may not exceed') === true
		) {
			return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end mapRuntimeException()
}//end class
