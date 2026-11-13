<?php

/**
 * Multi-PO Consolidation Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
class MultiPoConsolidationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param MultiPoConsolidationService $consolidation Slice 07 server-authoritative service.
	 * @param ContainerInterface $container DI container — OR's ObjectService for
	 *                                      the candidate-list endpoint that loads
	 *                                      the invoice header.
	 * @param AdministrationContextServiceInterface $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly MultiPoConsolidationService $consolidation,
		private readonly ContainerInterface $container,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function consolidate(): JSONResponse {
		$scope = $this->invoiceScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		return $this->consolidated(scope: $scope);
	}//end consolidate()

	/**
	 * Fan the invoice out over its POs.
	 *
	 * @param InvoiceLineScope $scope The authorised scope.
	 *
	 * @return JSONResponse 200 with the per-line results, the mapped service refusal, or a logged 500.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	private function consolidated(InvoiceLineScope $scope): JSONResponse {
		try {
			$results = $this->consolidation->consolidateInvoice(
				administrationId: $scope->administrationId,
				invoiceId: $scope->invoiceId
			);
		} catch (RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error('MultiPoConsolidationController: consolidate failed', $scope->invoiceLogContext(e: $e));
			return new JSONResponse(['error' => 'Could not consolidate invoice'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['lines' => $results], Http::STATUS_OK);
	}//end consolidated()

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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function candidates(): JSONResponse {
		$scope = $this->lineScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		try {
			return $this->invoiceCandidates(scope: $scope);
		} catch (RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error('MultiPoConsolidationController: candidates failed', $scope->lineLogContext(e: $e));
			return new JSONResponse(['error' => 'Could not enumerate candidates'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end candidates()

	/**
	 * Load the invoice of the scope and list the candidates of its line.
	 *
	 * @param InvoiceLineScope $scope The authorised scope.
	 *
	 * @return JSONResponse 200 with the candidates, or 404 when the invoice is missing.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	private function invoiceCandidates(InvoiceLineScope $scope): JSONResponse {
		$invoice = $this->loadInvoice(scope: $scope);
		if ($invoice === null) {
			return new JSONResponse(['error' => 'Supplier invoice not found'], Http::STATUS_NOT_FOUND);
		}

		return $this->lineCandidates(scope: $scope, invoice: $invoice);
	}//end invoiceCandidates()

	/**
	 * List the candidate tuples of the scope's invoice line.
	 *
	 * @param InvoiceLineScope    $scope   The authorised scope.
	 * @param array<string,mixed> $invoice The supplier invoice.
	 *
	 * @return JSONResponse 200 with the ordered candidates, or 404 when the line is missing.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	private function lineCandidates(InvoiceLineScope $scope, array $invoice): JSONResponse {
		$invoiceLine = $this->locateLine(
			invoice: $invoice,
			lineNumber: $scope->invoiceLineNumber
		);
		if ($invoiceLine === null) {
			return new JSONResponse(['error' => 'Invoice line not found'], Http::STATUS_NOT_FOUND);
		}

		$candidates = $this->consolidation->enumerateCandidateTuples(
			administrationId: $scope->administrationId,
			invoice: $invoice,
			invoiceLine: $invoiceLine
		);

		return $this->candidateList(scope: $scope, candidates: $candidates);
	}//end lineCandidates()

	/**
	 * The 200 answer listing an invoice line's candidates.
	 *
	 * @param InvoiceLineScope         $scope      The scope.
	 * @param array<int|string, mixed> $candidates The ordered candidate tuples.
	 *
	 * @return JSONResponse
	 */
	private function candidateList(InvoiceLineScope $scope, array $candidates): JSONResponse {
		return new JSONResponse(
			[
				'invoiceId' => $scope->invoiceId,
				'invoiceLineNumber' => $scope->invoiceLineNumber,
				'candidates' => $candidates,
			],
			Http::STATUS_OK
		);
	}//end candidateList()

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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function disambiguate(): JSONResponse {
		$scope = $this->lineScope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		$choice = new ChosenCandidate(request: $this->request);
		$refusal = $this->choiceRefusal(choice: $choice);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->persistChoice(scope: $scope, choice: $choice);
	}//end disambiguate()

	/**
	 * The 400 for a missing or malformed PO line id, or a malformed GRN line id.
	 *
	 * @param ChosenCandidate $choice The chosen tuple.
	 *
	 * @return JSONResponse|null The refusal, or null when the choice is well-formed.
	 */
	private function choiceRefusal(ChosenCandidate $choice): ?JSONResponse {
		if ($choice->hasPoLine() === false) {
			return new JSONResponse(['error' => 'chosenPoLineId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($choice->grnLineWellFormed() === false) {
			return new JSONResponse(['error' => 'chosenGrnLineId is malformed'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end choiceRefusal()

	/**
	 * Persist the chosen tuple.
	 *
	 * @param InvoiceLineScope $scope  The authorised scope.
	 * @param ChosenCandidate  $choice The well-formed choice.
	 *
	 * @return JSONResponse 201 with the persisted match, the mapped service refusal, or a logged 500.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	private function persistChoice(InvoiceLineScope $scope, ChosenCandidate $choice): JSONResponse {
		try {
			$match = $this->persistedMatch(scope: $scope, choice: $choice);
		} catch (RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			$this->logger->error('MultiPoConsolidationController: disambiguate failed', $scope->lineLogContext(e: $e));
			return new JSONResponse(['error' => 'Could not persist disambiguation choice'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse($match, Http::STATUS_CREATED);
	}//end persistChoice()

	/**
	 * Hand the chosen tuple to the service, which re-enumerates the candidate
	 * set server-side before persisting.
	 *
	 * @param InvoiceLineScope $scope  The authorised scope.
	 * @param ChosenCandidate  $choice The well-formed choice.
	 *
	 * @return array<string, mixed> The persisted ThreeWayMatch.
	 */
	private function persistedMatch(InvoiceLineScope $scope, ChosenCandidate $choice): array {
		return $this->consolidation->disambiguateAmbiguousMatches(
			administrationId: $scope->administrationId,
			invoiceId: $scope->invoiceId,
			invoiceLineNumber: $scope->invoiceLineNumber,
			chosenPoLineId: $choice->poLineId,
			chosenGrnLineId: $choice->grnLineId
		);
	}//end persistedMatch()

	/**
	 * The authorised invoice scope, or the refusal: 401 anonymous, 400 without
	 * a well-formed administration, a masked 404 outside it, 400 without an invoice.
	 *
	 * @return InvoiceLineScope|JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function invoiceScope(): InvoiceLineScope|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$scope = new InvoiceLineScope(request: $this->request);
		return $this->administrationRefusal(scope: $scope) ?? $this->invoiceRefusal(scope: $scope) ?? $scope;
	}//end invoiceScope()

	/**
	 * The authorised invoice scope with a line number, or the refusal.
	 *
	 * @return InvoiceLineScope|JSONResponse
	 */
	private function lineScope(): InvoiceLineScope|JSONResponse {
		$scope = $this->invoiceScope();
		if ($scope instanceof InvoiceLineScope && $scope->invoiceLineNumber < 1) {
			return new JSONResponse(['error' => 'invoiceLineNumber is required'], Http::STATUS_BAD_REQUEST);
		}

		return $scope;
	}//end lineScope()

	/**
	 * The 400 without a well-formed administration, or the masked 404 when the
	 * caller may not access it.
	 *
	 * @param InvoiceLineScope $scope The scope.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function administrationRefusal(InvoiceLineScope $scope): ?JSONResponse {
		if ($scope->administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $scope->administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The 400 without a well-formed invoice id.
	 *
	 * @param InvoiceLineScope $scope The scope.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function invoiceRefusal(InvoiceLineScope $scope): ?JSONResponse {
		if ($scope->invoiceId === '') {
			return new JSONResponse(['error' => 'invoiceId is required'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end invoiceRefusal()

	/**
	 * Load a SupplierInvoice (administration-scoped) via OR's
	 * ObjectService API; a failing lookup is logged and reads as missing.
	 *
	 * @param InvoiceLineScope $scope The authorised scope.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadInvoice(InvoiceLineScope $scope): ?array {
		try {
			$rows = $this->invoiceRows(scope: $scope);
		} catch (\Throwable $e) {
			$this->logger->error('MultiPoConsolidationController: invoice lookup failed', $scope->invoiceLogContext(e: $e));
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end loadInvoice()

	/**
	 * The SupplierInvoice rows matching the scope's invoice and administration.
	 *
	 * @param InvoiceLineScope $scope The authorised scope.
	 *
	 * @return iterable<mixed>
	 */
	private function invoiceRows(InvoiceLineScope $scope): iterable {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		return $objectService
			->setRegister($this->register())
			->setSchema('SupplierInvoice')
			->findAll(
				[
					'filters' => [
						'id' => $scope->invoiceId,
						'administrationId' => $scope->administrationId,
					],
				]
			);
	}//end invoiceRows()

	/**
	 * Find an invoice line by 1-based lineNumber.
	 *
	 * @param array<string,mixed> $invoice Invoice header.
	 * @param int $lineNumber 1-based position.
	 *
	 * @return array<string,mixed>|null
	 */
	private function locateLine(array $invoice, int $lineNumber): ?array {
		foreach ($this->invoiceLines(invoice: $invoice) as $index => $line) {
			if (is_array($line) === true && $this->lineNumberOf(line: $line, index: $index) === $lineNumber) {
				return $line;
			}
		}

		return null;
	}//end locateLine()

	/**
	 * The invoice's lines (under lines or invoiceLines), [] when it has none.
	 *
	 * @param array<string,mixed> $invoice Invoice header.
	 *
	 * @return array<int|string,mixed>
	 */
	private function invoiceLines(array $invoice): array {
		$lines = ($invoice['lines'] ?? $invoice['invoiceLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return $lines;
	}//end invoiceLines()

	/**
	 * An invoice line's 1-based number: its lineNumber, else its position.
	 *
	 * @param array<string,mixed> $line  The invoice line.
	 * @param int|string          $index The line's key in the lines list.
	 *
	 * @return int
	 */
	private function lineNumberOf(array $line, int|string $index): int {
		return (int)($line['lineNumber'] ?? ((int)$index + 1));
	}//end lineNumberOf()

	/**
	 * Resolve the OR register slug from app config (defaults to
	 * "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$appConfig = $this->container->get(\OCP\IAppConfig::class);
		$slug = $appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end register()

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
