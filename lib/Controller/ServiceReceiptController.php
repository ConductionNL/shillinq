<?php

/**
 * Service Receipt Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ServiceReceiptServiceInterface;
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
	 * Refusal message parts that mean a conflict (409).
	 *
	 * @var array<int,string>
	 */
	private const CONFLICT_MARKERS = ['requires statusCode'];

	/**
	 * Per action, the logged message and the error of an unexpected failure.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const FAILURES = [
		'create' => ['ServiceReceiptController: failed to create service receipt', 'Could not create service receipt'],
		'addLine' => ['ServiceReceiptController: failed to add service receipt line', 'Could not add service receipt line'],
		'confirm' => ['ServiceReceiptController: failed confirm transition', 'Could not confirm service receipt'],
		'accept' => ['ServiceReceiptController: failed to accept service receipt', 'Could not accept service receipt'],
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ServiceReceiptServiceInterface $serviceReceiptService The service-receipt service (server-authoritative).
	 * @param AdministrationContextServiceInterface $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ServiceReceiptServiceInterface $serviceReceiptService,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
		$administrationId = $this->guard()->newReceipt();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->serviceReceiptService->createServiceReceipt(
				administrationId: $administrationId,
				payload: $this->createPayload()
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'create', context: ['administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
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
		$administrationId = $this->receiptAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->addReceiptLine(administrationId: $administrationId, id: $id);
		} catch (\Throwable $e) {
			return $this->failed(action: 'addLine', context: ['receiptId' => $id, 'administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
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
		$administrationId = $this->receiptAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->serviceReceiptService->confirmServiceReceipt(
				administrationId: $administrationId,
				receiptId: $id
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'confirm', context: ['receiptId' => $id, 'administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_OK);
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
		$administrationId = $this->receiptAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->serviceReceiptService->acceptServiceReceipt(
				administrationId: $administrationId,
				receiptId: $id
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'accept', context: ['receiptId' => $id, 'administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end accept()

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
		return $this->guard()->receipt(id: $id, names: new ReceiptNames(invalidId: 'Invalid service receipt id', notFound: 'Service receipt not found'));
	}//end receiptAdministration()

	/**
	 * The create payload from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function createPayload(): array {
		return [
			'poIds' => (array)$this->request->getParam('poIds', []),
			'periodStart' => trim((string)$this->request->getParam('periodStart', '')),
			'periodEnd' => trim((string)$this->request->getParam('periodEnd', '')),
			'notes' => trim((string)$this->request->getParam('notes', '')),
			'costCenter' => trim((string)$this->request->getParam('costCenter', '')),
			'projectCode' => trim((string)$this->request->getParam('projectCode', '')),
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
			'percentageComplete' => $this->request->getParam('percentageComplete', null),
			'quantityConfirmed' => $this->request->getParam('quantityConfirmed', null),
			'amountConfirmedCents' => $this->request->getParam('amountConfirmedCents', null),
			'periodStart' => $this->request->getParam('periodStart', null),
			'periodEnd' => $this->request->getParam('periodEnd', null),
			'notes' => $this->request->getParam('notes', null),
		];

	}//end addLinePayload()

	/**
	 * Add the request's line to the service receipt.
	 *
	 * @param string $administrationId The administration.
	 * @param string $id The receipt.
	 *
	 * @return array<string,mixed>
	 */
	private function addReceiptLine(string $administrationId, string $id): array {
		return $this->serviceReceiptService->addServiceReceiptLine(
			administrationId: $administrationId,
			receiptId: $id,
			payload: $this->addLinePayload()
		);

	}//end addReceiptLine()

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
}//end class
