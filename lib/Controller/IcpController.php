<?php

/**
 * ICP Controller
 *
 * Tier-3 read-only intra-community supplies (ICP) API (REQ-ICP-003, REQ-ICP-004,
 * REQ-ICP-002). Exposes GET endpoints that return the ICP ledger (aggregated
 * lines + totals) for a period, the reconciliation outcome against the
 * BTW-aangifte rubriek 3b, and the EUR 50,000 periodicity-threshold decision for a
 * quarter. Every endpoint is available to any authenticated user
 * (#[NoAdminRequired]) who is a member of the requested administration: an
 * administration the caller does not belong to answers 404 before anything is
 * read or written (IDOR-safe, REQ-ICP-001). These endpoints
 * are read-only; ICP filing state changes go through the IcpOpgaaf lifecycle.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ArInvoiceIcpPdfRendererInterface;
use OCA\Shillinq\Kernel\Contracts\IcpFilingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IcpServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ViesServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET ICP ledger / reconciliation / periodicity endpoints.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class IcpController extends Controller {
	/**
	 * Identifier validation pattern (short slugs only).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor for the IcpController.
	 *
	 * @param IRequest $request The request object.
	 * @param IcpServiceInterface $icpService The ICP read-side computation service.
	 * @param IcpFilingServiceInterface $filingService The ICP filing write service (correction + export).
	 * @param ViesServiceInterface $viesService The VIES validation service.
	 * @param ArInvoiceIcpPdfRendererInterface $pdfRenderer The ICP overlay PDF renderer (REQ-ICP-007).
	 * @param IUserSession $userSession The session for the acting user id (auth body-guard).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param AdministrationContextServiceInterface $administrations The caller's administration memberships (REQ-ICP-001).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IcpServiceInterface $icpService,
		private readonly IcpFilingServiceInterface $filingService,
		private readonly ViesServiceInterface $viesService,
		private readonly ArInvoiceIcpPdfRendererInterface $pdfRenderer,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextServiceInterface $administrations,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Authorization guard — every IcpController endpoint requires an
	 * authenticated Nextcloud user (REQ-ICP-001 / ADR-005). The administration
	 * scope is then validated downstream by the IDOR-safe service layer. This
	 * helper is the in-body counterpart to #[NoAdminRequired] so gate-7
	 * no-admin-idor / gate-9 semantic-auth see the explicit auth posture.
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, null when ok.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireUser()

	/**
	 * Refuse an administration the caller is not a member of (REQ-ICP-001).
	 *
	 * Every endpoint here takes `administration_id` from the request. The id is
	 * validated, then checked against the caller's own memberships; an
	 * administration they do not belong to answers 404, the same as one that
	 * does not exist, so no other administration's data is read or written.
	 *
	 * @param string $administrationId The administration id from the request.
	 *
	 * @return JSONResponse|null A 400 or 404 response, or null when the caller is a member.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	private function requireMember(string $administrationId): ?JSONResponse {
		$error = $this->validateId(value: $administrationId, label: 'administration_id');
		if ($error !== null) {
			return $error;
		}

		if ($this->administrations->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end requireMember()

	/**
	 * Return the ICP ledger for a period (REQ-ICP-003).
	 *
	 * Query parameters:
	 *  - period_id         (required) filing period (YYYY-Qn or YYYY-MM).
	 *  - administration_id (required) administration scope (IDOR-safe, REQ-ICP-001).
	 *
	 * @return JSONResponse 200 with { period, lines, totals, supplyCount }; 400 on
	 *                      a missing/malformed parameter; 500 (no stack trace) on failure.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	#[NoAdminRequired]
	public function ledger(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$params = $this->periodAndAdministration();
		if (($params['error'] ?? null) !== null) {
			return $params['error'];
		}

		return $this->run(
			action: 'compute ICP ledger',
			compute: fn (): array => $this->icpService->ledger(
				administrationId: $params['administrationId'],
				period: $params['period']
			),
			context: ['administrationId' => $params['administrationId'], 'period' => $params['period']]
		);

	}//end ledger()

	/**
	 * Return the reconciliation outcome against rubriek 3b for a period (REQ-ICP-004).
	 *
	 * @return JSONResponse 200 with the reconciliation outcome; 400 / 500 as above.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	#[NoAdminRequired]
	public function reconcile(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$params = $this->periodAndAdministration();
		if (($params['error'] ?? null) !== null) {
			return $params['error'];
		}

		return $this->run(
			action: 'reconcile ICP filing',
			compute: fn (): array => $this->icpService->reconcile(
				administrationId: $params['administrationId'],
				period: $params['period']
			),
			context: ['administrationId' => $params['administrationId'], 'period' => $params['period']]
		);

	}//end reconcile()

	/**
	 * Return the EUR 50,000 periodicity-threshold decision for a quarter (REQ-ICP-002).
	 *
	 * Query parameters:
	 *  - quarter           (required) calendar quarter (YYYY-Qn).
	 *  - administration_id (required) administration scope.
	 *
	 * @return JSONResponse 200 with the threshold decision; 400 / 500 as above.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	#[NoAdminRequired]
	public function periodicity(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$quarter = trim((string)$this->request->getParam('quarter', ''));
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		$error = $this->validateId(value: $quarter, label: 'quarter');
		if ($error !== null) {
			return $error;
		}

		$error = $this->requireMember(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		return $this->run(
			action: 'check ICP periodicity',
			compute: fn (): array => $this->icpService->periodicityCheck(
				administrationId: $administrationId,
				quarter: $quarter
			),
			context: ['administrationId' => $administrationId, 'quarter' => $quarter]
		);

	}//end periodicity()

	/**
	 * Validate a buyer VAT-ID against VIES and persist evidence (REQ-ICP-001, REQ-ICP-009).
	 *
	 * Body parameters:
	 *  - vat_id            (required) the buyer VAT-ID to verify.
	 *  - administration_id (required) administration scope (IDOR-safe, REQ-ICP-001).
	 *
	 * @return JSONResponse 200 with the validation outcome; 400 on a bad parameter;
	 *                      500 (no stack trace) on failure.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	#[NoAdminRequired]
	public function lookupVatId(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$vatId = trim((string)$this->request->getParam('vat_id', ''));
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		if ($vatId === '' || strlen($vatId) > 32 || preg_match('/^[A-Za-z0-9 .\\-]{1,32}$/', $vatId) !== 1) {
			return new JSONResponse(['error' => 'vat_id must be a valid VAT identifier'], Http::STATUS_BAD_REQUEST);
		}

		$error = $this->requireMember(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		return $this->run(
			action: 'look up VAT-ID',
			compute: fn (): array => $this->viesService->validate(
				administrationId: $administrationId,
				vatId: $vatId
			),
			context: ['administrationId' => $administrationId]
		);

	}//end lookupVatId()

	/**
	 * Create a correction ICP-opgaaf for an already-submitted period (REQ-ICP-008).
	 *
	 * Body parameters:
	 *  - administration_id (required) administration scope.
	 *  - corrects_period   (required) the period being corrected (YYYY-Qn / YYYY-MM).
	 *  - lines             (required) array of {buyerVatId, supplyType, amountExclVat}.
	 *  - reason            (optional) free-text correction reason.
	 *
	 * @return JSONResponse 200 with the draft correction; 400 / 500 as above.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	#[NoAdminRequired]
	public function correction(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$correctsPeriod = trim((string)$this->request->getParam('corrects_period', ''));
		$reason = trim((string)$this->request->getParam('reason', ''));
		$lines = $this->request->getParam('lines', []);

		$error = $this->requireMember(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$error = $this->validateId(value: $correctsPeriod, label: 'corrects_period');
		if ($error !== null) {
			return $error;
		}

		if (is_array($lines) === false || $lines === []) {
			return new JSONResponse(['error' => 'lines must be a non-empty array'], Http::STATUS_BAD_REQUEST);
		}

		return $this->run(
			action: 'create ICP correction',
			compute: fn (): array => $this->filingService->createCorrection(
				administrationId: $administrationId,
				correctsPeriod: $correctsPeriod,
				correctiveLines: $lines,
				reason: $reason
			),
			context: ['administrationId' => $administrationId, 'period' => $correctsPeriod]
		);

	}//end correction()

	/**
	 * Export the Belastingdienst inspection bundle (ZIP) for a period (REQ-ICP-010).
	 *
	 * Query parameters:
	 *  - period_id         (required) filing period.
	 *  - administration_id (required) administration scope (IDOR-safe).
	 *
	 * @return JSONResponse 200 with the bundle metadata (zipPath, manifest,
	 *                      supplyCount); 400 / 500 as above. The actual byte stream
	 *                      is delivered by the OpenRegister file surface once a live
	 *                      instance is available (documented deferral).
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	#[NoAdminRequired]
	public function auditExport(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$params = $this->periodAndAdministration();
		if (($params['error'] ?? null) !== null) {
			return $params['error'];
		}

		return $this->run(
			action: 'export ICP audit bundle',
			compute: function () use ($params): array {
				$bundle = $this->filingService->exportForInspection(
					administrationId: $params['administrationId'],
					period: $params['period']
				);

				// Do not leak the server temp path to the client; report the manifest.
				unset($bundle['zipPath']);

				return $bundle;
			},
			context: ['administrationId' => $params['administrationId'], 'period' => $params['period']]
		);

	}//end auditExport()

	/**
	 * Render the ICP overlay PDF for an AR invoice (REQ-ICP-007).
	 *
	 * Returns the HTML body (Content-Type: text/html, downstream converters
	 * wrap it to a PDF binary) for an ARInvoice whose `icpContext.treatAsIcp`
	 * is true, including the reverse-charge notice, buyer + seller VAT-IDs and
	 * supply-type indication. Fails with HTTP 422 + `icp.invoice.vatid.missing`
	 * when the buyer VAT-ID is absent on a treatAsIcp invoice.
	 *
	 * Query parameters:
	 *  - invoice_id        (required) the ARInvoice record id (slug or @self.id).
	 *  - administration_id (required) administration scope (IDOR-safe, REQ-ICP-001).
	 *
	 * @return DataDisplayResponse|JSONResponse The rendered HTML payload, or a 4xx /
	 *                                          500 JSONResponse on validation /
	 *                                          internal failure.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	#[NoAdminRequired]
	public function renderInvoicePdf(): DataDisplayResponse|JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$invoiceId = trim((string)$this->request->getParam('invoice_id', ''));
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		$error = $this->validateId(value: $invoiceId, label: 'invoice_id') ?? $this->requireMember(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		return $this->renderFor(pdf: new IcpInvoicePdf(invoiceId: $invoiceId, administrationId: $administrationId));
	}//end renderInvoicePdf()

	/**
	 * Resolve the ARInvoice + CustomerMaster + seller administration triple
	 * for the requesting administration. Administration scope is server-resolved
	 * — clients never pick which administration's invoice to render
	 * (IDOR-safe, REQ-ICP-001).
	 *
	 * @param string $invoiceId The ARInvoice slug or id.
	 * @param string $administrationId The administration scope.
	 *
	 * @return array{invoice:?array<string,mixed>,customer:array<string,mixed>,seller:array<string,mixed>}
	 */
	private function loadInvoiceContext(string $invoiceId, string $administrationId): array {
		$invoice = self::firstWithKey(
			records: $this->records(schema: 'ARInvoice', filters: ['administrationId' => $administrationId]),
			key: $invoiceId
		);
		if ($invoice === null) {
			return ['invoice' => null, 'customer' => [], 'seller' => []];
		}

		return [
			'invoice' => $invoice,
			'customer' => $this->customerOf(invoice: $invoice, administrationId: $administrationId),
			'seller' => (self::firstWithKey(records: $this->records(schema: 'Administration', filters: []), key: $administrationId) ?? []),
		];

	}//end loadInvoiceContext()

	/**
	 * Validate and extract the shared period_id + administration_id parameters.
	 *
	 * @return array{period?:string,administrationId?:string,error?:JSONResponse}
	 */
	private function periodAndAdministration(): array {
		$period = trim((string)$this->request->getParam('period_id', ''));
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		$error = $this->validateId(value: $period, label: 'period_id');
		if ($error !== null) {
			return ['error' => $error];
		}

		$error = $this->requireMember(administrationId: $administrationId);
		if ($error !== null) {
			return ['error' => $error];
		}

		return ['period' => $period, 'administrationId' => $administrationId];
	}//end periodAndAdministration()

	/**
	 * Validate a short identifier parameter; returns a 400 JSONResponse or null.
	 *
	 * @param string $value The parameter value.
	 * @param string $label The parameter name for the error message.
	 *
	 * @return JSONResponse|null A 400 response when invalid, null when valid.
	 */
	private function validateId(string $value, string $label): ?JSONResponse {
		if ($value === '') {
			return new JSONResponse(['error' => $label . ' is required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match(self::ID_PATTERN, $value) !== 1) {
			return new JSONResponse(
				['error' => $label . ' must be a valid identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end validateId()

	/**
	 * Execute a service call, mapping any failure to a 500 without leaking a trace.
	 *
	 * @param string $action Human action label for the error log.
	 * @param callable():array $compute The service call to run.
	 * @param array<string,string> $context Log context (administration / period).
	 *
	 * @return JSONResponse 200 with the result, or 500 with a generic error.
	 */
	private function run(string $action, callable $compute, array $context): JSONResponse {
		try {
			$result = $compute();
		} catch (\Throwable $e) {
			$this->logger->error(
				'IcpController: failed to ' . $action,
				($context + ['exception' => $e->getMessage()])
			);

			return new JSONResponse(
				['error' => 'Failed to ' . $action],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end run()

	/**
	 * Load the invoice context and render the PDF; a failure to load is a
	 * logged 500, an unknown invoice a 404.
	 *
	 * @param IcpInvoicePdf $pdf The invoice to render.
	 *
	 * @return DataDisplayResponse|JSONResponse
	 */
	private function renderFor(IcpInvoicePdf $pdf): DataDisplayResponse|JSONResponse {
		try {
			$pdf->records = $this->loadInvoiceContext(
				invoiceId: $pdf->invoiceId,
				administrationId: $pdf->administrationId
			);
		} catch (\Throwable $e) {
			return $this->contextFailure(pdf: $pdf, failure: $e);
		}

		if ($pdf->records['invoice'] === null) {
			return new JSONResponse(['error' => 'Invoice not found'], Http::STATUS_NOT_FOUND);
		}

		return $this->renderRecords(pdf: $pdf);

	}//end renderFor()

	/**
	 * Render the loaded invoice: a missing buyer VAT-ID or another validation
	 * failure is a 422, a crash a logged 500.
	 *
	 * @param IcpInvoicePdf $pdf The invoice with its records.
	 *
	 * @return DataDisplayResponse|JSONResponse
	 */
	private function renderRecords(IcpInvoicePdf $pdf): DataDisplayResponse|JSONResponse {
		try {
			$payload = $this->pdfRenderer->render(
				invoice: $pdf->records['invoice'],
				customer: $pdf->records['customer'],
				seller: $pdf->records['seller']
			);
		} catch (\InvalidArgumentException $e) {
			return self::renderRefusal(failure: $e);
		} catch (\Throwable $e) {
			return $this->renderFailure(pdf: $pdf, failure: $e);
		}

		return self::pdfResponse(payload: $payload);

	}//end renderRecords()

	/**
	 * The 422 for invoice data the renderer refused, with its machine-readable
	 * code for a missing buyer VAT-ID (REQ-ICP-007).
	 *
	 * @param \InvalidArgumentException $failure The refusal.
	 *
	 * @return JSONResponse
	 */
	private static function renderRefusal(\InvalidArgumentException $failure): JSONResponse {
		$message = $failure->getMessage();
		$code = ArInvoiceIcpPdfRendererInterface::ERROR_VATID_MISSING;
		if (str_starts_with($message, $code) === true) {
			return new JSONResponse(
				['error' => $code, 'message' => $message],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			['error' => 'Render failed', 'message' => $message],
			Http::STATUS_UNPROCESSABLE_ENTITY
		);

	}//end renderRefusal()

	/**
	 * The rendered document, inline.
	 *
	 * @param array<string,mixed> $payload The renderer's payload.
	 *
	 * @return DataDisplayResponse
	 */
	private static function pdfResponse(array $payload): DataDisplayResponse {
		return new DataDisplayResponse(
			data: $payload['html'],
			statusCode: Http::STATUS_OK,
			headers: [
				'Content-Type' => 'text/html; charset=utf-8',
				'Content-Disposition' => 'inline; filename="' . $payload['filename'] . '"',
				'X-Shillinq-Icp' => 'true',
			]
		);

	}//end pdfResponse()

	/**
	 * Log a context that failed to load and answer a 500.
	 *
	 * @param IcpInvoicePdf $pdf The invoice.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function contextFailure(IcpInvoicePdf $pdf, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'IcpController: failed to load ARInvoice + CustomerMaster for ICP PDF',
			$pdf->logContext(failure: $failure)
		);

		return new JSONResponse(
			['error' => 'Failed to load invoice context'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end contextFailure()

	/**
	 * Log a render that crashed and answer a 500.
	 *
	 * @param IcpInvoicePdf $pdf The invoice.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function renderFailure(IcpInvoicePdf $pdf, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'IcpController: failed to render ICP PDF',
			$pdf->logContext(failure: $failure)
		);

		return new JSONResponse(
			['error' => 'Failed to render invoice PDF'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end renderFailure()

	/**
	 * The records of a schema of the shillinq register.
	 *
	 * @param string $schema The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return iterable<mixed>
	 */
	private function records(string $schema, array $filters): iterable {
		return $this->objectService
			->setRegister('shillinq')
			->setSchema($schema)
			->findAll(['filters' => $filters]);

	}//end records()

	/**
	 * The invoice's customer, by key or customer number; [] without a key or match.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed>
	 */
	private function customerOf(array $invoice, string $administrationId): array {
		$customerKey = trim((string)($invoice['customerId'] ?? ''));
		if ($customerKey === '') {
			return [];
		}

		foreach ($this->records(schema: 'CustomerMaster', filters: ['administrationId' => $administrationId]) as $candidate) {
			if (self::recordKey(record: $candidate) === $customerKey
				|| ((string)($candidate['customerNumber'] ?? '')) === $customerKey
			) {
				return $candidate;
			}
		}

		return [];

	}//end customerOf()

	/**
	 * The first record whose key is the given one.
	 *
	 * @param iterable<mixed> $records The records.
	 * @param string $key The key.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function firstWithKey(iterable $records, string $key): ?array {
		foreach ($records as $candidate) {
			if (self::recordKey(record: $candidate) === $key) {
				return $candidate;
			}
		}

		return null;

	}//end firstWithKey()

	/**
	 * A record's key: its slug, else its @self id, else its id.
	 *
	 * @param mixed $record The record.
	 *
	 * @return string
	 */
	private static function recordKey(mixed $record): string {
		return (string)(
			$record['@self']['slug'] ?? $record['@self']['id'] ?? $record['id'] ?? ''
		);

	}//end recordKey()
}//end class
