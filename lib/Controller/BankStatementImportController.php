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

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\StatementParser;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * HTTP API for importing a bank statement file into BankStatement + lines.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class BankStatementImportController extends Controller {

	/**
	 * Allowed statement formats accepted by the import endpoint.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_FORMATS = ['camt053', 'mt940', 'csv'];

	/**
	 * Register slug all shillinq objects live in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'shillinq';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param StatementParser $parser Deterministic CAMT.053/MT940/CSV parser.
	 * @param AdministrationContextService $administrationContext Server-resolved tenant scope.
	 * @param IUserSession $session User session.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param IL10N $l10n Translation service for error-response messages (ADR-050).
	 */
	public function __construct(
		IRequest $request,
		private readonly StatementParser $parser,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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
			$admin = $this->resolveAdministrationId();
			$payload = $this->readPayload();

			$format = strtolower(trim((string)($payload['format'] ?? '')));
			if (in_array($format, self::ALLOWED_FORMATS, true) === false) {
				return new JSONResponse(
					['error' => 'Unsupported or missing format (expected camt053, mt940 or csv)'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$contents = (string)($payload['contents'] ?? '');
			if (trim($contents) === '') {
				return new JSONResponse(['error' => 'Empty file — nothing to import'], Http::STATUS_BAD_REQUEST);
			}

			$glAccountId = trim((string)($payload['glAccountId'] ?? ''));

			$parsed = $this->parser->parse(contents: $contents, format: $format);
			if (empty($parsed) === true) {
				return new JSONResponse(
					['error' => 'Could not parse any transactions from the supplied file'],
					Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}


			$transactionCount = count($parsed);
			$statement = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema('BankStatement')
				->saveObject(
					[
						'bankConnectionId' => 'manual-import',
						'statementFormat' => $format,
						'statementDate' => (new DateTimeImmutable())->format('Y-m-d'),
						'transactionCount' => $transactionCount,
						'administrationId' => $admin,
						'glAccountId' => $glAccountId,
					]
				);

			// ADR-084: saveObject() returns an ObjectEntityInterface, and that
			// interface extends JsonSerializable ONLY -- it does not implement
			// ArrayAccess. The previous `$statement['id'] ?? $statement['uuid']`
			// therefore raised `Error: Cannot use object of type … as array`,
			// which `??` cannot suppress, and the surrounding catch (\Throwable)
			// turned it into a bare HTTP 500 on EVERY import. getObject() is
			// declared on the contract and returns the body as an array.
			$statementBody = $statement->getObject();
			$statementId   = (string)($statementBody['id'] ?? $statement->getUuid() ?? '');

			$lineNumber = 0;
			foreach ($parsed as $line) {
				$lineNumber++;
				$this->objectService
					->setRegister(self::REGISTER_SLUG)
					->setSchema('BankStatementLine')
					->saveObject($this->mapLine(line: $line, statementId: $statementId, admin: $admin, lineNumber: $lineNumber));
			}

			// Honest counts: auto-matching runs later on the reconciliation
			// page, so every freshly-imported line is unmatched.
			$matchedCount = 0;
			$unmatchedCount = $transactionCount;

			return new JSONResponse(
				[
					'statementId' => $statementId,
					'transactionCount' => $transactionCount,
					'matchedCount' => $matchedCount,
					'unmatchedCount' => $unmatchedCount,
				],
				Http::STATUS_OK
			);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error('BankStatementImportController.import failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Invalid bank statement import request'),
					'error' => 'bank-statement-import-invalid-input',
				],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error('BankStatementImportController.import failed', ['exception' => $e]);
			return new JSONResponse(
				['message' => $this->l10n->t('Unable to import bank statement'), 'error' => 'bank-statement-import-failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end import()

	/**
	 * Map a parser line map onto the BankStatementLine schema field shape.
	 *
	 * The parser emits remittanceInfo/endToEndRef/status; the schema fields are
	 * narrative/reference/matchState. This is where that translation happens.
	 *
	 * @param array<string,mixed> $line One normalised parser line.
	 * @param string $statementId The owning BankStatement id.
	 * @param string $admin Server-resolved administration id.
	 * @param int $lineNumber 1-based sequential line number.
	 *
	 * @return array<string,mixed> The BankStatementLine payload.
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	private function mapLine(array $line, string $statementId, string $admin, int $lineNumber): array {
		return [
			'statementId' => $statementId,
			'lineNumber' => $lineNumber,
			'valueDate' => (string)($line['valueDate'] ?? ''),
			'amount' => (float)($line['amount'] ?? 0),
			'currency' => (string)($line['currency'] ?? 'EUR'),
			'matchState' => (string)($line['status'] ?? 'unmatched'),
			'administrationId' => $admin,
			'counterpartyName' => (string)($line['counterpartyName'] ?? ''),
			'counterpartyIban' => (string)($line['counterpartyIban'] ?? ''),
			'reference' => (string)($line['endToEndRef'] ?? ''),
			'narrative' => (string)($line['remittanceInfo'] ?? ''),
			'rawPayload' => json_encode($line),
		];

	}//end mapLine()

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
		$format = (string)$this->request->getParam('format', '');
		$glAccountId = (string)$this->request->getParam('glAccountId', '');

		// 1) Multipart file upload.
		$uploaded = $this->request->getUploadedFile('file');
		if (is_array($uploaded) === true
			&& isset($uploaded['tmp_name']) === true
			&& is_uploaded_file((string)$uploaded['tmp_name']) === true
		) {
			$raw = file_get_contents((string)$uploaded['tmp_name']);
			if ($raw !== false) {
				$rawContents = $raw;
			} else {
				$rawContents = '';
			}

			return [
				'contents' => $rawContents,
				'format' => $format,
				'glAccountId' => $glAccountId,
			];
		}

		// 2) JSON body with raw/base64 contents.
		$rawBody = file_get_contents('php://input');
		if ($rawBody !== false && $rawBody !== '') {
			$decoded = json_decode($rawBody, true);
			if (is_array($decoded) === true) {
				$contents = (string)($decoded['contents'] ?? '');
				// Tolerate base64-wrapped payloads from the browser.
				if ($contents !== '' && ($decoded['encoding'] ?? '') === 'base64') {
					$maybe = base64_decode($contents, true);
					if ($maybe !== false) {
						$contents = $maybe;
					}
				}

				return [
					'contents' => $contents,
					'format' => (string)($decoded['format'] ?? $format),
					'glAccountId' => (string)($decoded['glAccountId'] ?? $glAccountId),
				];
			}
		}

		// 3) Plain request params fallback.
		return [
			'contents' => (string)$this->request->getParam('contents', ''),
			'format' => $format,
			'glAccountId' => $glAccountId,
		];

	}//end readPayload()

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
