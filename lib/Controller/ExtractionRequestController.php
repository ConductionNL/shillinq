<?php

/**
 * Extraction Request Controller
 *
 * Change receipt-extraction-consume (REQ-RXC-004 / REQ-RXC-005) — a thin
 * proxy so the BillImportModal / Receipt capture frontend never needs
 * docudesk credentials directly (design.md "API Design"):
 *
 *  - `request()` (POST /api/v1/extraction/request) forwards a (re-)extraction
 *    request to docudesk via {@see DocudeskExtractionClient} (REQ-RXC-005);
 *    since gl-account-suggestion-consume, it also captures the docudesk
 *    `financialExtraction` id from the synchronous response and persists it
 *    on the target draft (REQ-GAC-001).
 *  - `confirm()` (PUT /api/v1/extraction/drafts/{id}) records an operator
 *    correction on an existing extraction draft via
 *    {@see ExtractionPrefillService::recordCorrection()} (REQ-RXC-004) and
 *    persists it through the real OR ObjectService API; since
 *    gl-account-suggestion-consume, it also posts the committed GL-account
 *    booking back to docudesk as a correction whenever the draft carries a
 *    known extraction id (REQ-GAC-005).
 *  - `suggestGlAccount()` (POST /api/v1/extraction/drafts/{id}/suggest-account)
 *    is new in gl-account-suggestion-consume: proxies a GL-account suggestion
 *    request to docudesk, supplying shillinq's own active chart-of-accounts
 *    as candidates (REQ-GAC-002/003), degrading gracefully to
 *    `{suggestion: null}` — never an error — when the extraction id is
 *    unknown or docudesk is unreachable (REQ-GAC-006).
 *
 * All actions are `#[NoAdminRequired]` and IDOR-safe (ADR-005): the target
 * draft's `administrationId` is always checked against the caller's
 * accessible administrations via `AdministrationContextService::canAccess()`
 * before any read/write — never trusting a client-supplied administration
 * scope.
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
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Extraction\ChartOfAccountsCandidateService;
use OCA\Shillinq\Service\Extraction\DocudeskExtractionClient;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCA\Shillinq\Service\Extraction\GlAccountSuggestionClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP API for the extraction re-request proxy, correction commit, and
 * GL-account suggestion proxy (receipt-extraction-consume,
 * gl-account-suggestion-consume).
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class ExtractionRequestController extends Controller {
	/**
	 * The OpenRegister register slug for shillinq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'shillinq';

	/**
	 * Schemas this endpoint may operate on (REQ-RXC-001 docType targets).
	 *
	 * @var array<string>
	 */
	private const ALLOWED_SCHEMAS = [
		ExtractionPrefillService::SCHEMA_SUPPLIER_INVOICE,
		ExtractionPrefillService::SCHEMA_RECEIPT,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param DocudeskExtractionClient $extractionClient Outbound docudesk request client.
	 * @param GlAccountSuggestionClient $suggestionClient Outbound docudesk suggestion/correction client.
	 * @param ChartOfAccountsCandidateService $candidateService Shillinq's own chart-of-accounts candidates.
	 * @param ExtractionPrefillService $prefillService Correction-recording service.
	 * @param AdministrationContextService $administrationContext Server-resolved tenant scope (ADR-005).
	 * @param IUserSession $session User session.
	 * @param ContainerInterface $container DI container (OR ObjectService).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly DocudeskExtractionClient $extractionClient,
		private readonly GlAccountSuggestionClient $suggestionClient,
		private readonly ChartOfAccountsCandidateService $candidateService,
		private readonly ExtractionPrefillService $prefillService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $session,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * (Re-)request docudesk extraction for a document (REQ-RXC-005).
	 *
	 * Accepts `{documentUri, docType, id?}`. When `id` is supplied it MUST
	 * resolve to an existing draft the caller may access (IDOR guard); when
	 * omitted (first-ever extraction of a document not yet drafted in
	 * shillinq) the request proceeds without an administration check — there
	 * is nothing shillinq-side to scope yet, and the resulting draft is
	 * created by the listener once the event arrives.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	#[NoAdminRequired]
	public function request(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$documentUri = trim((string)$this->request->getParam('documentUri', ''));
		$docType = trim((string)$this->request->getParam('docType', ''));
		$id = trim((string)$this->request->getParam('id', ''));

		if ($documentUri === '' || in_array($docType, ['receipt', 'supplier-invoice'], true) === false) {
			return new JSONResponse(
				['error' => 'documentUri and a valid docType (receipt|supplier-invoice) are required'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$schema = '';
		if ($id !== '') {
			$schema = (string)$this->prefillService->schemaForDocType(docType: $docType);
			$guard = $this->guardDraftAccess(schema: $schema, id: $id);
			if ($guard !== null) {
				return $guard;
			}
		}

		$result = $this->extractionClient->requestExtraction(documentUri: $documentUri, docType: $docType);
		if ($result['success'] === false) {
			return new JSONResponse(
				['error' => $result['error'] ?? 'docudesk extraction request failed', 'accepted' => false],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		// REQ-GAC-001: capture the docudesk financialExtraction id onto the
		// existing draft, when one was targeted — the only channel available
		// to later request a GL-account suggestion for it.
		$extractionId = ($result['extractionId'] ?? null);
		if ($id !== '' && $schema !== '' && is_string($extractionId) === true && $extractionId !== '') {
			$this->persistExtractionId(schema: $schema, id: $id, extractionId: $extractionId);
		}

		return new JSONResponse(['accepted' => true], Http::STATUS_ACCEPTED);
	}//end request()

	/**
	 * Persist the docudesk `financialExtraction` id onto an existing draft
	 * (REQ-GAC-001). Best-effort: a failure is logged, not surfaced — the
	 * (re-)extraction request itself already succeeded and the suggestion
	 * feature simply stays unavailable for this draft until the id is
	 * captured on a later attempt.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $id OR object id.
	 * @param string $extractionId The docudesk financialExtraction object id.
	 *
	 * @return void
	 */
	private function persistExtractionId(string $schema, string $id, string $extractionId): void {
		$existing = $this->findById(schema: $schema, id: $id);
		if ($existing === null) {
			return;
		}

		$existing['docudeskExtractionId'] = $extractionId;

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema($schema)
				->saveObject($existing);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ExtractionRequestController: failed to persist docudeskExtractionId',
				['schema' => $schema, 'id' => $id, 'exception' => $e->getMessage()]
			);
		}

	}//end persistExtractionId()

	/**
	 * Commit an operator correction on an existing extraction draft (REQ-RXC-004).
	 *
	 * @param string $id The draft's OR object id.
	 * @param string $schema Schema query param (SupplierInvoice|Receipt).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	#[NoAdminRequired]
	public function confirm(string $id, string $schema = ''): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($schema, self::ALLOWED_SCHEMAS, true) === false) {
			return new JSONResponse(['error' => 'Unknown or missing schema'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$guard = $this->guardDraftAccess(schema: $schema, id: $id);
		if ($guard !== null) {
			return $guard;
		}

		$existing = $this->findById(schema: $schema, id: $id);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		$incomingFields = $this->decodeBody();
		// AdministrationId, id and the confidence/provenance bookkeeping
		// fields are never operator-editable via this endpoint.
		unset(
			$incomingFields['administrationId'],
			$incomingFields['id'],
			$incomingFields['fieldConfidence'],
			$incomingFields['overallConfidence'],
			$incomingFields['extractedFieldsOriginal'],
			$incomingFields['humanCorrected'],
			$incomingFields['extractionStatus']
		);

		$updated = $this->prefillService->recordCorrection(existingDraft: $existing, incomingFields: $incomingFields);

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$saved = $objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema($schema)
				->saveObject($updated);
		} catch (Throwable $e) {
			$this->logger->error('ExtractionRequestController.confirm failed to persist: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to save correction'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (is_array($saved) === true) {
			$record = $saved;
		} else {
			$record = $updated;
		}

		// REQ-GAC-005: feed every committed booking back to docudesk as a
		// correction — whether or not it matches the prior suggestion — so
		// the ranker's history reflects it. Best-effort: never blocks or
		// undoes the local booking that already succeeded above.
		$this->feedBookingBack(record: $record);

		return new JSONResponse(['record' => $record], Http::STATUS_OK);
	}//end confirm()

	/**
	 * Post the committed GL-account booking back to docudesk as a
	 * correction when the draft carries a known `docudeskExtractionId` and a
	 * non-empty `glAccount` (REQ-GAC-005). No-op, not an error, when either
	 * is absent — this is the graceful-degradation case (REQ-GAC-006).
	 *
	 * @param array<string,mixed> $record The just-persisted draft record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	private function feedBookingBack(array $record): void {
		$extractionId = (string)($record['docudeskExtractionId'] ?? '');
		$accountCode = trim((string)($record['glAccount'] ?? ''));
		if ($extractionId === '' || $accountCode === '') {
			return;
		}

		$accountLabel = $record['glAccountLabel'] ?? null;
		if (is_string($accountLabel) === false) {
			$accountLabel = null;
		}

		$this->suggestionClient->postCorrection(
			extractionId: $extractionId,
			accountCode: $accountCode,
			accountLabel: $accountLabel
		);

	}//end feedBookingBack()

	/**
	 * Compute a GL-account suggestion for a draft with a known docudesk
	 * extraction id (REQ-GAC-003), supplying shillinq's own active,
	 * administration-scoped chart of accounts as candidates (REQ-GAC-002).
	 * Degrades gracefully — never an error — when the extraction id is
	 * unknown, the account candidates cannot be resolved, or docudesk is
	 * unreachable/returns no suggestion (REQ-GAC-006).
	 *
	 * @param string $id The draft's OR object id.
	 * @param string $schema Schema query param (SupplierInvoice|Receipt).
	 *
	 * @return JSONResponse `{suggestion: {...}|null, reason?: string}`.
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	#[NoAdminRequired]
	public function suggestGlAccount(string $id, string $schema = ''): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($schema, self::ALLOWED_SCHEMAS, true) === false) {
			return new JSONResponse(['error' => 'Unknown or missing schema'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$guard = $this->guardDraftAccess(schema: $schema, id: $id);
		if ($guard !== null) {
			return $guard;
		}

		$draft = $this->findById(schema: $schema, id: $id);
		if ($draft === null) {
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		$extractionId = trim((string)($draft['docudeskExtractionId'] ?? ''));
		if ($extractionId === '') {
			return new JSONResponse(['suggestion' => null, 'reason' => 'extraction-id-unknown'], Http::STATUS_OK);
		}

		$administrationId = (string)($draft['administrationId'] ?? '');
		$candidateAccounts = $this->candidateService->activeCandidates(administrationId: $administrationId);

		$result = $this->suggestionClient->requestSuggestion(
			extractionId: $extractionId,
			candidateAccounts: $candidateAccounts
		);

		if ($result['success'] === false || is_array($result['suggestion']) === false) {
			return new JSONResponse(['suggestion' => null, 'reason' => 'provider-unavailable'], Http::STATUS_OK);
		}

		$suggested = (array)($result['suggestion']['suggestedAccounts'] ?? []);
		$top = $suggested[0] ?? null;
		if (is_array($top) === false) {
			return new JSONResponse(['suggestion' => null, 'reason' => 'no-suggestion'], Http::STATUS_OK);
		}

		$suggestion = [
			'code' => (string)($top['code'] ?? ''),
			'label' => ($top['label'] ?? null),
			'confidence' => (float)($top['confidence'] ?? 0),
			'rationale' => (string)($top['rationale'] ?? ''),
			'source' => (string)($result['suggestion']['source'] ?? 'none'),
		];

		$this->cacheSuggestion(schema: $schema, draft: $draft, suggestion: $suggestion);

		return new JSONResponse(['suggestion' => $suggestion], Http::STATUS_OK);
	}//end suggestGlAccount()

	/**
	 * Cache the last-fetched suggestion on the draft (design.md Seed Data —
	 * `suggestedGlAccount`), so a later commit-time diff needs no extra
	 * round-trip. Best-effort: a failure to persist the cache never blocks
	 * returning the suggestion to the caller.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $draft The draft to update.
	 * @param array<string,mixed> $suggestion The suggestion to cache.
	 *
	 * @return void
	 */
	private function cacheSuggestion(string $schema, array $draft, array $suggestion): void {
		$draft['suggestedGlAccount'] = $suggestion;

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema($schema)
				->saveObject($draft);
		} catch (Throwable $e) {
			$this->logger->info(
				'ExtractionRequestController: failed to cache suggestedGlAccount (non-blocking)',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
		}

	}//end cacheSuggestion()

	/**
	 * Load a draft by id and, when found, guard that the caller may access
	 * its administration (IDOR guard, ADR-005). Returns a masking 404
	 * JSONResponse when access is denied or the draft is missing/malformed,
	 * or NULL when the caller may proceed.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $id OR object id.
	 *
	 * @return JSONResponse|null
	 */
	private function guardDraftAccess(string $schema, string $id): ?JSONResponse {
		if ($schema === '' || $id === '') {
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		$existing = $this->findById(schema: $schema, id: $id);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		$administrationId = (string)($existing['administrationId'] ?? '');
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			// Masked 404, never 403 — never disclose that another tenant's
			// draft exists (REQ-MA-001).
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end guardDraftAccess()

	/**
	 * Find an object by id via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $id OR object id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findById(string $schema, string $id): ?array {
		try {
			// NOT findAll(['filters' => ['id' => …]]) — `filters` addresses
			// JSON properties and the entity's `id` is not one, so that shape
			// matched nothing for every value and this resolver returned null
			// for every object it was asked for.
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			return ObjectIdentifier::findOne(
				scoped: $objectService
					->setRegister(self::REGISTER_SLUG)
					->setSchema($schema),
				id: $id
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end findById()

	/**
	 * Decode the JSON request body, falling back to POST params.
	 *
	 * @return array<string,mixed>
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
}//end class
