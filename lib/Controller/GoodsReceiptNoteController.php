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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
class GoodsReceiptNoteController extends Controller {
	/**
	 * Refusal message parts that mean a conflict (409).
	 *
	 * @var array<int,string>
	 */
	private const CONFLICT_MARKERS = ['terminal state', 'requires statusCode', 'may not exceed'];

	/**
	 * Per action, the logged message and the error of an unexpected failure.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const FAILURES = [
		'create' => ['GoodsReceiptNoteController: failed to create GRN', 'Could not create goods receipt note'],
		'addLine' => ['GoodsReceiptNoteController: failed to add GRN line', 'Could not add goods receipt line'],
		'qualityCheck' => ['GoodsReceiptNoteController: failed quality-check transition', 'Could not transition GRN'],
		'accept' => ['GoodsReceiptNoteController: failed to accept GRN', 'Could not accept GRN'],
		'uploadPhotos' => ['GoodsReceiptNoteController: failed to attach GRN photos', 'Could not attach photos'],
	];

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
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$administrationId = $this->guard()->newReceipt();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->grnService->createGRN(
				administrationId: $administrationId,
				payload: $this->createPayload()
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'create', context: ['administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function addLine(string $id): JSONResponse {
		$administrationId = $this->receiptAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->addGrnLine(administrationId: $administrationId, id: $id);
		} catch (\Throwable $e) {
			return $this->failed(action: 'addLine', context: ['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function qualityCheck(string $id): JSONResponse {
		$administrationId = $this->receiptAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->grnService->qualityCheckPass(
				administrationId: $administrationId,
				grnId: $id
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'qualityCheck', context: ['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_OK);
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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		$administrationId = $this->receiptAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->grnService->acceptGRN(
				administrationId: $administrationId,
				grnId: $id
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'accept', context: ['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_OK);
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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function uploadPhotos(string $id): JSONResponse {
		$administrationId = $this->receiptAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->attachPhotos(administrationId: $administrationId, id: $id);
		} catch (\Throwable $e) {
			return $this->failed(action: 'uploadPhotos', context: ['grnId' => $id, 'administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end uploadPhotos()

	/**
	 * The checks a receipt request passes before it reaches the service.
	 *
	 * @return ReceiptRequestGuard
	 */
	private function guard(): ReceiptRequestGuard {
		$scope = new ReceiptRequestScope(request: $this->request, administrationContext: $this->administrationContext);
		return new ReceiptRequestGuard(userSession: $this->userSession, scope: $scope);
	}//end guard()

	/**
	 * The administration of a request about one receipt: 401 without a user,
	 * 400 for a malformed id or administration, a masked 404 for a foreign one.
	 *
	 * @param string $id The receipt id.
	 *
	 * @return string|JSONResponse
	 */
	private function receiptAdministration(string $id): string|JSONResponse {
		return $this->guard()->receipt(id: $id, names: new ReceiptNames(invalidId: 'Invalid GRN id', notFound: 'Goods receipt note not found'));
	}//end receiptAdministration()

	/**
	 * The create payload from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function createPayload(): array {
		return [
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

	}//end createPayload()

	/**
	 * The addLine payload from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function addLinePayload(): array {
		return [
			'poLineId' => trim((string)$this->request->getParam('poLineId', '')),
			'quantityReceived' => $this->request->getParam('quantityReceived', 0),
			'quantityAccepted' => $this->request->getParam('quantityAccepted', null),
			'quantityRejected' => $this->request->getParam('quantityRejected', 0),
			'rejectionReason' => trim((string)$this->request->getParam('rejectionReason', '')),
			'batchReference' => trim((string)$this->request->getParam('batchReference', '')),
		];

	}//end addLinePayload()

	/**
	 * Add the request's line to the goods receipt note.
	 *
	 * @param string $administrationId The administration.
	 * @param string $id The receipt.
	 *
	 * @return array<string,mixed>
	 */
	private function addGrnLine(string $administrationId, string $id): array {
		return $this->grnService->addGRNLine(
			administrationId: $administrationId,
			grnId: $id,
			payload: $this->addLinePayload()
		);

	}//end addGrnLine()

	/**
	 * Answer a failed action: a service refusal by its message, anything else
	 * logged under the action and a 500.
	 *
	 * @param string $action The action.
	 * @param array<string,mixed> $context The log context, with the failure as exception.
	 *
	 * @return JSONResponse
	 */
	private function failed(string $action, array $context): JSONResponse {
		$answer = new ReceiptFailureAnswer(logger: $this->logger, conflictMarkers: self::CONFLICT_MARKERS);
		return $answer->answer(messages: self::FAILURES[$action], context: $context);

	}//end failed()

	/**
	 * Attach the request's photos to the goods receipt note.
	 *
	 * @param string $administrationId The administration.
	 * @param string $id The receipt.
	 *
	 * @return array<string,mixed>
	 */
	private function attachPhotos(string $administrationId, string $id): array {
		return $this->grnService->uploadPhotos(
			administrationId: $administrationId,
			grnId: $id,
			photoFileIds: (array)$this->request->getParam('photos', [])
		);

	}//end attachPhotos()
}//end class
