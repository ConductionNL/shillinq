<?php

/**
 * Three-Way Match Audit Trail Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AuditExportServiceInterface;
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
	 * @param AuditExportServiceInterface $auditExportService The audit export service.
	 * @param AdministrationContextServiceInterface $administrationContext IDOR + tenant scope.
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
		private readonly AuditExportServiceInterface $auditExportService,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
		$scope = $this->auditScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		try {
			return $this->ledgerFor(scope: $scope);
		} catch (\Throwable $e) {
			return $this->ledgerFailure(scope: $scope, failure: $e);
		}
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
		$scope = $this->auditScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		try {
			$envelope = $this->auditPackage(scope: $scope);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			return $this->exportFailure(scope: $scope, failure: $e);
		}

		// Strip the local zipPath from the response — the path is a
		// server-only handle so the file can be streamed by a follow-up
		// download endpoint or handed to docudesk; exposing the FS path
		// would leak the temp-dir layout.
		unset($envelope['zipPath']);
		return new JSONResponse($envelope, Http::STATUS_OK);
	}//end export()

	/**
	 * Load the lifecycle bundle for the read-only ledger endpoint.
	 *
	 * Returns null when the invoice is not visible to the caller — the
	 * controller surfaces a 404 so cross-tenant probes never leak the
	 * existence of an invoice from another administration.
	 *
	 * @param ThreeWayAuditScope $scope The administration and invoice.
	 * @return array{
	 *     invoice:array<string,mixed>,
	 *     purchaseOrders:array<int,array<string,mixed>>,
	 *     goodsReceiptNotes:array<int,array<string,mixed>>,
	 *     threeWayMatches:array<int,array<string,mixed>>
	 * }|null
	 */
	private function loadBundle(ThreeWayAuditScope $scope): ?array {
		$invoice = $this->findInvoice(scope: $scope);
		if ($invoice === null) {
			return null;
		}

		$matches = $this->findMatches(scope: $scope);
		$poFilters = $scope->filtersFor(ids: self::linkedIds(matches: $matches, field: 'matchedPoIds'));
		$grnFilters = $scope->filtersFor(ids: self::linkedIds(matches: $matches, field: 'matchedGrnIds'));

		return [
			'invoice' => $invoice,
			'purchaseOrders' => $this->findEach(schema: self::SCHEMA_PURCHASE_ORDER, filters: $poFilters),
			'goodsReceiptNotes' => $this->findEach(schema: self::SCHEMA_GOODS_RECEIPT_NOTE, filters: $grnFilters),
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
			$rows = $this->query(schema: $schema, filters: $filters);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ThreeWayMatchAuditController: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $exception->getMessage()]
			);
			return [];
		}

		return self::recordsOnly(rows: $rows);
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

	/**
	 * The scope of an audit-trail request: 401 without a user, 400 without a
	 * well-formed administration or invoice, a masked 404 for an
	 * administration the caller may not access.
	 *
	 * @return ThreeWayAuditScope|JSONResponse
	 */
	private function auditScope(): ThreeWayAuditScope|JSONResponse {
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

		return $this->invoiceScope(administrationId: $administrationId);

	}//end auditScope()

	/**
	 * The scope with the request's invoice; 400 without a well-formed one.
	 *
	 * @param string $administrationId The checked administration.
	 *
	 * @return ThreeWayAuditScope|JSONResponse
	 */
	private function invoiceScope(string $administrationId): ThreeWayAuditScope|JSONResponse {
		$invoiceId = $this->scopeParam(name: 'invoiceId');
		if ($invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		return new ThreeWayAuditScope(administrationId: $administrationId, invoiceId: $invoiceId);

	}//end invoiceScope()

	/**
	 * Log a failed ledger build and answer a 500 without its details.
	 *
	 * @param ThreeWayAuditScope $scope The scope.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function ledgerFailure(ThreeWayAuditScope $scope, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'ThreeWayMatchAuditController: failed to build ledger',
			self::failureContext(scope: $scope, failure: $failure)
		);
		return new JSONResponse(['error' => 'Could not load audit trail'], Http::STATUS_INTERNAL_SERVER_ERROR);

	}//end ledgerFailure()

	/**
	 * Log a failed export and answer a 500 without its details.
	 *
	 * @param ThreeWayAuditScope $scope The scope.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function exportFailure(ThreeWayAuditScope $scope, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'ThreeWayMatchAuditController: failed to export audit package',
			self::failureContext(scope: $scope, failure: $failure)
		);
		return new JSONResponse(['error' => 'Could not export audit package'], Http::STATUS_INTERNAL_SERVER_ERROR);

	}//end exportFailure()

	/**
	 * The log context of a failure.
	 *
	 * @param ThreeWayAuditScope $scope The scope.
	 * @param \Throwable $failure The failure.
	 *
	 * @return array<string,string>
	 */
	private static function failureContext(ThreeWayAuditScope $scope, \Throwable $failure): array {
		return [
			'administrationId' => $scope->administrationId,
			'invoiceId' => $scope->invoiceId,
			'exception' => $failure->getMessage(),
		];

	}//end failureContext()

	/**
	 * The distinct, trimmed, non-empty ids a field of the matches links to, in
	 * first-seen order.
	 *
	 * @param array<int,array<string,mixed>> $matches The three-way matches.
	 * @param string $field matchedPoIds or matchedGrnIds.
	 *
	 * @return array<int,string>
	 */
	private static function linkedIds(array $matches, string $field): array {
		$ids = [];
		foreach ($matches as $match) {
			foreach ((array)($match[$field] ?? []) as $id) {
				$value = trim((string)$id);
				if ($value !== '' && in_array($value, $ids, true) === false) {
					$ids[] = $value;
				}
			}
		}

		return $ids;

	}//end linkedIds()

	/**
	 * The first record for each set of filters, skipping the sets without one.
	 *
	 * @param string $schema The schema.
	 * @param array<int,array<string,string>> $filters One filter set per record.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findEach(string $schema, array $filters): array {
		$records = [];
		foreach ($filters as $filter) {
			$record = $this->findOne(schema: $schema, filters: $filter);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end findEach()

	/**
	 * The rows that are records.
	 *
	 * @param iterable<mixed> $rows The rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function recordsOnly(iterable $rows): array {
		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;

	}//end recordsOnly()

	/**
	 * The ledger of the invoice's bundle; a 404 without the invoice.
	 *
	 * @param ThreeWayAuditScope $scope The scope.
	 *
	 * @return JSONResponse
	 */
	private function ledgerFor(ThreeWayAuditScope $scope): JSONResponse {
		$bundle = $this->loadBundle(scope: $scope);
		if ($bundle === null) {
			return new JSONResponse(['error' => 'Supplier invoice not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->auditExportService->buildLedger(bundle: $bundle), Http::STATUS_OK);

	}//end ledgerFor()

	/**
	 * Generate the audit package of the invoice.
	 *
	 * @param ThreeWayAuditScope $scope The scope.
	 *
	 * @return array<string,mixed> The package envelope.
	 */
	private function auditPackage(ThreeWayAuditScope $scope): array {
		return $this->auditExportService->generateAuditPackage(
			administrationId: $scope->administrationId,
			invoiceId:        $scope->invoiceId
		);

	}//end auditPackage()

	/**
	 * The supplier invoice within the administration.
	 *
	 * @param ThreeWayAuditScope $scope The scope.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findInvoice(ThreeWayAuditScope $scope): ?array {
		return $this->findOne(
			schema: self::SCHEMA_SUPPLIER_INVOICE,
			filters: [
				'id' => $scope->invoiceId,
				'administrationId' => $scope->administrationId,
			]
		);

	}//end findInvoice()

	/**
	 * The three-way matches of the invoice within the administration.
	 *
	 * @param ThreeWayAuditScope $scope The scope.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findMatches(ThreeWayAuditScope $scope): array {
		return $this->findAll(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			filters: [
				'invoiceId' => $scope->invoiceId,
				'administrationId' => $scope->administrationId,
			]
		);

	}//end findMatches()

	/**
	 * Query a schema of the shillinq register.
	 *
	 * @param string $schema The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return iterable<mixed>
	 */
	private function query(string $schema, array $filters): iterable {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		return $objectService
			->setRegister('shillinq')
			->setSchema($schema)
			->findAll(['filters' => $filters]);

	}//end query()
}//end class
