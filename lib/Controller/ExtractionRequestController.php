<?php

/**
 * Extraction Request Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ChartOfAccountsCandidateServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DocudeskExtractionClientInterface;
use OCA\Shillinq\Kernel\Contracts\ExtractionPrefillServiceInterface;
use OCA\Shillinq\Kernel\Contracts\GlAccountSuggestionClientInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceChecksInterface;
use OCA\Shillinq\Service\Extraction\ExtractionDraftCommitter;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCA\Shillinq\Util\ObjectIdentifier;
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
	 * Administration roles that approve receipts and may correct anyone's.
	 *
	 * @var array<string>
	 */
	private const RECEIPT_APPROVER_ROLES = ['eigenaar', 'controller', 'boekhouder'];

	/**
	 * Schemas this endpoint may operate on (REQ-RXC-001 docType targets).
	 *
	 * @var array<string>
	 */
	private const ALLOWED_SCHEMAS = [
		ExtractionPrefillServiceInterface::SCHEMA_SUPPLIER_INVOICE,
		ExtractionPrefillServiceInterface::SCHEMA_RECEIPT,
	];

	/**
	 * Saves a corrected draft and feeds its booking back to docudesk.
	 *
	 * @var ExtractionDraftCommitter
	 */
	private readonly ExtractionDraftCommitter $committer;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param DocudeskExtractionClientInterface $extractionClient Outbound docudesk request client.
	 * @param GlAccountSuggestionClientInterface $suggestionClient Outbound docudesk suggestion/correction client.
	 * @param ChartOfAccountsCandidateServiceInterface $candidateService Shillinq's own chart-of-accounts candidates.
	 * @param ExtractionPrefillServiceInterface $prefillService Correction-recording service.
	 * @param SupplierInvoiceChecksInterface $invoiceChecks The duplicate-invoice rule.
	 * @param AdministrationContextServiceInterface $administrationContext Server-resolved tenant scope (ADR-005).
	 * @param IUserSession $session User session.
	 * @param ContainerInterface $container DI container (OR ObjectService).
	 * @param LoggerInterface $logger Logger.
	 * @param ExtractionDraftCommitter|null $committer Saves a draft and feeds its booking back; built here when absent.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly DocudeskExtractionClientInterface $extractionClient,
		private readonly GlAccountSuggestionClientInterface $suggestionClient,
		private readonly ChartOfAccountsCandidateServiceInterface $candidateService,
		private readonly ExtractionPrefillServiceInterface $prefillService,
		private readonly SupplierInvoiceChecksInterface $invoiceChecks,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $session,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		?ExtractionDraftCommitter $committer = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
		$this->committer = $committer ?? new ExtractionDraftCommitter(container: $container, suggestionClient: $suggestionClient);

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

		$ask = new DocumentExtraction(request: $this->request);
		$draft = $this->targetDraft(ask: $ask);
		if ($draft instanceof JSONResponse) {
			return $draft;
		}

		return $this->requested(ask: $ask, draft: $draft);
	}//end request()

	/**
	 * The draft the extraction id is captured on (null when none is targeted),
	 * or the refusal: 422 on incomplete input, a masked 404 for a draft the
	 * caller may not access.
	 *
	 * @param DocumentExtraction $ask The extraction request.
	 *
	 * @return ExtractionDraft|JSONResponse|null
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	private function targetDraft(DocumentExtraction $ask): ExtractionDraft|JSONResponse|null {
		if ($ask->isComplete() === false) {
			return new JSONResponse(
				['error' => 'documentUri and a valid docType (receipt|supplier-invoice) are required'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		if ($ask->draftId === '') {
			return null;
		}

		$draft = new ExtractionDraft(schema: (string)$this->prefillService->schemaForDocType(docType: $ask->docType), id: $ask->draftId);
		return $this->guardDraftAccess(schema: $draft->schema, id: $draft->id) ?? $draft;
	}//end targetDraft()

	/**
	 * Forward the extraction request to docudesk and capture the extraction id.
	 *
	 * @param DocumentExtraction   $ask   The extraction request.
	 * @param ExtractionDraft|null $draft The targeted draft, null for none.
	 *
	 * @return JSONResponse 202 accepted, or 503 when docudesk refuses.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	private function requested(DocumentExtraction $ask, ExtractionDraft|null $draft): JSONResponse {
		$result = $this->extractionClient->requestExtraction(documentUri: $ask->documentUri, docType: $ask->docType);
		if ($result['success'] === false) {
			return new JSONResponse(
				['error' => $result['error'] ?? 'docudesk extraction request failed', 'accepted' => false],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		// REQ-GAC-001: capture the docudesk financialExtraction id onto the
		// existing draft, when one was targeted — the only channel available
		// to later request a GL-account suggestion for it.
		$this->captureExtractionId(draft: $draft, extractionId: ($result['extractionId'] ?? null));

		return new JSONResponse(['accepted' => true], Http::STATUS_ACCEPTED);
	}//end requested()

	/**
	 * Persist a non-empty extraction id on the targeted draft.
	 *
	 * @param ExtractionDraft|null $draft        The targeted draft, null for none.
	 * @param mixed                $extractionId The id docudesk answered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	private function captureExtractionId(ExtractionDraft|null $draft, mixed $extractionId): void {
		if ($draft !== null && $draft->schema !== '' && is_string($extractionId) === true && $extractionId !== '') {
			$this->persistExtractionId(draft: $draft, extractionId: $extractionId);
		}
	}//end captureExtractionId()

	/**
	 * Persist the docudesk `financialExtraction` id onto an existing draft
	 * (REQ-GAC-001). Best-effort: a failure is logged, not surfaced — the
	 * (re-)extraction request itself already succeeded and the suggestion
	 * feature simply stays unavailable for this draft until the id is
	 * captured on a later attempt.
	 *
	 * @param ExtractionDraft $draft        The targeted draft (OR schema slug and object id).
	 * @param string          $extractionId The docudesk financialExtraction object id.
	 *
	 * @return void
	 */
	private function persistExtractionId(ExtractionDraft $draft, string $extractionId): void {
		$existing = $this->findById(schema: $draft->schema, id: $draft->id);
		if ($existing === null) {
			return;
		}

		$existing['docudeskExtractionId'] = $extractionId;

		try {
			$this->committer->save(schema: $draft->schema, draft: $existing);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ExtractionRequestController: failed to persist docudeskExtractionId',
				['schema' => $draft->schema, 'id' => $draft->id, 'exception' => $e->getMessage()]
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
		$existing = $this->accessibleDraft(draft: new ExtractionDraft(schema: $schema, id: $id));
		if ($existing instanceof JSONResponse) {
			return $existing;
		}

		$receiptRefusal = $this->receiptRefusal(schema: $schema, id: $id, existing: $existing);
		if ($receiptRefusal !== null) {
			return $receiptRefusal;
		}

		$incomingFields = $this->editableFields(schema: $schema);
		$refusal        = $this->invoiceRefusal(schema: $schema, existing: $existing, incomingFields: $incomingFields);
		if ($refusal !== null) {
			return $refusal;
		}

		$updated = $this->prefillService->recordCorrection(existingDraft: $existing, incomingFields: $incomingFields);

		return $this->savedCorrection(schema: $schema, updated: $updated);
	}//end confirm()

	/**
	 * The draft the caller may act on, or the refusal: 401 anonymous, 422 for
	 * an unknown schema, a masked 404 for another administration's or an
	 * unknown draft.
	 *
	 * @param ExtractionDraft $draft The requested draft.
	 *
	 * @return array<string, mixed>|JSONResponse
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	private function accessibleDraft(ExtractionDraft $draft): array|JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array($draft->schema, self::ALLOWED_SCHEMAS, true) === false) {
			return new JSONResponse(['error' => 'Unknown or missing schema'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->guardDraftAccess(schema: $draft->schema, id: $draft->id) ?? $this->foundDraft(draft: $draft);
	}//end accessibleDraft()

	/**
	 * The draft, or 404 when it cannot be found.
	 *
	 * @param ExtractionDraft $draft The requested draft.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function foundDraft(ExtractionDraft $draft): array|JSONResponse {
		$existing = $this->findById(schema: $draft->schema, id: $draft->id);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		return $existing;
	}//end foundDraft()

	/**
	 * The posted fields an operator may correct on this schema.
	 *
	 * @param string $schema The draft's schema.
	 *
	 * @return array<string, mixed>
	 */
	private function editableFields(string $schema): array {
		return $this->prefillService->correctableFields(schema: $schema, incomingFields: $this->decodeBody());
	}//end editableFields()

	/**
	 * Save the corrected draft and feed the booking back to docudesk.
	 *
	 * @param string               $schema  The draft's schema.
	 * @param array<string, mixed> $updated The corrected draft.
	 *
	 * @return JSONResponse 200 with the record, or a logged 500.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	private function savedCorrection(string $schema, array $updated): JSONResponse {
		try {
			$record = $this->committer->save(schema: $schema, draft: $updated);
		} catch (Throwable $e) {
			$this->logger->error('ExtractionRequestController.confirm failed to persist: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to save correction'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// REQ-GAC-005: feed every committed booking back to docudesk as a
		// correction — whether or not it matches the prior suggestion — so
		// the ranker's history reflects it. Best-effort: never blocks or
		// undoes the local booking that already succeeded above.
		$this->committer->feedBack(record: $record);

		return new JSONResponse(['record' => $record], Http::STATUS_OK);
	}//end savedCorrection()

	/**
	 * Refuse a receipt change by anyone but its submitter or a receipt approver.
	 *
	 * @param string               $schema   The draft's schema.
	 * @param string               $id       The draft id.
	 * @param array<string, mixed> $existing The stored draft.
	 *
	 * @return JSONResponse|null The 403 refusal, or null when the change may go ahead.
	 */
	private function receiptRefusal(string $schema, string $id, array $existing): ?JSONResponse {
		if ($schema !== ExtractionPrefillServiceInterface::SCHEMA_RECEIPT) {
			return null;
		}

		if ($this->mayEditReceipt(id: $id, administrationId: (string)($existing['administrationId'] ?? '')) === true) {
			return null;
		}

		return new JSONResponse(['error' => 'Only the employee who submitted this receipt, or a bookkeeper, can change it.'], Http::STATUS_FORBIDDEN);

	}//end receiptRefusal()

	/**
	 * A receipt is changed by the employee who submitted it, or by someone
	 * who approves receipts in its administration.
	 *
	 * @param string $id               The receipt id.
	 * @param string $administrationId The receipt's administration.
	 *
	 * @return bool
	 */
	private function mayEditReceipt(string $id, string $administrationId): bool {
		$userId = (string)($this->session->getUser()?->getUID() ?? '');
		if ($userId === '') {
			return false;
		}

		if ($this->ownerOf(schema: ExtractionPrefillServiceInterface::SCHEMA_RECEIPT, id: $id) === $userId) {
			return true;
		}

		foreach (($this->administrationContext->buildContext()['administrations'] ?? []) as $membership) {
			if ((string)($membership['administrationId'] ?? '') === $administrationId
				&& in_array((string)($membership['role'] ?? ''), self::RECEIPT_APPROVER_ROLES, true) === true
			) {
				return true;
			}
		}

		return false;

	}//end mayEditReceipt()

	/**
	 * The user who created a record.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The record id.
	 *
	 * @return string The owner's user id; empty when it cannot be read.
	 */
	private function ownerOf(string $schema, string $id): string {
		try {
			$found = $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->setRegister(self::REGISTER_SLUG)
				->setSchema($schema)
				->find($id);
		} catch (Throwable $e) {
			return '';
		}

		if (is_object($found) === true && method_exists($found, 'getOwner') === true) {
			return (string)($found->getOwner() ?? '');
		}

		if (is_array($found) === true) {
			return (string)($found['@self']['owner'] ?? ($found['owner'] ?? ''));
		}

		return '';

	}//end ownerOf()

	/**
	 * A supplier invoice is confirmed once, from its review draft, and the
	 * correction may not turn it into a duplicate of another invoice.
	 *
	 * @param string              $schema         The draft's schema.
	 * @param array<string,mixed> $existing       The stored draft.
	 * @param array<string,mixed> $incomingFields The correctable fields sent.
	 *
	 * @return JSONResponse|null The refusal, or null when the confirm may go ahead.
	 */
	private function invoiceRefusal(string $schema, array $existing, array $incomingFields): ?JSONResponse {
		if ($schema !== ExtractionPrefillServiceInterface::SCHEMA_SUPPLIER_INVOICE) {
			return null;
		}

		if (($existing['extractionStatus'] ?? '') !== ExtractionPrefillService::STATUS_PENDING_REVIEW) {
			return new JSONResponse(['error' => 'This invoice is already confirmed.'], Http::STATUS_CONFLICT);
		}

		$merged    = array_merge($existing, $incomingFields);
		$duplicate = $this->invoiceChecks->duplicateOf(
			administrationId: (string)($merged['administrationId'] ?? ''),
			supplierId: (string)($merged['supplierId'] ?? ''),
			invoiceNumber: (string)($merged['invoiceNumber'] ?? ''),
			ignoreIds: [(string)($existing['id'] ?? '')]
		);
		if ($duplicate !== null) {
			return new JSONResponse(['error' => 'This invoice number already exists for this supplier'], Http::STATUS_CONFLICT);
		}

		return null;

	}//end invoiceRefusal()

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
		$draft = $this->accessibleDraft(draft: new ExtractionDraft(schema: $schema, id: $id));
		if ($draft instanceof JSONResponse) {
			return $draft;
		}

		if ($this->extractionIdOf(draft: $draft) === '') {
			return new JSONResponse(['suggestion' => null, 'reason' => 'extraction-id-unknown'], Http::STATUS_OK);
		}

		return $this->suggestionFor(schema: $schema, draft: $draft);
	}//end suggestGlAccount()

	/**
	 * The draft's docudesk extraction id, trimmed ('' when unknown).
	 *
	 * @param array<string, mixed> $draft The draft.
	 *
	 * @return string
	 */
	private function extractionIdOf(array $draft): string {
		return trim((string)($draft['docudeskExtractionId'] ?? ''));
	}//end extractionIdOf()

	/**
	 * Ask docudesk for a suggestion among the administration's active
	 * accounts, cache the top one on the draft and answer it.
	 *
	 * @param string               $schema The draft's schema.
	 * @param array<string, mixed> $draft  The draft with a known extraction id.
	 *
	 * @return JSONResponse 200 with the suggestion, or with a null suggestion and the reason.
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	private function suggestionFor(string $schema, array $draft): JSONResponse {
		$administrationId = (string)($draft['administrationId'] ?? '');
		$candidateAccounts = $this->candidateService->activeCandidates(administrationId: $administrationId);

		$result = $this->suggestionClient->requestSuggestion(
			extractionId: $this->extractionIdOf(draft: $draft),
			candidateAccounts: $candidateAccounts
		);

		$suggestion = $this->topSuggestion(result: $result);
		if (is_string($suggestion) === true) {
			return new JSONResponse(['suggestion' => null, 'reason' => $suggestion], Http::STATUS_OK);
		}

		$draft['suggestedGlAccount'] = $suggestion;
		$this->cacheSuggestion(schema: $schema, draft: $draft);

		return new JSONResponse(['suggestion' => $suggestion], Http::STATUS_OK);
	}//end suggestionFor()

	/**
	 * The top-ranked suggestion, or why there is none.
	 *
	 * @param array<string, mixed> $result The provider's answer.
	 *
	 * @return array<string, mixed>|string The suggestion, or 'provider-unavailable' / 'no-suggestion'.
	 */
	private function topSuggestion(array $result): array|string {
		if ($result['success'] === false || is_array($result['suggestion']) === false) {
			return 'provider-unavailable';
		}

		$suggested = (array)($result['suggestion']['suggestedAccounts'] ?? []);
		$top = $suggested[0] ?? null;
		if (is_array($top) === false) {
			return 'no-suggestion';
		}

		return $this->suggestionOf(top: $top, suggestion: $result['suggestion']);
	}//end topSuggestion()

	/**
	 * The suggestion as answered and cached.
	 *
	 * @param array<string, mixed> $top        The top-ranked account.
	 * @param array<string, mixed> $suggestion The provider's suggestion envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function suggestionOf(array $top, array $suggestion): array {
		return [
			'code' => (string)($top['code'] ?? ''),
			'label' => ($top['label'] ?? null),
			'confidence' => (float)($top['confidence'] ?? 0),
			'rationale' => (string)($top['rationale'] ?? ''),
			'source' => (string)($suggestion['source'] ?? 'none'),
		];
	}//end suggestionOf()

	/**
	 * Cache the last-fetched suggestion on the draft (design.md Seed Data —
	 * `suggestedGlAccount`), so a later commit-time diff needs no extra
	 * round-trip. Best-effort: a failure to persist the cache never blocks
	 * returning the suggestion to the caller.
	 *
	 * @param string              $schema OR schema slug.
	 * @param array<string,mixed> $draft  The draft, carrying the suggestion to cache.
	 *
	 * @return void
	 */
	private function cacheSuggestion(string $schema, array $draft): void {
		try {
			$this->committer->save(schema: $schema, draft: $draft);
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

		return $this->tenantRefusal(existing: $this->findById(schema: $schema, id: $id));
	}//end guardDraftAccess()

	/**
	 * The masked 404 for a missing draft or one in an administration the caller may not access.
	 *
	 * @param array<string, mixed>|null $existing The draft, null when missing.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	private function tenantRefusal(?array $existing): ?JSONResponse {
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
	}//end tenantRefusal()

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
