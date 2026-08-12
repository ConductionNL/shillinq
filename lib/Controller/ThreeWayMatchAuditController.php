<?php

/**
 * Three-Way Match Audit Trail Controller
 *
 * Slice 11 of the bookkeeping-purchase-order-3way chain (REQ-PO3W-010).
 * Exposes two read-only endpoints over the audit-trail export of a
 * SupplierInvoice's complete 3-way-match lifecycle:
 *
 *   GET  /api/three-way-match/audit-trail
 *        Body/query: administrationId, invoiceId.
 *        → 200 with the deterministic JSON ledger (events + summary)
 *          for the Vue timeline view (no ZIP — fast read).
 *
 *   POST /api/three-way-match/audit-trail/export
 *        Body: administrationId, invoiceId.
 *        → 200 with the package envelope (packageId, sha256, zipPath,
 *          archived flag) so the auditor can stream the bytes.
 *
 * Every endpoint is #[NoAdminRequired] (admin posture is the NC
 * SecurityMiddleware default — controllers without the attribute are
 * admin-only, see [[nc-security-defaults]]); a manual user-session
 * guard rejects anonymous callers and the administration scope is
 * validated via AdministrationContextService so cross-tenant access is
 * masked as 404 (ADR-005 IDOR-safe). No stack traces are returned to
 * the client.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AuditExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only audit-trail endpoints (ledger + export envelope).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
class ThreeWayMatchAuditController extends Controller {

	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Schema slug for SupplierInvoice records (slice 01) — needed for
	 * the ledger-by-id read path.
	 *
	 * @var string
	 */
	private const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';

	/**
	 * Schema slug for PurchaseOrder records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PURCHASE_ORDER = 'PurchaseOrder';

	/**
	 * Schema slug for GoodsReceiptNote records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_GOODS_RECEIPT_NOTE = 'GoodsReceiptNote';

	/**
	 * Schema slug for ThreeWayMatch records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_THREE_WAY_MATCH = 'ThreeWayMatch';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AuditExportService $auditExportService The audit export service.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User-session guard.
	 * @param ContainerInterface $container DI container — OR's
	 *                                      ObjectService is fetched
	 *                                      lazily for the ledger-only
	 *                                      read path.
	 * @param LoggerInterface $logger Logger (no stack traces to
	 *                                client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AuditExportService $auditExportService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Fetch the lifecycle ledger for one SupplierInvoice (Vue timeline).
	 *
	 * GET /api/three-way-match/audit-trail
	 * Query: administrationId, invoiceId.
	 *
	 * @return JSONResponse 200 with the ledger; 400 on validation;
	 *                      401 anonymous; 404 cross-tenant or missing
	 *                      invoice; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	#[NoAdminRequired]
	public function ledger(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Supplier invoice not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = $this->scopeParam(name: 'invoiceId');
		if ($invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$bundle = $this->loadBundle(
				administrationId: $administrationId,
				invoiceId: $invoiceId
			);
			if ($bundle === null) {
				return new JSONResponse(['error' => 'Supplier invoice not found'], Http::STATUS_NOT_FOUND);
			}

			$ledger = $this->auditExportService->buildLedger(bundle: $bundle);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ThreeWayMatchAuditController: failed to build ledger',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not load audit trail'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse($ledger, Http::STATUS_OK);
	}//end ledger()

	/**
	 * Generate an immutable audit package (ZIP) for one SupplierInvoice
	 * and return the package envelope so the operator can hand the ZIP
	 * to the external auditor.
	 *
	 * POST /api/three-way-match/audit-trail/export
	 * Body: administrationId, invoiceId.
	 *
	 * @return JSONResponse 200 with the package envelope; 400 on
	 *                      validation; 401 anonymous; 404 cross-tenant
	 *                      or missing invoice; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	#[NoAdminRequired]
	public function export(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Supplier invoice not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = $this->scopeParam(name: 'invoiceId');
		if ($invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$envelope = $this->auditExportService->generateAuditPackage(
				administrationId: $administrationId,
				invoiceId:        $invoiceId
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ThreeWayMatchAuditController: failed to export audit package',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not export audit package'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Strip the local zipPath from the response — the path is a
		// server-only handle so the file can be streamed by a follow-up
		// download endpoint or handed to docudesk; exposing the FS path
		// would leak the temp-dir layout.
		$payload = $envelope;
		unset($payload['zipPath']);
		return new JSONResponse($payload, Http::STATUS_OK);
	}//end export()

	/**
	 * Load the lifecycle bundle for the read-only ledger endpoint.
	 *
	 * Returns null when the invoice is not visible to the caller — the
	 * controller surfaces a 404 so cross-tenant probes never leak the
	 * existence of an invoice from another administration.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array{
	 *     invoice:array<string,mixed>,
	 *     purchaseOrders:array<int,array<string,mixed>>,
	 *     goodsReceiptNotes:array<int,array<string,mixed>>,
	 *     threeWayMatches:array<int,array<string,mixed>>
	 * }|null
	 */
	private function loadBundle(string $administrationId, string $invoiceId): ?array {
		$invoice = $this->findOne(
			schema: self::SCHEMA_SUPPLIER_INVOICE,
			filters: [
				'id' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);
		if ($invoice === null) {
			return null;
		}

		$matches = $this->findAll(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			filters: [
				'invoiceId' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);

		$poIds = [];
		$grnIds = [];
		foreach ($matches as $match) {
			foreach ((array)($match['matchedPoIds'] ?? []) as $poId) {
				$value = trim((string)$poId);
				if ($value !== '' && in_array($value, $poIds, true) === false) {
					$poIds[] = $value;
				}
			}

			foreach ((array)($match['matchedGrnIds'] ?? []) as $grnId) {
				$value = trim((string)$grnId);
				if ($value !== '' && in_array($value, $grnIds, true) === false) {
					$grnIds[] = $value;
				}
			}
		}

		$purchaseOrders = [];
		foreach ($poIds as $poId) {
			$purchaseOrder = $this->findOne(
				schema: self::SCHEMA_PURCHASE_ORDER,
				filters: [
					'id' => $poId,
					'administrationId' => $administrationId,
				]
			);
			if ($purchaseOrder !== null) {
				$purchaseOrders[] = $purchaseOrder;
			}
		}

		$goodsReceiptNotes = [];
		foreach ($grnIds as $grnId) {
			$grn = $this->findOne(
				schema: self::SCHEMA_GOODS_RECEIPT_NOTE,
				filters: [
					'id' => $grnId,
					'administrationId' => $administrationId,
				]
			);
			if ($grn !== null) {
				$goodsReceiptNotes[] = $grn;
			}
		}

		return [
			'invoice' => $invoice,
			'purchaseOrders' => $purchaseOrders,
			'goodsReceiptNotes' => $goodsReceiptNotes,
			'threeWayMatches' => $matches,
		];

	}//end loadBundle()

	/**
	 * Read + validate a scope parameter; '' when blank/malformed.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()

	/**
	 * Fetch one record via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all records via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister('shillinq')
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ThreeWayMatchAuditController: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $exception->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Map a service-level RuntimeException to a JSONResponse.
	 *
	 * @param \RuntimeException $exception The exception.
	 *
	 * @return JSONResponse
	 */
	private function mapRuntimeException(\RuntimeException $exception): JSONResponse {
		$message = $exception->getMessage();
		if (str_contains($message, 'not found') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end mapRuntimeException()
}//end class
