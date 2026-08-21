<?php

/**
 * Supplier Invoice Import Controller
 *
 * Dashboard-launched supplier-invoice import endpoint for the
 * shillinq-bill-import-modal change. Backs the BillImportModal's Upload
 * step (REQ-BIM-001) by accepting a UBL/e-invoice XML or CSV file (or a
 * JSON body with `contents` + `format`) and ingesting it through the
 * existing, deterministic `SupplierInvoiceService::ingestUBLInvoice`
 * (REQ-BIM-002 — UBL/CSV are deterministic, no OCR). A PDF upload is
 * honestly deferred: there is no OCR engine bundled with this change, so
 * the endpoint returns HTTP 422 with a `deferred: pdf-ocr` marker rather
 * than fabricating a confidence-scored extraction (REQ-BIM-002).
 *
 * The endpoint is #[NoAdminRequired]; the administration scope is always
 * resolved server-side via AdministrationContextService and never accepted
 * from the client (ADR-005 IDOR-safe), mirroring InvoiceApiController.
 * Reads/writes go through OpenRegister's real ObjectService API
 * (find / findAll / saveObject) — `findObject` / `createFromArray` do NOT
 * exist and are never used ([[or-objectservice-api]]).
 *
 * Duplicate detection (REQ-BIM-005): before ingesting a UBL invoice the
 * controller parses just the header identifiers and pre-checks for an
 * existing `(administrationId, invoiceNumber, supplierId)` SupplierInvoice;
 * a match returns HTTP 409 so the user is told the invoice number already
 * exists for that supplier instead of relying on the service's idempotent
 * return.
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
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * HTTP API for the dashboard "Import bill" modal (shillinq-bill-import-modal).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class SupplierInvoiceImportController extends Controller {
	/**
	 * The OpenRegister register slug for shillinq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'shillinq';

	/**
	 * The SupplierInvoice schema slug.
	 *
	 * @var string
	 */
	private const SUPPLIER_INVOICE_SCHEMA = 'SupplierInvoice';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param SupplierInvoiceService $supplierInvoiceService Deterministic UBL ingestion + parser.
	 * @param AdministrationContextService $administrationContext Server-resolved tenant scope (ADR-005).
	 * @param IUserSession $session User session.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SupplierInvoiceService $supplierInvoiceService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Import a supplier invoice (POST /api/v1/supplier-invoices/import).
	 *
	 * Accepts a multipart `file` upload OR a JSON body carrying `contents`
	 * (the raw file text) and `format` (`ubl` | `csv` | `pdf`). The format
	 * is inferred from the uploaded file name/extension when not supplied.
	 *
	 *  - ubl: parsed + ingested deterministically via the supplier-invoice
	 *    service (REQ-BIM-001 / REQ-BIM-002). A duplicate
	 *    (administrationId, invoiceNumber, supplierId) returns HTTP 409
	 *    (REQ-BIM-005).
	 *  - csv: parsed row-by-row (header
	 *    supplier,invoiceNumber,invoiceDate,amount,vatAmount) and one
	 *    SupplierInvoice is created per non-duplicate row (REQ-BIM-001).
	 *  - pdf: honestly deferred — HTTP 422 with `deferred: pdf-ocr`
	 *    (REQ-BIM-002). No fake extraction.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	#[NoAdminRequired]
	public function import(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$admin = $this->resolveAdministrationId();
			[$contents, $format] = $this->readUpload();

			if ($contents === '') {
				return new JSONResponse(
					['error' => 'No file contents were uploaded'],
					Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}

			switch ($format) {
				case 'ubl':
					return $this->importUbl(administrationId: $admin, ublXml: $contents);
				case 'csv':
					return $this->importCsv(administrationId: $admin, csv: $contents);
				case 'pdf':
					// Honest deferral — no OCR engine is bundled (REQ-BIM-002).
					return new JSONResponse(
						[
							'error' => 'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.',
							'deferred' => 'pdf-ocr',
						],
						Http::STATUS_UNPROCESSABLE_ENTITY
					);
				default:
					return new JSONResponse(
						['error' => 'Unsupported import format. Use ubl, csv or pdf.'],
						Http::STATUS_UNPROCESSABLE_ENTITY
					);
			}//end switch
		} catch (\Throwable $e) {
			$this->logger->error('SupplierInvoiceImportController.import failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end import()

	/**
	 * Import a single UBL/e-invoice XML document.
	 *
	 * Parses the header identifiers first to pre-check for a duplicate
	 * (REQ-BIM-005) before delegating to the deterministic, idempotent
	 * service ingestion (REQ-BIM-002).
	 *
	 * @param string $administrationId Server-resolved tenant scope.
	 * @param string $ublXml UBL Invoice XML.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function importUbl(string $administrationId, string $ublXml): JSONResponse {
		try {
			$parsed = $this->supplierInvoiceService->parseUblInvoice(ublXml: $ublXml);
			$invoiceNumber = (string)($parsed['invoiceNumber'] ?? '');
			$supplierId = (string)($parsed['supplierId'] ?? '');

			if ($this->duplicateExists($administrationId, $invoiceNumber, $supplierId) === true) {
				return new JSONResponse(
					['error' => 'This invoice number already exists for this supplier'],
					Http::STATUS_CONFLICT
				);
			}

			$record = $this->supplierInvoiceService->ingestUBLInvoice(
				administrationId: $administrationId,
				ublXml: $ublXml
			);

			return new JSONResponse(
				['imported' => 1, 'record' => $record],
				Http::STATUS_OK
			);
		} catch (RuntimeException $e) {
			return $this->mapServiceException($e);
		}//end try

	}//end importUbl()

	/**
	 * Import a CSV bill list.
	 *
	 * Header row MUST be supplier,invoiceNumber,invoiceDate,amount,vatAmount.
	 * Each non-duplicate data row becomes one SupplierInvoice with
	 * statusCode = received and sourceFormat = csv. Duplicate rows are
	 * skipped and counted so a partially-overlapping re-upload still
	 * imports the new rows (REQ-BIM-001 / REQ-BIM-005).
	 *
	 * @param string $administrationId Server-resolved tenant scope.
	 * @param string $csv Raw CSV text.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function importCsv(string $administrationId, string $csv): JSONResponse {
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_FORBIDDEN);
		}

		$rows = $this->parseCsv($csv);
		if ($rows === []) {
			return new JSONResponse(
				['error' => 'CSV did not contain any data rows'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$imported = 0;
		$skipped = 0;
		$records = [];
		foreach ($rows as $row) {
			$invoiceNumber = trim((string)($row['invoiceNumber'] ?? ''));
			$supplierId = trim((string)($row['supplier'] ?? ''));

			if ($invoiceNumber === '' || $supplierId === '') {
				$skipped++;
				continue;
			}

			if ($this->duplicateExists($administrationId, $invoiceNumber, $supplierId) === true) {
				$skipped++;
				continue;
			}

			$record = [
				'administrationId' => $administrationId,
				'invoiceNumber' => $invoiceNumber,
				'supplierId' => $supplierId,
				'invoiceDate' => trim((string)($row['invoiceDate'] ?? '')),
				'totalInclVat' => $this->toCents($row['amount'] ?? null),
				'totalVat' => $this->toCents($row['vatAmount'] ?? null),
				'currency' => 'EUR',
				'sourceFormat' => 'csv',
				'statusCode' => SupplierInvoiceService::STATUS_RECEIVED,
				'createdAt' => date('c'),
			];

			$saved = $this->saveSupplierInvoice($record);
			$records[] = $saved;
			$imported++;
		}//end foreach

		return new JSONResponse(
			[
				'imported' => $imported,
				'skipped' => $skipped,
				'records' => $records,
			],
			Http::STATUS_OK
		);

	}//end importCsv()

	/**
	 * Pre-check for an existing SupplierInvoice with the same
	 * (administrationId, invoiceNumber, supplierId) tuple (REQ-BIM-005).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceNumber Invoice number.
	 * @param string $supplierId Supplier id.
	 *
	 * @return bool True when a matching record already exists.
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function duplicateExists(string $administrationId, string $invoiceNumber, string $supplierId): bool {
		if ($invoiceNumber === '') {
			return false;
		}

		try {
			$rows = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SUPPLIER_INVOICE_SCHEMA)
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'invoiceNumber' => $invoiceNumber,
							'supplierId' => $supplierId,
						],
					]
				);
		} catch (\Throwable $e) {
			// A lookup failure must not mask a real import; treat as "no
			// duplicate" and let the service's own idempotency guard the
			// write.
			return false;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return true;
			}
		}

		return false;
	}//end duplicateExists()

	/**
	 * Persist a SupplierInvoice via OR's real ObjectService API (saveObject).
	 *
	 * @param array<string,mixed> $record SupplierInvoice payload.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function saveSupplierInvoice(array $record): array {
		$result = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SUPPLIER_INVOICE_SCHEMA)
			->saveObject($record);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
		// is_array() arm here was unreachable by type and this helper returned
		// the INPUT record on every import — the stored invoice's id/uuid never
		// reached the response.
		return (array)$result->jsonSerialize();
	}//end saveSupplierInvoice()

	/**
	 * Map a service RuntimeException to the right HTTP status.
	 *
	 * "already exists" → 409 (REQ-BIM-005); "Administration not found" →
	 * 403; anything else (malformed UBL, missing InvoiceNumber) → 422.
	 *
	 * @param RuntimeException $e The service exception.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function mapServiceException(RuntimeException $e): JSONResponse {
		$message = $e->getMessage();

		if (stripos($message, 'already exists') !== false) {
			return new JSONResponse(
				['error' => 'This invoice number already exists for this supplier'],
				Http::STATUS_CONFLICT
			);
		}

		if (stripos($message, 'Administration not found') !== false) {
			return new JSONResponse(['error' => $message], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end mapServiceException()

	/**
	 * Read the uploaded file contents + resolve its format.
	 *
	 * Prefers a multipart `file` upload; falls back to a JSON body carrying
	 * `contents` + `format`. The format is taken from the explicit `format`
	 * param, else inferred from the file name extension.
	 *
	 * @return array{0:string,1:string} [contents, format]
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function readUpload(): array {
		$explicitFormat = strtolower(trim((string)$this->request->getParam('format', '')));

		$file = $this->request->getUploadedFile('file');
		if (is_array($file) === true && isset($file['tmp_name']) === true && $file['tmp_name'] !== '') {
			$contents = (string)file_get_contents($file['tmp_name']);
			if ($explicitFormat !== '') {
				$format = $explicitFormat;
			} else {
				$format = $this->inferFormat((string)($file['name'] ?? ''), $contents);
			}

			return [$contents, $format];
		}

		$body = $this->decodeBody();
		$contents = (string)($body['contents'] ?? '');
		$format = $explicitFormat;
		if ($format === '') {
			$format = strtolower(trim((string)($body['format'] ?? '')));
		}

		if ($format === '' && $contents !== '') {
			$format = $this->inferFormat((string)($body['filename'] ?? ''), $contents);
		}

		return [$contents, $format];
	}//end readUpload()

	/**
	 * Infer the import format from a file name extension, falling back to a
	 * content sniff (a leading `<` implies XML/UBL).
	 *
	 * @param string $fileName File name (may be empty).
	 * @param string $contents Raw contents (for the sniff fallback).
	 *
	 * @return string One of ubl|csv|pdf|'' (unknown).
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function inferFormat(string $fileName, string $contents): string {
		$extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
		if ($extension === 'xml' || $extension === 'ubl') {
			return 'ubl';
		}

		if ($extension === 'csv') {
			return 'csv';
		}

		if ($extension === 'pdf') {
			return 'pdf';
		}

		$trimmed = ltrim($contents);
		if (str_starts_with($trimmed, '%PDF') === true) {
			return 'pdf';
		}

		if (str_starts_with($trimmed, '<') === true) {
			return 'ubl';
		}

		if ($trimmed !== '' && str_contains($trimmed, ',') === true) {
			return 'csv';
		}

		return '';
	}//end inferFormat()

	/**
	 * Parse the documented CSV (header
	 * supplier,invoiceNumber,invoiceDate,amount,vatAmount) into a list of
	 * associative rows keyed by header column.
	 *
	 * @param string $csv Raw CSV text.
	 *
	 * @return array<int,array<string,string>>
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function parseCsv(string $csv): array {
		$lines = preg_split('/\r\n|\r|\n/', trim($csv));
		if (is_array($lines) === false || count($lines) < 2) {
			return [];
		}

		$header = str_getcsv((string)array_shift($lines), escape: '\\');
		$header = array_map(static fn ($h): string => trim((string)$h), $header);

		$rows = [];
		foreach ($lines as $line) {
			if (trim((string)$line) === '') {
				continue;
			}

			$cells = str_getcsv((string)$line, escape: '\\');
			$row = [];
			foreach ($header as $index => $key) {
				if ($key === '') {
					continue;
				}

				if (isset($cells[$index]) === true) {
					$row[$key] = trim((string)$cells[$index]);
				} else {
					$row[$key] = '';
				}
			}

			$rows[] = $row;
		}//end foreach

		return $rows;
	}//end parseCsv()

	/**
	 * Convert a decimal amount string to integer euro cents (half-up).
	 *
	 * @param mixed $value Raw amount (string/number/null).
	 *
	 * @return int|null Cents, or null when no usable value.
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function toCents(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		$normalised = str_replace(',', '.', (string)$value);
		if (is_numeric($normalised) === false) {
			return null;
		}

		return (int)round(((float)$normalised * 100), 0, PHP_ROUND_HALF_UP);
	}//end toCents()

	/**
	 * Decode the JSON request body, falling back to POST/GET params.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function decodeBody(): array {
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		$params = $this->request->getParams();
		if (is_array($params) === true) {
			return $params;
		}

		return [];
	}//end decodeBody()

	/**
	 * Resolve the administration id server-side (never client-supplied).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private function resolveAdministrationId(): string {
		try {
			$context = $this->administrationContext->buildContext();
			$candidate = (string)($context['activeAdministrationId'] ?? '');
			if ($candidate !== '') {
				return $candidate;
			}
		} catch (\Throwable $e) {
			// Fall through to default.
		}

		return 'default';
	}//end resolveAdministrationId()
}//end class
