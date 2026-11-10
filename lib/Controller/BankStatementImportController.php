<?php

/**
 * Bank Statement Import Controller
 *
 * The real import endpoint behind the BankStatementWizard
 * (shillinq-bank-statement-wizard, REQ-BSW-004). It accepts a CAMT.053 /
 * MT940 / CSV bank-statement file (multipart upload OR a JSON body carrying
 * raw/base64 contents + format), parses it with the deterministic
 * StatementParser, and persists exactly one BankStatement plus one
 * BankStatementLine per parsed line through OpenRegister's ObjectService.
 *
 * Endpoint is authenticated (#[NoAdminRequired]). Per-object scope is
 * delegated to the server-resolved administration (AdministrationContextService
 * multitenancy); the controller never accepts a client-supplied
 * administrationId — IDOR-safe per ADR-005.
 *
 * Auto-matching is the reconciliation engine's job (invoked after import on the
 * reconciliation page), so this endpoint honestly reports matchedCount = 0 and
 * unmatchedCount = transactionCount. It does not fabricate match counts.
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
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Lifecycle\StatementParser;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\StatementIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * HTTP API for importing a bank statement file into BankStatement + lines.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 */
class BankStatementImportController extends Controller {

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param StatementParser $parser Deterministic CAMT.053/MT940/CSV parser.
	 * @param AdministrationContextService $administrationContext Server-resolved tenant scope.
	 * @param IUserSession $session User session.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translation service for error-response messages (ADR-050).
	 * @param StatementIntakeService $intake Writes the statement and its lines (REQ-BCON-002).
	 */
	public function __construct(
		IRequest $request,
		private readonly StatementParser $parser,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly StatementIntakeService $intake,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Import a bank statement file (POST /api/v1/bank-statements/import).
	 *
	 * Accepts either a multipart 'file' upload or a JSON body with raw/base64
	 * 'contents'. The 'format' must be one of camt053/mt940/csv. On a
	 * successful parse it creates one BankStatement and one BankStatementLine
	 * per parsed line, all scoped to the server-resolved administration, and
	 * returns the created statementId plus the transaction / match counts.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function import(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(
				['message' => $this->l10n->t('Not logged in'), 'error' => 'bank-statement-import-unauthenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$import = new StatementImport(administrationId: $this->resolveAdministrationId(), payload: $this->readPayload());
			return $this->importStatement(import: $import);
		} catch (\Throwable $e) {
			return $this->importFailed(e: $e);
		}

	}//end import()

	/**
	 * Check, parse and ingest one statement.
	 *
	 * @param StatementImport $import The normalised import.
	 *
	 * @return JSONResponse 200 with the counts; 400 on format/contents; 422 when nothing parsed.
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	private function importStatement(StatementImport $import): JSONResponse {
		if ($import->supportedFormat() === false) {
			return new JSONResponse(['error' => 'Unsupported or missing format (expected camt053, mt940 or csv)'], Http::STATUS_BAD_REQUEST);
		}

		if ($import->isEmpty() === true) {
			return new JSONResponse(['error' => 'Empty file — nothing to import'], Http::STATUS_BAD_REQUEST);
		}

		$parsed = $this->parser->parse(contents: $import->contents, format: $import->format);
		if (empty($parsed) === true) {
			return new JSONResponse(['error' => 'Could not parse any transactions from the supplied file'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->ingested(import: $import, parsed: $parsed);
	}//end importStatement()

	/**
	 * Write the parsed lines and answer the counts.
	 *
	 * @param StatementImport                 $import The normalised import.
	 * @param array<int, array<string,mixed>> $parsed The parsed lines.
	 *
	 * @return JSONResponse 200 with statementId and the transaction / match counts.
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	private function ingested(StatementImport $import, array $parsed): JSONResponse {
		// One intake for files and the bank feed (banking-connected-accounts
		// REQ-BCON-002): it writes every field the merged register requires,
		// which the old inline loop did not (lineId, status, a date-time
		// statementDate), and skips lines already on file for the account.
		$result = $this->intake->ingest(
			administrationId: $import->administrationId,
			iban: trim((string)$this->request->getParam('bankAccountIban', '')),
			lines: $parsed,
			meta: ['source' => 'file', 'format' => $import->format, 'glAccountId' => $import->glAccountId]
		);
		$transactionCount = count($result['written']);

		// Honest counts: auto-matching runs later on the reconciliation
		// page, so every freshly-imported line is unmatched.
		$counts = ['transactionCount' => $transactionCount, 'matchedCount' => 0, 'unmatchedCount' => $transactionCount];
		return new JSONResponse(['statementId' => $result['statementId']] + $counts, Http::STATUS_OK);
	}//end ingested()

	/**
	 * Log a failed import and answer it: invalid input is a 400, anything else a 500.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	private function importFailed(\Throwable $e): JSONResponse {
		$this->logger->error('BankStatementImportController.import failed', ['exception' => $e]);
		if ($e instanceof \InvalidArgumentException) {
			return new JSONResponse(
				['message' => $this->l10n->t('Invalid bank statement import request'), 'error' => 'bank-statement-import-invalid-input'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			['message' => $this->l10n->t('Unable to import bank statement'), 'error' => 'bank-statement-import-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end importFailed()

	/**
	 * Read the import payload from a multipart upload or a JSON/raw body.
	 *
	 * Resolution order: a 'file' multipart upload first; then a JSON body with
	 * 'contents' (raw or base64) + 'format'; then plain request params. Returns
	 * a normalised ['contents' => string, 'format' => string,
	 * 'glAccountId' => string] map. Never throws.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	private function readPayload(): array {
		$reader = new StatementBodyReader(request: $this->request);

		// 1) Multipart file upload.
		$uploaded = $reader->uploadedContents();
		if ($uploaded !== null) {
			return $this->payload(contents: $uploaded, body: []);
		}

		// 2) JSON body with raw/base64 contents.
		$decoded = $reader->jsonBody();
		if ($decoded !== null) {
			return $this->payload(contents: $reader->bodyContents(decoded: $decoded), body: $decoded);
		}

		// 3) Plain request params fallback.
		return $this->payload(contents: (string)$this->request->getParam('contents', ''), body: []);

	}//end readPayload()

	/**
	 * The normalised payload map: format and glAccountId from the JSON body
	 * when it carries them, else from the request params.
	 *
	 * @param string              $contents The file contents.
	 * @param array<string,mixed> $body     The decoded JSON body ([] when none).
	 *
	 * @return array<string,mixed>
	 */
	private function payload(string $contents, array $body): array {
		return [
			'contents' => $contents,
			'format' => (string)($body['format'] ?? $this->request->getParam('format', '')),
			'glAccountId' => (string)($body['glAccountId'] ?? $this->request->getParam('glAccountId', '')),
		];
	}//end payload()

	/**
	 * Resolve the administration id server-side (never from the client).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
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
