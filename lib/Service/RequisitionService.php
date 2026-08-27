<?php

/**
 * Requisition Service
 *
 * Server-authoritative create / submit / approve / reject for the purchase
 * requisition (aanvraag) sub-ledger (REQ-REQ-001..004). A Requisition is the
 * internal pre-PO document: an employee raises it, it is submitted for
 * approval, and the approval decision reuses the existing commitment
 * mandate/budget infrastructure — this service does NOT reimplement approval
 * routing. approveRequisition() calls
 * OCA\Shillinq\Lifecycle\BudgetBlocker::canCommit() directly, unmodified,
 * against the Requisition object itself: BudgetBlocker is schema-agnostic (it
 * reads array fields off whatever object it is given), and Requisition
 * deliberately carries the same programma/boekjaar/totaalbedrag_excl_btw/soort
 * field contract the Commitment schema already uses, so the guard's budget
 * lookup and override-mandate check work verbatim (ADR-031, ADR-022 —
 * consume, don't reimplement).
 *
 * Every read/write is scoped to the caller's administrationId via
 * AdministrationContextService (ADR-005, IDOR-safe: cross-tenant access is
 * masked as "not found").
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Requisition create / submit / approve / reject (REQ-REQ-001..004).
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 */
class RequisitionService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param BudgetBlocker $budgetBlocker Reused, unmodified budget/mandate guard.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly BudgetBlocker $budgetBlocker,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create a draft requisition with its lines (REQ-REQ-001).
	 *
	 * Server-authoritative: the requester is derived from the validated
	 * administration membership, never trusted from the request body
	 * (ADR-005). The requisitionNumber is generated server-side. Lines are
	 * normalised and totaalbedrag_excl_btw is computed as their sum.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (programma, boekjaar,
	 *                                     neededByDate, justification, soort,
	 *                                     preferredSupplierId, lines).
	 *
	 * @return array<string,mixed> The persisted Requisition payload.
	 *
	 * @throws \RuntimeException When the requester lacks access or a required field is missing.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function createRequisition(string $administrationId, array $payload): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			// Mask as not-found per ADR-005 (avoid disclosing other tenants).
			throw new RuntimeException('Administration not found');
		}

		$requester = (string)$this->administrationContext->currentUserId();
		if ($requester === '') {
			throw new RuntimeException('Authenticated requester is required');
		}

		$programma = trim((string)($payload['programme'] ?? ''));
		if ($programma === '') {
			throw new RuntimeException('programma is required');
		}

		$financialYear = (int)($payload['financialYear'] ?? 0);
		if ($financialYear <= 0) {
			throw new RuntimeException('boekjaar is required');
		}

		$neededByDate = trim((string)($payload['neededByDate'] ?? ''));
		if ($neededByDate === '') {
			throw new RuntimeException('neededByDate is required');
		}

		$justification = trim((string)($payload['justification'] ?? ''));
		if ($justification === '') {
			throw new RuntimeException('justification is required');
		}

		$kind = trim((string)($payload['kind'] ?? ''));
		if ($kind === '') {
			throw new RuntimeException('soort is required');
		}

		$lines = $this->normaliseLines(rawLines: (array)($payload['lines'] ?? []));
		$totalCent = $this->totalCents(lines: $lines);
		if ($totalCent <= 0) {
			throw new RuntimeException('Requisition total must be positive');
		}

		$requisitionNumber = $this->generateRequisitionNumber(administrationId: $administrationId);

		$requisition = [
			'requisitionNumber' => $requisitionNumber,
			'administrationId' => $administrationId,
			'requester' => $requester,
			'programme' => $programma,
			'financialYear' => $financialYear,
			'neededByDate' => $neededByDate,
			'justification' => $justification,
			'kind' => $kind,
			'preferredSupplierId' => trim((string)($payload['preferredSupplierId'] ?? '')),
			'total_amount_excl_vat' => $totalCent,
			'statusCode' => 'draft',
		];

		$persisted = $this->saveObject(schema: 'Requisition', object: $requisition);
		$requisitionId = (string)($persisted['id'] ?? ($persisted['@self']['id'] ?? $requisitionNumber));

		foreach ($lines as $line) {
			$line['requisitionId'] = $requisitionId;
			$line['administrationId'] = $administrationId;
			$this->saveObject(schema: 'RequisitionLine', object: $line);
		}

		return $persisted;
	}//end createRequisition()

	/**
	 * Submit a draft requisition for approval (REQ-REQ-002).
	 *
	 * Always routes through human approval regardless of mandate sufficiency
	 * — unlike Commitment's `indienen` transition, a Requisition never
	 * skips straight to approved.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 *
	 * @return array<string,mixed> The updated Requisition.
	 *
	 * @throws \RuntimeException When the requisition is missing, not in draft,
	 *                           or has no positive total.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function submitRequisition(string $administrationId, string $requisitionId): array {
		$requisition = $this->loadRequisition(administrationId: $administrationId, requisitionId: $requisitionId);

		if ((string)($requisition['statusCode'] ?? '') !== 'draft') {
			throw new RuntimeException('Requisition can only be submitted from draft');
		}

		if ((int)($requisition['total_amount_excl_vat'] ?? 0) <= 0) {
			throw new RuntimeException('Requisition has no positive total; add lines before submitting');
		}

		$requisition['statusCode'] = 'submitted';

		return $this->saveObject(schema: 'Requisition', object: $requisition);
	}//end submitRequisition()

	/**
	 * Approve a submitted requisition (REQ-REQ-003).
	 *
	 * Gated by BudgetBlocker::canCommit() — reused unmodified from
	 * bookkeeping-verplichtingenadministratie. BudgetBlocker resolves the
	 * matching Budget for (programma, boekjaar) and checks that
	 * totaalbedrag_excl_btw fits the free room, or that the approver's
	 * mandate carries an override, exactly as it does for a Commitment.
	 * Fail-closed: when the budget check fails or errors, the requisition is
	 * NOT approved (CWE-863).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 * @param string $approverId User id of the approving user (server-resolved).
	 *
	 * @return array<string,mixed> The updated Requisition.
	 *
	 * @throws \RuntimeException When the requisition is missing, not submitted,
	 *                           or the budget check fails.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function approveRequisition(string $administrationId, string $requisitionId, string $approverId): array {
		$requisition = $this->loadRequisition(administrationId: $administrationId, requisitionId: $requisitionId);

		if ((string)($requisition['statusCode'] ?? '') !== 'submitted') {
			throw new RuntimeException('Requisition can only be approved from submitted');
		}

		// Reuse BudgetBlocker unmodified: it reads programma/boekjaar/
		// totaalbedrag_excl_btw/administrationId/soort straight off $requisition
		// because $object is supplied directly (no Commitment lookup happens).
		if ($this->budgetBlocker->canCommit(commitmentNumber: $requisitionId, object: $requisition) === false) {
			throw new RuntimeException('Requisition exceeds available budget');
		}

		$requisition['statusCode'] = 'approved';
		$requisition['approvedBy'] = $approverId;
		$requisition['approvedAt'] = $this->nowIso();

		return $this->saveObject(schema: 'Requisition', object: $requisition);
	}//end approveRequisition()

	/**
	 * Reject a submitted requisition (REQ-REQ-004).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 * @param string $rejectorId User id of the rejecting user (server-resolved).
	 * @param string $reason Non-blank rejection reason.
	 *
	 * @return array<string,mixed> The updated Requisition.
	 *
	 * @throws \RuntimeException When the requisition is missing, not submitted,
	 *                           or the reason is blank.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function rejectRequisition(
		string $administrationId,
		string $requisitionId,
		string $rejectorId,
		string $reason,
	): array {
		$requisition = $this->loadRequisition(administrationId: $administrationId, requisitionId: $requisitionId);

		if ((string)($requisition['statusCode'] ?? '') !== 'submitted') {
			throw new RuntimeException('Requisition can only be rejected from submitted');
		}

		$reason = trim($reason);
		if ($reason === '') {
			throw new RuntimeException('rejectionReason is required');
		}

		$requisition['statusCode'] = 'rejected';
		$requisition['rejectedBy'] = $rejectorId;
		$requisition['rejectionReason'] = $reason;

		return $this->saveObject(schema: 'Requisition', object: $requisition);
	}//end rejectRequisition()

	/**
	 * Load a Requisition for the administration, IDOR-checked.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $requisitionId Requisition id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When access is denied or the requisition is missing.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function loadRequisition(string $administrationId, string $requisitionId): array {
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Requisition not found');
		}

		$requisition = $this->findOne(
			schema: 'Requisition',
			filters: [
				'id' => $requisitionId,
				'administrationId' => $administrationId,
			]
		);
		if ($requisition === null) {
			throw new RuntimeException('Requisition not found');
		}

		return $requisition;
	}//end loadRequisition()

	/**
	 * Fetch the RequisitionLine records for a requisition.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $requisitionId Requisition id.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function findLines(string $administrationId, string $requisitionId): array {
		return $this->findAll(
			schema: 'RequisitionLine',
			filters: [
				'requisitionId' => $requisitionId,
				'administrationId' => $administrationId,
			]
		);

	}//end findLines()

	/**
	 * Normalise + validate the line items in the request payload.
	 *
	 * Mirrors PurchaseOrderService::normaliseLines() (same shape: description,
	 * quantity, unitPrice, glAccountSuggestion, computed lineTotal).
	 *
	 * @param array<int,mixed> $rawLines Raw line entries from the caller.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws \RuntimeException When a line is malformed.
	 */
	private function normaliseLines(array $rawLines): array {
		if ($rawLines === []) {
			throw new RuntimeException('Requisition must have at least one line');
		}

		$lines = [];
		$lineNumber = 0;
		foreach ($rawLines as $raw) {
			if (is_array($raw) === false) {
				throw new RuntimeException('Line item must be an object');
			}

			$lineNumber++;
			$description = trim((string)($raw['description'] ?? ''));
			$glAccount = trim((string)($raw['glAccountSuggestion'] ?? ''));
			$quantity = (float)($raw['quantity'] ?? 0);
			$unitPrice = (float)($raw['unitPrice'] ?? 0);

			if ($description === '') {
				throw new RuntimeException('Line ' . $lineNumber . ' is missing description');
			}

			if ($glAccount === '') {
				throw new RuntimeException('Line ' . $lineNumber . ' is missing glAccountSuggestion');
			}

			if ($quantity <= 0.0) {
				throw new RuntimeException('Line ' . $lineNumber . ' must have positive quantity');
			}

			if ($unitPrice < 0.0) {
				throw new RuntimeException('Line ' . $lineNumber . ' must have non-negative unitPrice');
			}

			$unitCents = $this->toCents(amount: $unitPrice);
			$lineCents = (int)round(($unitCents * $quantity), 0, PHP_ROUND_HALF_UP);

			$lines[] = [
				'lineNumber' => ((int)($raw['lineNumber'] ?? $lineNumber)),
				'description' => $description,
				'quantity' => $quantity,
				'unitPrice' => $unitCents,
				'lineTotal' => $lineCents,
				'glAccountSuggestion' => $glAccount,
			];
		}//end foreach

		return $lines;
	}//end normaliseLines()

	/**
	 * Sum the lineTotal of every line as integer cents.
	 *
	 * @param array<int,array<string,mixed>> $lines Normalised lines.
	 *
	 * @return int Total in cents.
	 */
	private function totalCents(array $lines): int {
		$total = 0;
		foreach ($lines as $line) {
			$total += (int)($line['lineTotal'] ?? 0);
		}

		return $total;
	}//end totalCents()

	/**
	 * Generate a requisition number for the administration.
	 *
	 * Format: REQ-{year}-{administrationId}-{6-digit-sequence}.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return string
	 */
	private function generateRequisitionNumber(string $administrationId): string {
		$year = (int)date('Y');

		$existing = $this->findAll(
			schema: 'Requisition',
			filters: ['administrationId' => $administrationId]
		);

		$thisYear = 0;
		foreach ($existing as $row) {
			$number = (string)($row['requisitionNumber'] ?? '');
			if ($number !== '' && str_starts_with($number, 'REQ-' . $year . '-') === true) {
				$thisYear++;
			}
		}

		$sequence = str_pad((string)($thisYear + 1), 6, '0', STR_PAD_LEFT);

		return sprintf('REQ-%d-%s-%s', $year, $administrationId, $sequence);
	}//end generateRequisitionNumber()

	/**
	 * Persist an object via OpenRegister's real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object The object to persist.
	 *
	 * @return array<string,mixed> The persisted record (id stamped by OR).
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// is_array() arm here was unreachable by type and this helper returned
			// the INPUT on every save — silently discarding the id/uuid the store
			// had just generated, which callers then read back as empty.
			return (array)$result->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->error(
				'RequisitionService: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all matching records via the real ObjectService API (findAll).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RequisitionService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Convert a euro float to integer cents, rounding half-up.
	 *
	 * @param float $amount Amount in euro.
	 *
	 * @return int Cents.
	 */
	private function toCents(float $amount): int {
		return (int)round(($amount * 100), 0, PHP_ROUND_HALF_UP);
	}//end toCents()

	/**
	 * Current timestamp in ISO-8601 — server-authoritative for approvedAt.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
