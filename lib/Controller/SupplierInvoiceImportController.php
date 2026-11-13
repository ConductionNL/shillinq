<?php

/**
 * Supplier Invoice Import Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceChecksInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
	 * @param SupplierInvoiceServiceInterface $supplierInvoiceService Deterministic UBL ingestion + parser.
	 * @param AdministrationContextServiceInterface $administrationContext Server-resolved tenant scope (ADR-005).
	 * @param IUserSession $session User session.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param SupplierInvoiceChecksInterface  $checks The one duplicate rule every channel uses (purchasing-supplier-invoice-intake REQ-PSII-003).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SupplierInvoiceServiceInterface $supplierInvoiceService,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly SupplierInvoiceChecksInterface $checks,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
			return $this->importedUpload(administrationId: $this->resolveAdministrationId());
		} catch (\Throwable $e) {
			$this->logger->error('SupplierInvoiceImportController.import failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end import()

	/**
	 * Read the upload and import it by its format; empty contents are a 422.
	 *
	 * @param string $administrationId Server-resolved tenant scope.
	 *
	 * @return JSONResponse
	 */
	private function importedUpload(string $administrationId): JSONResponse {
		[$contents, $format] = $this->readUpload();
		if ($contents === '') {
			return new JSONResponse(['error' => 'No file contents were uploaded'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return match ($format) {
			'ubl' => $this->importUbl(administrationId: $administrationId, ublXml: $contents),
			'csv' => $this->importCsv(administrationId: $administrationId, csv: $contents),
			'pdf' => self::pdfDeferred(),
			default => new JSONResponse(['error' => 'Unsupported import format. Use ubl, csv or pdf.'], Http::STATUS_UNPROCESSABLE_ENTITY),
		};
	}//end importedUpload()

	/**
	 * Honest deferral — no OCR engine is bundled (REQ-BIM-002).
	 *
	 * @return JSONResponse
	 */
	private static function pdfDeferred(): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.',
				'deferred' => 'pdf-ocr',
			],
			Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end pdfDeferred()

	/**
	 * The 409 for an invoice number the supplier already used (REQ-BIM-005).
	 *
	 * @return JSONResponse
	 */
	private static function duplicateInvoice(): JSONResponse {
		return new JSONResponse(['error' => 'This invoice number already exists for this supplier'], Http::STATUS_CONFLICT);
	}//end duplicateInvoice()

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
			$parsed = $this->supplierInvoiceService->resolveSupplier(
				administrationId: $administrationId,
				parsed: $this->supplierInvoiceService->parseUblInvoice(ublXml: $ublXml)
			);
			if ($this->duplicateOf(administrationId: $administrationId, invoice: $parsed) !== null) {
				return self::duplicateInvoice();
			}

			$record = $this->supplierInvoiceService->ingestUBLInvoice(administrationId: $administrationId, ublXml: $ublXml);

			return new JSONResponse(['imported' => 1, 'record' => $record], Http::STATUS_OK);
		} catch (RuntimeException $e) {
			return $this->mapServiceException($e);
		}
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
			return new JSONResponse(['error' => 'CSV did not contain any data rows'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($this->csvOutcome(administrationId: $administrationId, rows: $rows), Http::STATUS_OK);
	}//end importCsv()

	/**
	 * Import every row; skipped rows are listed with their reason.
	 *
	 * @param string                           $administrationId Server-resolved tenant scope.
	 * @param array<int,array<string,string>> $rows             The parsed data rows.
	 *
	 * @return array{imported:int,skipped:int,skippedRows:array<int,array<string,string>>,records:array<int,array<string,mixed>>}
	 */
	private function csvOutcome(string $administrationId, array $rows): array {
		$skippedRows = [];
		$records = [];
		foreach ($rows as $row) {
			$outcome = $this->importCsvRow(administrationId: $administrationId, row: $row);
			if (isset($outcome['skipped']) === true) {
				$skippedRows[] = $outcome['skipped'];
				continue;
			}

			$records[] = $outcome['record'];
		}

		return ['imported' => count($records), 'skipped' => count($skippedRows), 'skippedRows' => $skippedRows, 'records' => $records];
	}//end csvOutcome()

	/**
	 * Import one CSV row: skipped when its number or supplier is missing, or it repeats an earlier invoice.
	 *
	 * @param string               $administrationId Server-resolved tenant scope.
	 * @param array<string,string> $row              The row by header column.
	 *
	 * @return array{skipped?:array<string,string>,record?:array<string,mixed>} The skip entry, or the saved record.
	 */
	private function importCsvRow(string $administrationId, array $row): array {
		$invoiceNumber = trim((string)($row['invoiceNumber'] ?? ''));
		$supplier = $this->csvSupplier(administrationId: $administrationId, value: trim((string)($row['supplier'] ?? '')));
		if ($invoiceNumber === '' || $supplier === []) {
			return ['skipped' => ['invoiceNumber' => $invoiceNumber, 'reason' => 'incomplete']];
		}

		$duplicate = $this->duplicateOf(administrationId: $administrationId, invoice: $supplier + ['invoiceNumber' => $invoiceNumber]);
		if ($duplicate !== null) {
			return ['skipped' => ['invoiceNumber' => $invoiceNumber, 'reason' => 'duplicate', 'duplicateOfId' => $duplicate]];
		}

		$base = $supplier + ['administrationId' => $administrationId, 'invoiceNumber' => $invoiceNumber];

		return ['record' => $this->saveSupplierInvoice(self::csvRecord(base: $base, row: $row))];
	}//end importCsvRow()

	/**
	 * The SupplierInvoice payload of a CSV row: received, EUR, amounts in cents.
	 *
	 * @param array<string,string> $base The supplier, administration and invoice number.
	 * @param array<string,string> $row  The row by header column.
	 *
	 * @return array<string,mixed>
	 */
	private static function csvRecord(array $base, array $row): array {
		return $base + [
			'invoiceDate' => trim((string)($row['invoiceDate'] ?? '')),
			'totalInclVat' => self::toCents($row['amount'] ?? null),
			'totalVat' => self::toCents($row['vatAmount'] ?? null),
			'currency' => 'EUR',
			'sourceFormat' => 'csv',
			'statusCode' => SupplierInvoiceServiceInterface::STATUS_RECEIVED,
			'createdAt' => date('c'),
		];
	}//end csvRecord()

	/**
	 * The earlier invoice this one repeats, by the one rule every channel uses.
	 *
	 * A failed lookup counts as a possible duplicate (REQ-PSII-003).
	 *
	 * @param string              $administrationId Tenant scope.
	 * @param array<string,mixed> $invoice          invoiceNumber and supplierId or supplierIdentifier.
	 *
	 * @return string|null The earlier record's id, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	private function duplicateOf(string $administrationId, array $invoice): ?string {
		$number = (string)($invoice['invoiceNumber'] ?? '');
		if (isset($invoice['supplierId']) === true) {
			return $this->checks->duplicateOf(administrationId: $administrationId, supplierId: (string)$invoice['supplierId'], invoiceNumber: $number);
		}

		return $this->checks->duplicateOfUnresolved(
			administrationId: $administrationId,
			supplierIdentifier: (string)($invoice['supplierIdentifier'] ?? ''),
			invoiceNumber: $number
		);

	}//end duplicateOf()

	/**
	 * The supplier of a CSV row: the payee it names (uuid or vendor number,
	 * else KvK or VAT number), else the value as stated.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $value            The row's supplier column.
	 *
	 * @return array<string,string> supplierId and/or supplierIdentifier; empty for an empty value.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	private function csvSupplier(string $administrationId, string $value): array {
		if ($value === '') {
			return [];
		}

		$payee = $this->checks->payee(supplierId: $value);
		if ($payee === null) {
			$payee = $this->checks->resolvePayee(administrationId: $administrationId, kvkNumber: $value, vatNumber: $value);
		}

		if ($payee === null || (string)($payee['id'] ?? '') === '') {
			return ['supplierIdentifier' => $value];
		}

		return ['supplierId' => (string)$payee['id'], 'supplierIdentifier' => $value];

	}//end csvSupplier()

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
			return self::duplicateInvoice();
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

		return $this->fileUpload(explicitFormat: $explicitFormat) ?? $this->bodyUpload(explicitFormat: $explicitFormat);
	}//end readUpload()

	/**
	 * The multipart `file` upload and its format, or null when there is none.
	 *
	 * @param string $explicitFormat The lower-cased `format` parameter, or ''.
	 *
	 * @return array{0:string,1:string}|null [contents, format]
	 */
	private function fileUpload(string $explicitFormat): ?array {
		$file = $this->request->getUploadedFile('file');
		if (self::hasTmpFile(file: $file) === false) {
			return null;
		}

		$contents = (string)file_get_contents($file['tmp_name']);
		if ($explicitFormat !== '') {
			return [$contents, $explicitFormat];
		}

		return [$contents, $this->inferFormat((string)($file['name'] ?? ''), $contents)];
	}//end fileUpload()

	/**
	 * Whether an uploaded-file entry points at a temp file.
	 *
	 * @param mixed $file What getUploadedFile() answered.
	 *
	 * @return bool
	 */
	private static function hasTmpFile(mixed $file): bool {
		return is_array($file) === true && isset($file['tmp_name']) === true && $file['tmp_name'] !== '';
	}//end hasTmpFile()

	/**
	 * The body's `contents` and its format: the parameter, else the body's `format`, else inferred.
	 *
	 * @param string $explicitFormat The lower-cased `format` parameter, or ''.
	 *
	 * @return array{0:string,1:string} [contents, format]
	 */
	private function bodyUpload(string $explicitFormat): array {
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
	}//end bodyUpload()

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
		$byExtension = match (strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION))) {
			'xml', 'ubl' => 'ubl',
			'csv' => 'csv',
			'pdf' => 'pdf',
			default => '',
		};
		if ($byExtension !== '') {
			return $byExtension;
		}

		return self::sniffFormat(contents: $contents);
	}//end inferFormat()

	/**
	 * The format a file's contents suggest: `%PDF` → pdf, a leading `<` → ubl, a comma → csv.
	 *
	 * @param string $contents Raw contents.
	 *
	 * @return string One of ubl|csv|pdf|'' (unknown).
	 */
	private static function sniffFormat(string $contents): string {
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
	}//end sniffFormat()

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

		$header = array_map(static fn ($h): string => trim((string)$h), str_getcsv((string)array_shift($lines), escape: '\\'));
		$rows = [];
		foreach ($lines as $line) {
			if (trim((string)$line) !== '') {
				$rows[] = self::csvRow(header: $header, line: (string)$line);
			}
		}

		return $rows;
	}//end parseCsv()

	/**
	 * One data line keyed by the header; blank header columns are dropped, missing cells are ''.
	 *
	 * @param array<int,string> $header The trimmed header columns.
	 * @param string            $line   The data line.
	 *
	 * @return array<string,string>
	 */
	private static function csvRow(array $header, string $line): array {
		$cells = str_getcsv($line, escape: '\\');
		$row = [];
		foreach ($header as $index => $key) {
			if ($key !== '') {
				$row[$key] = trim((string)($cells[$index] ?? ''));
			}
		}

		return $row;
	}//end csvRow()

	/**
	 * Convert a decimal amount string to integer euro cents (half-up).
	 *
	 * @param mixed $value Raw amount (string/number/null).
	 *
	 * @return int|null Cents, or null when no usable value.
	 *
	 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
	 */
	private static function toCents(mixed $value): ?int {
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
		$decoded = self::jsonInput();
		if ($decoded !== null) {
			return $decoded;
		}

		$params = $this->request->getParams();
		if (is_array($params) === true) {
			return $params;
		}

		return [];
	}//end decodeBody()

	/**
	 * The raw request body when it is a JSON object or array.
	 *
	 * @return array<string,mixed>|null Null for an empty, unreadable or non-array body.
	 */
	private static function jsonInput(): ?array {
		$raw = file_get_contents('php://input');
		if ($raw === false || $raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end jsonInput()

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
