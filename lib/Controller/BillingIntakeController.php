<?php

/**
 * Billing Intake Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * HTTP ingress for the time-expense-invoice-intake change: a single
 * authenticated endpoint another Conduction app (pipelinq, via its
 * time-billing-handoff-emit change) POSTs a batch of externally-approved
 * time entries to. Materialises the batch into one draft T&M
 * BillableInvoice via TimeIntakeService, which delegates the actual invoice
 * construction to the existing InvoiceGenerationService.
 *
 *   POST /api/billing/time-intake — draft/replay a batch (idempotent).
 *
 * Endpoint is authenticated (#[NoAdminRequired], never #[PublicPage]). Per
 * ADR-005, the administration (tenant) scope is always resolved server-side
 * via AdministrationContextService and the personId is always the session
 * user's uid — a client-supplied administrationId in the body is ignored,
 * mirroring InvoiceApiController / SupplierInvoiceImportController.
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
 * @spec openspec/specs/time-expense-invoice-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TimeIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * HTTP API for the pipelinq time-billing handoff ingress.
 *
 * @spec openspec/specs/time-expense-invoice-intake/spec.md
 */
class BillingIntakeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param TimeIntakeService $service Validates/materialises/delegates the batch.
	 * @param AdministrationContextService $administrationContext Server-resolved tenant scope (ADR-005).
	 * @param IUserSession $session User session.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translation service for error-response messages (ADR-050).
	 */
	public function __construct(
		IRequest $request,
		private readonly TimeIntakeService $service,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Ingest a batch of approved time entries (POST /api/billing/time-intake).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/time-expense-invoice-intake/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function timeIntake(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => $this->l10n->t('Not logged in'), 'error' => 'billing-time-intake-unauthenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			return new JSONResponse($this->ingested(user: $user), Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error('BillingIntakeController.timeIntake failed', ['exception' => $e]);
			return $this->failureResponse(e: $e);
		}
	}//end timeIntake()

	/**
	 * Ingest the batch for the server-resolved administration and the session user.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return array<string,mixed> The service result.
	 */
	private function ingested(IUser $user): array {
		$admin = $this->resolveAdministrationId();
		$personId = $user->getUID();
		$body = $this->decodeBody();

		return $this->service->ingest(administrationId: $admin, personId: $personId, body: $body);
	}//end ingested()

	/**
	 * The answer for a failed intake: 400 invalid input, 409 / 422 for a runtime failure, else 500.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function failureResponse(\Throwable $e): JSONResponse {
		if ($e instanceof \InvalidArgumentException) {
			return new JSONResponse(
				['message' => $this->l10n->t('Invalid time-intake batch'), 'error' => 'billing-time-intake-invalid-input'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($e instanceof \RuntimeException) {
			return $this->runtimeFailureResponse(e: $e);
		}

		return new JSONResponse(
			['message' => $this->l10n->t('Unable to process time-intake batch'), 'error' => 'billing-time-intake-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end failureResponse()

	/**
	 * 409 for a batch already processed (a "Conflict:" message), else 422.
	 *
	 * @param \RuntimeException $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function runtimeFailureResponse(\RuntimeException $e): JSONResponse {
		if (str_starts_with($e->getMessage(), 'Conflict:') === true) {
			return new JSONResponse(
				['message' => $this->l10n->t('This batch has already been processed'), 'error' => 'billing-time-intake-conflict'],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(
			[
				'message' => $this->l10n->t('Time-intake batch could not be processed'),
				'error' => 'billing-time-intake-unprocessable',
			],
			Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end runtimeFailureResponse()

	/**
	 * Decode the JSON request body, falling back to POST/GET params.
	 *
	 * @return array<string,mixed>
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
	 * Resolve the administration id server-side (never client-supplied — ADR-005).
	 *
	 * @return string
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
