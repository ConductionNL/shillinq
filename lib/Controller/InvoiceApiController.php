<?php

/**
 * Invoice API Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * HTTP API for invoice-from-time-and-expense (issue #111, Task 19).
 * Exposes five endpoints:
 *
 *   POST   /api/v1/invoices/generate         — draft BillableInvoice
 *   GET    /api/v1/invoices                  — list (filterable)
 *   GET    /api/v1/invoices/{invoiceId}       — detail (invoice + lines)
 *   POST   /api/v1/invoices/{invoiceId}/post  — transition draft → posted
 *   GET    /api/v1/invoices/{invoiceId}/pdf   — export PDF (HTML body wrapped)
 *
 * Endpoints are authenticated (#[NoAdminRequired]). Per-object authorisation
 * is delegated to OpenRegister's ObjectService (administration-scoped
 * multitenancy); the controller never accepts a client-supplied
 * administrationId and always resolves it server-side.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BillableLinesService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * HTTP API for BillableInvoice drafting + posting.
 */
class InvoiceApiController extends Controller {
	/**
	 * Body for a generation request that fails validation.
	 */
	private const DRAFT_INVALID = [
		'message' => 'The invoice generation request is invalid.',
		'error' => 'invoice-generate-invalid',
	];

	/**
	 * Body for chosen lines that are not billable to the customer.
	 */
	private const DRAFT_FOREIGN_LINES = [
		'message' => 'Some chosen lines are not billable to this customer in this administration.',
		'error' => 'invoice-generate-foreign-lines',
	];

	/**
	 * Body for lines that are already invoiced.
	 */
	private const DRAFT_CONFLICT = [
		'message' => 'One or more time entries, expenses or meter readings are already invoiced.',
		'error' => 'invoice-generate-conflict',
	];

	/**
	 * Body for any other failed draft.
	 */
	private const DRAFT_FAILED = [
		'message' => 'Unable to generate the invoice.',
		'error' => 'invoice-generate-failed',
	];

	/**
	 * Body for an invoice that is already posted.
	 */
	private const POST_CONFLICT = [
		'message' => 'The invoice is already posted.',
		'error' => 'invoice-post-conflict',
	];

	/**
	 * Body for any other failed post.
	 */
	private const POST_FAILED = [
		'message' => 'Unable to post the invoice.',
		'error' => 'invoice-post-failed',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param InvoiceGenerationService $service Drafting/posting service.
	 * @param InvoicePdfGenerator $pdfGenerator PDF/HTML renderer.
	 * @param IUserSession $session User session.
	 * @param AdministrationContextService $administrationContext Server-resolved tenant scope.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param BillableLinesService $billableLines The lines one customer can be invoiced for.
	 */
	public function __construct(
		IRequest $request,
		private readonly InvoiceGenerationService $service,
		private readonly InvoicePdfGenerator $pdfGenerator,
		private readonly IUserSession $session,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly BillableLinesService $billableLines,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Draft a BillableInvoice (POST /api/v1/invoices/generate).
	 *
	 * Authorization: the administration scope is resolved server-side from the
	 * caller's own session (never accepted from the request body), so the drafted
	 * invoice is always scoped to an administration the caller is a member of
	 * (ADR-005 Rule 3 — session-derived, IDOR-safe by construction).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function generate(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->draft();
		} catch (\InvalidArgumentException $e) {
			$this->logger->error('InvoiceApiController.generate: invalid request: ' . $e->getMessage());
			return new JSONResponse(self::DRAFT_INVALID, Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\RuntimeException $e) {
			return $this->draftFailure(failure: $e);
		} catch (\Throwable $e) {
			return $this->internalError(action: 'generate', failure: $e);
		}

	}//end generate()

	/**
	 * The lines one customer can be invoiced for
	 * (GET /api/v1/invoices/billable-lines).
	 *
	 * Query: customerId, projectId (optional), fromDate, toDate. The
	 * administration is the caller's active one, resolved on the server.
	 *
	 * @return JSONResponse 200 with `{timeEntries, expenses}`; 401 when anonymous.
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	#[NoAdminRequired]
	public function billable(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$admin = $this->resolveAdministrationId();
		if ($admin === '') {
			return $this->withoutAdministration();
		}

		return new JSONResponse($this->billableLinesFor(administrationId: $admin));
	}//end billable()

	/**
	 * List invoices for the current administration (GET /api/v1/invoices).
	 *
	 * Authorization: the administration scope is resolved server-side from the
	 * caller's own session and applied as a hard filter — the endpoint accepts
	 * no client-supplied administrationId, so there is no parameter to guess or
	 * enumerate against another tenant (ADR-005 Rule 3 — session-derived,
	 * IDOR-safe by construction).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->listInvoices();
		} catch (\Throwable $e) {
			return $this->internalError(action: 'index', failure: $e);
		}

	}//end index()

	/**
	 * Show one invoice + its lines (GET /api/v1/invoices/{invoiceId}).
	 *
	 * @param string $invoiceId BillableInvoice id.
	 *
	 * Authorization: the fetched invoice's own `administrationId` is compared
	 * against the caller's server-resolved administration; a mismatch (or an
	 * orphan record with no administrationId) is refused with 403 before any
	 * line data is read (ADR-005 Rule 3).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function show(string $invoiceId): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->showInvoice(invoiceId: $invoiceId);
		} catch (\Throwable $e) {
			return $this->internalError(action: 'show', failure: $e);
		}

	}//end show()

	/**
	 * Post an invoice (POST /api/v1/invoices/{invoiceId}/post).
	 *
	 * @param string $invoiceId Id.
	 *
	 * Authorization: the fetched invoice's own `administrationId` is compared
	 * against the caller's server-resolved administration; a mismatch (or an
	 * orphan record with no administrationId) is refused with 403 before any
	 * posting side-effect runs (ADR-005 Rule 3).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function post(string $invoiceId): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->postInvoice(invoiceId: $invoiceId);
		} catch (\RuntimeException $e) {
			return $this->postFailure(failure: $e);
		} catch (\Throwable $e) {
			return $this->internalError(action: 'post', failure: $e);
		}

	}//end post()

	/**
	 * Export invoice PDF (GET /api/v1/invoices/{invoiceId}/pdf).
	 *
	 * @param string $invoiceId Id.
	 *
	 * Authorization: the fetched invoice's own `administrationId` is compared
	 * against the caller's server-resolved administration; a mismatch (or an
	 * orphan record with no administrationId) is refused with 403 before the
	 * PDF is rendered (ADR-005 Rule 3).
	 *
	 * @return DataDisplayResponse|JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function pdf(string $invoiceId): DataDisplayResponse|JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->renderPdf(invoiceId: $invoiceId);
		} catch (\Throwable $e) {
			return $this->internalError(action: 'pdf', failure: $e);
		}

	}//end pdf()

	/**
	 * The customer's billable lines in the administration, for the requested
	 * project and period.
	 *
	 * @param string $administrationId The caller's administration.
	 *
	 * @return array<string,mixed>
	 */
	private function billableLinesFor(string $administrationId): array {
		return $this->billableLines->forCustomer(
			administrationId: $administrationId,
			customerId: trim((string)$this->request->getParam('customerId', '')),
			projectId: trim((string)$this->request->getParam('projectId', '')),
			fromDate: trim((string)$this->request->getParam('fromDate', '')),
			toDate: trim((string)$this->request->getParam('toDate', ''))
		);

	}//end billableLinesFor()

	/**
	 * Draft an invoice for the caller's administration after checking that
	 * every chosen line is billable to the customer.
	 *
	 * @return JSONResponse
	 *
	 * @throws \InvalidArgumentException When the request is invalid.
	 * @throws \RuntimeException When the draft cannot be completed.
	 */
	private function draft(): JSONResponse {
		$admin = $this->resolveAdministrationId();
		if ($admin === '') {
			return $this->withoutAdministration();
		}

		$req = InvoiceGenerationRequest::fromArray($admin, $this->decodeBody());
		if ($this->hasForeignLines(req: $req) === true) {
			return new JSONResponse(self::DRAFT_FOREIGN_LINES, Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($this->service->draftInvoice($req), Http::STATUS_OK);

	}//end draft()

	/**
	 * Whether any chosen time entry or expense is not billable to the
	 * request's customer in its administration.
	 *
	 * @param InvoiceGenerationRequest $req The generation request.
	 *
	 * @return bool
	 */
	private function hasForeignLines(InvoiceGenerationRequest $req): bool {
		$foreign = $this->billableLines->foreignLineIds(
			administrationId: $req->administrationId,
			customerId: $req->customerId,
			timeEntryIds: array_values(array_map('strval', $req->timeEntryIds)),
			expenseIds: array_values(array_map('strval', $req->expenseIds))
		);

		return $foreign !== [];

	}//end hasForeignLines()

	/**
	 * Map a failed draft: a conflict on already-invoiced lines is a 409,
	 * anything else a 400; the message is logged, never returned.
	 *
	 * @param \RuntimeException $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function draftFailure(\RuntimeException $failure): JSONResponse {
		$message = $failure->getMessage();
		$this->logger->error('InvoiceApiController.generate: request could not be completed: ' . $message);

		if (str_contains($message, 'Conflict') === true) {
			return new JSONResponse(self::DRAFT_CONFLICT, Http::STATUS_CONFLICT);
		}

		return new JSONResponse(self::DRAFT_FAILED, Http::STATUS_BAD_REQUEST);

	}//end draftFailure()

	/**
	 * List the caller's billable invoices, optionally by billing model and status.
	 *
	 * @return JSONResponse
	 */
	private function listInvoices(): JSONResponse {
		$admin = $this->resolveAdministrationId();
		if ($admin === '') {
			return $this->withoutAdministration();
		}

		$all = $this->objectService
			->setRegister('shillinq')
			->setSchema('BillableInvoice')
			->findAll(['filters' => $this->invoiceFilters(administrationId: $admin)]);

		return new JSONResponse($all, Http::STATUS_OK);

	}//end listInvoices()

	/**
	 * The listing filters: the administration plus the optional billingModel
	 * and status parameters.
	 *
	 * @param string $administrationId The caller's administration.
	 *
	 * @return array<string,string>
	 */
	private function invoiceFilters(string $administrationId): array {
		$filters = ['administrationId' => $administrationId];

		$billingModel = (string)$this->request->getParam('billingModel', '');
		if ($billingModel !== '') {
			$filters['billingModel'] = $billingModel;
		}

		$status = (string)$this->request->getParam('status', '');
		if ($status !== '') {
			$filters['status'] = $status;
		}

		return $filters;

	}//end invoiceFilters()

	/**
	 * The invoice with its lines, when it belongs to the caller's administration.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return JSONResponse
	 */
	private function showInvoice(string $invoiceId): JSONResponse {
		$invoice = $this->ownInvoice(invoiceId: $invoiceId);
		if ($invoice instanceof JSONResponse) {
			return $invoice;
		}

		return new JSONResponse(
			[
				'invoice' => $invoice,
				'lines' => $this->invoiceLines(invoiceId: $invoiceId),
				'auditTrail' => [],
			],
			Http::STATUS_OK
		);

	}//end showInvoice()

	/**
	 * Render the invoice document, when it belongs to the caller's administration.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return DataDisplayResponse|JSONResponse
	 */
	private function renderPdf(string $invoiceId): DataDisplayResponse|JSONResponse {
		$invoice = $this->ownInvoice(invoiceId: $invoiceId);
		if ($invoice instanceof JSONResponse) {
			return $invoice;
		}

		$pdf = $this->pdfGenerator->generatePdf(invoice: $invoice, lines: $this->invoiceLines(invoiceId: $invoiceId));

		return new DataDisplayResponse(
			$pdf['html'],
			Http::STATUS_OK,
			[
				'Content-Type' => 'text/html; charset=utf-8',
				'Content-Disposition' => sprintf('inline; filename="%s.html"', preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf['filename'])),
			]
		);

	}//end renderPdf()

	/**
	 * Resolve the invoice for the caller: 403 without an administration, 404
	 * when it does not exist, 403 when it belongs to another administration.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return array<string,mixed>|JSONResponse The invoice as an array, or the refusal.
	 */
	private function ownInvoice(string $invoiceId): array|JSONResponse {
		$admin = $this->resolveAdministrationId();
		if ($admin === '') {
			return $this->withoutAdministration();
		}

		$invoice = $this->findInvoice(invoiceId: $invoiceId);
		if ($invoice === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (((string)($invoice['administrationId'] ?? '')) !== $admin) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $invoice;

	}//end ownInvoice()

	/**
	 * Find an invoice by id or UUID, as an array.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findInvoice(string $invoiceId): ?array {
		$found = ObjectIdentifier::findScoped(
			scoped: $this->objectService->setRegister('shillinq')->setSchema('BillableInvoice'),
			id: $invoiceId
		);
		if ($found === null) {
			return null;
		}

		// Normalise once: find() hands back an entity, and everything that
		// reads the invoice reads it as an array.
		return $found->jsonSerialize();

	}//end findInvoice()

	/**
	 * The lines of an invoice.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return array<mixed>
	 */
	private function invoiceLines(string $invoiceId): array {
		return $this->objectService
			->setRegister('shillinq')
			->setSchema('BillableInvoiceLine')
			->findAll(['filters' => ['invoiceId' => $invoiceId]]);

	}//end invoiceLines()

	/**
	 * Post the invoice, when it belongs to the caller's administration.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return JSONResponse
	 *
	 * @throws \RuntimeException When the invoice cannot be posted.
	 */
	private function postInvoice(string $invoiceId): JSONResponse {
		$invoice = $this->ownInvoice(invoiceId: $invoiceId);
		if ($invoice instanceof JSONResponse) {
			return $invoice;
		}

		return new JSONResponse($this->service->postInvoice($invoice), Http::STATUS_OK);

	}//end postInvoice()

	/**
	 * Map a failed post: an already-posted invoice is a 409, anything else a
	 * 400; the message is logged, never returned.
	 *
	 * @param \RuntimeException $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function postFailure(\RuntimeException $failure): JSONResponse {
		$message = $failure->getMessage();
		$this->logger->error('InvoiceApiController.post: request could not be completed: ' . $message);

		if (str_contains($message, 'already posted') === true) {
			return new JSONResponse(self::POST_CONFLICT, Http::STATUS_CONFLICT);
		}

		return new JSONResponse(self::POST_FAILED, Http::STATUS_BAD_REQUEST);

	}//end postFailure()

	/**
	 * Log an unexpected failure of an action and answer a bare 500.
	 *
	 * @param string $action The action name, as logged.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function internalError(string $action, \Throwable $failure): JSONResponse {
		$this->logger->error('InvoiceApiController.' . $action . ' failed: ' . $failure->getMessage());
		return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);

	}//end internalError()

	/**
	 * Decode the JSON request body, falling back to POST/GET params.
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

		// Fall back to request params.
		$params = $this->request->getParams();
		if (is_array($params) === true) {
			return $params;
		}

		return [];
	}//end decodeBody()

	/**
	 * The caller's active administration, resolved on the server.
	 *
	 * @return string The administration id, or '' when the caller has none.
	 */
	private function resolveAdministrationId(): string {
		try {
			return (string)($this->administrationContext->buildContext()['activeAdministrationId'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->error('InvoiceApiController: administration context failed: ' . $e->getMessage());
			return '';
		}
	}//end resolveAdministrationId()

	/**
	 * The answer for a caller who belongs to no administration.
	 *
	 * @return JSONResponse
	 */
	private function withoutAdministration(): JSONResponse {
		return new JSONResponse(
			['message' => 'You are not a member of any administration.', 'error' => 'invoice-no-administration'],
			Http::STATUS_FORBIDDEN
		);
	}//end withoutAdministration()
}//end class
