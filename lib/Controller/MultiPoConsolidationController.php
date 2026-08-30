<?php

/**
 * Multi-PO Consolidation Controller
 *
 * Slice 07 of the bookkeeping-purchase-order-3way chain — exposes the
 * consolidation surface of {@see MultiPoConsolidationService} so the
 * crediteuren-administrateur UI can:
 *
 *  - run a fan-out for a consolidated supplier invoice
 *    (POST /api/three-way-match/consolidate),
 *  - list the candidate (PO line, GRN line) tuples for an ambiguous
 *    invoice line (GET /api/three-way-match/candidates),
 *  - persist the operator's chosen tuple
 *    (POST /api/three-way-match/disambiguate).
 *
 * Every endpoint is #[NoAdminRequired] (admin posture is the NC
 * SecurityMiddleware default — controllers without the attribute are
 * admin-only, see [[nc-security-defaults]]); a manual user-session guard
 * rejects anonymous callers and the administration scope is validated
 * server-side via AdministrationContextService so cross-tenant calls are
 * masked as 404 (ADR-005 IDOR-safe). The chosen tuple is re-enumerated
 * server-side before persistence so a forged POST cannot inject a
 * cross-tenant PO line id past the controller. No stack traces are
 * returned to the client.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\MultiPoConsolidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Multi-PO consolidation REST endpoints.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
 */
class MultiPoConsolidationController extends Controller {
	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param MultiPoConsolidationService $consolidation Slice 07 server-authoritative service.
	 * @param ContainerInterface $container DI container — OR's ObjectService for
	 *                                      the candidate-list endpoint that loads
	 *                                      the invoice header.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly MultiPoConsolidationService $consolidation,
		private readonly ContainerInterface $container,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Run the multi-PO consolidation fan-out for a supplier invoice.
	 *
	 * POST /api/three-way-match/consolidate
	 * Body: administrationId, invoiceId.
	 *
	 * Re-verified during security-endpoint-guards (REQ-001): ALREADY-GUARDED —
	 * `$this->administrationContext->canAccess($administrationId)` below is an
	 * enforced per-administration membership check (masked 404 on denial), not
	 * a syntactic no-op. The mechanical `hydra-gate-no-admin-idor` scan flagged
	 * this method only because `canAccess(` is not shaped like
	 * `authorize*`/`require*`/`ensure*` — a documented false positive, not a
	 * missing guard. No code change needed; recorded in the change's verdict
	 * table for traceability.
	 *
	 * @return JSONResponse 200 with per-line results; 400 on validation;
	 *                      401 anonymous; 404 cross-tenant or missing
	 *                      invoice; 500 without stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function consolidate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = $this->scopeParam(name: 'invoiceId');
		if ($invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$results = $this->consolidation->consolidateInvoice(
				administrationId: $administrationId,
				invoiceId: $invoiceId
			);
		} catch (RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MultiPoConsolidationController: consolidate failed',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not consolidate invoice'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['lines' => $results], Http::STATUS_OK);
	}//end consolidate()

	/**
	 * List candidate (PO line, GRN line) tuples for one invoice line so
	 * the disambiguation panel can render the operator's choices.
	 *
	 * GET /api/three-way-match/candidates?administrationId=...&invoiceId=...&invoiceLineNumber=...
	 *
	 * Re-verified during security-endpoint-guards (REQ-001): ALREADY-GUARDED —
	 * same enforced `canAccess()` membership check as {@see consolidate()};
	 * a false positive of the mechanical scan, not a missing guard.
	 *
	 * @return JSONResponse 200 with the ordered candidate list; 400 on
	 *                      validation; 401 anonymous; 404 on cross-tenant
	 *                      or missing invoice / line.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function candidates(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = $this->scopeParam(name: 'invoiceId');
		if ($invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		$invoiceLineNumber = (int)$this->request->getParam('invoiceLineNumber', 0);
		if ($invoiceLineNumber < 1) {
			return new JSONResponse(['error' => 'invoiceLineNumber is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$invoice = $this->loadInvoice(
				administrationId: $administrationId,
				invoiceId: $invoiceId
			);
			if ($invoice === null) {
				return new JSONResponse(['error' => 'Supplier invoice not found'], Http::STATUS_NOT_FOUND);
			}

			$invoiceLine = $this->locateLine(
				invoice: $invoice,
				lineNumber: $invoiceLineNumber
			);
			if ($invoiceLine === null) {
				return new JSONResponse(['error' => 'Invoice line not found'], Http::STATUS_NOT_FOUND);
			}

			$candidates = $this->consolidation->enumerateCandidateTuples(
				administrationId: $administrationId,
				invoice: $invoice,
				invoiceLine: $invoiceLine
			);
		} catch (RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MultiPoConsolidationController: candidates failed',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'invoiceLineNumber' => $invoiceLineNumber,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not enumerate candidates'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(
			[
				'invoiceId' => $invoiceId,
				'invoiceLineNumber' => $invoiceLineNumber,
				'candidates' => $candidates,
			],
			Http::STATUS_OK
		);

	}//end candidates()

	/**
	 * Persist the operator's chosen candidate tuple.
	 *
	 * POST /api/three-way-match/disambiguate
	 * Body: administrationId, invoiceId, invoiceLineNumber,
	 *       chosenPoLineId, chosenGrnLineId (optional).
	 *
	 * Re-verified during security-endpoint-guards (REQ-001): ALREADY-GUARDED —
	 * same enforced `canAccess()` membership check as {@see consolidate()};
	 * a false positive of the mechanical scan, not a missing guard. The
	 * chosen tuple is additionally re-enumerated server-side by
	 * `MultiPoConsolidationService` before persistence (class docblock), so a
	 * forged `chosenPoLineId`/`chosenGrnLineId` cannot smuggle a cross-tenant
	 * line id past this administration-level guard.
	 *
	 * @return JSONResponse 201 with the persisted ThreeWayMatch; 400 on
	 *                      validation or chosen tuple not in candidate
	 *                      set; 401 anonymous; 404 cross-tenant or
	 *                      missing invoice / line.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function disambiguate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = $this->scopeParam(name: 'invoiceId');
		if ($invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		$invoiceLineNumber = (int)$this->request->getParam('invoiceLineNumber', 0);
		if ($invoiceLineNumber < 1) {
			return new JSONResponse(['error' => 'invoiceLineNumber is required'], Http::STATUS_BAD_REQUEST);
		}

		$chosenPoLineId = trim((string)$this->request->getParam('chosenPoLineId', ''));
		$chosenGrnLineId = trim((string)$this->request->getParam('chosenGrnLineId', ''));

		if ($chosenPoLineId === '' || preg_match(self::ID_PATTERN, $chosenPoLineId) !== 1) {
			return new JSONResponse(['error' => 'chosenPoLineId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($chosenGrnLineId !== '' && preg_match(self::ID_PATTERN, $chosenGrnLineId) !== 1) {
			return new JSONResponse(['error' => 'chosenGrnLineId is malformed'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$match = $this->consolidation->disambiguateAmbiguousMatches(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				invoiceLineNumber: $invoiceLineNumber,
				chosenPoLineId: $chosenPoLineId,
				chosenGrnLineId: $chosenGrnLineId
			);
		} catch (RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MultiPoConsolidationController: disambiguate failed',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'invoiceLineNumber' => $invoiceLineNumber,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => 'Could not persist disambiguation choice'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse($match, Http::STATUS_CREATED);
	}//end disambiguate()

	/**
	 * Load a SupplierInvoice (administration-scoped) via OR's
	 * ObjectService API.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId Invoice id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadInvoice(string $administrationId, string $invoiceId): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema('SupplierInvoice')
				->findAll(
					[
						'filters' => [
							'id' => $invoiceId,
							'administrationId' => $administrationId,
						],
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MultiPoConsolidationController: invoice lookup failed',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end loadInvoice()

	/**
	 * Find an invoice line by 1-based lineNumber.
	 *
	 * @param array<string,mixed> $invoice Invoice header.
	 * @param int $lineNumber 1-based position.
	 *
	 * @return array<string,mixed>|null
	 */
	private function locateLine(array $invoice, int $lineNumber): ?array {
		$lines = ($invoice['lines'] ?? $invoice['invoiceLines'] ?? []);
		if (is_array($lines) === false) {
			return null;
		}

		foreach ($lines as $index => $line) {
			if (is_array($line) === false) {
				continue;
			}

			$candidateLineNumber = (int)($line['lineNumber'] ?? ((int)$index + 1));
			if ($candidateLineNumber === $lineNumber) {
				return $line;
			}
		}

		return null;
	}//end locateLine()

	/**
	 * Resolve the OR register slug from app config (defaults to
	 * "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$appConfig = $this->container->get(\OCP\IAppConfig::class);
		$slug = $appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end register()

	/**
	 * Read and validate a scope parameter, returning '' when blank or
	 * malformed.
	 *
	 * @param string $name Parameter name (body for POST / query for GET).
	 *
	 * @return string The validated value or '' (blank/malformed).
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()

	/**
	 * Map a service-level RuntimeException to a JSONResponse.
	 *
	 * Conventions:
	 *  - "not found"                            → 404
	 *  - "not in the candidate set"             → 400 (forged choice)
	 *  - anything else                          → 400 (validation)
	 *
	 * @param \RuntimeException $exception The exception to map.
	 *
	 * @return JSONResponse
	 */
	private function mapRuntimeException(\RuntimeException $exception): JSONResponse {
		$message = $exception->getMessage();
		if (str_contains($message, 'not found') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end mapRuntimeException()
}//end class
