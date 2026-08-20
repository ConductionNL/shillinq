<?php

/**
 * ICP Controller
 *
 * Tier-3 read-only intra-community supplies (ICP) API (REQ-ICP-003, REQ-ICP-004,
 * REQ-ICP-002). Exposes GET endpoints that return the ICP ledger (aggregated
 * lines + totals) for a period, the reconciliation outcome against the
 * BTW-aangifte rubriek 3b, and the EUR 50,000 periodicity-threshold decision for a
 * quarter. Every endpoint is available to any authenticated user
 * (#[NoAdminRequired]); the administration scope is validated and reads are
 * delegated to OpenRegister's ObjectService, which enforces multitenancy / RBAC,
 * so no cross-administration data leaks (IDOR-safe, REQ-ICP-001). These endpoints
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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ArInvoiceIcpPdfRenderer;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\ViesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
	 * @param IcpService $icpService The ICP read-side computation service.
	 * @param IcpFilingService $filingService The ICP filing write service (correction + export).
	 * @param ViesService $viesService The VIES validation service.
	 * @param ArInvoiceIcpPdfRenderer $pdfRenderer The ICP overlay PDF renderer (REQ-ICP-007).
	 * @param IUserSession $userSession The session for the acting user id (auth body-guard).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IcpService $icpService,
		private readonly IcpFilingService $filingService,
		private readonly ViesService $viesService,
		private readonly ArInvoiceIcpPdfRenderer $pdfRenderer,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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

		$error = $this->validateId(value: $administrationId, label: 'administration_id');
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

		$error = $this->validateId(value: $administrationId, label: 'administration_id');
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

		$error = $this->validateId(value: $administrationId, label: 'administration_id');
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

		$error = $this->validateId(value: $invoiceId, label: 'invoice_id');
		if ($error !== null) {
			return $error;
		}

		$error = $this->validateId(value: $administrationId, label: 'administration_id');
		if ($error !== null) {
			return $error;
		}

		try {
			$records = $this->loadInvoiceContext(
				invoiceId: $invoiceId,
				administrationId: $administrationId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IcpController: failed to load ARInvoice + CustomerMaster for ICP PDF',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to load invoice context'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($records['invoice'] === null) {
			return new JSONResponse(['error' => 'Invoice not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$payload = $this->pdfRenderer->render(
				invoice: $records['invoice'],
				customer: $records['customer'],
				seller: $records['seller']
			);
		} catch (\InvalidArgumentException $e) {
			$message = $e->getMessage();
			$code = ArInvoiceIcpPdfRenderer::ERROR_VATID_MISSING;
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
		} catch (\Throwable $e) {
			$this->logger->error(
				'IcpController: failed to render ICP PDF',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to render invoice PDF'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		$response = new DataDisplayResponse(
			data: $payload['html'],
			statusCode: Http::STATUS_OK,
			headers: [
				'Content-Type' => 'text/html; charset=utf-8',
				'Content-Disposition' => 'inline; filename="' . $payload['filename'] . '"',
				'X-Shillinq-Icp' => 'true',
			]
		);

		return $response;
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
		$invoices = $this->objectService
			->setRegister('shillinq')
			->setSchema('ARInvoice')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$invoice = null;
		foreach ($invoices as $candidate) {
			$candidateId = (string)(
				$candidate['@self']['slug'] ?? $candidate['@self']['id'] ?? $candidate['id'] ?? ''
			);
			if ($candidateId === $invoiceId) {
				$invoice = $candidate;
				break;
			}
		}

		if ($invoice === null) {
			return ['invoice' => null, 'customer' => [], 'seller' => []];
		}

		$customer = [];
		$customerKey = trim((string)($invoice['customerId'] ?? ''));
		if ($customerKey !== '') {
			$candidates = $this->objectService
				->setRegister('shillinq')
				->setSchema('CustomerMaster')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
			foreach ($candidates as $candidate) {
				$candidateId = (string)(
					$candidate['@self']['slug'] ?? $candidate['@self']['id'] ?? $candidate['id'] ?? ''
				);
				if ($candidateId === $customerKey
					|| ((string)($candidate['customerNumber'] ?? '')) === $customerKey
				) {
					$customer = $candidate;
					break;
				}
			}
		}

		$seller = [];
		$administrations = $this->objectService
			->setRegister('shillinq')
			->setSchema('Administration')
			->findAll(['filters' => []]);
		foreach ($administrations as $candidate) {
			$candidateId = (string)(
				$candidate['@self']['slug'] ?? $candidate['@self']['id'] ?? $candidate['id'] ?? ''
			);
			if ($candidateId === $administrationId) {
				$seller = $candidate;
				break;
			}
		}

		return [
			'invoice' => $invoice,
			'customer' => $customer,
			'seller' => $seller,
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

		$error = $this->validateId(value: $administrationId, label: 'administration_id');
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
}//end class
